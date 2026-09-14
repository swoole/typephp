<?php

namespace NativeCalledClassShort;

#[\Native]
class NativeGetCalledClassShort
{
    public function name(): string
    {
        return gEt_CaLlEd_CLaSs();
    }
}
