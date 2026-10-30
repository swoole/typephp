<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Described::class, target: 'class')]
final class DescribedProvider
{
    public function compile(Generator $g): void
    {
        $info = $g->classInfo();

        $properties = [];
        foreach ($g->properties() as $property) {
            $properties[] = $property->name . ':' . $property->visibility . ':' . ($property->type ?? 'mixed')
                . ($property->hasDefault ? '=' : '');
        }

        $promoted = [];
        foreach ($g->methods() as $method) {
            if (!$method->isConstructor()) {
                continue;
            }
            foreach ($method->params as $parameter) {
                if ($parameter->promoted) {
                    $promoted[] = $parameter->name;
                }
            }
        }

        $describe = implode('|', [
            'fqcn=' . $info->fqcn,
            'final=' . ($info->isFinal ? 'yes' : 'no'),
            'abstract=' . ($info->isAbstract ? 'yes' : 'no'),
            'readonly=' . ($info->isReadonly ? 'yes' : 'no'),
            'extends=' . ($info->extends ?? '-'),
            'implements=' . ($info->implements === [] ? '-' : implode(',', $info->implements)),
            'constants=' . ($g->constants() === [] ? '-' : implode(',', array_keys($g->constants()))),
            'methods=' . ($g->methods() === [] ? '-' : implode(',', array_keys($g->methods()))),
            'properties=' . ($properties === [] ? '-' : implode(',', $properties)),
            'promoted=' . ($promoted === [] ? '-' : implode(',', $promoted)),
            'via=' . $g->site()->attribute,
        ]);

        $g->member('public static function describe(): string { return ' . var_export($describe, true) . '; }');
    }
}
