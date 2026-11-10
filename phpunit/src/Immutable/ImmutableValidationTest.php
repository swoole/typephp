<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Immutable;

use TypePhp\Exception\TestError;

/**
 * @internal
 * @coversNothing
 */
final class ImmutableValidationTest extends \BaseTest
{
    public function testRejectsPropertyWriteInImmutableMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot modify immutable value `$this`');
        $this->compile('immutable-method-property-write.php');
    }

    public function testRejectsImmutableParameterReassignment(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot modify immutable value `$value`');
        $this->compile('immutable-parameter-reassign.php');
    }

    public function testRejectsMutableMethodCallOnImmutableThis(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableMethodCallsMutable::mutate()` on immutable value `$this`');
        $this->compile('immutable-method-calls-mutable.php');
    }

    public function testRejectsMutableMethodCallOnImmutableObjectParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableObjectParameterTarget::mutate()` on immutable value `$target`');
        $this->compile('immutable-object-parameter-calls-mutable.php');
    }

    public function testRejectsImmutableObjectPassedToMutableParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Immutable object `$value` requires an #[Immutable] parameter');
        $this->compile('immutable-object-passed-to-mutable-parameter.php');
    }

    public function testRejectsImmutablePropertyPassedToBuiltinReferenceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot pass immutable value `$this` to reference parameter 1 of sort()');
        $this->compile('immutable-property-byref-builtin.php');
    }

    public function testRejectsImmutableArrayPassedToBuiltinReferenceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot pass immutable value `$values` to reference parameter 1 of sort()');
        $this->compile('immutable-array-byref-builtin.php');
    }

    public function testImmutableObjectAliasesRemainImmutable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableAliasTarget::mutate()` on immutable value `$alias`');
        $this->compile('immutable-object-alias-mutation.php');
    }

    public function testMethodOverrideCannotDropImmutableContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Declaration of `ImmutableOverrideChild::read()` must be compatible');
        $this->compile('immutable-method-override-drops-contract.php');
    }

    public function testParameterOverrideCannotDropImmutableContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Declaration of `ImmutableParameterChild::inspect()` must be compatible');
        $this->compile('immutable-parameter-override-drops-contract.php');
    }

    public function testClosureCapturePreservesImmutableBinding(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot modify immutable value `$target`');
        $this->compile('immutable-closure-capture-mutation.php');
    }

    public function testForeachByReferenceCannotEscapeImmutableArray(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot modify immutable value `$values`');
        $this->compile('immutable-write-forms.php');
    }

    /** @dataProvider immutableWriteProvider */
    public function testRejectsAdditionalImmutableWriteForms(string $fixture, string $variable): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage("Cannot modify immutable value `\${$variable}`");
        $this->compile($fixture);
    }

    public static function immutableWriteProvider(): array
    {
        return [
            'compound array write' => ['immutable-compound-write.php', 'values'],
            'unset property' => ['immutable-unset.php', 'value'],
            'take reference' => ['immutable-reference.php', 'values'],
            'destructuring assignment' => ['immutable-destructuring-write.php', 'values'],
        ];
    }

    public function testRightAssociativeObjectAliasesRemainImmutable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableChainAliasTarget::mutate()` on immutable value `$first`');
        $this->compile('immutable-chain-alias.php');
    }

    public function testImmutableObjectCannotBeStoredInMutableProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Immutable object `$value` cannot be stored in mutable state');
        $this->compile('immutable-object-storage-escape.php');
    }

    public function testImmutableObjectCannotEscapeAsMutableReturnValue(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Immutable object `$value` cannot escape through a return value');
        $this->compile('immutable-object-return-escape.php');
    }

    public function testGeneratorBodyPreservesImmutableParameters(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableGeneratorTarget::mutate()` on immutable value `$target`');
        $this->compile('immutable-generator-context.php');
    }

    public function testClosureInImmutableMethodPreservesImmutableThis(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableClosureThis::mutate()` on immutable value `$this`');
        $this->compile('immutable-closure-this.php');
    }

    /** @dataProvider generatorClosureImmutableBindings */
    public function testGeneratorClosureKeepsImmutableBindings(string $fixture, string $binding): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot modify immutable value `' . $binding . '`');
        $this->compile($fixture);
    }

    public static function generatorClosureImmutableBindings(): iterable
    {
        yield 'capture' => ['immutable-generator-closure-capture.php', '$value'];
        yield 'parameter' => ['immutable-generator-closure-parameter.php', '$value'];
        yield 'receiver' => ['immutable-generator-closure-this.php', '$this'];
        yield 'nested capture' => ['immutable-generator-nested-closure.php', '$values'];
    }

    public function testPropertyHookMustDeclareImmutableContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method');
        $this->compile('immutable-mutable-property-hook.php');
    }

    public function testConstructorMustAcceptImmutableObjectContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Immutable object `$value` requires an #[Immutable] parameter');
        $this->compile('immutable-constructor-parameter.php');
    }

    public function testRejectsMutatingArrayExtensionMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutating method `sort()` on immutable value `$values`');
        $this->compile('immutable-array-mutating-method.php');
    }

    public function testExtensionReceiverMustDeclareImmutableContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot call mutable method `ImmutableExtensionTarget::touch()`');
        $this->compile('immutable-mutable-extension-method.php');
    }
}
