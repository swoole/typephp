<?php

namespace Consumer;

use Vendor\Package as Lib;

function imported(): mixed
{
    return Lib\runtime_function();
}

function currentNamespace(): mixed
{
    return Sub\runtime_function();
}
