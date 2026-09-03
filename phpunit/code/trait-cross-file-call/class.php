<?php

namespace Lib;

class ClassB
{
    use TraitA;

    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
