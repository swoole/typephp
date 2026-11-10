<?php

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Build\IncrementalBuildState;
use TypePhp\CompilerTest;

final class SymbolDependencyTest extends TestCase
{
    private CompilerTest $compiler;

    protected function setUp(): void
    {
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($this->compiler, $method))->invoke($this->compiler, ...$arguments);
    }

    public function testCanonicalizationStaysWithTheParserAndUsesKeepFirstSeenOrder(): void
    {
        $class = $this->invoke('getClassDependencySymbol', '\\Demo\\Item');
        $function = $this->invoke('getFunctionDependencySymbol', '\\Demo\\Run');
        $upper = $this->invoke('getConstantDependencySymbol', '\\Demo\\VALUE');
        $lower = $this->invoke('getConstantDependencySymbol', '\\Demo\\value');
        self::assertSame('class:demo\\item', $class);
        self::assertSame('function:demo\\run', $function);
        self::assertSame('constant:Demo\\VALUE', $upper);
        self::assertSame('constant:Demo\\value', $lower);
        foreach ([$function, $upper, $class, $function, $lower, $upper] as $symbol) {
            $this->invoke('recordSymbolUse', '/consumer.php', $symbol);
        }
        self::assertSame([$function, $upper, $class, $lower], $this->invoke('getFileSymbolUses', '/consumer.php'));
    }

    public function testReparsingAFileClearsBothUsesAndTheirMembershipIndex(): void
    {
        $this->invoke('recordSymbolUse', '/a.php', 'function:first');
        $this->invoke('recordSymbolUse', '/b.php', 'function:other');
        $this->invoke('clearFileSymbolUses', '/a.php');
        self::assertSame([], $this->invoke('getFileSymbolUses', '/a.php'));
        $this->invoke('recordSymbolUse', '/a.php', 'function:first');
        $this->invoke('recordSymbolUse', '/a.php', 'function:second');
        $this->invoke('recordSymbolUse', '/a.php', 'function:first');
        self::assertSame(['function:first', 'function:second'], $this->invoke('getFileSymbolUses', '/a.php'));
        self::assertSame(['function:other'], $this->invoke('getFileSymbolUses', '/b.php'));
    }

    public function testDeclarationsRetainExistingReplacementAndRemovalSemantics(): void
    {
        $this->invoke('registerSymbolDeclaration', '/first.php', 'function:shared');
        $this->invoke('registerSymbolDeclaration', '/first.php', 'constant:VALUE');
        $this->invoke('registerSymbolDeclaration', '/second.php', 'function:shared');
        self::assertSame('/second.php', $this->invoke('getSymbolDeclarationFile', 'function:shared'));
        self::assertSame(['constant:VALUE'], $this->invoke('getFileDeclaredSymbols', '/first.php'));
        self::assertSame(['function:shared'], $this->invoke('getFileDeclaredSymbols', '/second.php'));
        $this->invoke('forgetSymbolDeclaration', 'function:shared');
        self::assertNull($this->invoke('getSymbolDeclarationFile', 'function:shared'));
        self::assertSame('/first.php', $this->invoke('getSymbolDeclarationFile', 'constant:VALUE'));
    }

    public function testActualGlobalOwnershipAndPlanningOwnershipRemainSeparate(): void
    {
        $this->invoke('recordGlobalDependencyUsage', '/first.php', 'shared');
        $this->invoke('recordGlobalDependencyUsage', '/second.php', 'shared');
        self::assertSame('/first.php', $this->invoke('getGlobalDeclarationFile', 'shared'));
        self::assertSame('/first.php', $this->invoke('getSymbolDeclarationFile', 'global:shared'));
        self::assertSame(['global:shared'], $this->invoke('getFileSymbolUses', '/second.php'));
        $this->invoke('registerSymbolDeclaration', '/planned.php', 'global:shared');
        self::assertSame('/first.php', $this->invoke('getGlobalDeclarationFile', 'shared'));
        self::assertSame('/planned.php', $this->invoke('getSymbolDeclarationFile', 'global:shared'));
        $this->invoke('clearGlobalDependencyOwners');
        $this->invoke('recordGlobalDependencyUsage', '/second.php', 'shared');
        self::assertSame('/second.php', $this->invoke('getGlobalDeclarationFile', 'shared'));
        self::assertSame('/second.php', $this->invoke('getSymbolDeclarationFile', 'global:shared'));
        self::assertSame(['global:shared'], $this->invoke('getFileSymbolUses', '/second.php'));
    }

    public function testGlobalNamesRemainCaseSensitive(): void
    {
        $this->invoke('recordGlobalDependencyUsage', '/upper.php', 'Value');
        $this->invoke('recordGlobalDependencyUsage', '/lower.php', 'value');
        self::assertSame('/upper.php', $this->invoke('getGlobalDeclarationFile', 'Value'));
        self::assertSame('/lower.php', $this->invoke('getGlobalDeclarationFile', 'value'));
        self::assertSame('/upper.php', $this->invoke('getSymbolDeclarationFile', 'global:Value'));
        self::assertSame('/lower.php', $this->invoke('getSymbolDeclarationFile', 'global:value'));
    }

    public function testSnapshotsRetainWireShapeAndRebuildTheUseIndex(): void
    {
        $state = [
            'symbolDeclInFile' => ['constant:VALUE' => '/provider.php'],
            'symbolCallInFile' => ['/consumer.php' => ['constant:VALUE', 'function:run', 'constant:VALUE']],
            'globalVarDeclInFile' => [],
        ];
        $this->invoke('restoreSymbolDependencyState', $state);
        $this->invoke('recordSymbolUse', '/consumer.php', 'function:run');
        $this->invoke('recordSymbolUse', '/consumer.php', 'class:item');
        $state['symbolCallInFile']['/consumer.php'] = ['constant:VALUE', 'function:run', 'class:item'];
        self::assertSame($state, $this->invoke('exportSymbolDependencyState'));
        $snapshot = unserialize(serialize($state));
        $this->invoke('restoreSymbolDependencyState', $snapshot);
        self::assertSame($state, $this->invoke('exportSymbolDependencyState'));
    }

    public static function invalidSnapshots(): array
    {
        return [
            'missing fields' => [[]],
            'invalid owner' => [['symbolDeclInFile' => ['function:bad' => 123], 'symbolCallInFile' => [], 'globalVarDeclInFile' => []]],
            'invalid use list' => [['symbolDeclInFile' => [], 'symbolCallInFile' => ['/a.php' => 'function:bad'], 'globalVarDeclInFile' => []]],
            'invalid symbol' => [['symbolDeclInFile' => [], 'symbolCallInFile' => ['/a.php' => [123]], 'globalVarDeclInFile' => []]],
        ];
    }

    #[DataProvider('invalidSnapshots')]
    public function testInvalidSnapshotsCannotPartiallyReplaceLiveRecords(array $state): void
    {
        $this->invoke('recordGlobalDependencyUsage', '/live.php', 'value');
        $before = $this->invoke('exportSymbolDependencyState');
        try {
            $this->invoke('restoreSymbolDependencyState', $state);
            self::fail('Expected malformed dependency records to be rejected');
        } catch (\LogicException) {
            self::assertSame($before, $this->invoke('exportSymbolDependencyState'));
        }
        $this->invoke('recordSymbolUse', '/live.php', 'global:value');
        self::assertSame(['global:value'], $this->invoke('getFileSymbolUses', '/live.php'));
    }

    public function testPlanningDropsRemovedProvidersWithoutChangingActualGlobalOwnership(): void
    {
        $state = new IncrementalBuildState(sys_get_temp_dir() . '/typephp-unused-' . bin2hex(random_bytes(8)) . '.json');
        $this->invoke('recordGlobalDependencyUsage', '/removed.php', 'shared');
        $this->invoke('recordSymbolUse', '/kept.php', 'global:shared');
        $this->invoke('restoreGlobalDependencyOwnersForPlanning', ['/kept.php'], $state);
        self::assertNull($this->invoke('getSymbolDeclarationFile', 'global:shared'));
        self::assertSame('/removed.php', $this->invoke('getGlobalDeclarationFile', 'shared'));
        $this->invoke('clearGlobalDependencyOwners');
        $this->invoke('recordGlobalDependencyUsage', '/kept.php', 'shared');
        self::assertSame('/kept.php', $this->invoke('getGlobalDeclarationFile', 'shared'));
        self::assertSame('/kept.php', $this->invoke('getSymbolDeclarationFile', 'global:shared'));
    }

    public function testNewGlobalPlanningUsesCallerOrderUntilConversionRegistersItsOwner(): void
    {
        $state = new IncrementalBuildState(sys_get_temp_dir() . '/typephp-unused-' . bin2hex(random_bytes(8)) . '.json');
        $this->invoke('recordSymbolUse', '/a.php', 'global:new');
        $this->invoke('recordSymbolUse', '/b.php', 'global:new');
        $this->invoke('restoreGlobalDependencyOwnersForPlanning', ['/b.php', '/a.php'], $state);
        self::assertSame('/b.php', $this->invoke('getSymbolDeclarationFile', 'global:new'));
        self::assertNull($this->invoke('getGlobalDeclarationFile', 'new'));
        $this->invoke('recordGlobalDependencyUsage', '/a.php', 'new');
        self::assertSame('/a.php', $this->invoke('getSymbolDeclarationFile', 'global:new'));
        self::assertSame('/a.php', $this->invoke('getGlobalDeclarationFile', 'new'));
    }
}
