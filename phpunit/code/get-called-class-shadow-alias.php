<?php

namespace ExternalFunctions;

function shadow(): string
{
    return 'shadow';
}

namespace CalledAlias;

use function ExternalFunctions\shadow as GET_CALLED_CLASS;

class Probe
{
    public function name(): string
    {
        return gEt_CaLlEd_CLaSs();
    }
}
