<?php

namespace NativeCalledClass;

use function \get_called_class as CALLED_CLASS;

#[\Native]
class NativeGetCalledClass
{
    public function name(): string
    {
        return cAlLeD_cLaSs();
    }
}
