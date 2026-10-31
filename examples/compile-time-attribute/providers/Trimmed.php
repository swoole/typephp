<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Trimmed::class, target: 'property')]
final class TrimmedProvider
{
    public function compile(Generator $g): void
    {
        $g->property()->setter('$value = \trim($value); {{call}}');
    }
}
