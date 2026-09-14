<?php

#[\Native]
class NativeGetCalledClassQualified
{
    public function name(): string
    {
        return \GET_CALLED_CLASS();
    }
}
