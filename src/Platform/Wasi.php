<?php

namespace TypePhp\Platform;

final class Wasi extends UnixPlatform
{
    public function __construct(private readonly string $target = 'wasm32-unknown-wasip2')
    {
    }

    public function getName(): string
    {
        return "WASI SDK ({$this->target})";
    }

    public function isCurrent(): bool
    {
        return false;
    }

    public function getSharedLibraryExtension(): string
    {
        return '.a';
    }

    /**
     * A WASI module resolves no shared libraries, and the build host's paths
     * do not exist wherever it runs.
     */
    public function getDefaultRpaths(?string $phpxDir = null, ?string $phpDir = null): array
    {
        return [];
    }

    public function getExecutableExtension(): string
    {
        return '.wasm';
    }

    public function getDefaultCompiler(): string
    {
        $compiler = getenv('TYPEPHP_WASI_CXX');
        return is_string($compiler) && $compiler !== '' ? $compiler : 'clang++';
    }

    public function getBuildLibraryWarnings(
        string $phpDir,
        string $phpxDir,
        string $buildMode,
        bool $checkPhpxRuntime = true,
    ): array {
        return [];
    }

    public function getIntegerLiteralSuffix(): string
    {
        return 'LL';
    }
}
