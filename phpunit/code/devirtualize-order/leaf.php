<?php

namespace OrderTest;

class Leaf extends Mid
{
    protected function perform(): string
    {
        return 'leaf';
    }
}
