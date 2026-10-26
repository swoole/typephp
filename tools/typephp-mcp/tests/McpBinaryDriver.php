<?php

/**
 * Drives a typephp-mcp executable over a real stdio session and checks the
 * wire behavior both MCP clients and the CI stages depend on. Shared by
 * McpBinaryTest (PHPUnit) and mcp-binary-smoke.php (standalone CI driver),
 * so the same checks run against the AOT binary and the interpreted entry.
 *
 * The reply reads use deadlines: a reply must arrive while stdin is still
 * open, which proves the server flushes per message instead of buffering
 * until EOF (a buffering server would deadlock every real MCP client).
 */
final class McpBinaryDriver
{
    private const REPLY_TIMEOUT_SECONDS = 30.0;

    private const NOTIFICATION_WAIT_SECONDS = 2.0;

    /**
     * @param array<int, string>|string $command executable (and arguments)
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    public static function run(array|string $command, string $repoRoot, ?string $cwd = null): array
    {
        // Pass the full environment through, as a real MCP client does: the
        // child needs PATH, the loader paths (LD_LIBRARY_PATH /
        // DYLD_LIBRARY_PATH on CI), TMP/TEMP for its temporary directory,
        // and PHP_HOME/PHPX_HOME for the compiler it spawns. TYPEPHP_MCP_ROOT
        // is overridden so the run is pinned to this repository.
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['TYPEPHP_MCP_ROOT'] = $repoRoot;

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $repoRoot,
            $environment
        );
        if (!is_resource($process)) {
            return [['name' => 'spawn', 'ok' => false, 'detail' => 'cannot start ' . json_encode($command)]];
        }

        $checks = [];
        try {
            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);

            // 1. Handshake with an unsupported protocol version: the server
            //    must answer with a version it actually implements.
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '1900-01-01', 'capabilities' => new stdClass()],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $checks[] = self::check(
                'handshake negotiates protocol version',
                $reply !== null && ($reply['result']['protocolVersion'] ?? '') === '2024-11-05',
                (string) json_encode($reply)
            );

            // 2. tools/list exposes the 12 tools.
            self::write($pipes[0], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $tools = is_array($reply) ? ($reply['result']['tools'] ?? []) : [];
            $checks[] = self::check('tools/list exposes 12 tools', count($tools) === 12, 'got ' . count($tools));

            // 3. A tool call answers while stdin is still open (per-message flush).
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'list_restrictions', 'arguments' => ['topic' => 'structure']],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $text = is_array($reply) ? ($reply['result']['content'][0]['text'] ?? '') : '';
            $checks[] = self::check('tools/call list_restrictions answers', str_contains($text, 'main()'), substr($text, 0, 200));

            // 4. get_symbol finds a symbol in a >256 KiB file (Translator.php).
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
                'params' => ['name' => 'get_symbol', 'arguments' => ['name' => 'genClassPropertyInit']],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $text = is_array($reply) ? ($reply['result']['content'][0]['text'] ?? '') : '';
            $checks[] = self::check(
                'get_symbol scans large files',
                str_contains($text, 'src/Translator.php:') && str_contains($text, 'genClassPropertyInit'),
                substr($text, 0, 200)
            );

            // 5. check_code on a missing file is a tool error (isError: true).
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
                'params' => ['name' => 'check_code', 'arguments' => ['path' => 'no/such/file.php']],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $isError = is_array($reply) && !empty($reply['result']['isError']);
            $checks[] = self::check('check_code missing file is a tool error', $isError, (string) json_encode($reply));

            // 6. check_code on a valid file reports the generate stage.
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
                'params' => ['name' => 'check_code', 'arguments' => ['path' => 'examples/hello.php']],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $text = is_array($reply) ? ($reply['result']['content'][0]['text'] ?? '') : '';
            // Keep enough of the compiler output to see the real failure:
            // a 200-character excerpt hides everything past the first lines.
            $checks[] = self::check('check_code valid file reports stage=generate', str_starts_with($text, 'OK stage=generate'), substr($text, 0, 1200));

            // 7. get_index_info reports the repo root (the tpc+git path).
            self::write($pipes[0], [
                'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
                'params' => ['name' => 'get_index_info', 'arguments' => []],
            ]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $text = is_array($reply) ? ($reply['result']['content'][0]['text'] ?? '') : '';
            $checks[] = self::check('get_index_info reports repo root', str_contains($text, 'repo_root:'), substr($text, 0, 200));

            // 8. A notification (no id) is never answered.
            self::write($pipes[0], ['jsonrpc' => '2.0', 'method' => 'tools/list']);
            $spurious = self::readReply($pipes[1], self::NOTIFICATION_WAIT_SECONDS);
            $checks[] = self::check('notification without id is not answered', $spurious === null, (string) json_encode($spurious));

            // 9. The server stays responsive after the notification (ping).
            self::write($pipes[0], ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'ping']);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $checks[] = self::check(
                'server responsive after notification',
                $reply !== null && array_key_exists('result', $reply),
                (string) json_encode($reply)
            );
        } finally {
            // Close the pipes first, then reap. proc_close() waits for EOF on
            // them, so a server that stopped reading must be terminated rather
            // than waited on - a hung server would otherwise hang CI forever.
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            $status = @proc_get_status($process);
            if (is_array($status) && $status['running']) {
                @proc_terminate($process, 9);
            }
            @proc_close($process);
        }
        return $checks;
    }

    private static function write($stream, array $payload): void
    {
        $line = json_encode($payload) . "\n";
        $written = 0;
        $length = strlen($line);
        while ($written < $length) {
            $chunk = fwrite($stream, substr($line, $written));
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('cannot write to the server stdin');
            }
            $written += $chunk;
        }
    }

    /**
     * Read one newline-delimited JSON reply, waiting at most $seconds.
     *
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private static function readReply($stream, float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $read = [$stream];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                return null;
            }
            if ($ready > 0) {
                $chunk = (string) fread($stream, 8192);
                if ($chunk === '') {
                    return null;
                }
                $buffer .= $chunk;
                $position = strpos($buffer, "\n");
                if ($position !== false) {
                    $line = substr($buffer, 0, $position);
                    $decoded = json_decode($line, true);
                    return is_array($decoded) ? $decoded : null;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $reply
     * @return array{name: string, ok: bool, detail: string}
     */
    private static function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
