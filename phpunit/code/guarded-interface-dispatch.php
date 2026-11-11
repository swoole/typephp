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
