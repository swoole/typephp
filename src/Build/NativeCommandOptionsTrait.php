<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Metadata\Constants;

trait NativeCommandOptionsTrait
{
    /** @var null|array{header: string, artifact: string} */
    protected ?array $precompiledHeader = null;

    protected function getCommonCompileCommandOptions(): CompileOptions
    {
        $includePaths = $this->getIncludePaths();
        if (!empty($this->userIncludePaths)) {
            $includePaths = array_merge($includePaths, $this->userIncludePaths);
        }

        $userDefines = $this->userDefines;
        if ($this->isIosTarget()) {
            $userDefines[] = 'PHPX_IOS=1';
        }
        if ($this->isAndroidTarget()) {
            $userDefines[] = 'PHPX_ANDROID=1';
        }
        if ($this->isBuildModeLib()) {
            $userDefines[] = 'TYPEPHP_NO_MAIN=1';
            $userDefines[] = $this->getLibraryExportsMacroName() . '=1';
        }
        if ($this->isNanoMode()) {
            array_push(
                $userDefines,
                'TYPEPHP_NATIVE=1',
                'PHP_NANO=1',
                'PHPX_NANO=1',
                '_POSIX_C_SOURCE=200809L',
            );
            if ($this->isMacos() || $this->isIosTarget()) {
                // A strict POSIX feature profile hides Darwin's BSD integer
                // aliases (u_int/u_char/u_short), which are still used by
                // public macOS and iOS SDK headers such as sys/sysctl.h.
                $userDefines[] = '_DARWIN_C_SOURCE=1';
            }
            array_push($userDefines, ...$this->nanoRuntimeDefines);
            if ($this->isWasiTarget()) {
                $userDefines[] = 'ZEND_MM_ERROR=0';
            }
        }

        $values = [
            'include_paths' => $includePaths,
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
            'sanitize' => $this->sanitize,
            'march' => $this->march,
            'target_platform' => $this->targetPlatform,
            // Source-composed Nano owns its runtime configuration and uses the
            // same single-threaded ABI on every native platform.
            'is_zts' => $this->isNanoMode() ? false : $this->isPhpZts,
            'build_mode' => $this->buildMode,
            'enable_profiler' => $this->enableProfiler,
            'prof_output' => $this->targetName . '.prof',
            'user_defines' => $userDefines,
            'lto' => $this->enableLto,
            'section_gc' => $this->isNanoMode(),
            'wasi_exceptions' => $this->isNanoMode() && $this->isWasiTarget(),
        ];

        if ($this->debug && $this->isWindows()) {
            $values['compiler_pdb'] = $this->getMsvcCompilerPdbFile();
        }

        return new CompileOptions($values);
    }

