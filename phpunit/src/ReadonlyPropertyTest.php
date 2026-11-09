<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

/**
 * @internal
 * @coversNothing
 */
class ReadonlyPropertyTest extends BaseTest
{
    public function testDirectInitializationIsAllowedOutsideConstructor(): void
    {
        $this->compile('readonly-write-outside-constructor.php');
        $this->compile('readonly-write-child-constructor.php');
        $this->compile('readonly-write-constructor-closure.php');
        $this->compile('readonly-write-other-instance-constructor.php');
    }

    public function testDirectCloneInitializationUsesTheSameWritePath(): void
    {
        $this->compile('readonly-write-child-clone.php');
        $this->compile('readonly-write-clone-closure.php');
        $this->compile('readonly-write-other-instance-clone.php');
    }

    public function testReadonlyPropertyCannotBeAssignedByReference(): void
    {
        $this->exec('Cannot assign readonly property `ReadonlyReferenceAssignment::$value` by reference', 'readonly-reference-assignment.php');
    }

    public function testReadonlyPropertyCannotBeTakenByReference(): void
    {
        $this->exec('Cannot take reference to readonly property `ReadonlyReferenceFetch::$value`', 'readonly-reference-fetch.php');
        $this->exec('Cannot take reference to readonly property `ReadonlyReferenceCallArgument::$value`', 'readonly-reference-call-argument.php');
    }

    public function testAllReadonlyWriteFormsOutsideConstructorAreRejected(): void
    {
        foreach ([
            'readonly-write-compound.php',
            'readonly-write-increment.php',
            'readonly-write-decrement.php',
            'readonly-write-subtract.php',
            'readonly-write-concat.php',
            'readonly-write-array-dim.php',
            'readonly-write-array-index.php',
            'readonly-write-coalesce.php',
            'readonly-write-list.php',
            'readonly-write-foreach.php',
            'readonly-write-foreach-list.php',
        ] as $file) {
            $this->exec('only supports direct assignment', $file);
        }
    }
}
