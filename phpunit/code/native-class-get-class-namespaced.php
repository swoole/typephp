<?php

namespace NativeClassIntrospection;

#[\Native]
class NativeGetClassNamespaced
{
    public function name(): string
    {
        return gEt_CLaSs();
    }
}
