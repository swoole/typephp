<?php

namespace NativeClassIntrospectionShadow;

function get_class(): string
{
    return 'class';
}

function get_parent_class(): string
{
    return 'parent';
}

#[\Native]
class NativeGetClassShadow
{
    public function names(): array
    {
        return [get_class(), get_parent_class()];
    }
}
