--TEST--
Namespaced PHP_INT_MAX shadows the global constant in unqualified fetches
--FILE--
<?php

namespace N {
    const PHP_INT_MAX = 5;

    function shadowed(): int
    {
        return PHP_INT_MAX + 1;
    }

    function globalValue(): float
    {
        return \PHP_INT_MAX + 1;
    }
}

namespace {
    function main(): void
    {
        var_dump(\N\shadowed());
        var_dump(\N\globalValue());
        var_dump(PHP_INT_MAX + 1);
    }
}
?>
--EXPECT--
int(6)
float(9.223372036854776E+18)
float(9.223372036854776E+18)
