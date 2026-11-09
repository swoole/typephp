<?php

namespace EvalObjectContext;

class EvalTarget
{
    public int $value = 0;

    public function mutate(): void
    {
        $this->value = eval('return 100;');
    }

    public function includeMutate(int $parameter = 1): void
    {
        $local = $parameter;
        $this->value = include __DIR__ . '/eval-object-context/included.php';
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

    public function requireFile(int $parameter): void
    {
        $local = $parameter;
        require __DIR__ . '/eval-object-context/included.php';
    }

    public function includeOnce(int $parameter): void
    {
        $local = $parameter;
        include_once __DIR__ . '/eval-object-context/included.php';
    }

    public function requireOnce(int $parameter): void
    {
        $local = $parameter;
        require_once __DIR__ . '/eval-object-context/included.php';
    }

    public function stableEval(): int
    {
        $this->value = 1;
        eval('return 42;');
        $this->value += 1;
        return $this->value;
    }

    public function stableInclude(): int
    {
        $this->value = 1;
        include __DIR__ . '/eval-object-context/included.php';
        $this->value += 1;
        return $this->value;
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
