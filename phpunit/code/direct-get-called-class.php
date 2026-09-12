<?php

class DirectCalledBase
{
    final public function instanceName(): string
    {
        return get_called_class();
    }

    public static function staticName(): string
    {
        return \GET_CALLED_CLASS();
    }
}

class DirectCalledChild extends DirectCalledBase
{
}

function directCalledName(DirectCalledBase $object): string
{
    return $object->instanceName();
}
