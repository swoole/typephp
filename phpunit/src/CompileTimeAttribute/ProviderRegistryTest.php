<?php

namespace TypePhp\Tests\CompileTimeAttribute;

use PHPUnit\Framework\TestCase;
use TypePhp\CompileTimeAttribute\ProviderDefinition;
use TypePhp\CompileTimeAttribute\ProviderTable;
use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\CompileTimeAttributeRegistry;

final class ProviderRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderTable::reset();
        CompileTimeAttributeRegistry::resetProviders();
    }

    public function testRegisteringAProviderLeavesBuiltinsUntouched(): void
    {
        $builtins = CompileTimeAttributeRegistry::names();

        CompileTimeAttributeRegistry::registerProvider([
            'name' => 'App\\Count',
            'targets' => [CompileTimeAttributeRegistry::TARGET_NAMED_CLASS],
            'target_error' => 'nope',
            'repeatable' => false,
            'argument_parser' => CompileTimeAttributeRegistry::ARGUMENTS_PROVIDER,
            'conflicts' => [],
            'phase' => CompileTimeAttributeRegistry::PHASE_PROVIDER,
            'preserve_in_library_stub' => false,
        ]);

        $this->assertSame($builtins, array_slice(CompileTimeAttributeRegistry::names(), 0, count($builtins)));
        $this->assertNotNull(CompileTimeAttributeRegistry::get('App\\Count'));
        $this->assertNotNull(CompileTimeAttributeRegistry::get('app\\count'));
        $this->assertSame(
            ['Override', 'MustUse', 'Immutable', 'Hot', 'Cold'],
            CompileTimeAttributeRegistry::namesForPhase(CompileTimeAttributeRegistry::PHASE_ENTER),
        );
        $this->assertSame(['App\\Count'], CompileTimeAttributeRegistry::namesForPhase(CompileTimeAttributeRegistry::PHASE_PROVIDER));

        CompileTimeAttributeRegistry::resetProviders();
        $this->assertNull(CompileTimeAttributeRegistry::get('App\\Count'));
        $this->assertSame($builtins, CompileTimeAttributeRegistry::names());
    }

    public function testProviderCannotReuseABuiltinName(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('is built in');

        ProviderTable::register($this->definition('Getter'));
    }

    public function testTwoProvidersCannotClaimTheSameAnnotation(): void
    {
        ProviderTable::register($this->definition('App\\Count'));

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('claimed by both');

        ProviderTable::register($this->definition('app\\count'));
    }

    public function testResetClearsTheTableAndBumpsTheGeneration(): void
    {
        ProviderTable::register($this->definition('App\\Count'));
        ProviderTable::setFingerprint('abc');
        $generation = ProviderTable::generation();

        ProviderTable::reset();

        $this->assertFalse(ProviderTable::active());
        $this->assertSame('', ProviderTable::fingerprint());
        $this->assertGreaterThan($generation, ProviderTable::generation());
        $this->assertNull(CompileTimeAttributeRegistry::get('App\\Count'));
    }

    public function testTargetMappingMatchesTheRegistryVocabulary(): void
    {
        $expected = [
            'class' => CompileTimeAttributeRegistry::TARGET_NAMED_CLASS,
            'trait' => CompileTimeAttributeRegistry::TARGET_TRAIT,
            'enum' => CompileTimeAttributeRegistry::TARGET_ENUM,
            'property' => CompileTimeAttributeRegistry::TARGET_DECLARED_PROPERTY,
            'method' => CompileTimeAttributeRegistry::TARGET_METHOD,
        ];
        foreach ($expected as $target => $registryTarget) {
            $definition = new ProviderDefinition('App\\Count', $target, new \stdClass(), 'App\\CountProvider', '/tmp/x.php');
            $this->assertSame([$registryTarget], $definition->registryDefinition()['targets']);
            $this->assertSame(CompileTimeAttributeRegistry::PHASE_PROVIDER, $definition->registryDefinition()['phase']);
            $this->assertFalse($definition->registryDefinition()['preserve_in_library_stub']);
        }
    }

    private function definition(string $attribute): ProviderDefinition
    {
        return new ProviderDefinition($attribute, 'class', new \stdClass(), 'App\\Provider', '/tmp/x.php');
    }
}
