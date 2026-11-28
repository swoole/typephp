--TEST--
Nested protected exits run finally once in order and outside exited catch clauses
--FILE--
<?php

function nested_try_return(): int
{
    try {
        try {
            return 22;
        } catch (Throwable $e) {
            return -22;
        }
    } finally {
        echo "try:finally\n";
    }
}

function nested_catch_return(): int
{
    try {
        try {
            throw new RuntimeException('inner');
        } catch (RuntimeException $e) {
            return 33;
        }
    } finally {
        echo "catch:finally\n";
    }
}

function nested_finally_return(): int
{
    try {
        try {
            return 44;
        } finally {
            echo "order:inner\n";
        }
    } finally {
        echo "order:outer\n";
    }
}

function finally_replaces_return(): int
{
    try {
        try {
            return 1;
        } finally {
            return 2;
        }
    } finally {
        echo "replace:outer\n";
    }
}

function finally_exception_scope(): int
{
    try {
        try {
            return 1;
        } catch (LogicException $e) {
            echo "WRONG:inner-catch\n";
            return 2;
        }
    } finally {
        echo "throw:finally\n";
        throw new LogicException('cleanup');
    }
}

function nested_loop_exits(): void
{
    for ($i = 0; $i < 3; $i++) {
        try {
            while (true) {
                try {
                    if ($i === 0) {
                        continue 2;
                    }
                    break 2;
                } finally {
                    echo 'loop:inner:', $i, "\n";
                }
            }
        } finally {
            echo 'loop:outer:', $i, "\n";
        }
    }
    try {
        while (true) {
            break;
        }
        echo "loop:after-local-break\n";
    } finally {
        echo "loop:local-finally\n";
    }
}

function nested_goto(): void
{
    try {
        try {
            goto inside;
        } finally {
            echo "goto:inner\n";
        }
        echo "WRONG:after-goto\n";
        inside:
        echo "goto:inside\n";
    } finally {
        echo "goto:outer\n";
    }
}

function repeated_finally(): void
{
    for ($i = 0; $i < 2; $i++) {
        try {
            try {
                if ($i === 0) {
                    throw new RuntimeException('first');
                }
                echo "repeat:success\n";
            } finally {
                echo 'repeat:finally:', $i, "\n";
            }
        } catch (RuntimeException $e) {
            echo 'repeat:caught:', $e->getMessage(), "\n";
        }
    }
}

function main(): void
{
    echo 'try:result:', nested_try_return(), "\n";
    echo 'catch:result:', nested_catch_return(), "\n";
    echo 'order:result:', nested_finally_return(), "\n";
    echo 'replace:result:', finally_replaces_return(), "\n";
    try {
        finally_exception_scope();
    } catch (LogicException $e) {
        echo 'throw:caught:', $e->getMessage(), "\n";
    }
    nested_loop_exits();
    nested_goto();
    repeated_finally();
}
?>
--EXPECT--
try:result:try:finally
22
catch:result:catch:finally
33
order:result:order:inner
order:outer
44
replace:result:replace:outer
2
throw:finally
throw:caught:cleanup
loop:inner:0
loop:outer:0
loop:inner:1
loop:outer:1
loop:after-local-break
loop:local-finally
goto:inner
goto:inside
goto:outer
repeat:finally:0
repeat:caught:first
repeat:success
repeat:finally:1
