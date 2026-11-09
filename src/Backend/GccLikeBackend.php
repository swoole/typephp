<?php

namespace TypePhp\Backend;

use TypePhp\Platform\PlatformBase;
use TypePhp\Platform\Windows;

/**
 * Shared backend base class for GCC/Clang.
 * Contains the common command-line construction logic for Unix-like compilers (GCC, Clang).
 * Subclasses only need to override the platform-specific hook methods.
 */
abstract class GccLikeBackend extends CompilerBackend
{
    protected string $compilerCommand;
    protected ?string $linkerCommand;

    public function __construct(PlatformBase $platform, string $compilerCommand, ?string $linkerCommand = null)
    {
        parent::__construct($platform);
        $this->compilerCommand = $compilerCommand;
        $this->linkerCommand = $linkerCommand;
    }

    public function getCompilerCommand(): string
    {
        return $this->compilerCommand;
    }

    public function supportsPrecompiledHeaders(): bool
    {
        return true;
    }

    public function getPrecompiledHeaderArtifact(string $headerFile): string
    {
        return $headerFile . '.gch';
    }

    // ──── Hook methods (subclass override points) ────

    /** Compiler-specific prefix flags (e.g. MSVC compatibility mode). */
    protected function getCompilerPrefixFlags(): string
    {
        return '';
    }

    /** Linker output flag (-o vs /OUT:). */
    protected function getLinkerOutputFlag(): string
    {
        return '-o';
    }

    protected function formatLinkerOutputArgument(string $outputFile): string
    {
        return $this->getLinkerOutputFlag() . ' ' . escapeshellarg($outputFile);
    }

    /** Include flag syntax is defined by the compiler driver, not the host platform. */
    protected function formatIncludePaths(array $includePaths): string
    {
        $flags = [];
        foreach ($includePaths as $path) {
            $flags[] = '-I' . escapeshellarg($path);
        }

        return implode(' ', $flags);
    }

    /** Format the sanitizer flag. */
    protected function formatSanitizerFlag(string $sanitizer): string
    {
        return match ($sanitizer) {
            'address', 'addr' => '-fsanitize=address',
            'undefined', 'undef' => '-fsanitize=undefined',
            default => '-fsanitize=' . $sanitizer,
        };
    }

    /** Get the PIC flag. */
    protected function getPICFlag(array $config): string
    {
        if ((!empty($config['build_mode']) && ($config['build_mode'] === 'ext' || $config['build_mode'] === 'lib')) || !empty($config['pic'])) {
            return ' -fPIC';
        }
        return '';
    }

