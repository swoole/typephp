<?php

namespace TypePhp\Build;

/** Conservative whole-project cache: any input change repeats preprocessing. */
trait PreparedProjectCacheTrait
{
    private const array PREPARED_PROJECT_FIELDS = [
        'symbols', 'preparedFileAsts', 'constants', 'symbolDeclInFile', 'symbolCallInFile',
        'classMethodOverride', 'classSubClasses', 'internalFunctions', 'internalConstants',
        'stdTypeMap', 'nativeClassDeclarations', 'nativeGlobalObjects', 'nativeGlobalTypeResolver',
        'globalVars', 'globalVarsInFile', 'globalVarDeclInFile', 'linkLibs',
        'traitDeclarationsComposed', 'declarationExpressionsFinalized', 'methodOverrideFlagsFinalized',
    ];

    private function preparedProjectKey(array $files): string
    {
        $sources = [];
        foreach ($files as $file) {
            $sources[realpath($file) ?: $file] = hash_file('sha256', $file);
        }
        ksort($sources, SORT_STRING);
        $extensions = [];
        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = phpversion($extension);
        }
        $initialState = $this->capturePreparedProjectState();
        return hash('sha256', serialize([
            2, $this->getIncrementalGeneratorFingerprint(), $sources, PHP_VERSION,
            $extensions, get_defined_constants(), $this->linkLibs,
            $this->varIntTypes, $this->decimalTypes, $this->bigintTypes, $initialState,
        ]));
    }

    private function preparedProjectCacheFile(): string
    {
        return $this->buildDir . '/cache/prepared/' . hash('sha256', $this->targetName) . '.bin';
    }

    private function capturePreparedProjectState(): array
    {
        $dependencies = $this->exportSymbolDependencyState();
        $state = [];
        foreach (self::PREPARED_PROJECT_FIELDS as $field) {
            $state[$field] = $dependencies[$field] ?? $this->{$field};
        }
        return $state;
    }

    private function restorePreparedProject(string $key): bool
    {
        if ($this->climate->arguments->defined('force') || $this->preparedFileAsts !== []
            || $this->symbols->functions() !== [] || $this->symbols->classes() !== []
            || $this->symbols->interfaces() !== []) {
            return false;
        }
        $file = $this->preparedProjectCacheFile();
        if (!is_file($file)) {
            return false;
        }
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            // Reject changed inputs before deserializing the large AST graph.
            if (fgets($stream) !== $key . "\n") {
                return false;
            }
            $contents = stream_get_contents($stream);
            $state = is_string($contents) ? @unserialize($contents) : false;
            if (!is_array($state) || array_keys($state) !== self::PREPARED_PROJECT_FIELDS
                || !($state['symbols'] instanceof \TypePhp\Symbol\SymbolRepository)
                || ($state['nativeGlobalTypeResolver'] !== null
                    && !($state['nativeGlobalTypeResolver'] instanceof \TypePhp\NativeClass\NativeGlobalTypeResolver))) {
                return false;
            }
            $currentState = $this->capturePreparedProjectState();
            foreach (self::PREPARED_PROJECT_FIELDS as $field) {
                if (get_debug_type($state[$field]) !== get_debug_type($currentState[$field])
                    && $field !== 'nativeGlobalTypeResolver') {
                    return false;
                }
            }
            $this->restoreSymbolDependencyState($state);
            $dependencies = $this->exportSymbolDependencyState();
            foreach (self::PREPARED_PROJECT_FIELDS as $field) {
                if (!array_key_exists($field, $dependencies)) {
                    $this->{$field} = $state[$field];
                }
            }
            $this->resetFile();
            $this->resetNamespace();
            $this->resetClass();
            $this->resetMethod();
            $this->resetFunction();
            $this->climate->darkGray('[cached] project preprocessing');
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($stream);
        }
    }

    private function storePreparedProject(string $key): void
    {
        // Imported stubs can live outside the scanned input set. Do not cache
        // them until they are included in the snapshot's dependency manifest.
        if ($this->externalImportStubFiles !== []) {
            return;
        }
        $bodies = [];
        try {
            // Conversion always loads a fresh pristine AST. The prepared tree
            // is needed only for declaration expressions, not ordinary bodies.
            // Keep trait templates intact: a missing output may require their
            // bodies to be composed into a dirty consuming class on a cache hit.
            $stack = [];
            foreach ($this->preparedFileAsts as $statements) {
                array_push($stack, ...$statements);
            }
            while ($stack !== []) {
                $node = array_pop($stack);
                if (!($node instanceof \PhpParser\Node) || $node instanceof \PhpParser\Node\Stmt\Trait_) {
                    continue;
                }
                if (($node instanceof \PhpParser\Node\Stmt\Function_ || $node instanceof \PhpParser\Node\Stmt\ClassMethod)
                    && $node->stmts !== null) {
                    $bodies[] = [$node, $node->stmts];
                    $node->stmts = [];
                }
                foreach ($node->getSubNodeNames() as $name) {
                    $child = $node->{$name};
                    if (is_array($child)) {
                        array_push($stack, ...$child);
                    } elseif ($child instanceof \PhpParser\Node) {
                        $stack[] = $child;
                    }
                }
            }
            $state = $this->capturePreparedProjectState();
            $file = $this->preparedProjectCacheFile();
            AtomicFile::write($file, $key . "\n" . serialize($state), '.prepared-');
        } catch (\Throwable) {
            // Optional cache failures must not fail a valid compilation.
        } finally {
            foreach ($bodies as [$node, $statements]) {
                $node->stmts = $statements;
            }
        }
    }
}
