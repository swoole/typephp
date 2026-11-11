--TEST--
Interface specialization uses an exact class guard and retains Zend fallback
--FILE--
<?php

interface GuardedCounter
{
    public function countUp(): int;
}

class GuardedCounterImpl implements GuardedCounter
{
    public function countUp(): int { return 3; }
}

final class GuardedCounterHolder
{
    public function __construct(public GuardedCounter $counter) {}
}

function guardedInterfaceCall(GuardedCounter $counter): int { return $counter->countUp(); }
function guardedNullableCall(?GuardedCounter $counter): int { return $counter->countUp(); }
function guardedPropertyCall(GuardedCounterHolder $holder): int { return $holder->counter->countUp(); }

interface GuardedMultiCounter
{
    public function countUp(): int;
}

final class GuardedFirstCounter implements GuardedMultiCounter
{
    public function countUp(): int { return 5; }
}

final class GuardedSecondCounter implements GuardedMultiCounter
{
    public function countUp(): int { return 7; }
}

function guardedMultiCall(GuardedMultiCounter $counter): int { return $counter->countUp(); }

interface GuardedOptionalCounter
{
    public function countUp(int $step = 1): int;
}

final class GuardedOptionalImpl implements GuardedOptionalCounter
{
    public function countUp(int $step = 9): int { return $step; }
}

function guardedDefaultCall(GuardedOptionalCounter $counter): int { return $counter->countUp(); }

function main(): void
{
    $counter = new GuardedCounterImpl();
    var_dump(guardedInterfaceCall($counter), guardedPropertyCall(new GuardedCounterHolder($counter)));

    $makeChild = eval('return static function () { return new class extends GuardedCounterImpl {
        public function countUp(): int { return 13; }
    }; };');
    $makeOther = eval('return static function () { return new class implements GuardedCounter {
        public function countUp(): int { return 17; }
    }; };');
    var_dump(guardedInterfaceCall($makeChild()), guardedInterfaceCall($makeOther()), guardedInterfaceCall($counter));

    try {
        guardedNullableCall(null);
    } catch (Error $error) {
        echo "null receiver error\n";
    }
    var_dump(guardedMultiCall(new GuardedFirstCounter()), guardedMultiCall(new GuardedSecondCounter()));
    var_dump(guardedDefaultCall(new GuardedOptionalImpl()));
}
?>
--EXPECT--
int(3)
int(3)
int(13)
int(17)
int(3)
null receiver error
int(5)
int(7)
int(9)
