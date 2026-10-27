<?php

/**
 * Minimal MCP server over newline-delimited JSON-RPC 2.0 on stdio.
 *
 * Supported: initialize, ping, tools/list, tools/call, and notifications
 * (messages without an id are never answered).
 *
 * Protocol negotiation follows the MCP versioning spec: the server answers
 * `initialize` with a protocolVersion it actually implements. The client's
 * requested version is echoed only when it is one of the supported versions;
 * any other value gets the server's latest supported version instead of a
 * pretend-agreement (echoing an arbitrary version back would claim support
 * for a spec the server does not implement).
 *
 * Capability notes: `roots` is a client capability of the 2024-11-05 /
 * 2025-03-26 revisions and was deprecated in newer revisions; it is only
 * advertised for versions where it exists. Workspace discovery never depends
 * on roots alone - TYPEPHP_MCP_PROJECT_ROOT and cwd-based auto-detection are
 * always available, and roots is kept as a compatibility path.
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Protocol revisions this server implements. The tool capability and the
     * JSON-RPC surface are stable across revisions, but only these versions
     * are claimed; anything else is answered with PROTOCOL_VERSION.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2024-11-05'];

    /**
     * Revisions where the roots capability exists (not deprecated).
     *
     * @var array<int, string>
     */
    private const ROOTS_CAPABILITY_VERSIONS = ['2024-11-05', '2025-03-26'];

    private RepoRoot $root;

    private ?DocIndex $docIndex = null;

    private ?CodeIndex $codeIndex = null;

    /** @var array<int, string> Search roots auto-detected from the client's
     *   open workspace (MCP roots) and/or TYPEPHP_MCP_PROJECT_ROOT. */
    private array $autoDirs = [];

    /** @var array<int, string> JSON-RPC requests the server must send to the
     *   client (e.g. roots/list); drained by the I/O loop. */
    private array $outbound = [];

    private ?int $rootsRequestId = null;

    private bool $clientSupportsRoots = false;

    private int $nextRequestId = 1;

    public function __construct(RepoRoot $root)
    {
        $this->root = $root;
        $this->seedFromEnv();
        $this->seedFromCwd();
    }

    /**
     * TYPEPHP_MCP_PROJECT_ROOT seeds the auto-detected project dirs even when
     * the client does not send MCP roots.
     *
     * Several paths are separated by ';', and on POSIX also by ':'. The colon
     * cannot be a separator on Windows: it is part of every drive-letter path,
     * so splitting on it turned "D:/work/proj" into "D" and "/work/proj" and
     * the project was never detected.
     */
    private function seedFromEnv(): void
    {
        $raw = getenv('TYPEPHP_MCP_PROJECT_ROOT');
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $separator = stripos(PHP_OS, 'WIN') === 0 ? '#;#' : '#[;:]#';
        foreach (preg_split($separator, $raw) ?: [] as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $entry = str_replace('\\', '/', $entry);
            if (ProjectDetector::isTypePhpProject($entry)) {
                foreach (ProjectDetector::searchDirs($entry) as $dir) {
                    $this->addAutoDir($dir);
                }
            }
        }
    }

    private function addAutoDir(string $dir): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir) || in_array($dir, $this->autoDirs, true)) {
            return;
        }
        $this->autoDirs[] = $dir;
    }

    /**
     * Best-effort, cross-IDE auto-detection: many MCP hosts (Claude Code, and
     * several IDE integrations) launch the server with the working directory set
     * to the open workspace. Walk up from cwd and, for the nearest TypePHP
     * project found, merge it into the default search set. This needs no client
     * cooperation (unlike MCP roots) and works regardless of which agent IDE is
     * hosting the server. The bundled compiler repo itself is skipped, since it
     * is already the default search root.
     */
    private function seedFromCwd(): void
    {
        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return;
        }
        // Normalize the official repo path to forward slashes so the comparison
        // below is separator/case-insensitive across Windows and Linux.
        $officialRaw = $this->root->path();
        $official = str_replace('\\', '/', is_string($r = realpath($officialRaw)) ? $r : $officialRaw);
        $dir = str_replace('\\', '/', $cwd);
        for ($depth = 0; $depth < 6; $depth++) {
            $real = realpath($dir);
            $check = is_string($real) ? str_replace('\\', '/', $real) : $dir;
            if (ProjectDetector::isTypePhpProject($check)) {
                $isOfficial = $check === $official || str_starts_with($check . '/', $official . '/');
                if (!$isOfficial) {
                    foreach (ProjectDetector::searchDirs($check) as $d) {
                        $this->addAutoDir($d);
                    }
                }
                return;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    /**
     * Handle one raw line. Returns the JSON reply, or null for notifications.
     */
    public function handleLine(string $line): ?string
    {
        // Some hosts pipe stdin through text encoders that prepend a UTF-8
        // BOM; json_decode rejects the first line otherwise.
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            $line = substr($line, 3);
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return $this->encode(['jsonrpc' => '2.0', 'id' => null, 'error' => [
                'code' => -32700,
                'message' => 'Parse error',
            ]]);
        }
        // A JSON-RPC batch (a top-level array) would have to be answered with
        // a batch. Answer it with an explicit invalid-request error instead
        // of dropping the line: a client that sent one would otherwise wait
        // forever for replies it never gets. Written without array_is_list()
        // (PHP 8.1+) to stay inside the PHP subset the compiler accepts.
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return $this->encode(['jsonrpc' => '2.0', 'id' => null, 'error' => [
                'code' => -32600,
                'message' => 'Invalid Request: batch requests are not supported',
            ]]);
        }

        $hasId = array_key_exists('id', $decoded);
        $id = $hasId ? $decoded['id'] : null;
        $method = isset($decoded['method']) && is_string($decoded['method']) ? $decoded['method'] : '';
        // A JSON-RPC response (id + result/error, no method) to a request we
        // sent (e.g. roots/list) is consumed, never answered.
        if ($method === '' && $hasId && (isset($decoded['result']) || isset($decoded['error']))) {
            if ($this->rootsRequestId !== null && $id === $this->rootsRequestId) {
                if (isset($decoded['result'])) {
                    $this->consumeRootsResponse($decoded['result']);
                }
                $this->rootsRequestId = null;
            }
            return null;
        }
        if ($method === '') {
            if (!$hasId) {
                return null;
            }
            return $this->error($id, -32600, 'Invalid Request: missing method');
        }

        // JSON-RPC notifications (no id) are never answered, even when they
        // name a request method: a client sending tools/list without an id is
        // not waiting for a reply, and answering would corrupt its reply
        // stream. Notifications are neither executed nor answered.
        if (!$hasId && in_array($method, [
            'initialize', 'ping', 'tools/list', 'tools/call',
            'prompts/list', 'resources/list', 'resources/templates/list',
            'logging/setLevel',
        ], true)) {
            return null;
        }

        if ($method === 'initialize') {
            $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : [];
            $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion']) && $params['protocolVersion'] !== ''
                ? $params['protocolVersion']
                : '';
            $clientCaps = isset($params['capabilities']) && is_array($params['capabilities']) ? $params['capabilities'] : [];
            $this->clientSupportsRoots = isset($clientCaps['roots']);
            // Negotiate per the MCP versioning spec: answer with a version we
            // actually implement. Echo the client's version only when it is
            // supported; never pretend to support an arbitrary newer one.
            $negotiated = $requested !== '' && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requested
                : self::PROTOCOL_VERSION;
            $capabilities = ['tools' => (object)[]];
            if (in_array($negotiated, self::ROOTS_CAPABILITY_VERSIONS, true)) {
                $capabilities['roots'] = (object)[];
            }
            return $this->result($id, [
                'protocolVersion' => $negotiated,
                'capabilities' => $capabilities,
                'serverInfo' => [
                    'name' => 'typephp-mcp',
                    'version' => ToolDefinitions::SERVER_VERSION,
                ],
                'instructions' => Restrictions::summary(),
            ]);
        }
        if ($method === 'ping') {
            return $this->result($id, (object)[]);
        }
        if ($method === 'notifications/initialized') {
            // The client is ready: ask it for the open workspace so we can
            // auto-detect TypePHP projects. Only if the client advertised the
            // roots capability. Workspace discovery also works without roots
            // via TYPEPHP_MCP_PROJECT_ROOT and cwd-based detection.
            if ($this->clientSupportsRoots) {
                $this->enqueueRootsList();
            }
            return null;
        }
        if ($method === 'notifications/roots/list_changed') {
            if ($this->clientSupportsRoots) {
                $this->enqueueRootsList();
            }
            return null;
        }
        if ($method === 'tools/list') {
            return $this->result($id, ['tools' => ToolDefinitions::all()]);
        }
        if ($method === 'tools/call') {
            return $this->handleToolCall($id, $decoded);
        }
        // This server exposes tools only. Answer the other standard list
        // methods with empty results instead of -32601: clients that probe
        // all capabilities in parallel treat an error reply as a failed
        // listing and then report "0 tools".
        if ($method === 'prompts/list') {
            return $this->result($id, ['prompts' => []]);
        }
        if ($method === 'resources/list') {
            return $this->result($id, ['resources' => []]);
        }
        if ($method === 'resources/templates/list') {
            return $this->result($id, ['resourceTemplates' => []]);
        }
        if ($method === 'logging/setLevel') {
            return $this->result($id, (object)[]);
        }

        if (!$hasId) {
            return null;
        }
        return $this->error($id, -32601, 'Method not found: ' . $method);
    }

    private function handleToolCall(int|string|float|null $id, array $request): string
    {
        $params = isset($request['params']) && is_array($request['params']) ? $request['params'] : [];
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        $known = ToolDefinitions::names();
        if (!in_array($name, $known, true)) {
            return $this->error($id, -32602, 'Unknown tool: ' . $name);
        }

        try {
            $text = $this->dispatchTool($name, $arguments);
        } catch (Throwable $error) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Tool error: ' . $error->getMessage()]],
                'isError' => true,
            ]);
        }
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $text]],
        ]);
    }

    private function dispatchTool(string $name, array $arguments): string
    {
        if ($name === 'check_code') {
            return CompilerOracle::check(self::argString($arguments, 'path', ''), $this->root);
        }
        if ($name === 'list_restrictions') {
            return Restrictions::render(self::argString($arguments, 'topic', ''));
        }
        if ($name === 'list_topics') {
            return $this->docs()->listTopics();
        }
        if ($name === 'search_docs') {
            return $this->docs()->search(
                self::argString($arguments, 'query', ''),
                self::argString($arguments, 'lang', 'zh-cn'),
                self::argInt($arguments, 'limit', 5)
            );
        }
        if ($name === 'get_changelog') {
            return $this->docs()->changelog(self::argString($arguments, 'version', ''));
        }
        if ($name === 'search_code') {
            return $this->code()->searchCode(
                self::argString($arguments, 'query', ''),
                self::argInt($arguments, 'limit', 15),
                self::argArray($arguments, 'dirs')
            );
        }
        if ($name === 'get_symbol') {
            return $this->code()->getSymbol(
                self::argString($arguments, 'name', ''),
                self::argArray($arguments, 'dirs')
            );
        }
        if ($name === 'get_example') {
            return $this->code()->getExample(
                self::argString($arguments, 'name', ''),
                self::argArray($arguments, 'dirs')
            );
        }
        if ($name === 'get_stub_api') {
            return $this->code()->getStubApi(
                self::argString($arguments, 'path', ''),
                self::argArray($arguments, 'dirs')
            );
        }
        if ($name === 'list_stdlib') {
            return $this->code()->listStdlib(self::argString($arguments, 'category', ''));
        }
        if ($name === 'get_build_config_help') {
            return Restrictions::render('build') . "\n\n" . Restrictions::render('stub');
        }
        return $this->renderIndexInfo();
    }

    private function renderIndexInfo(): string
    {
        return IndexInfo::render($this->root, ToolDefinitions::names(), $this->autoDirs);
    }

    private function docs(): DocIndex
    {
        if ($this->docIndex === null) {
            $this->docIndex = new DocIndex($this->root);
        }
        return $this->docIndex;
    }

    private function code(): CodeIndex
    {
        if ($this->codeIndex === null) {
            $this->codeIndex = new CodeIndex($this->root);
        }
        $this->codeIndex->setAutoDirs($this->autoDirs);
        return $this->codeIndex;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private static function argString(array $arguments, string $key, string $default): string
    {
        if (!array_key_exists($key, $arguments) || !is_string($arguments[$key])) {
            return $default;
        }
        return (string) $arguments[$key];
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private static function argInt(array $arguments, string $key, int $default): int
    {
        if (!array_key_exists($key, $arguments) || !is_int($arguments[$key])) {
            return $default;
        }
        return (int) $arguments[$key];
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int, mixed>
     */
    private static function argArray(array $arguments, string $key): array
    {
        if (!array_key_exists($key, $arguments) || !is_array($arguments[$key])) {
            return [];
        }
        return $arguments[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function result(int|string|float|null $id, array|object $result): string
    {
        return $this->encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(int|string|float|null $id, int $code, string $message): string
    {
        return $this->encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => [
            'code' => $code,
            'message' => $message,
        ]]);
    }

    private function enqueueRootsList(): void
    {
        $id = $this->nextRequestId++;
        $this->rootsRequestId = $id;
        $this->outbound[] = $this->encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'roots/list',
            'params' => (object)[],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function consumeRootsResponse(array $result): void
    {
        $roots = isset($result['roots']) && is_array($result['roots']) ? $result['roots'] : [];
        foreach ($roots as $root) {
            if (!is_array($root)) {
                continue;
            }
            $uri = isset($root['uri']) && is_string($root['uri']) ? $root['uri'] : '';
            if ($uri === '') {
                continue;
            }
            $path = ProjectDetector::uriToPath($uri);
            if (ProjectDetector::isTypePhpProject($path)) {
                foreach (ProjectDetector::searchDirs($path) as $dir) {
                    $this->addAutoDir($dir);
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function drainOutbound(): array
    {
        $out = $this->outbound;
        $this->outbound = [];
        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function getAutoDirs(): array
    {
        return $this->autoDirs;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error: json_encode failed"}}';
        }
        return $json;
    }
}
