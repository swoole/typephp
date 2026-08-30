--TEST--
round() with an explicit mode is validated by Zend, not by the Native wrapper
--FILE--
<?php
function main()
{
    // A valid legacy mode still produces PHP's result.
    var_dump(round(2.5, 0, PHP_ROUND_HALF_DOWN));
    var_dump(round(3.5, 0, PHP_ROUND_HALF_ODD));

    // An out-of-range integer mode must raise ValueError. The Native wrapper
    // calls _php_math_round() directly, where the same value aborts the
    // process inside php_round_helper.
    try {
        var_dump(round(2.5, 0, 99));
        echo "value-error-not-thrown\n";
    } catch (ValueError $e) {
        echo "caught=", $e->getMessage(), "\n";
    }
    try {
        var_dump(round(2.5, 0, 0));
        echo "value-error-not-thrown\n";
    } catch (ValueError $e) {
        echo "caught=", $e->getMessage(), "\n";
    }

    // A statically typed int says nothing about the runtime value either.
    $mode = 99;
    try {
        var_dump(round(2.5, 0, $mode));
        echo "value-error-not-thrown\n";
    } catch (ValueError $e) {
        echo "caught=", $e->getMessage(), "\n";
    }

    // Unpacking hides the real arity from the syntactic argument count.
    $all = [2.5, 0, PHP_ROUND_HALF_DOWN];
    var_dump(round(...$all));
    $tail = [0, PHP_ROUND_HALF_DOWN];
    var_dump(round(2.5, ...$tail));

    // Calls without an explicit mode keep the native wrapper.
    var_dump(round(2.5));
    var_dump(round(2.567, 2));
}
?>
--EXPECT--
float(2)
float(3)
caught=round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)
caught=round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)
caught=round(): Argument #3 ($mode) must be a valid rounding mode (RoundingMode::*)
float(2)
float(2)
float(3)
float(2.57)
