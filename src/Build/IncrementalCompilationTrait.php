<?php

namespace TypePhp\Build;

/** Project-level incremental planning kept separate from the language parser. */
trait IncrementalCompilationTrait
{
    private ?IncrementalBuildState $incrementalBuildState = null;
    private string $incrementalBuildStateFile = '';
    /** @var array<string, string> */
    private array $incrementalSourceHashes = [];
    /** @var array<string, list<string>> */
    private array $incrementalDependencies = [];
    /** @var array<string, true> */
    private array $incrementalDirtyFiles = [];
    /** @var array<string, array<string, mixed>> */
    private array $incrementalFileMetadata = [];
    /** @var array<string, bool> */
    private array $incrementalTranslationUnits = [];
    private string $incrementalGeneratorFingerprint = '';
    private bool $incrementalPlanInitialized = false;

    /**
     * Stubs provide declarations without emitting a body translation unit.
     * Include them in header generation and dependency invalidation as well.
     * @param list<string> $files
     * @return list<string>
     */
    private function getDeclarationInputFiles(array $files): array
    {
        $inputs = [];
        foreach ($files as $file) {
            $inputs[realpath($file) ?: $file] = true;
        }
        foreach ($this->preparedFileAsts as $file => $_ast) {
            if ($this->isStubFile($file)) {
                $inputs[$file] = true;
            }
        }
        return array_keys($inputs);
    }

    /** @param list<string> $files */
    protected function initializeIncrementalCompilation(array $files): void
    {
        $this->incrementalPlanInitialized = false;
        $this->incrementalFileMetadata = [];
        $this->incrementalTranslationUnits = [];
        $this->incrementalDirtyFiles = [];

        $phpFiles = [];
        foreach ($this->getDeclarationInputFiles($files) as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path !== false) {
                $phpFiles[] = $path;
            }
        }
        sort($phpFiles, SORT_STRING);

