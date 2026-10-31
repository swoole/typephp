<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Labelled::class, target: 'enum')]
final class LabelledProvider
{
    public function compile(Generator $g): void
    {
        $prefix = $g->args()[0] ?? 'enum';
        $info = $g->classInfo();

        $label = $prefix . ':' . $info->fqcn
            . ':enum=' . ($info->isEnum ? 'yes' : 'no')
            . ':backing=' . ($info->enumBackingType ?? 'none');
        $g->member('public function label(): string { return ' . var_export($label, true) . '; }');
    }
}
