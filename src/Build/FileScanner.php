<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

class FileScanner
{
    public const array PHP_EXT = ['php'];

    public const array CPP_EXT = ['cpp', 'cxx', 'cc'];

    public const array C_EXT = ['c'];

    public const array ASM_EXT = ['s', 'S'];

    public const array OBJC_EXT = ['m'];

    public const array OBJCXX_EXT = ['mm'];

    public const array NATIVE_SRC_EXT = ['cpp', 'cxx', 'cc', 'c', 's', 'S', 'm', 'mm'];

    private string $directory;
    private array $excludePatterns;

    public function __construct(string $directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        $this->directory       = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->excludePatterns = [];
    }

    public static function getFileName(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    public static function getFileExt(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    public static function isPhpFile(string $file): bool
    {
        return in_array(self::getFileExt($file), self::PHP_EXT);
    }

    public static function isCppFile(string $file): bool
    {
        return in_array(self::getFileExt($file), self::CPP_EXT);
    }

    public static function isNativeSourceFile(string $file): bool
    {
        return in_array(self::getFileExt($file), self::NATIVE_SRC_EXT);
    }

    public function addExcludePattern(string $pattern): self
    {
        $this->excludePatterns[] = $pattern;

        return $this;
    }

    public function setExcludePatterns(array $patterns): self
    {
        $this->excludePatterns = $patterns;

        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function scan(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator($this->createDirectoryIterator());

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getPathname();
                if (!self::isPhpFile($filePath) && !self::isNativeSourceFile($filePath)) {
                    continue;
                }
                if (!$this->isExcluded($filePath)) {
                    $files[] = $filePath;
                }
            }
        }

        // RecursiveDirectoryIterator follows filesystem directory-entry order,
        // which differs between a fresh checkout and a long-lived worktree.
        // Preparation allocates symbol-cache IDs while visiting this list, so
        // keep both generated code and cache classification deterministic.
        sort($files, SORT_STRING);

        return $this->deduplicateByRealPath($files);
    }

    /**
     * Descend into symlinked directories.
     *
     * RecursiveDirectoryIterator does not follow them unless asked to:
     * RecursiveIteratorIterator calls hasChildren(), whose $allowLinks argument
     * defaults to false. Composer path repositories install a package as a
     * symlink, so without FOLLOW_SYMLINKS a source directory that lives behind
     * one contributes no files at all.
     */
    private function createDirectoryIterator(): \RecursiveIterator
    {
        $iterator = new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
        );

        return new \RecursiveCallbackFilterIterator(
            $iterator,
            fn(\SplFileInfo $entry): bool => !$this->closesLoop($entry),
        );
    }

    /**
     * Whether descending into an entry would repeat the branch that reached it,
     * which is what a link pointing at one of its own ancestors does. Only a
     * link can: every other directory resolves below the one holding it.
     *
     * The branch is the whole test, never a set of everything visited so far.
     * Two links to one directory are two ordinary source paths, and either of
     * them may be the one a project excludes, so which of the two is the same
     * file is decided after exclusions instead, by real path.
     */
    private function closesLoop(\SplFileInfo $entry): bool
    {
        if (!$entry->isLink() || !$entry->isDir()) {
            return false;
        }

        $target = $entry->getRealPath();
        if ($target === false) {
            return false;
        }

        $ancestor = dirname($entry->getPathname());
        while (true) {
            if (realpath($ancestor) === $target) {
                return true;
            }
            if ($ancestor === $this->directory) {
                return false;
            }
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return false;
            }
            $ancestor = $parent;
        }
    }

    /**
     * One file reachable through several links is still one source file, and
     * compiling it twice would define its symbols twice.
     *
     * Exclusions have already run, so a path the project excluded can never be
     * the alias that survives here. The list is sorted, so which alias survives
     * does not depend on directory-entry order.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function deduplicateByRealPath(array $files): array
    {
        $unique = [];
        $seen   = [];

        foreach ($files as $file) {
            $identity = realpath($file) ?: $file;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $unique[]        = $file;
        }

        return $unique;
    }

    private function isExcluded(string $filePath): bool
    {
        $excluded = false;
        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchPattern($pattern, $filePath)) {
                $excluded = true;
                break;
            }
        }

        return $excluded;
    }

    private function matchPattern(string $pattern, string $path): bool
    {
        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
}
