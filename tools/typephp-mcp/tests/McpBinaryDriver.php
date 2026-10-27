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
    /**
     * Per-reply deadline. Must exceed the server's own compiler timeout
     * (TYPEPHP_MCP_COMPILE_TIMEOUT, default 120 s) so a slow check_code is
     * reported as its own result instead of a driver timeout.
     */
    private const REPLY_TIMEOUT_SECONDS = 180.0;

    private const NOTIFICATION_WAIT_SECONDS = 2.0;

    /**
     * Hard ceiling for the whole session. A wedged server is killed instead of
     * hanging the job: CI hung for over half an hour once because a stuck
     * check_code never answered and nothing bounded the total run.
     */
    private const SESSION_BUDGET_SECONDS = 360.0;

    /**
     * Absolute deadline for the current session, enforced by readReply().
     *
     * @var float|null
     */
    private static $deadline = null;

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
        self::$deadline = microtime(true) + self::SESSION_BUDGET_SECONDS;
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

            // 8. A notification (no id) is never answered, and the server
            // stays responsive afterwards. Send the notification and a ping
            // back to back: the next reply must be the ping's (id 8). If the
            // notification were answered, that stray reply (with a null or
            // missing id) would arrive first. Probing this way avoids waiting
            // for a "quiet window", which needs a readable-with-timeout pipe
            // and PHP on Windows cannot provide one.
            self::write($pipes[0], ['jsonrpc' => '2.0', 'method' => 'tools/list']);
            self::write($pipes[0], ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'ping']);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $checks[] = self::check(
                'notification without id is not answered',
                is_array($reply) && ($reply['id'] ?? null) === 8,
                (string) json_encode($reply)
            );
            $checks[] = self::check(
                'server responsive after notification',
                is_array($reply) && array_key_exists('result', $reply),
                (string) json_encode($reply)
            );

            // 9. A float id survives the round trip. Narrowing it to int made
            // the reply unmatchable for the client that sent 1.5.
            self::write($pipes[0], ['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping']);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $checks[] = self::check(
                'float request id is preserved',
                is_array($reply) && ($reply['id'] ?? null) === 1.5,
                (string) json_encode($reply)
            );

            // 10. A batch line is rejected explicitly rather than dropped.
            self::write($pipes[0], [(object) ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping']]);
            $reply = self::readReply($pipes[1], self::REPLY_TIMEOUT_SECONDS);
            $checks[] = self::check(
                'batch request is rejected explicitly',
                is_array($reply) && ($reply['error']['code'] ?? null) === -32600,
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
            if (is_array($status) && $status['running'] && isset($status['pid'])) {
                self::killTree((int) $status['pid']);
            }
            @proc_close($process);
        }
        return $checks;
    }

    /**
     * Terminate the process tree. Windows needs taskkill /T because
     * proc_terminate only signals the direct child, leaving the compiler
     * grandchildren (and their inherited handles) alive.
     */
    private static function killTree(int $pid): void
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $discard = [];
            @exec('taskkill /PID ' . $pid . ' /T /F 2>nul', $discard, $discardCode);
            return;
        }
        @exec('kill -9 ' . $pid . ' 2>/dev/null', $discard, $discardCode);
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
        // Blocking fgets() plus stream_set_timeout() is NOT a timeout: PHP
        // ignores that setting on pipes, so a silent server blocked forever
        // while the declared deadline went unused (verified with a real
        // proc_open pipe: a reply arriving after 1.57 s was still accepted for
        // a 1.0 s deadline).
        //
        // Read non-blocking in a loop against a wall-clock deadline instead.
        // stream_set_blocking() is ignored for pipes on Windows as well, so
        // each attempt is additionally guarded by a short, bounded sleep and
        // the whole wait is capped by both $seconds and the session deadline.
        $deadline = microtime(true) + $seconds;
        if (self::$deadline !== null && self::$deadline < $deadline) {
            $deadline = self::$deadline;
        }
        stream_set_blocking($stream, false);
        $buffer = '';
        $emptyReads = 0;
        while (microtime(true) < $deadline) {
            $chunk = (string) @fread($stream, 8192);
            if ($chunk !== '') {
                $emptyReads = 0;
                $buffer .= $chunk;
                $position = strpos($buffer, "\n");
                if ($position !== false) {
                    $decoded = json_decode(trim(substr($buffer, 0, $position)), true);
                    return is_array($decoded) ? $decoded : null;
                }
                continue;
            }
            // Only trust EOF once reads have been empty for a while: some
            // platforms report it prematurely right after the child starts.
            $emptyReads++;
            if ($emptyReads > 100 && feof($stream)) {
                return null;
            }
            usleep(20000);
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $reply
     * @return array{name: string, ok: bool, detail: string}
     */
    private static function check(string $name, bool $ok, string $detail): array
    {
        // Report progress immediately: if a later step wedges the process,
        // the job log still shows which checks completed and which one hung.
        fwrite(STDERR, sprintf("[driver] %s %s\n", $ok ? 'ok  ' : 'fail', $name));
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
