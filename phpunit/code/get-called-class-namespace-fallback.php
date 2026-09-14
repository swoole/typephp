<?php

namespace CalledClassKnown;

function get_called_class(): string
{
    return 'known';
}

class KnownProbe
{
    public function name(): string
    {
        return get_called_class();
    }
}

namespace CalledClassDynamic;

class DynamicProbe
{
    public function name(): string
    {
        return get_called_class();
    }
}
