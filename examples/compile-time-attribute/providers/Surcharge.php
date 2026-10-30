<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Surcharge::class, target: 'property')]
final class SurchargeProvider
{
    public function compile(Generator $g): void
    {
        $fee = $g->args()['fee'];

        $g->property()->getter('{{value}} + ' . $fee);
    }
}
