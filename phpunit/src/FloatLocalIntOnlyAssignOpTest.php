<?php

/**
 * PHP turns a float operand of %=, &=, |=, ^=, <<= or >>= into an int and the
 * result is an int, so a native float local would have to change type. That is
 * rejected at compile time instead of emitting a C++ operator that does not
 * exist for double.
 */
class FloatLocalIntOnlyAssignOpTest extends BaseTest
{
    public function testIntOnlyCompoundAssignmentOnNativeFloatLocalIsRejected(): void
    {
        $this->exec("Cannot apply %= to a native float variable: PHP converts the result to int", "float_local_int_only_assign_op.php");
    }
}
