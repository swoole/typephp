<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Clamped::class, target: 'property')]
final class ClampedProvider
{
    public function compile(Generator $g): void
    {
        [$min, $max] = $g->args();

        $g->property()->setter(
            'if ($value < ' . $min . ') { $value = ' . $min . '; }'
            . ' if ($value > ' . $max . ') { $value = ' . $max . '; }'
            . ' {{call}}',
        );
    }
}
