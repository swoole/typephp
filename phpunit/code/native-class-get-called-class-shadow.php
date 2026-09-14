<?php

namespace NativeCalledClassShadow;

function get_called_class(): string
{
    return 'shadow';
}

#[\Native]
class NativeGetCalledClassShadow
{
    public function name(): string
    {
        return get_called_class();
    }
}
