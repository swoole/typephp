<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Derived::class, target: 'property')]
final class DerivedProvider
{
    public function compile(Generator $g): void
    {
        $g->property()->getter($g->args()[0]);
    }
}
