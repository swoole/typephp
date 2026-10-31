<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Stamp::class, target: 'trait')]
final class StampProvider
{
    public function compile(Generator $g): void
    {
        $info = $g->classInfo();

        $g->member(
            'public function stamped(): string { return ' . var_export('stamp:' . $info->fqcn, true) . '; }',
        );
    }
}
