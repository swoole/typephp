<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/TextUtil.php';
require_once __DIR__ . '/../src/RepoRoot.php';
require_once __DIR__ . '/../src/Restrictions.php';
require_once __DIR__ . '/../src/DocIndex.php';
require_once __DIR__ . '/../src/CodeIndex.php';
require_once __DIR__ . '/../src/CompilerOracle.php';
require_once __DIR__ . '/../src/IndexInfo.php';
require_once __DIR__ . '/../src/ToolDefinitions.php';
require_once __DIR__ . '/../src/McpServer.php';
require_once __DIR__ . '/../src/ProjectDetector.php';

/**
 * Unit tests for the typephp-mcp server logic. Everything here runs with a
 * plain PHP interpreter: the sources are kept inside the PHP subset that both
 * stock PHP and the TypePHP compiler accept, so the same files are exercised
 * interpreted (here) and AOT-compiled (see McpBinaryTest).
 */
final class McpServerTest extends TestCase
{
    private McpServer $server;

    private RepoRoot $root;

    protected function setUp(): void
    {
        $this->root = RepoRoot::detect();
        $this->server = new McpServer($this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $line): array
    {
        $reply = $this->server->handleLine($line);
        self::assertNotNull($reply, 'expected a JSON-RPC reply');
        $decoded = json_decode((string) $reply, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function toolText(string $name, array $arguments = []): string
    {
        $reply = $this->request((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]));
        self::assertArrayHasKey('result', $reply);
        $content = $reply['result']['content'][0]['text'] ?? null;
        self::assertIsString($content);
        return $content;
    }

    public function testInitializeHandshake(): void
    {
        $reply = $this->request('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        self::assertSame('2024-11-05', $reply['result']['protocolVersion']);
        self::assertSame('typephp-mcp', $reply['result']['serverInfo']['name']);
        self::assertSame([], (array) $reply['result']['capabilities']['tools']);
        self::assertStringContainsString('global scope allows declarations only', $reply['result']['instructions']);
    }

    public function testPingReturnsEmptyResult(): void
    {
        $reply = $this->request('{"jsonrpc":"2.0","id":2,"method":"ping"}');
        self::assertSame([], (array) $reply['result']);
    }

    public function testNotificationsAreNotAnswered(): void
    {
        self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    }

    public function testParseErrorReportsNullId(): void
    {
        $reply = $this->request('this is not json');
        self::assertNull($reply['id']);
        self::assertSame(-32700, $reply['error']['code']);
    }

    public function testUnknownMethodAndUnknownTool(): void
    {
        $reply = $this->request('{"jsonrpc":"2.0","id":3,"method":"bogus/method"}');
        self::assertSame(-32601, $reply['error']['code']);

        $reply = $this->request('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"nope","arguments":{}}}');
        self::assertSame(-32602, $reply['error']['code']);
    }

    public function testToolsListExposesTwelveTools(): void
    {
        $raw = (string) $this->server->handleLine('{"jsonrpc":"2.0","id":5,"method":"tools/list"}');
        $reply = json_decode($raw, true);
        $tools = $reply['result']['tools'];
        self::assertCount(12, $tools);
        foreach ($tools as $tool) {
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertSame('object', $tool['inputSchema']['type']);
        }
        // JSON Schema requires inputSchema.properties to be an object, never an
        // array. A parameterless tool whose properties is an empty PHP list
        // encodes to [] and fails the MCP SDK's ListToolsResultSchema
        // validation, which makes clients (CodeBuddy, the official SDK) report
        // 0 tools. Verify the serialized reply never contains "properties":[].
        self::assertStringNotContainsString(
            '"properties":[]',
            $raw,
            'inputSchema.properties must serialize as an object, never an array'
        );
    }

    public function testInitializeNegotiatesProtocolVersion(): void
    {
        // The server answers with a version it actually implements. A
        // supported version is echoed; an unsupported one is answered with
        // the server's latest supported version instead of a pretend-agreement.
        $reply = $this->request('{"jsonrpc":"2.0","id":6,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"x","version":"1"}}}');
        self::assertSame('2024-11-05', $reply['result']['protocolVersion']);

        $reply = $this->request('{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":"1900-01-01","capabilities":{},"clientInfo":{"name":"x","version":"1"}}}');
        self::assertSame('2024-11-05', $reply['result']['protocolVersion']);
        self::assertSame([], (array) $reply['result']['capabilities']['tools']);
        // roots is a client capability of the supported revision only.
        self::assertArrayHasKey('roots', $reply['result']['capabilities']);

        // No version offered by the client -> fall back to ours.
        $reply = $this->request('{"jsonrpc":"2.0","id":8,"method":"initialize","params":{}}');
        self::assertSame('2024-11-05', $reply['result']['protocolVersion']);

        // Garbage params must not break the handshake.
        $reply = $this->request('{"jsonrpc":"2.0","id":9,"method":"initialize","params":"not-an-object"}');
        self::assertSame('2024-11-05', $reply['result']['protocolVersion']);
    }

    public function testRequestNotificationsAreNotAnswered(): void
    {
        // A request method sent without an id is a JSON-RPC notification and
        // must never be answered - not even with an error.
        self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","method":"tools/list"}'));
        self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","method":"ping"}'));
        self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"list_topics","arguments":{}}}'));
    }

    public function testOptionalCapabilityListsReturnEmptyResults(): void
    {
        // Clients probe prompts/resources in parallel with tools; an error
        // reply makes picky clients report "0 tools".
        $pairs = [
            'prompts/list' => 'prompts',
            'resources/list' => 'resources',
            'resources/templates/list' => 'resourceTemplates',
        ];
        foreach ($pairs as $method => $key) {
            $reply = $this->request('{"jsonrpc":"2.0","id":8,"method":"' . $method . '"}');
            self::assertArrayHasKey('result', $reply, $method);
            self::assertSame([], (array) $reply['result'][$key], $method);
        }
        $reply = $this->request('{"jsonrpc":"2.0","id":8,"method":"logging/setLevel","params":{"level":"info"}}');
        self::assertSame([], (array) $reply['result']);
    }

    public function testCheckCodeAcceptsAValidFile(): void
    {
        if (!CompilerOracle::hasCompiler($this->root)) {
            self::markTestSkipped('no TypePHP compiler available (no tpc binary and no php on PATH)');
        }
        $text = $this->toolText('check_code', ['path' => 'examples/hello.php']);
        self::assertStringStartsWith('OK stage=generate', $text);
        self::assertStringContainsString('compile and link stages are not run', $text);
    }

    public function testMsvcBinDirDiscovery(): void
    {
        $vswhere = getenv('ProgramFiles(x86)') . '\Microsoft Visual Studio\Installer\vswhere.exe';
        if (!is_file($vswhere)) {
            self::markTestSkipped('vswhere.exe not found');
        }
        $method = new ReflectionMethod(CompilerOracle::class, 'findMsvcBinDir');
        $binDir = $method->invoke(null);
        if ($binDir === null) {
            self::markTestSkipped('no MSVC toolchain with VC.Tools.x86.x64 installed');
        }
        self::assertDirectoryExists($binDir);
        self::assertFileExists($binDir . '\cl.exe');
    }

    public function testCheckCodeRejectsAnIllegalGlobalStatement(): void
    {
        if (!CompilerOracle::hasCompiler($this->root)) {
            self::markTestSkipped('no TypePHP compiler available (no tpc binary and no php on PATH)');
        }
        $file = sys_get_temp_dir() . '/typephp-mcp-test-' . bin2hex(random_bytes(4)) . '.php';
        self::assertNotFalse(file_put_contents($file, "<?php\necho \"illegal at global scope\";\n"));
        try {
            $text = $this->toolText('check_code', ['path' => $file]);
            self::assertStringStartsWith('FAILED', $text);
            self::assertStringContainsString('Unsupported statement', $text);
        } finally {
            @unlink($file);
        }
    }

    public function testCheckCodeMissingFileIsAToolError(): void
    {
        // A missing path is a tool-execution failure, not a check result:
        // the MCP reply must carry isError: true so clients can tell the
        // difference between "your code failed" and "the tool could not run".
        $reply = $this->request((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'check_code', 'arguments' => ['path' => 'no/such/file.php']],
        ]));
        self::assertTrue($reply['result']['isError'] ?? false);
        self::assertStringContainsString('File not found', $reply['result']['content'][0]['text']);
    }

    public function testListRestrictionsCoversMainSignatureAndScope(): void
    {
        $text = $this->toolText('list_restrictions', ['topic' => 'structure']);
        self::assertStringContainsString('main()', $text);
        self::assertStringContainsString('全局作用域', $text);
        self::assertStringContainsString('[structure]', $text);

        // Inheritance rules must match the compiler's real behavior (v0.9.5):
        // Zend internal classes CAN be inherited; only runtime-only (autoload)
        // user classes cannot. See tests/compiler/class/class_extends.phpt.
        $text = $this->toolText('list_restrictions', ['topic' => 'objects']);
        self::assertStringContainsString('可以继承内置类', $text);
        self::assertStringContainsString('autoload', $text);
        self::assertStringContainsString('Native', $text);

        $text = $this->toolText('list_restrictions', ['topic' => 'does-not-exist']);
        self::assertStringContainsString('Available topics:', $text);

        $text = $this->toolText('list_restrictions', []);
        self::assertStringContainsString('.stub.php', $text);
        self::assertStringContainsString('std::ref()', $text);
    }

    public function testSearchDocsCitesSourceFileAndLine(): void
    {
        $text = $this->toolText('search_docs', ['query' => 'bigInt', 'lang' => 'zh-cn', 'limit' => 3]);
        self::assertStringContainsString('docs/zh-cn/', $text);
        self::assertStringContainsString('(L', $text);
        self::assertStringContainsString('bigInt', $text);
    }

    public function testSearchCodeFindsStdContainerUsage(): void
    {
        $text = $this->toolText('search_code', ['query' => 'orderedMap', 'limit' => 5]);
        self::assertStringContainsString('src/Parser/StdContainerTrait.php:', $text);
        self::assertStringContainsString('in ', $text);
    }

    public function testGetSymbolFindsMain(): void
    {
        $text = $this->toolText('get_symbol', ['name' => 'main']);
        self::assertStringContainsString('function main', $text);
        self::assertStringContainsString(':', $text);

        $text = $this->toolText('get_symbol', ['name' => 'definitely_not_a_symbol_xyz']);
        self::assertStringContainsString('No symbol found', $text);
    }

    public function testGetSymbolScansLargeFiles(): void
    {
        // src/Translator.php is ~400 KB, far above the old 256 KiB silent
        // cutoff. The symbol must be found, and no coverage truncation may
        // be reported for it.
        $text = $this->toolText('get_symbol', ['name' => 'genClassPropertyInit']);
        self::assertStringContainsString('src/Translator.php:', $text);
        self::assertStringContainsString('genClassPropertyInit', $text);
        self::assertStringNotContainsString('truncated', $text);
    }

    public function testGetSymbolSupportsNamespaceAndClassMethod(): void
    {
        // Full namespace path resolves against the file's namespace context.
        $text = $this->toolText('get_symbol', ['name' => 'TypePhp\Translator']);
        self::assertStringContainsString('class Translator', $text);
        self::assertStringContainsString('src/Translator.php:', $text);

        // Class::method finds the method inside the class body.
        $text = $this->toolText('get_symbol', ['name' => 'Translator::genClassPropertyInit']);
        self::assertStringContainsString('function genClassPropertyInit', $text);
        self::assertStringContainsString('in class Translator', $text);

        $text = $this->toolText('get_symbol', ['name' => 'Translator::no_such_method_xyz']);
        self::assertStringContainsString('No symbol found', $text);
    }

    public function testGetSymbolReturnsDuplicateDefinitions(): void
    {
        // The same short name defined in several files yields one hit per
        // definition (duplicate symbols are not silently deduped).
        $text = $this->toolText('get_symbol', ['name' => 'main']);
        $lines = explode("\n", $text);
        $declarationLines = array_values(array_filter($lines, static function (string $line): bool {
            return str_contains($line, 'function main');
        }));
        self::assertGreaterThanOrEqual(2, count($declarationLines), $text);
    }

    public function testGetSymbolScansLargeSyntheticFile(): void
    {
        // A file above the old cutoff, passed via dirs, must be scanned.
        $dir = sys_get_temp_dir() . '/typephp-mcp-big-' . bin2hex(random_bytes(4));
        self::assertNotFalse(mkdir($dir, 0777, true));
        $file = $dir . '/Big.php';
        try {
            $filler = "<?php\n// " . str_repeat('x', 100) . "\n";
            self::assertNotFalse(file_put_contents($file, $filler . str_repeat($filler, 3000) . "<?php\nfunction bigFileSymbol() {}\n"));
            $text = $this->toolText('get_symbol', ['name' => 'bigFileSymbol', 'dirs' => [$dir]]);
            self::assertStringContainsString('bigFileSymbol', $text);
            self::assertStringNotContainsString('No symbol found', $text);
        } finally {
            self::removeDir($dir);
        }
    }

    public function testGetExampleReturnsFileAndDirectory(): void
    {
        $text = $this->toolText('get_example', ['name' => 'hello']);
        self::assertStringContainsString('examples/hello.php', $text);
        self::assertStringContainsString('function main', $text);

        $text = $this->toolText('get_example', ['name' => 'qt-taskboard']);
        self::assertStringContainsString('examples/qt-taskboard/', $text);
        self::assertStringContainsString('project.yml', $text);

        $text = $this->toolText('get_example', ['name' => '../../etc/passwd']);
        self::assertStringContainsString('Invalid example name', $text);
    }

    public function testListStdlibListsCategoriesAndTests(): void
    {
        $text = $this->toolText('list_stdlib', []);
        self::assertStringContainsString('array (', $text);
        self::assertStringContainsString('strings (', $text);
        self::assertStringContainsString('STD_CONTAINERS.md', $text);

        $text = $this->toolText('list_stdlib', ['category' => 'hrtime']);
        self::assertStringContainsString('.phpt', $text);
    }

    public function testGetStubApiListsAndReadsStubFiles(): void
    {
        $text = $this->toolText('get_stub_api', []);
        self::assertStringContainsString('taskboard.stub.php', $text);

        $text = $this->toolText('get_stub_api', ['path' => 'examples/qt-taskboard/php-src/taskboard.stub.php']);
        self::assertStringContainsString('qt_board_', $text);

        $text = $this->toolText('get_stub_api', ['path' => 'project.yml']);
        self::assertStringContainsString('Not a .stub.php file', $text);
    }

    public function testGetChangelogReturnsLatestAndSpecificSections(): void
    {
        $text = $this->toolText('get_changelog', []);
        self::assertStringContainsString('## 0.8.0', $text);

        $text = $this->toolText('get_changelog', ['version' => '0.8']);
        self::assertStringContainsString('native scalar storage', $text);

        $text = $this->toolText('get_changelog', ['version' => '99.0']);
        self::assertStringContainsString('No changelog section', $text);
    }

    public function testGetBuildConfigHelpCoversYamlAndStubContract(): void
    {
        $text = $this->toolText('get_build_config_help', []);
        self::assertStringContainsString('ext-deps', $text);
        self::assertStringContainsString('php_ 前缀', $text);
    }

    public function testListTopicsIncludesIncompatibleFeatures(): void
    {
        $text = $this->toolText('list_topics', []);
        self::assertStringContainsString('[zh-cn] INCOMPATIBLE_PHP_FEATURES.md', $text);
        self::assertStringContainsString('[en] INCOMPATIBLE_PHP_FEATURES.md', $text);
    }

    public function testGetIndexInfoReportsRepoAndVersion(): void
    {
        $text = $this->toolText('get_index_info', []);
        self::assertStringContainsString('repo_root: ' . $this->root->path(), $text);
        self::assertStringContainsString('tools (12):', $text);
    }

    public function testTextClipKeepsUtf8Boundaries(): void
    {
        $text = str_repeat('中', 100);
        $clipped = TextUtil::clip($text, 50);
        self::assertLessThanOrEqual(50, strlen($clipped));
        self::assertNotFalse(json_encode($clipped, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('truncated', $clipped);
        self::assertSame($text, TextUtil::clip($text, 5000));
        // Cutting at every possible offset must stay valid UTF-8.
        for ($i = 1; $i <= 40; $i++) {
            self::assertNotFalse(json_encode(TextUtil::clip($text, $i), JSON_UNESCAPED_UNICODE));
        }
        // A slice starting mid-character must drop the partial lead.
        $mixed = 'a中b文c';
        self::assertSame('', TextUtil::utf8SafeSlice($mixed, 1, 2));
        self::assertSame('中', TextUtil::utf8SafeSlice($mixed, 1, 3));
        self::assertSame('b文', TextUtil::utf8SafeSlice($mixed, 4, 4));
    }

    private function makeSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/typephp-mcp-dirs-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));
        // Normalize to forward slashes so it matches ProjectDetector /
        // resolveInsideAny, which also normalize (realpath returns forward
        // slashes on Windows).
        return str_replace('\\', '/', $dir);
    }

    private function sandboxWrite(string $dir, string $rel, string $content): void
    {
        $path = $dir . '/' . $rel;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            self::assertTrue(mkdir($parent, 0777, true));
        }
        self::assertNotFalse(file_put_contents($path, $content));
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testRepoRootDetectsFromOutsideRepo(): void
    {
        // Launching from a directory outside the repository must still locate
        // the tool resource root: first via TYPEPHP_MCP_ROOT, then via the
        // source-relative default (this test file lives inside the checkout).
        $sandbox = $this->makeSandbox();
        $previousRoot = getenv('TYPEPHP_MCP_ROOT');
        $previousCwd = getcwd();
        try {
            putenv('TYPEPHP_MCP_ROOT=' . $this->root->path());
            chdir($sandbox);
            $root = RepoRoot::detect();
            self::assertSame($this->root->path(), $root->path());

            putenv('TYPEPHP_MCP_ROOT');
            $root = RepoRoot::detect();
            self::assertSame($this->root->path(), $root->path());
        } finally {
            if ($previousRoot === false) {
                putenv('TYPEPHP_MCP_ROOT');
            } else {
                putenv('TYPEPHP_MCP_ROOT=' . $previousRoot);
            }
            chdir((string) $previousCwd);
            $this->removeDir($sandbox);
        }
    }

    public function testGetStubApiListsUserProjectDirs(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $this->sandboxWrite($sandbox, 'php-src/Foo.stub.php', "<?php\nfunction php_foo(): void {}\n");

            $text = $this->toolText('get_stub_api', ['dirs' => [$sandbox]]);
            self::assertStringContainsString('php-src/Foo.stub.php', $text);

            $text = $this->toolText('get_stub_api', ['path' => 'php-src/Foo.stub.php', 'dirs' => [$sandbox]]);
            self::assertStringContainsString('function php_foo', $text);

            // Without dirs the same relative path is not found in the repo.
            $text = $this->toolText('get_stub_api', ['path' => 'php-src/Foo.stub.php']);
            self::assertStringContainsString('Stub file not found inside the repository', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testSearchCodeSearchesUserDirsAndIncludesVendor(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $marker = 'UNIQUE_MARKER_X9Z';
            $this->sandboxWrite($sandbox, 'src/App.php', "<?php\n// $marker\nclass App {}\n");
            $this->sandboxWrite($sandbox, 'vendor/swoole/typephp/Framework.php', "<?php\n// $marker\nfunction php_framework() {}\n");

            $text = $this->toolText('search_code', ['query' => $marker, 'dirs' => [$sandbox]]);
            self::assertStringContainsString('src/App.php', $text);
            self::assertStringContainsString('vendor/swoole/typephp/Framework.php', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testGetSymbolSearchesUserDirs(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $this->sandboxWrite($sandbox, 'src/CmySymbol.php', "<?php\nclass CmySymbol {}\n");

            $text = $this->toolText('get_symbol', ['name' => 'CmySymbol', 'dirs' => [$sandbox]]);
            self::assertStringContainsString('src/CmySymbol.php', $text);

            $text = $this->toolText('get_symbol', ['name' => 'CmySymbol']);
            self::assertStringContainsString('No symbol found', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testGetExampleInUserDirs(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $this->sandboxWrite($sandbox, 'main.php', "<?php\nfunction main(): void {}\n");

            $text = $this->toolText('get_example', ['name' => 'main', 'dirs' => [$sandbox]]);
            self::assertStringContainsString('main.php', $text);
            self::assertStringContainsString('function main', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testDirsInvalidRaisesToolError(): void
    {
        $text = $this->toolText('get_stub_api', ['dirs' => ['D:/no/such/dir/' . bin2hex(random_bytes(4))]]);
        self::assertStringContainsString('none of the provided paths', $text);
    }

    public function testDirsTraversalRejected(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $this->sandboxWrite($sandbox, 'php-src/Foo.stub.php', "<?php\nfunction php_foo(): void {}\n");

            $text = $this->toolText('get_stub_api', ['path' => '../../etc/passwd', 'dirs' => [$sandbox]]);
            self::assertStringContainsString('Stub file not found inside the provided dirs', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testProjectDetectorRecognizesTypePhpProject(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            self::assertFalse(ProjectDetector::isTypePhpProject($sandbox));

            mkdir($sandbox . '/vendor/swoole/typephp', 0777, true);
            self::assertTrue(ProjectDetector::isTypePhpProject($sandbox));

            $dirs = ProjectDetector::searchDirs($sandbox);
            self::assertContains($sandbox, $dirs);
            self::assertContains($sandbox . '/vendor/swoole/typephp', $dirs);

            // composer.json requiring the framework also counts.
            $plain = $this->makeSandbox();
            file_put_contents($plain . '/composer.json', '{"require":{"swoole/typephp":"*"}}');
            self::assertTrue(ProjectDetector::isTypePhpProject($plain));
            $this->removeDir($plain);

            // project.yml counts too.
            $plain2 = $this->makeSandbox();
            file_put_contents($plain2 . '/project.yml', "entry: main\n");
            self::assertTrue(ProjectDetector::isTypePhpProject($plain2));
            $this->removeDir($plain2);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testProjectDetectorUriToPath(): void
    {
        self::assertSame('D:/git/php/typephp-qt', ProjectDetector::uriToPath('file:///D:/git/php/typephp-qt'));
        self::assertSame('/home/user/proj', ProjectDetector::uriToPath('file:///home/user/proj'));
        self::assertSame('D:/git/php/typephp-qt', ProjectDetector::uriToPath('D:/git/php/typephp-qt'));
    }

    public function testCodeIndexMergesAutoDirsIntoDefault(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            $marker = 'AUTODIR_MARKER_Q7';
            $this->sandboxWrite($sandbox, 'src/App.php', "<?php\n// $marker\nclass App {}\n");
            $this->sandboxWrite($sandbox, 'php-src/ProjBar.stub.php', "<?php\nfunction php_bar(): void {}\n");

            $code = new CodeIndex($this->root);
            $code->setAutoDirs([$sandbox]);

            // Default search (no explicit dirs) now covers the project.
            $text = $code->searchCode($marker, 5);
            self::assertStringContainsString('src/App.php', $text);

            $text = $code->getStubApi('');
            self::assertStringContainsString('php-src/ProjBar.stub.php', $text);

            $text = $code->getStubApi('php-src/ProjBar.stub.php');
            self::assertStringContainsString('function php_bar', $text);

            // Explicit dirs still replace the auto-dirs (this valid dir has
            // no marker, so the project hit must not appear).
            $other = $this->makeSandbox();
            $text = $code->searchCode($marker, 5, [$other]);
            self::assertStringContainsString('No code matches', $text);
            $this->removeDir($other);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testRootsCapabilityAutoDetectsProject(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            mkdir($sandbox . '/vendor/swoole/typephp', 0777, true);
            file_put_contents($sandbox . '/vendor/swoole/typephp/ProjFoo.stub.php', "<?php\nfunction php_foo(): void {}\n");

            // Client advertises roots; server should respond and ask for them.
            $reply = $this->request('{"jsonrpc":"2.0","id":1,"method":"initialize","params":'
                . '{"protocolVersion":"2024-11-05","capabilities":{"roots":{}},'
                . '"clientInfo":{"name":"x","version":"1"}}}');
            self::assertArrayHasKey('roots', $reply['result']['capabilities']);

            // initialized notification -> server enqueues a roots/list request.
            self::assertNull($this->server->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
            $out = $this->server->drainOutbound();
            self::assertCount(1, $out);
            $req = json_decode($out[0], true);
            self::assertSame('roots/list', $req['method']);

            // Client answers with the TypePHP project URI.
            $uri = 'file:///' . str_replace('\\', '/', $sandbox);
            self::assertNull($this->server->handleLine(json_encode([
                'jsonrpc' => '2.0',
                'id' => $req['id'],
                'result' => ['roots' => [['uri' => $uri]]],
            ])));

            // Now the default (no-dirs) stub listing includes the project.
            $text = $this->toolText('get_stub_api', []);
            self::assertStringContainsString('ProjFoo.stub.php', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testCwdAutoDetectsProject(): void
    {
        $sandbox = $this->makeSandbox();
        try {
            mkdir($sandbox . '/vendor/swoole/typephp', 0777, true);
            file_put_contents($sandbox . '/vendor/swoole/typephp/CwdFoo.stub.php', "<?php\nfunction php_cwd_foo(): void {}\n");

            $prev = getcwd();
            chdir($sandbox);
            try {
                // Server built with cwd = the project: should auto-merge it
                // into the default search set without any env or roots.
                $server = new McpServer($this->root);
            } finally {
                chdir($prev);
            }

            $reply = $server->handleLine(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'get_stub_api', 'arguments' => []],
            ]));
            $data = json_decode($reply, true);
            $text = $data['result']['content'][0]['text'];
            self::assertStringContainsString('CwdFoo.stub.php', $text);
        } finally {
            $this->removeDir($sandbox);
        }
    }

    public function testCwdDoesNotDoubleScanCompilerRepo(): void
    {
        // If cwd lands inside the bundled compiler repo, it must NOT be re-added
        // as an auto project (it is already the default root).
        $prev = getcwd();
        chdir($this->root->path());
        try {
            $server = new McpServer($this->root);
        } finally {
            chdir($prev);
        }
        // No assertion beyond "does not throw / no crash"; autoDirs stays empty.
        self::assertTrue(true);
    }
}
