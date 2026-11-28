--TEST--
Generator close exits run nested finally blocks after yield and yield from
--FILE--
<?php
function close_nested_generator(bool $delegate): iterable
{
    try {
        try {
            try {
                if ($delegate) {
                    yield from [10, 20];
                } else {
                    yield 1;
                }
                echo "resumed\n";
            } catch (Throwable $error) {
                echo "WRONG:close caught\n";
            }
        } finally {
            echo "inner\n";
        }
    } finally {
        echo "outer\n";
    }
}

function close_catch_generator(): iterable
{
    try {
        throw new RuntimeException('caught');
    } catch (RuntimeException $error) {
        yield $error->getMessage();
        echo "WRONG:catch resumed\n";
    } finally {
        echo "catch-finally\n";
    }
}

function close_throwing_generator(): iterable
{
    try {
        try {
            yield 5;
        } finally {
            echo "throwing-finally\n";
            throw new LogicException('cleanup');
        }
    } finally {
        echo "throwing-outer\n";
    }
}

function main(): void
{
    foreach ([false, true] as $delegate) {
        $generator = close_nested_generator($delegate);
        var_dump($generator->current());
        unset($generator);
        gc_collect_cycles();
    }

    $generator = close_catch_generator();
    var_dump($generator->current());
    unset($generator);
    gc_collect_cycles();

    $generator = close_throwing_generator();
    var_dump($generator->current());
    try {
        unset($generator);
    } catch (LogicException $error) {
        echo $error->getMessage(), "\n";
    }

    $factory = function (): iterable {
        try {
            yield from [3, 4];
            echo "WRONG:closure resumed\n";
        } finally {
            echo "closure-finally\n";
        }
    };
    $generator = $factory();
    var_dump($generator->current());
    unset($generator);
    gc_collect_cycles();

    // A normally resumed generator still executes its body and each cleanup.
    $generator = close_nested_generator(false);
    var_dump($generator->current());
    $generator->next();
    var_dump($generator->valid());
}
?>
--EXPECT--
int(1)
inner
outer
int(10)
inner
outer
string(6) "caught"
catch-finally
int(5)
throwing-finally
throwing-outer
cleanup
int(3)
closure-finally
int(1)
resumed
inner
outer
bool(false)
