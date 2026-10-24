--TEST--
Match throw arms evaluate the exception before releasing temporary receivers
--FILE--
<?php
class MatchExceptionFactory
{
    public function makeException(): RuntimeException
    {
        echo "make exception\n";
        return new RuntimeException('expected');
    }

    public function __destruct()
    {
        echo "destroy factory\n";
    }
}

function typedMatch(bool $flag): int
{
    return match ($flag) {
        true => 1,
        default => throw (new MatchExceptionFactory())?->makeException(),
    };
}

function dynamicMatch(int $flag): mixed
{
    return match ($flag) {
        1 => 1,
        2 => 'other',
        default => throw (new MatchExceptionFactory())?->makeException(),
    };
}

function allThrowMatch(): mixed
{
    return match (0) {
        default => throw (new MatchExceptionFactory())?->makeException(),
    };
}

function main(): void
{
    var_dump(typedMatch(true));
    var_dump(dynamicMatch(2));
    try {
        typedMatch(false);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        dynamicMatch(0);
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        allThrowMatch();
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
int(1)
string(5) "other"
make exception
destroy factory
RuntimeException: expected
make exception
destroy factory
RuntimeException: expected
make exception
destroy factory
RuntimeException: expected
