<?php

final class IndexInfo
{
    /**
     * @param array<int, string> $toolNames
     * @param array<int, string> $projectDirs
     */
    public static function render(RepoRoot $root, array $toolNames, array $projectDirs = []): string
    {
        $lines = [];
        $lines[] = 'repo_root: ' . $root->path();
        $lines[] = 'server: typephp-mcp ' . ToolDefinitions::SERVER_VERSION . ' (TypePHP AOT native binary)';
        $lines[] = 'protocol: ' . McpServer::PROTOCOL_VERSION;
        $lines[] = 'tpc_version: ' . self::tpcVersion($root);
        $lines[] = 'git_commit: ' . self::gitCommit($root);
        $lines[] = 'docs: ' . self::countDocs($root, 'zh-cn') . ' zh-cn + '
            . self::countDocs($root, 'en') . ' en markdown files';
        $lines[] = 'tools (' . count($toolNames) . '): ' . implode(', ', $toolNames);
        $cwd = getcwd();
        $cwdNorm = is_string($cwd) ? str_replace('\\', '/', $cwd) : '';
        $lines[] = 'server_cwd: ' . ($cwdNorm ?: '(none)');
        if ($cwdNorm !== '') {
            $lines[] = 'server_cwd_is_typephp_project: ' . (ProjectDetector::isTypePhpProject($cwdNorm) ? 'yes' : 'no');
        }
        if ($projectDirs === []) {
            $lines[] = 'project_dirs: (none auto-detected; set TYPEPHP_MCP_PROJECT_ROOT or open a TypePHP project)';
        } else {
            $lines[] = 'project_dirs (' . count($projectDirs) . '): ' . implode(', ', $projectDirs);
        }
        $lines[] = 'staleness: no prebuilt index - docs/examples/src are read live on each call,'
            . ' so results always match this checkout. Rebuild the server after upgrading tpc.';
        return implode("\n", $lines);
    }

    private static function tpcVersion(RepoRoot $root): string
    {
        $php = CompilerOracle::phpBinary();
        if ($php === '') {
            return 'unknown (no php on PATH; set TYPEPHP_MCP_PHP)';
        }
        $output = [];
        $code = -1;
        exec(escapeshellarg($php) . ' ' . escapeshellarg($root->path() . '/bin/tpc.php')
            . ' --version --no-color 2>&1', $output, $code);
        if ($code !== 0) {
            return 'unknown';
        }
        foreach ($output as $line) {
            if (is_string($line) && preg_match('/v\d+\.\d+\.\d+/', $line, $m) === 1) {
                return $m[0];
            }
        }
        return 'unknown';
    }

    private static function gitCommit(RepoRoot $root): string
    {
        $output = [];
        $code = -1;
        exec('git -C ' . escapeshellarg($root->path()) . ' rev-parse --short HEAD 2>&1', $output, $code);
        if ($code !== 0 || count($output) === 0) {
            return 'unknown';
        }
        $commit = trim((string) $output[0]);
        if ($commit === '' || str_starts_with($commit, 'fatal')) {
            return 'unknown';
        }
        return $commit;
    }

    private static function countDocs(RepoRoot $root, string $lang): int
    {
        $dir = $root->docsPath($lang);
        if (!is_dir($dir)) {
            return 0;
        }
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return 0;
        }
        $count = 0;
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.md')) {
                $count++;
            }
        }
        return $count;
    }
}
