<?php

namespace NativeClassIntrospection;

#[\Native]
class NativeGetParentClassNamespacedBase {}

#[\Native]
class NativeGetParentClassNamespaced extends NativeGetParentClassNamespacedBase
{
    public function parentName(): string|false
    {
        return gEt_PaReNt_ClAsS($this);
    }
}
