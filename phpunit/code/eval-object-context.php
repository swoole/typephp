<?php

namespace EvalObjectContext;

class EvalTarget
{
    public int $value = 0;

    public function mutate(): void
    {
        eval('$this->value = 100;');
    }

    public function includeMutate(): void
    {
        include __DIR__ . '/eval-object-context/included.php';
    }

    public function runNew(): void
    {
        (new self())->mutate();
    }

    public function runVariable(EvalTarget $target): void
    {
        $target->mutate();
    }

    public function runThis(): void
    {
        $this->mutate();
    }

    public function runInclude(): void
    {
        (new self())->includeMutate();
    }

    public function runPlain(): int
    {
        $target = new PlainTarget();
        $target->poke();
        return $target->value;
    }
}

class EvalChild extends EvalTarget
{
    public function runInherited(): void
    {
        (new EvalChild())->mutate();
    }
}

class PlainTarget
{
    public int $value = 0;

    public function poke(): void
    {
        $this->value = 1;
    }
}
