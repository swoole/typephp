--TEST--
Typed Closure explicit and nullsafe invoke calls resolve per object and preserve argument semantics
--FILE--
<?php

final class ExplicitInvokeHolder
{
    public ?Closure $callback = null;
}

function explicitNullableInvoke(?Closure $callback, int $value): mixed
{
    return $callback?->__invoke($value);
}
function explicitOrdinaryInvoke(?Closure $callback, int $value): mixed
{
    return $callback->__INVOKE($value);
}
function explicitNamedInvoke(?Closure $callback, int $value): mixed
{
    return $callback?->__invoke(value: $value);
}
function explicitUnpackedInvoke(?Closure $callback, array $values): mixed
{
    return $callback?->__invoke(...$values);
}
function explicitReferenceInvoke(Closure $callback, mixed &$value): mixed
{
    return $callback->__invoke(std::ref($value));
}
function explicitZeroInvoke(?Closure $callback): mixed
{
    return $callback?->__invoke();
}
function explicitPropertyInvoke(ExplicitInvokeHolder $holder, int $value): mixed
{
    return $holder->callback?->__invoke($value);
}

function explicitArgument(int &$count): int
{
    ++$count;
    return 3;
}
function explicitReceiver(int &$count): Closure
{
    ++$count;
    return static fn(int $value): int => $value + 30;
}
function main(): void
{
    $first = static fn(int $value): int => $value + 1;
    $second = static fn(int $value): int => $value + 10;
    foreach ([$first, $second, $first, $second] as $callback) {
        var_dump(explicitNullableInvoke($callback, 2));
    }
    var_dump(explicitNullableInvoke(null, 2));
    var_dump(explicitOrdinaryInvoke($first, 2), explicitNamedInvoke($second, 3));
    $sum = static fn(int $left, int $right): int => $left + $right;
    var_dump(explicitUnpackedInvoke($sum, [3, 4]));
    $reference = static function (mixed &$value): mixed { $value = 'updated'; return $value; };
    $value = std::any(1);
    var_dump(explicitReferenceInvoke($reference, $value), $value);
    var_dump(explicitZeroInvoke(static fn(): int => 42), explicitZeroInvoke(null));
    $holder = new ExplicitInvokeHolder();
    var_dump(explicitPropertyInvoke($holder, 4));
    $holder->callback = $second;
    var_dump(explicitPropertyInvoke($holder, 4));
    $count = 0;
    $missing = std::any(null);
    var_dump($missing?->__invoke(explicitArgument($count)), $count);
    var_dump(explicitReceiver($count)->__invoke(3), $count);
    $internal = Closure::fromCallable('strlen');
    var_dump($internal->__invoke('abc'));
    try { explicitOrdinaryInvoke(null, 1); }
    catch (Error $error) { echo "ordinary null checked\n"; }
}
?>
--EXPECT--
int(3)
int(12)
int(3)
int(12)
NULL
int(3)
int(13)
int(7)
string(7) "updated"
string(7) "updated"
int(42)
NULL
NULL
int(14)
NULL
int(0)
int(33)
int(1)
int(3)
ordinary null checked
