--TEST--
Readonly scalar reads preserve typed storage for parameters, locals and expression receivers
--FILE--
<?php

final class ReadonlyReceiverState
{
    public function __construct(
        public readonly int $status,
        public readonly float $ratio = 0.5,
        public readonly bool $enabled = true,
    ) {
    }
}

function readonlyParameterStatus(ReadonlyReceiverState $state): string
{
    switch ($state->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyLocalStatus(int $status): string
{
    $local = new ReadonlyReceiverState($status);
    switch ($local->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyExpressionStatus(int $status): string
{
    switch ((new ReadonlyReceiverState($status))->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyParameterRatio(ReadonlyReceiverState $state): float
{
    return $state->ratio;
}

function readonlyParameterEnabled(ReadonlyReceiverState $state): bool
{
    return $state->enabled;
}

function main(): void
{
    foreach ([0, 1, 2] as $status) {
        $state = new ReadonlyReceiverState($status);
        echo readonlyParameterStatus($state), ':', readonlyLocalStatus($status), ':', readonlyExpressionStatus($status), "\n";
    }
    var_dump(readonlyParameterRatio(new ReadonlyReceiverState(1, 1.25)));
    var_dump(readonlyParameterEnabled(new ReadonlyReceiverState(1)));
    var_dump(readonlyParameterEnabled(new ReadonlyReceiverState(1, 0.5, false)));
    $uninitialized = (new ReflectionClass(ReadonlyReceiverState::class))->newInstanceWithoutConstructor();
    try {
        readonlyParameterStatus($uninitialized);
    } catch (Error $error) {
        echo "uninitialized checked\n";
    }
    try {
        readonlyParameterRatio($uninitialized);
    } catch (Error $error) {
        echo "uninitialized ratio checked\n";
    }
    try {
        readonlyParameterEnabled($uninitialized);
    } catch (Error $error) {
        echo "uninitialized enabled checked\n";
    }
}
?>
--EXPECT--
pending:pending:pending
done:done:done
unknown:unknown:unknown
float(1.25)
bool(true)
bool(false)
uninitialized checked
uninitialized ratio checked
uninitialized enabled checked
