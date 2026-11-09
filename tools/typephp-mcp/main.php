<?php

/**
 * typephp-mcp entry point: newline-delimited JSON-RPC 2.0 over stdio.
 *
 * Wire this binary into an MCP client (Finch/Claude Code) as a stdio server.
 * Environment:
 *   TYPEPHP_MCP_ROOT  repository root (default: walk up from cwd, then build-time default)
 *   TYPEPHP_MCP_PHP   php executable used by check_code/get_index_info
 *                     (default: `php` from PATH)
 *   TYPEPHP_MCP_PROJECT_ROOT  optional TypePHP project path(s) (separate multiple
 *                     with ; - POSIX also accepts :) to auto-merge into the
 *                     default search set when
 *                     the client does not send MCP roots.
 *
 * The server also advertises the MCP roots capability and, once initialized,
 * asks the client for its open workspace, auto-detecting any TypePHP projects
 * (vendor/swoole/typephp, composer.json requiring it, or project.yml) and
 * merging them into the default search set.
 *
 * Note: std streams are opened through php://stdin|stdout|stderr on purpose.
 * The STDIN/STDOUT/STDERR constants are resolved at compile time and can be
 * folded to plain integers by the optimizer in some project shapes, which
 * breaks fwrite()/fgets() with a TypeError. Stream wrappers are always
 * resolved at runtime and match what the TypePHP runtime itself registers.
 */
function main(): int
{
    try {
        $server = new McpServer(RepoRoot::detect());
        $input = fopen('php://stdin', 'r');
        $output = fopen('php://stdout', 'w');
        $errors = fopen('php://stderr', 'w');
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            return 1;
        }

        // Diagnostic (stderr only): report the server's working directory and the
        // auto-detected project dirs so the user can confirm whether the host
        // launches the server with cwd = the open workspace (pure-auto path) or
        // whether the explicit TYPEPHP_MCP_PROJECT_ROOT env is doing the work.
        $cwd = getcwd();
        $cwdNormalized = is_string($cwd) ? str_replace('\\', '/', $cwd) : '';
        $cwdIsProject = $cwdNormalized !== '' && ProjectDetector::isTypePhpProject($cwdNormalized);
        fwrite($errors, sprintf(
            "[typephp-mcp] startup cwd=%s cwd_is_typephp_project=%s autoDirs=%s\n",
            $cwdNormalized ?: '(none)',
            $cwdIsProject ? 'yes' : 'no',
            implode(',', $server->getAutoDirs())
        ));
        fflush($errors);
        while (true) {
            $line = fgets($input);
            if ($line === false) {
                break;
            }
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $response = $server->handleLine($trimmed);
            if ($response !== null) {
                fwrite($output, $response . "\n");
                fflush($output);
            }
            // Flush any requests the server wants to send to the client
            // (e.g. roots/list), so the client can answer them next.
            foreach ($server->drainOutbound() as $out) {
                fwrite($output, $out . "\n");
                fflush($output);
            }
        }
        return 0;
    } catch (Throwable $error) {
        $errors = fopen('php://stderr', 'w');
        if (is_resource($errors)) {
            fwrite($errors, 'typephp-mcp: ' . $error->getMessage() . PHP_EOL);
        }
        return 1;
    }
}