    /** Build the shared GCC/Clang compile flags; reused by both the C and C++ compilation paths. */
    protected function buildSharedCompileFlags(array $config, bool $includeCppStd = false): string
    {
        $cmd = '';

        if (!empty($config['sanitize'])) {
            $cmd .= ' ' . $this->formatSanitizerFlag($config['sanitize']);
            // Symbols for native diagnostics must not enable TypePHP's
            // separate PHP-level debug tracing or change exception locations.
            $cmd .= ' -g -fno-omit-frame-pointer';
        }

        if (!empty($config['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $optimizeLevel = $config['optimize'] ?? 2;
            $cmd .= ' -O' . $optimizeLevel;
        }

        $cmd .= ' -Wall';

        if ($includeCppStd && !empty($config['cpp_std'])) {
            $cmd .= ' -std=' . $config['cpp_std'];
        }

        if (!empty($config['march'])) {
            $cmd .= ' -march=' . $config['march'];
        }

        if (!empty($config['section_gc'])) {
            $cmd .= ' -ffunction-sections -fdata-sections';
        }

        if (!empty($config['wasi_exceptions'])) {
            $cmd .= ' -fwasm-exceptions'
                . ' -mllvm -wasm-enable-sjlj'
                . ' -mllvm -wasm-use-legacy-eh=false';
        }

        if (!$includeCppStd && !empty($config['cflags'])) {
            $cmd .= ' ' . $config['cflags'];
        }

        if (!empty($config['target_platform'])) {
            $cmd .= ' --target=' . $config['target_platform'];
        }

        $cmd .= $this->getPICFlag($config);

        if (in_array(($config['build_mode'] ?? null), ['ext', 'lib'], true)
            && !($this->platform instanceof Windows)) {
            $cmd .= ' -fvisibility=hidden';
        }

        if (!empty($config['enable_profiler'])) {
            $cmd .= ' ' . $this->formatDefineFlag('PPROF_ON=1', '-D');
            if (!empty($config['prof_output'])) {
                $profOutput = addcslashes($config['prof_output'], "\\\"");
                $cmd .= ' ' . $this->formatDefineFlag('PROF_OUTPUT_FILE="' . $profOutput . '"', '-D');
            }
        }

        if ($includeCppStd && !empty($config['cxxflags'])) {
            $cmd .= ' ' . $config['cxxflags'];
        }

        if (!empty($config['user_defines'])) {
            foreach ($config['user_defines'] as $define) {
                $cmd .= ' ' . $this->formatDefineFlag($define, '-D');
            }
        }

        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        if ($includeCppStd && !empty($config['precompiled_header'])) {
            $cmd .= $this->formatPrecompiledHeaderFlag($config['precompiled_header']);
        }

        if ($includeCppStd && !empty($config['forced_include'])) {
            $cmd .= ' -include ' . escapeshellarg($config['forced_include']);
        }

        return $cmd;
    }

    /** @param array{header: string, artifact: string} $precompiledHeader */
    protected function formatPrecompiledHeaderFlag(array $precompiledHeader): string
    {
        return ' -include ' . escapeshellarg($precompiledHeader['header']);
    }

    /** Get platform-specific link options. */
    protected function getPlatformLinkFlags(array $config): string
    {
        $flags = '';

        if ((!empty($config['build_mode']) && ($config['build_mode'] === 'ext' || $config['build_mode'] === 'lib')) || !empty($config['shared'])) {
            $flags .= ' ' . $this->platform->getSharedLinkFlag();

            // Shared libraries must be self-contained. Executables can defer symbols
            // to their host, but a lib-mode artifact must be loadable via dlopen().
            if (($config['build_mode'] ?? null) === 'lib' && !($this->platform instanceof \TypePhp\Platform\Macos)) {
                $flags .= ' -Wl,-z,defs';
            }

            // A macOS PHP extension intentionally leaves Zend/PHP symbols for
            // the host SAPI to resolve. Linking libphp.dylib would create a
            // second runtime, so use the platform's standard bundle behavior.
            if (($config['build_mode'] ?? null) === 'ext' && $this->platform instanceof \TypePhp\Platform\Macos) {
                $flags .= ' -undefined dynamic_lookup';
            }

            if ($this->platform instanceof \TypePhp\Platform\Macos && !empty($config['install_name'])) {
                $flags .= ' ' . $this->platform->getCurrentInstallNameOption($config['install_name']);
            }
        }

        if (!empty($config['rpath'])) {
            foreach ($config['rpath'] as $path) {
                $flags .= ' -Wl,-rpath,' . escapeshellarg($path);
            }
        }

        return $flags;
    }

    // ──── Abstract method implementations ────

    public function buildCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);

        return $cmd;
    }

    public function buildCCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        $cmd .= ' -x c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['c_std'])) {
            $cmd .= ' -std=' . $options['c_std'];
        }

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }
        $cmd .= $this->buildSharedCompileFlags($options, false);

        return $cmd;
    }

    public function buildNativeCompileCommand(string $sourceFile, string $outputFile, array $options = [], string $language = ''): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        if ($language !== '') {
            $cmd .= ' -x ' . $language;
        }
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);
        if (!empty($options['nativeflags'])) {
            $cmd .= ' ' . $options['nativeflags'];
        }

        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . $this->createResponseFile(
            $objectFiles,
            $outputFile,
            $options['response_file'] ?? null,
        );
        $cmd .= ' ' . $this->formatLinkerOutputArgument($outputFile);

        if (!empty($options['library_paths'])) {
            $cmd .= ' ' . $this->formatLibraryPaths($options['library_paths']);
        }

        if (!empty($options['ldflags'])) {
            $cmd .= ' ' . $options['ldflags'];
        }

        $cmd .= $this->buildLinkOptions($options);

        if (!empty($options['libraries'])) {
            $cmd .= ' ' . $this->formatLibraries($options['libraries']);
        }
        if (!empty($options['post_ldflags'])) {
            $cmd .= ' ' . $options['post_ldflags'];
        }

        return $cmd;
    }

    public function buildCompileOptions(array $config = []): string
    {
        $cmd = $this->getCompilerPrefixFlags();
        $cmd .= $this->buildSharedCompileFlags($config, true);
        return $cmd;
    }

    public function buildLinkOptions(array $config = []): string
    {
        $cmd = '';

        $cmd .= $this->getPlatformLinkFlags($config);

        if (!empty($config['sanitize'])) {
            $cmd .= ' ' . $this->formatSanitizerFlag($config['sanitize']);
        }

        if (!empty($config['target_platform'])) {
            $cmd .= ' --target=' . $config['target_platform'];
        }

        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        return $cmd;
    }

}