        $this->incrementalSourceHashes = [];
        foreach ($phpFiles as $file) {
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                throw new \RuntimeException('Cannot hash PHP source: ' . $file);
            }
            $this->incrementalSourceHashes[$file] = $hash;
        }
        $state = $this->getIncrementalBuildState();
        $this->restoreGlobalDependencyOwnersForPlanning($phpFiles, $state);
        $this->incrementalDependencies = $this->buildPhpFileDependencyGraph($phpFiles);
        $this->incrementalGeneratorFingerprint = $this->getIncrementalGeneratorFingerprint();
        $this->incrementalDirtyFiles = $state->dirtyFiles(
            $this->incrementalSourceHashes,
            $this->incrementalDependencies,
            $this->incrementalGeneratorFingerprint,
            function (string $source, ?array $metadata): bool {
                if (!is_array($metadata)
                    || !is_file($this->getDeclarationHeaderFile($source))
                    || !is_file($this->getArgInfoHeaderFile($source))) {
                    return false;
                }
                $emits = $metadata['emitsTranslationUnit'] ?? null;
                if (($metadata['splitTranslationUnits'] ?? []) !== $this->getSplitTranslationUnits($source)) {
                    return false;
                }
                foreach ($this->getSplitTranslationUnits($source) as $part) {
                    if (!is_file($part)) {
                        return false;
                    }
                }
                if (!is_bool($emits)) {
                    return false;
                }
                if ($emits && !is_file($this->getCppFile($source))) {
                    return false;
                }
                // Different targets can share generated paths in one build
                // directory. A target's saved state alone does not prove that
                // the files still contain that target's declarations and code.
                $hashes = $metadata['generatedHashes'] ?? null;
                return is_array($hashes)
                    && $hashes === $this->getIncrementalOutputHashes($source, $emits);
            },
            $this->climate->arguments->defined('force'),
        );
        $this->incrementalPlanInitialized = true;
    }

    protected function shouldRegeneratePhpFile(string $file): bool
    {
        if (!$this->incrementalPlanInitialized) {
            return true;
        }
        $path = realpath($file) ?: $file;
        return isset($this->incrementalDirtyFiles[$path]);
    }

    protected function incrementalTranslationUnitWasEmitted(string $file): bool
    {
        $path = realpath($file) ?: $file;
        return $this->incrementalTranslationUnits[$path]
            ?? (bool) ($this->getIncrementalBuildState()->metadata($path)['emitsTranslationUnit'] ?? false);
    }

    protected function registerGeneratedProjectSource(string $source): void
    {
        $this->generatedProjectSources[$source] = true;
    }

    /**
     * Restore conversion outputs which are consumed by whole-program generators.
     *
     * @param list<string> $files
     */
    protected function restoreCleanIncrementalMetadata(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        foreach ($this->getDeclarationInputFiles($files) as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $file = realpath($file) ?: $file;
            if ($this->shouldRegeneratePhpFile($file)) {
                continue;
            }
            $metadata = $this->getIncrementalBuildState()->metadata($file);
            if (!is_array($metadata)) {
                continue;
            }
            $this->incrementalFileMetadata[$file] = $metadata;
            $this->incrementalTranslationUnits[$file] = (bool) ($metadata['emitsTranslationUnit'] ?? false);
            $statistics = $metadata['statistics'] ?? [];
            if (is_array($statistics)) {
                $this->compilationStatistics->merge($statistics);
            }
            $globals = $metadata['globals'] ?? [];
            if (is_array($globals)) {
                foreach ($globals as $name => $type) {
                    if (is_string($name) && is_string($type)) {
                        $this->globalVarsInFile[$file][$name] = $type;
                    }
                }
            }
            $initializers = $metadata['nativeStaticInitializers'] ?? [];
            if (is_array($initializers)) {
                foreach ($initializers as $name) {
                    if (is_string($name)) {
                        $this->nativeStaticInitializersInFile[$file][$name] = true;
                    }
                }
            }
            $this->registerExistingArgInfoHeader($file);
        }
        $this->rebuildIncrementalGlobalState();
    }

    /** @param array<string, array<string, int>> $statistics */
    protected function recordIncrementalConversion(
        string $file,
        bool $emitsTranslationUnit,
        array $statistics,
    ): void {
        $path = realpath($file) ?: $file;
        $this->incrementalTranslationUnits[$path] = $emitsTranslationUnit;
        $this->incrementalFileMetadata[$path]['statistics'] = $statistics;
    }

    protected function rebuildIncrementalGlobalState(): void
    {
        foreach ($this->globalVarDeclInFile as $name => $_file) {
            unset($this->symbolDeclInFile[$this->getGlobalDependencySymbol($name)]);
        }
        $this->globalVarDeclInFile = [];
        $this->nativeStaticInitializerDeclInFile = [];
        foreach ($this->globalVarsInFile as $file => $globals) {
            ksort($globals, SORT_STRING);
            foreach ($globals as $name => $type) {
                if (!isset($this->nativeGlobalObjects[$name])) {
                    $this->globalVars[$name] = $type;
                }
                if (!isset($this->globalVarDeclInFile[$name])) {
                    $this->globalVarDeclInFile[$name] = $file;
                    $this->symbolDeclInFile[$this->getGlobalDependencySymbol($name)] = $file;
                }
                $this->symbolCallInFile[$file][] = $this->getGlobalDependencySymbol($name);
            }
        }
        foreach ($this->nativeStaticInitializersInFile as $file => $initializers) {
            foreach ($initializers as $name => $_) {
                $this->nativeStaticInitializers[$name] = true;
                $this->nativeStaticInitializerDeclInFile[$name] = $file;
            }
        }
    }

    /** @param list<string> $files */
    protected function finalizeIncrementalConversionMetadata(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        $this->rebuildIncrementalGlobalState();
        $phpFiles = [];
        foreach ($this->getDeclarationInputFiles($files) as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path !== false) {
                $phpFiles[] = $path;
            }
        }
        $this->incrementalDependencies = $this->buildPhpFileDependencyGraph($phpFiles);
    }

    /** @param list<string> $files */
    protected function saveIncrementalCompilationState(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        $this->rebuildIncrementalGlobalState();
        $stateFiles = [];
        foreach ($this->getDeclarationInputFiles($files) as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path === false || !isset($this->incrementalSourceHashes[$path])) {
                continue;
            }
            $previous = $this->incrementalFileMetadata[$path]
                ?? $this->getIncrementalBuildState()->metadata($path)
                ?? [];
            $declared = [];
            foreach ($this->symbolDeclInFile as $symbol => $declaringFile) {
                if ($declaringFile === $path) {
                    $declared[] = $symbol;
                }
            }
            sort($declared, SORT_STRING);
            $used = array_values(array_unique($this->symbolCallInFile[$path] ?? []));
            sort($used, SORT_STRING);
            $dependencies = $this->incrementalDependencies[$path] ?? [];
            sort($dependencies, SORT_STRING);
            $initializers = array_keys($this->nativeStaticInitializersInFile[$path] ?? []);
            sort($initializers, SORT_STRING);
            $emits = $this->incrementalTranslationUnits[$path]
                ?? (!$this->isStubFile($path) && (bool) ($previous['emitsTranslationUnit'] ?? false));
            $hashes = $this->getIncrementalOutputHashes($path, $emits);
            if ($hashes === null) {
                throw new \RuntimeException('Cannot hash generated outputs for PHP source: ' . $path);
            }
            $stateFiles[$path] = [
                'hash' => $this->incrementalSourceHashes[$path],
                'dependencies' => $dependencies,
                'symbolsDeclared' => $declared,
                'symbolsUsed' => $used,
                'emitsTranslationUnit' => $emits,
                'generatedHashes' => $hashes,
                'header' => $this->getDeclarationHeaderFile($path),
                'cpp' => $this->getCppFile($path),
                'splitTranslationUnits' => $this->getSplitTranslationUnits($path),
                'statistics' => $this->incrementalFileMetadata[$path]['statistics']
                    ?? ($previous['statistics'] ?? []),
                'globals' => $this->globalVarsInFile[$path] ?? [],
                'nativeStaticInitializers' => $initializers,
            ];
        }
        $this->getIncrementalBuildState()->save(
            $this->incrementalGeneratorFingerprint,
            $stateFiles,
        );
    }

    /** @return array<string, string>|null */
    private function getIncrementalOutputHashes(string $source, bool $emits): ?array
    {
        $outputs = [$this->getDeclarationHeaderFile($source), $this->getArgInfoHeaderFile($source)];
        if ($emits) {
            $outputs[] = $this->getCppFile($source);
        }
        foreach ($this->getSplitTranslationUnits($source) as $part) {
            $outputs[] = $part;
        }
        $hashes = [];
        foreach ($outputs as $output) {
            if (!is_file($output)) {
                return null;
            }
            $hash = hash_file('sha256', $output);
            if (!is_string($hash)) {
                return null;
            }
            $hashes[$output] = $hash;
        }
        return $hashes;
    }

    /** @param list<string> $files @return array<string, list<string>> */
    private function buildPhpFileDependencyGraph(array $files): array
    {
        $known = array_fill_keys($files, true);
        $hierarchyDependencies = [];
        // Dispatch in a base class (and its typed callers) depends on whether
        // descendants override its methods. Keep these reverse inheritance
        // edges even before an override exists, so adding/removing one cannot
        // leave a cached direct call to the base implementation behind.
        // Direct children suffice: dirty propagation walks the whole graph.
        foreach ($this->classSubClasses as $class => $children) {
            $parentFile = $this->symbolDeclInFile[$this->getClassDependencySymbol($class)] ?? null;
            if (!is_string($parentFile) || !isset($known[$parentFile])) {
                continue;
            }
            foreach ($children as $child) {
                $childFile = $this->symbolDeclInFile[$this->getClassDependencySymbol($child)] ?? null;
                if (is_string($childFile) && $childFile !== $parentFile && isset($known[$childFile])) {
                    $hierarchyDependencies[$parentFile][$childFile] = true;
                }
            }
        }
        $dependencies = [];
        foreach ($files as $file) {
            $requirements = $hierarchyDependencies[$file] ?? [];
            foreach ($this->symbolCallInFile[$file] ?? [] as $symbol) {
                $dependency = $this->symbolDeclInFile[$symbol] ?? null;
                if (is_string($dependency)
                    && $dependency !== $file
                    && isset($known[$dependency])) {
                    $requirements[$dependency] = true;
                }
            }
            $list = array_keys($requirements);
            sort($list, SORT_STRING);
            $dependencies[$file] = $list;
        }
        return $dependencies;
    }

    private function getIncrementalBuildState(): IncrementalBuildState
    {
        $file = $this->getBuildDir() . '/cache/incremental/' . $this->targetName . '/build-state.json';
        if ($this->incrementalBuildState === null || $this->incrementalBuildStateFile !== $file) {
            $this->incrementalBuildState = new IncrementalBuildState($file);
            $this->incrementalBuildStateFile = $file;
        }
        return $this->incrementalBuildState;
    }

    /** @param list<string> $files */
    private function restoreGlobalDependencyOwnersForPlanning(
        array $files,
        IncrementalBuildState $state,
    ): void {
        $knownFiles = array_fill_keys($files, true);
        foreach ($files as $file) {
            $metadata = $state->metadata($file);
            if (!is_array($metadata)) {
                continue;
            }
            foreach ($metadata['symbolsDeclared'] ?? [] as $symbol) {
                if (is_string($symbol) && str_starts_with($symbol, 'global:')) {
                    $this->symbolDeclInFile[$symbol] = $file;
                }
            }
        }
        foreach ($files as $file) {
            foreach ($this->symbolCallInFile[$file] ?? [] as $symbol) {
                if (!str_starts_with($symbol, 'global:')
                    || isset($this->symbolDeclInFile[$symbol])) {
                    continue;
                }
                // A newly introduced global has no previous owner. Assign one
                // deterministically for this planning pass; conversion will
                // record the actual first declaration owner in sorted order.
                $this->symbolDeclInFile[$symbol] = $file;
            }
        }
        foreach ($this->symbolDeclInFile as $symbol => $file) {
            if (str_starts_with($symbol, 'global:') && !isset($knownFiles[$file])) {
                unset($this->symbolDeclInFile[$symbol]);
            }
        }
    }

    private function getIncrementalGeneratorFingerprint(): string
    {
        $context = hash_init('sha256');
        hash_update($context, serialize([
            'translator' => self::VERSION,
            'php' => $this->phpVersion,
            'mode' => $this->buildMode,
            'nano' => $this->isNanoMode(),
            'nanoPolicy' => $this->isNanoPolicyMode(),
            'wasi' => $this->isWasiTarget(),
            'platform' => $this->targetPlatform,
            'literalStrings' => !$this->noLiteralStrings,
            'debug' => $this->debug,
        ]));

        // New self-hosted compiler builds embed their immutable source snapshot.
        // Reading and hashing a 20+ MB executable on every tiny build costs more
        // than processing the consumer source. Keep the executable fallback for
        // binaries built before this metadata was introduced.
        if (!$this->compilerRuntime->sourceEntry
            && defined('TYPEPHP_COMPILER_BUILD_FINGERPRINT')) {
            $snapshot = constant('TYPEPHP_COMPILER_BUILD_FINGERPRINT');
            if (is_string($snapshot) && preg_match('/^[a-f0-9]{64}$/D', $snapshot) === 1) {
                hash_update($context, $snapshot);
                return hash_final($context);
            }
        }

        // A compiled tpc executable is an immutable snapshot of the generator.
        // Walking and hashing every compiler PHP source on each consumer build
        // is both unnecessary and disproportionately expensive through the AOT
        // Zend bridge. Fingerprint the executable snapshot with one native hash
        // operation instead. The interpreted development entry keeps the source
        // walk below so edits invalidate generated-code caches immediately.
        if (!$this->compilerRuntime->sourceEntry) {
            $executable = $this->compilerRuntime->executable;
            if (is_file($executable)) {
                hash_update($context, str_replace('\\', '/', $executable) . "\0");
                if (!hash_update_file($context, $executable)) {
                    throw new \RuntimeException(
                        'Cannot fingerprint TypePHP compiler executable: ' . $executable,
                    );
                }
                return hash_final($context);
            }
        }

        $sourceDirectory = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
        );
        $sources = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $sources[] = $entry->getPathname();
            }
        }
        sort($sources, SORT_STRING);
        foreach ($sources as $source) {
            hash_update($context, str_replace('\\', '/', $source) . "\0");
            hash_update_file($context, $source);
        }
        return hash_final($context);
    }

    protected function genCompiledGeneratorFingerprintRegistration(): string
    {
        // Only the compiler executable owns this runtime constant; ordinary
        // consumer binaries, libraries and extensions must not register it.
        if (!$this->isBuildModeBin()
            || !$this->incrementalPlanInitialized
            || !$this->hasFunction(self::ENTRY_FUNCTION)
            || !$this->hasClass('TypePhp\\Translator')
            || realpath($this->getFunction(self::ENTRY_FUNCTION)->sourceFile)
                !== realpath($this->rootPath . '/src/compiler.php')
            || realpath($this->getClass('TypePhp\\Translator')->sourceFile)
                !== realpath($this->rootPath . '/src/Translator.php')) {
            return '';
        }
        // Do not include the running generator's fingerprint: that would make
        // repeated self-hosted builds recursively fingerprint their predecessor.
        $snapshot = hash('sha256', serialize([
            'sources' => $this->incrementalSourceHashes,
            'php' => $this->phpVersion,
            'platform' => $this->targetPlatform,
            'cxxStd' => $this->cxxStd,
            'cxxFlags' => $this->cxxFlags,
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
        ]));
        // Embed mode activates this module after PHP startup has snapshotted
        // persistent_constants_count. A late persistent constant would be freed
        // as request memory by Zend shutdown. Publish the tiny value in RINIT.
        return 'php::fn::define("TYPEPHP_COMPILER_BUILD_FINGERPRINT", php::Str("'
            . $snapshot . '"));' . PHP_EOL;
    }
}