    protected function getMsvcCompilerPdbFile(): string
    {
        $directory = $this->getBuildDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'msvc';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create MSVC PDB directory: ' . $directory);
        }
        return $directory . DIRECTORY_SEPARATOR . $this->targetName . '.compile.pdb';
    }

    protected function getCompileCommandOptions(): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        $options = $options
            ->with('cpp_std', $this->cxxStd)
            ->with('cxxflags', $this->cxxFlags)
            ->with('suppressed_warnings', Constants::MSVC_SUPPRESSED_WARNINGS ?? []);

        if ($this->isBuildModeLib()) {
            $options = $options->with(
                'forced_include',
                $this->getIncludeDir() . '/php_' . $this->targetName . '_all_decl.h'
            );
        }

        if ($this->precompiledHeader !== null) {
            $options = $options->with('precompiled_header', $this->precompiledHeader);
        }

        return $options;
    }

    protected function getCCompileCommandOptions(): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        $options = $options
            ->with('cflags', $this->cFlags)
            ->with('suppressed_warnings', ['4244', '4146']);
        return $this->isNanoMode() ? $options->with('c_std', 'c11') : $options;
    }

    protected function getPrecompiledHeaderCompileCommandOptions(): CompileOptions
    {
        $values = $this->getCompileCommandOptions()->toArray();
        unset($values['forced_include'], $values['precompiled_header']);
        return new CompileOptions($values);
    }

    protected function getNativeCompileCommandOptions(string $language = ''): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        $options = $options->with('suppressed_warnings', Constants::MSVC_SUPPRESSED_WARNINGS ?? []);

        if ($language === 'objective-c++') {
            $options = $options->with('cpp_std', $this->cxxStd)->with('cxxflags', $this->cxxFlags);
        } elseif ($language === 'assembler') {
            $options = $options->with('nativeflags', $this->asmFlags);
        } elseif ($language === 'objective-c') {
            $options = $options->with('nativeflags', $this->cFlags);
        }

        return $options;
    }

    protected function getProjectRuntimeEntryCompileCommandOptions(): CompileOptions
    {
        $values = $this->getCompileCommandOptions()->toArray();
        // TYPEPHP_PROJECT_NAME is deliberately confined to the small,
        // project-specific entry translation unit. Defining it while loading
        // the common PCH would make every output target require a distinct PCH.
        unset($values['forced_include'], $values['precompiled_header']);
        $values['user_defines'][] = 'TYPEPHP_RUNTIME_EXPORTS=1';
        $values['user_defines'][] = 'TYPEPHP_PROJECT_NAME=' . $this->targetName;
        if (!$this->isNanoMode()) {
            $values['user_defines'][] = 'TYPEPHP_PROJECT_SEPARATE_ENTRY=1';
        }
        return new CompileOptions($values);
    }

    protected function getLinkCommandOptions(): LinkOptions
    {
        $libraryPaths = array_merge($this->getLibraryPaths(), $this->linkPaths);
        $libraries = $this->getLibraries();
        if ($this->enableProfiler) {
            $libraries[] = 'profiler';
        }
        $libraries = array_merge($libraries, $this->linkLibs);

        $ldflags = $this->ldflags;
        $postLdflags = '';
        if ($this->isPhpBuilderBuild()
            && $this->hasSapi('embed')
            && $this->sapiPhpBuildDirectory !== null
        ) {
            $makefile = $this->sapiPhpBuildDirectory . '/Makefile';
            $contents = is_file($makefile) ? (string) file_get_contents($makefile) : '';
            if (preg_match('/^EXTRA_LIBS[ \t]*=[ \t]*(.*)$/m', $contents, $match) === 1) {
                // Static libphp references these system libraries, so they must
                // appear after -lphp on linkers that resolve archives left-to-right.
                $postLdflags = trim($match[1]);
            }
        }
        $targetPlatform = $this->targetPlatform;
        if ($this->fullStatic) {
            // The bundled libphp.a carries musl libc, so the link must use musl's
            // C runtime instead of glibc's. -B points the driver at the musl
            // startup files; -static keeps the result free of NEEDED entries.
            // Only the link step switches target: the translation units are still
            // compiled against the host libstdc++ headers, which is fine because
            // TLS relocations are resolved here rather than at compile time.
            $targetPlatform = $this->getFullStaticTargetTriple();
            $ldflags = trim('-static -B ' . escapeshellarg($this->getFullStaticMuslDir()) . ' ' . $ldflags);
        }
        if ($this->isNanoMode()) {
            if (!$this->isWindows()) {
                $gcSections = ($this->isMacos() || $this->isIosTarget())
                    ? '-Wl,-dead_strip'
                    : '-Wl,--gc-sections';
                $ldflags = trim($gcSections . ' ' . $ldflags);
            }
            if ($this->isWasiTarget()) {
                $ldflags = trim(
                    '-fwasm-exceptions -lsetjmp -lunwind ' . $ldflags,
                );
            }
        }

        $options = [
            'library_paths' => $libraryPaths,
            'libraries' => $libraries,
            'ldflags' => $ldflags,
            'post_ldflags' => $postLdflags,
            'debug' => $this->debug,
            'no_console' => $this->noConsole,
            // A shared-runtime library resolves Zend symbols from its
            // consumer, just like a PHP extension. Keep library export and
            // compile options unchanged.
            'build_mode' => $this->isBuildModeLib() && !$this->libraryEmbedsPhp
                ? self::BUILD_MODE_EXT
                : $this->buildMode,
            'sanitize' => $this->sanitize,
            'lto' => $this->enableLto,
            'section_gc' => $this->isNanoMode(),
            'target_platform' => $targetPlatform,
            'response_file' => $this->getBuildDir() . DIRECTORY_SEPARATOR . 'cache'
                . DIRECTORY_SEPARATOR . 'link' . DIRECTORY_SEPARATOR
                . basename($this->getTargetFileName()) . '.rsp',
        ];

        $rpaths = $this->isNanoMode()
            ? []
            : $this->getPlatform()->getDefaultRpaths($this->getPhpxDir(), $this->getPhpDir());
        if (!empty($rpaths)) {
            $options['rpath'] = $rpaths;
        }

        return new LinkOptions($options);
    }
}
