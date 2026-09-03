<?php

namespace Lib;

trait TraitA
{
    public function getAttribute(string $cls): string
    {
        return $cls;
    }
}
