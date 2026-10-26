<?php

/**
 * Standalone CI driver for the typephp-mcp native binary.
 *
 * Builds are useless if the artifact cannot serve a real stdio session, so
 * every platform CI stage builds the binary first and then runs this script
 * against it. The script fails (exit 1) when the binary is missing or any
 * wire check fails - a stage that skips the test when the artifact is absent
 * would not prove native compatibility.
 *
 * Usage:
 *   php tools/typephp-mcp/tests/mcp-binary-smoke.php [binary] [repoRoot] [cwd]
 *
 *   binary     absolute path to typephp_mcp(.exe); defaults to
 *              TYPEPHP_MCP_BINARY or the platform-default location next to
 *              this script's parent directory.
 *   repoRoot   TYPEPHP_MCP_ROOT for the child; defaults to the environment
 *              or the repository root derived from this script's location.
 *   cwd        working directory for the child; defaults to repoRoot. Pass a
 *              temporary directory to prove the server does not depend on
 *              the dev machine's checkout location.
 *
 * --interpreted runs the same checks against the interpreted entry
 * (run-interpreted.php) so interpreted and AOT results can be compared.
 */

$options = array_slice($argv, 1);
$interpreted = in_array('--interpreted', $options, true);
$options = array_values(array_filter($options, static fn(string $option): bool => $option !== '--interpreted'));

$scriptDir = __DIR__;
$toolDir = dirname($scriptDir);

$binary = '';
if (!$interpreted) {
    if (isset($options[0]) && $options[0] !== '') {
        $binary = $options[0];
    } else {
        $override = getenv('TYPEPHP_MCP_BINARY');
        if (is_string($override) && $override !== '') {
            $binary = $override;
        }
    }
}

$repoRoot = $options[$interpreted ? 0 : 1] ?? (getenv('TYPEPHP_MCP_ROOT') ?: dirname($toolDir, 2));
$repoRoot = str_replace('\\', '/', (string) $repoRoot);
$cwd = isset($options[$interpreted ? 1 : 2]) && $options[$interpreted ? 1 : 2] !== '' ? $options[$interpreted ? 1 : 2] : null;

require_once $scriptDir . '/McpBinaryDriver.php';

if ($interpreted) {
    $php = getenv('TYPEPHP_MCP_PHP');
    if (!is_string($php) || $php === '' || !is_file($php)) {
        $php = 'php';
    }
    $command = [$php, $toolDir . '/run-interpreted.php'];
    $label = 'interpreted (php run-interpreted.php)';
} else {
    if ($binary === '' || !is_file($binary)) {
        fwrite(STDERR, "[smoke] ERROR: typephp_mcp binary not found (looked at: " . var_export($binary, true) . ").\n"
            . "[smoke] Build it first: tools/typephp-mcp/build.sh (Unix) or build.bat (Windows).\n");
        exit(1);
    }
    $command = [$binary];
    $label = basename($binary);
}

fwrite(STDOUT, "[smoke] target: $label\n[smoke] repoRoot: $repoRoot\n[smoke] cwd: " . ($cwd ?? '(repo root)') . "\n");

$checks = McpBinaryDriver::run($command, $repoRoot, $cwd);
$failures = 0;
foreach ($checks as $check) {
    printf("[smoke] %s %s - %s\n", $check['ok'] ? 'PASS' : 'FAIL', $check['name'], $check['detail']);
    // Flush per line so a stage that wedges still shows how far it got.
    fflush(STDOUT);
    if (!$check['ok']) {
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "[smoke] $failures check(s) failed\n");
    exit(1);
}
fwrite(STDOUT, "[smoke] all checks passed\n");
exit(0);
