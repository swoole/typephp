<?php

namespace TypePhp\Build;

/** Owns dependency records; parsers supply canonical symbols and source paths. */
trait SymbolDependencyTrait
{
    /** @var array<string, string> Canonical symbol => declaring PHP file. */
    private array $symbolDeclInFile = [];
    /** @var array<string, list<string>> PHP file => symbols used in first-seen order. */
    private array $symbolCallInFile = [];
    /** @var array<string, array<string, true>> Derived membership index, excluded from cache snapshots. */
    private array $symbolUseIndex = [];
    /** @var array<string, string> Global/static slot => actual first declaring PHP file. */
    private array $globalVarDeclInFile = [];

    protected function recordSymbolUse(string $file, string $symbol): void
    {
        $key = '@' . $symbol;
        if (!isset($this->symbolUseIndex[$file][$key])) {
            $this->symbolCallInFile[$file][] = $symbol;
            $this->symbolUseIndex[$file][$key] = true;
        }
    }

    protected function clearFileSymbolUses(string $file): void
    {
        $this->symbolCallInFile[$file] = [];
        $this->symbolUseIndex[$file] = [];
    }

    protected function registerSymbolDeclaration(string $file, string $symbol): void
    {
        $this->symbolDeclInFile[$symbol] = $file;
    }

    protected function forgetSymbolDeclaration(string $symbol): void
    {
        unset($this->symbolDeclInFile[$symbol]);
    }

    protected function getSymbolDeclarationFile(string $symbol): ?string
    {
        return $this->symbolDeclInFile[$symbol] ?? null;
    }

    protected function getSymbolDeclarations(): array
    {
        return $this->symbolDeclInFile;
    }

    protected function getFileSymbolUses(string $file): array
    {
        return $this->symbolCallInFile[$file] ?? [];
    }

    protected function getSymbolUsesByFile(): array
    {
        return $this->symbolCallInFile;
    }

    protected function getFileDeclaredSymbols(string $file): array
    {
        $symbols = [];
        foreach ($this->symbolDeclInFile as $symbol => $owner) {
            if ($owner === $file) {
                $symbols[] = $symbol;
            }
        }
        return $symbols;
    }

    protected function recordGlobalDependencyUsage(string $file, string $name): void
    {
        $symbol = $this->getGlobalDependencySymbol($name);
        if (!isset($this->globalVarDeclInFile[$name])) {
            $this->globalVarDeclInFile[$name] = $file;
            $this->registerSymbolDeclaration($file, $symbol);
        }
        $this->recordSymbolUse($file, $symbol);
    }

    protected function getGlobalDeclarationFile(string $name): ?string
    {
        return $this->globalVarDeclInFile[$name] ?? null;
    }

    protected function clearGlobalDependencyOwners(): void
    {
        foreach ($this->globalVarDeclInFile as $name => $_file) {
            $this->forgetSymbolDeclaration($this->getGlobalDependencySymbol($name));
        }
        $this->globalVarDeclInFile = [];
    }

    /** Preserve the existing prepared-cache field names and array shapes. */
    protected function exportSymbolDependencyState(): array
    {
        return [
            'symbolDeclInFile' => $this->symbolDeclInFile,
            'symbolCallInFile' => $this->symbolCallInFile,
            'globalVarDeclInFile' => $this->globalVarDeclInFile,
        ];
    }

    protected function restoreSymbolDependencyState(array $state): void
    {
        foreach (['symbolDeclInFile', 'symbolCallInFile', 'globalVarDeclInFile'] as $field) {
            if (!isset($state[$field]) || !is_array($state[$field])) {
                throw new \LogicException('Invalid symbol dependency snapshot');
            }
        }
        $declarations = $state['symbolDeclInFile'];
        $globals = $state['globalVarDeclInFile'];
        foreach ([$declarations, $globals] as $owners) {
            foreach ($owners as $symbol => $file) {
                if (!is_string($symbol) || !is_string($file)) {
                    throw new \LogicException('Invalid symbol dependency ownership snapshot');
                }
            }
        }
        $uses = [];
        $index = [];
        foreach ($state['symbolCallInFile'] as $file => $symbols) {
            if (!is_string($file) || !is_array($symbols)) {
                throw new \LogicException('Invalid symbol dependency use snapshot');
            }
            $uses[$file] = [];
            $index[$file] = [];
            foreach ($symbols as $symbol) {
                if (!is_string($symbol)) {
                    throw new \LogicException('Invalid symbol dependency use snapshot');
                }
                $key = '@' . $symbol;
                if (!isset($index[$file][$key])) {
                    $uses[$file][] = $symbol;
                    $index[$file][$key] = true;
                }
            }
        }
        $this->symbolDeclInFile = $declarations;
        $this->globalVarDeclInFile = $globals;
        $this->symbolCallInFile = $uses;
        $this->symbolUseIndex = $index;
    }
}
