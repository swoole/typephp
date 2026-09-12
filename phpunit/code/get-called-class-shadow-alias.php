<?php

namespace CalledAlias;

use function ExternalFunctions\shadow as GET_CALLED_CLASS;

class Probe
{
    public function name(): string
    {
        return get_called_class();
    }
}
