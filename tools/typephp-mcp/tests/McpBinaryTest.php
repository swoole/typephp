<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/TextUtil.php';
require_once __DIR__ . '/../src/RepoRoot.php';
require_once __DIR__ . '/McpBinaryDriver.php';

/**
 * Integration test: drives the compiled typephp_mcp binary over a real stdio
 * session (shared wire checks with the CI smoke driver). Skips when the
 * binary has not been built unless TYPEPHP_MCP_REQUIRE_BINARY=1, which CI
 * sets so a missing artifact fails the stage instead of silently passing.
 */
final class McpBinaryTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    public function testStdioMcpSession(): void
    {
        $binary = self::binaryPath();
        if ($binary === null) {
            if (getenv('TYPEPHP_MCP_REQUIRE_BINARY') === '1') {
                self::fail('typephp_mcp binary missing; CI must build it (tools/typephp-mcp/build.sh or build.bat)');
            }
            self::markTestSkipped('typephp_mcp binary not built; run tools/typephp-mcp/build.sh (Unix) or build.bat (Windows)');
        }

        $checks = McpBinaryDriver::run($binary, self::repoRoot(), null);
        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertTrue($check['ok'], $check['name'] . ': ' . $check['detail']);
        }
    }

    public function testStdioSessionFromOutsideRepo(): void
    {
        // The server must not depend on the dev machine's checkout location:
        // launch it from a temporary directory outside the repository.
        $binary = self::binaryPath();
        if ($binary === null) {
            if (getenv('TYPEPHP_MCP_REQUIRE_BINARY') === '1') {
                self::fail('typephp_mcp binary missing; CI must build it (tools/typephp-mcp/build.sh or build.bat)');
            }
            self::markTestSkipped('typephp_mcp binary not built; run tools/typephp-mcp/build.sh (Unix) or build.bat (Windows)');
        }

        $dir = sys_get_temp_dir() . '/typephp-mcp-outside-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));
        try {
            $checks = McpBinaryDriver::run($binary, self::repoRoot(), $dir);
            self::assertNotEmpty($checks);
            foreach ($checks as $check) {
                self::assertTrue($check['ok'], $check['name'] . ': ' . $check['detail']);
            }
        } finally {
            self::removeDir($dir);
        }
    }

    public function testInterpretedEntryMatchesAotChecks(): void
    {
        // The same wire checks against the interpreted entry: the sources are
        // kept in the stock-PHP/TypePHP intersection, so both runtimes must
        // answer identically.
        $php = getenv('TYPEPHP_MCP_PHP');
        if (!is_string($php) || $php === '' || !is_file($php)) {
            $php = 'php';
        }
        $command = [$php, dirname(__DIR__) . '/run-interpreted.php'];
        $checks = McpBinaryDriver::run($command, self::repoRoot(), null);
        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertTrue($check['ok'], $check['name'] . ': ' . $check['detail']);
        }
    }

    public function testSilentServerDeadlineIsEnforced(): void
    {
        // A service that stays alive but never replies must not wedge the
        // driver. A normal session passing proves nothing about the reply
        // deadline - every reply there arrives immediately - so this probe
        // runs a stand-in that holds the pipe open and never writes. On
        // Windows the watchdog must also have terminated the whole tree by
        // the time the read gives up; on POSIX the non-blocking loop ends the
        // read at the deadline and the probe cleans the stub up itself.
        $probe = McpBinaryDriver::runSilentServerProbe(
            [PHP_BINARY, '-r', 'while (true) { usleep(50000); }'],
            1.0
        );
        self::assertFalse($probe['gotReply'], 'a silent server must not produce a reply');
        // reply deadline (1.0) + watchdog margin (2.0) + CI slack.
        self::assertLessThan(4.5, $probe['elapsed'], 'the reply deadline was not enforced');
        if (stripos(PHP_OS, 'WIN') === 0) {
            self::assertTrue($probe['treeKilled'], 'the watchdog must terminate the whole silent tree');
        }
    }

    private static function binaryPath(): ?string
    {
        $override = getenv('TYPEPHP_MCP_BINARY');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }
        $dir = dirname(__DIR__);
        foreach (['typephp_mcp.exe', 'typephp_mcp'] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function repoRoot(): string
    {
        $root = getenv('TYPEPHP_MCP_ROOT');
        if (is_string($root) && $root !== '') {
            return str_replace('\\', '/', $root);
        }
        return str_replace('\\', '/', self::REPO_ROOT);
    }

    private static function removeDir(string $dir): void
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
