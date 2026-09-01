<?php

namespace OrderTest;

class Base
{
    protected function perform(): string
    {
        return 'base';
    }

    public function delete(): string
    {
        return $this->perform();
    }
}
