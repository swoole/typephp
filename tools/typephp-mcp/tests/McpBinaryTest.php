<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration test: drives the compiled typephp_mcp.exe over a real stdio
 * session, including a live per-message flush check (the reply must arrive
 * while stdin is still open). Skips when the binary has not been built.
 */
final class McpBinaryTest extends TestCase
{
    private const EXECUTABLE = __DIR__ . '/../typephp_mcp.exe';

    private const REPO_ROOT = __DIR__ . '/../../..';

    public function testStdioMcpSession(): void
    {
        if (!is_file(self::EXECUTABLE)) {
            self::markTestSkipped('typephp_mcp.exe not built; run tools/typephp-mcp/build.bat');
        }

        $process = proc_open(
            [self::EXECUTABLE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::REPO_ROOT
        );
        self::assertIsResource($process);

        try {
            stream_set_blocking($pipes[0], false);
            $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []];
            self::assertNotFalse(fwrite($pipes[0], (string) json_encode($initialize) . "\n"));

            $reply = $this->readReply($pipes[1], 20.0);
            self::assertNotNull($reply, 'no initialize reply within timeout');
            self::assertSame('typephp-mcp', $reply['result']['serverInfo']['name']);
            self::assertSame('2024-11-05', $reply['result']['protocolVersion']);

            $notification = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
            self::assertNotFalse(fwrite($pipes[0], (string) json_encode($notification) . "\n"));

            $list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
            self::assertNotFalse(fwrite($pipes[0], (string) json_encode($list) . "\n"));
            $reply = $this->readReply($pipes[1], 20.0);
            self::assertNotNull($reply);
            self::assertCount(12, $reply['result']['tools']);

            $call = [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => 'list_restrictions', 'arguments' => ['topic' => 'structure']],
            ];
            self::assertNotFalse(fwrite($pipes[0], (string) json_encode($call) . "\n"));
            $reply = $this->readReply($pipes[1], 20.0);
            self::assertNotNull($reply);
            self::assertStringContainsString('main()', $reply['result']['content'][0]['text']);

            // A CJK-heavy query: the reply must be valid JSON even though the
            // excerpt is cut on a byte budget (UTF-8 boundary safety).
            $call = [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => ['name' => 'search_docs', 'arguments' => ['query' => 'bigInt', 'lang' => 'zh-cn', 'limit' => 3]],
            ];
            self::assertNotFalse(fwrite($pipes[0], (string) json_encode($call) . "\n"));
            $reply = $this->readReply($pipes[1], 20.0);
            self::assertNotNull($reply);
            self::assertSame(JSON_ERROR_NONE, json_last_error());
            self::assertStringContainsString('docs/zh-cn/', $reply['result']['content'][0]['text']);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * Read one newline-delimited JSON reply, waiting at most $seconds. The
     * timeout proves the server flushes each reply instead of buffering until
     * stdin closes (which would deadlock every real MCP client).
     *
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function readReply($stream, float $seconds): ?array
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
}
