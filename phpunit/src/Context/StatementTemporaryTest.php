<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class StatementTemporaryTest extends TestCase
{
    private CompilerTest $compiler;

    protected function setUp(): void
    {
        global $translator;
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $this->compiler;
        $this->invoke('resetFunction');
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($this->compiler, $method))->invoke($this->compiler, ...$arguments);
    }

    private function context(): FunctionContext
    {
        return (new \ReflectionProperty($this->compiler, 'context'))->getValue($this->compiler);
    }

    public function testOperandWritebackFinishesBeforeConsumptionAndTemporaryRelease(): void
    {
        $temporary = $this->invoke('addTmpVar', Type::VAR);
        $buffer = $this->context()->statements;
        $buffer->addBefore('outer_prepare();');
        $buffer->addAfter('outer_cleanup();');
        $this->invoke('materializeStatementTemporary', $temporary, 'make_value()', ['operand_writeback();']);

        $emitted = [...$buffer->takeBefore(), 'consume(' . $temporary . ');', ...$buffer->takeAfter()];
        self::assertSame([
            'outer_prepare();',
            $temporary . ' = make_value();',
            'operand_writeback();',
            'consume(' . $temporary . ');',
            'outer_cleanup();',
            $temporary . '.unset();',
        ], $emitted);
    }

    public function testReferencePreservationResetsTheOldAliasBeforeCopyingTheNewReference(): void
    {
        $temporary = $this->invoke('addTmpVar', Type::VAR);
        $buffer = $this->context()->statements;
        $this->invoke('materializeStatementTemporary', $temporary, 'reference_key()', [], true);
        $emitted = [...$buffer->takeBefore(), 'evaluate_value();', 'insert(' . $temporary . ');', ...$buffer->takeAfter()];
        self::assertSame([
            $temporary . '.unset();',
            $temporary . '.copyFrom(php::Var(reference_key()).const_ptr());',
            'evaluate_value();',
            'insert(' . $temporary . ');',
            $temporary . '.unset();',
        ], $emitted);
        // The same function-scoped slot may be reused on the next iteration.
        $this->invoke('assignTemporaryValue', $temporary, 'next_reference()', true);
        self::assertSame([
            $temporary . '.unset();',
            $temporary . '.copyFrom(php::Var(next_reference()).const_ptr());',
        ], $buffer->takeBefore());
    }

    public function testWritableReferenceContainerUsesBindingAssignmentInsteadOfValueCopy(): void
    {
        $temporary = $this->invoke('addTmpVar', Type::REF);
        $this->invoke('materializeStatementTemporary', $temporary, 'reference_container()');
        self::assertSame([$temporary . ' = reference_container();'], $this->context()->statements->takeBefore());
        self::assertSame([$temporary . '.unset();'], $this->context()->statements->takeAfter());
        self::assertSame(Type::REF, $this->context()->localVars[$temporary]);
    }

    public function testNativeScalarTemporariesDoNotGenerateBoxingOrCleanup(): void
    {
        foreach ([Type::INT, Type::FLOAT, Type::BOOL] as $type) {
            $temporary = $this->invoke('addTmpVar', $type);
            $this->invoke('materializeStatementTemporary', $temporary, 'native_value()');
            self::assertSame([$temporary . ' = native_value();'], $this->context()->statements->takeBefore());
            self::assertSame([], $this->context()->statements->takeAfter());
            self::assertSame($type, $this->context()->localVars[$temporary]);
        }
    }

    public function testNativeObjectTemporariesUsePointerStorageAndReleaseTheirRoots(): void
    {
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/native-class-property-unset.php';
        $this->compiler->addFiles([$source]);
        $this->compiler->prepareFile($source);
        $this->invoke('resetFunction');
        $temporary = $this->invoke('addObjectTmpVar', 'NativeUnsetProperty', Type::VAR);
        $this->invoke('materializeStatementTemporary', $temporary, 'make_native()', ['finish_operand();']);

        self::assertSame(
            $this->invoke('getNativeObjectPointerType', 'NativeUnsetProperty'),
            $this->context()->localVars[$temporary],
        );
        self::assertSame('NativeUnsetProperty', $this->context()->nativeObjects[$temporary]);
        self::assertSame('NativeUnsetProperty', $this->context()->objects[$temporary]);
        self::assertSame([
            $temporary . ' = make_native();',
            'finish_operand();',
        ], $this->context()->statements->takeBefore());
        self::assertSame([$temporary . ' = nullptr;'], $this->context()->statements->takeAfter());
    }

    public function testStringAndArrayTemporariesReleaseTheirOwnedStorageAtStatementEnd(): void
    {
        foreach ([Type::STR, Type::ARRAY] as $type) {
            $temporary = $this->invoke('addTmpVar', $type);
            $this->invoke('materializeStatementTemporary', $temporary, 'make_owned_value()');
            self::assertSame([$temporary . ' = make_owned_value();'], $this->context()->statements->takeBefore());
            self::assertSame([$temporary . '.unset();'], $this->context()->statements->takeAfter());
        }
    }

    public function testZendObjectTemporariesKeepTheirRequestedStorageAndDispatchClass(): void
    {
        foreach ([Type::OBJECT, Type::VAR] as $storage) {
            $temporary = $this->invoke('addObjectTmpVar', 'stdClass', $storage);
            $this->invoke('materializeStatementTemporary', $temporary, 'make_object()');
            self::assertSame($storage, $this->context()->localVars[$temporary]);
            self::assertSame('stdClass', $this->context()->objects[$temporary]);
            self::assertArrayNotHasKey($temporary, $this->context()->nativeObjects);
            self::assertSame([$temporary . ' = make_object();'], $this->context()->statements->takeBefore());
            self::assertSame([$temporary . '.unset();'], $this->context()->statements->takeAfter());
        }
    }

    public function testDeferredWritebackControlsReceiverAndCurrentValueReleaseOrder(): void
    {
        $receiver = $this->invoke('addTmpVar', Type::VAR);
        $current = $this->invoke('addTmpVar', Type::VAR);
        $buffer = $this->context()->statements;
        $this->invoke('assignTemporaryValue', $receiver, 'make_receiver()');
        $this->invoke('assignTemporaryValue', $current, 'read_property(' . $receiver . ')');
        self::assertSame([], $buffer->takeAfter());

        $this->invoke('scheduleTemporaryCleanup', $current);
        $this->invoke('scheduleTemporaryCleanup', $receiver);
        $emitted = [...$buffer->takeBefore(), 'write_property(' . $receiver . ');', ...$buffer->takeAfter()];
        self::assertSame([
            $receiver . ' = make_receiver();',
            $current . ' = read_property(' . $receiver . ');',
            'write_property(' . $receiver . ');',
            $current . '.unset();',
            $receiver . '.unset();',
        ], $emitted);
    }
}
