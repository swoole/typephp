<?php

class ReentrantWriteHolder
{
    public array $rows = [[1]];

    public function rhs(): int
    {
        unset($this->rows[0]);
        return 7;
    }

    public function assign(): void
    {
        $this->rows[writeOuterKey()][writeInnerKey()] = $this->rhs();
    }

    public function compound(): void
    {
        $this->rows[writeOuterKey()][writeInnerKey()] += $this->rhs();
    }

    public function variableKeys(): void
    {
        $key = 'old';
        $this->rows[$key][0] = ($key = 'new');
    }
}

function writeOuterKey(): int
{
    return 0;
}

function writeInnerKey(): int
{
    return 0;
}

function main(): void
{
    $holder = new ReentrantWriteHolder();
    $holder->assign();
}
