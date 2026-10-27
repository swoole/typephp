<?php

/**
 * Detects whether a directory is a TypePHP project and derives the search roots
 * the code tools should scan for it.
 *
 * A TypePHP project is recognized by any of:
 *   - a Composer-installed framework at vendor/swoole/typephp
 *   - a composer.json that requires swoole/typephp
 *   - a project.yml (the TypePHP build manifest)
 *
 * This lets the server auto-merge the user's own project (plus its installed
 * framework package) into the default search set, so the tools work on the
 * IDE's open workspace without per-call dirs.
 */
final class ProjectDetector
{
    /**
     * @return array<int, string> Normalized (forward-slash) absolute search
     *   roots: the project root, plus vendor/swoole/typephp when present.
     */
    public static function searchDirs(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $dirs = [$dir];
        $framework = $dir . '/vendor/swoole/typephp';
        if (is_dir($framework)) {
            $dirs[] = $framework;
        }
        return $dirs;
    }

    public static function isTypePhpProject(string $dir): bool
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        if (is_dir($dir . '/vendor/swoole/typephp')) {
            return true;
        }
        $composer = $dir . '/composer.json';
        if (is_file($composer)) {
            $content = file_get_contents($composer);
            if (is_string($content) && str_contains($content, 'swoole/typephp')) {
                return true;
            }
        }
        if (is_file($dir . '/project.yml')) {
            return true;
        }
        return false;
    }

    /**
     * Convert an MCP root URI (file://...) into a normalized absolute path.
     */
    public static function uriToPath(string $uri): string
    {
        $uri = trim($uri);
        $unc = preg_match('#^file://(?!localhost/|/)([^/]+)#', $uri, $matches) === 1;
        $path = preg_replace('#^file://(?:localhost)?#', '', $uri);
        if (!is_string($path)) {
            $path = $uri;
        }
        // Decode before looking for a drive letter: a client may percent-encode
        // the colon ("file:///d%3A/git/proj"), and testing the encoded form
        // left the leading slash in place.
        $path = rawurldecode($path);
        // file:///D:/x  ->  /D:/x  ->  D:/x
        if (str_starts_with($path, '/') && preg_match('#^/[A-Za-z]:#', $path) === 1) {
            $path = substr($path, 1);
        }
        $path = str_replace('\\', '/', $path);
        // file://server/share names a UNC share; keep it in UNC form instead
        // of a relative-looking "server/share".
        if ($unc && !str_starts_with($path, '//')) {
            $path = '//' . ltrim($path, '/');
        }
        return $path;
    }
}
