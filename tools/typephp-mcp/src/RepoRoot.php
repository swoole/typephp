<?php

final class RepoRoot
{
    private string $root;

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function detect(): self
    {
        $override = getenv('TYPEPHP_MCP_ROOT');
        if (is_string($override) && $override !== '' && self::looksLikeRoot($override)) {
            return new self(self::normalize($override));
        }

        $seeds = [];
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $seeds[] = $cwd;
        }
        $seeds[] = TYPEPHP_MCP_DEFAULT_ROOT;

        foreach ($seeds as $seed) {
            $dir = self::normalize($seed);
            for ($depth = 0; $depth < 8; $depth++) {
                if (self::looksLikeRoot($dir)) {
                    return new self($dir);
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        throw new RuntimeException(
            'TypePHP repository root not found. Set TYPEPHP_MCP_ROOT to the aot-compiler checkout.'
        );
    }

    public static function looksLikeRoot(string $dir): bool
    {
        return is_dir($dir . '/docs') && is_file($dir . '/bin/tpc.php');
    }

    private static function normalize(string $dir): string
    {
        $real = realpath($dir);
        return is_string($real) && $real !== '' ? $real : $dir;
    }

    public function path(): string
    {
        return $this->root;
    }

    public function docsPath(string $lang): string
    {
        return $this->root . '/docs/' . $lang;
    }

    public function examplesPath(): string
    {
        return $this->root . '/examples';
    }

    public function stdlibTestsPath(): string
    {
        return $this->root . '/tests/std';
    }

    public function srcPath(): string
    {
        return $this->root . '/src';
    }

    /**
     * Resolve a repo-relative path, rejecting anything that escapes the root.
     */
    public function resolveInside(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $candidate = $this->root . '/' . ltrim($relative, '/');
        $real = realpath($candidate);
        if (!is_string($real) || $real === '') {
            return null;
        }
        if (!str_starts_with($real, $this->root)) {
            return null;
        }
        return $real;
    }

    public function relativeFrom(string $absolute): string
    {
        $prefix = $this->root . '/';
        if (str_starts_with($absolute, $prefix)) {
            return substr($absolute, strlen($prefix));
        }
        return $absolute;
    }
}
