<?php

namespace TypePhp\Platform;

/**
 * macOS 平台实现
 */
class Macos extends UnixPlatform
{
    private const array HOMEBREW_INCLUDE_PATHS = [
        '/opt/homebrew/include',
        '/usr/local/include',
    ];

    private const array HOMEBREW_LIBRARY_PATHS = [
        '/opt/homebrew/lib',
        '/usr/local/lib',
    ];

    public function getName(): string
    {
        return 'macOS';
    }

    public function isCurrent(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN';
    }

    public function getSharedLibraryExtension(): string
    {
        return '.dylib';
    }

    public function getDefaultCompiler(): string
    {
        return 'clang++';
    }

    /**
     * 获取共享库链接选项
     */
    public function getSharedLinkFlag(): string
    {
        return '-dynamiclib';
    }

    /**
     * 获取当前安装名称选项
     */
    public function getCurrentInstallNameOption(string $path): string
    {
        return '-install_name ' . escapeshellarg($path);
    }

    /**
     * Add the standard Apple Silicon and Intel Homebrew prefixes after the
     * selected PHP installation's include paths.
     */
    public function buildPhpIncludePaths(string $phpDir): array
    {
        return array_values(array_unique(array_merge(
            parent::buildPhpIncludePaths($phpDir),
            self::HOMEBREW_INCLUDE_PATHS,
        )));
    }

    /**
     * Add the standard Apple Silicon and Intel Homebrew library prefixes to
     * every native link command.
     */
    public function buildPhpLibPaths(string $phpDir): array
    {
        return array_values(array_unique(array_merge(
            parent::buildPhpLibPaths($phpDir),
            self::HOMEBREW_LIBRARY_PATHS,
        )));
    }
}
