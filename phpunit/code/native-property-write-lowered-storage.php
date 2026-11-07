<?php

class NativePropertyWriteSource
{
    public int $number = 42;
    public float $amount = 1.25;
}

class NativePropertyWriteLoweredStorageBox
{
    public int $number = 0;
    public float $amount = 0.0;
    public static int $staticNumber = 0;
    public static float $staticAmount = 0.0;

    public function testAssignOps(NativePropertyWriteSource $source): void
    {
        $this->number += $source->number;
        $this->amount += $source->amount;
        self::$staticNumber += $source->number;
        self::$staticAmount += $source->amount;
    }

    public function testExpressions(NativePropertyWriteSource $source, bool $cond): void
    {
        $this->number = $source->number + 1;
        $this->amount = $source->amount + 0.5;
        $this->number = -$source->number;
        $this->number = $cond ? $source->number : 0;
        $this->number = $source->number ?: 10;
    }
}
