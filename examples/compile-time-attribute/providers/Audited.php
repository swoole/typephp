<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Audited::class, target: 'class')]
final class AuditedProvider
{
    public function compile(Generator $g): void
    {
        $g->member('private int $writes = 0;');
        $g->member('public function writes(): int { return $this->writes; }');

        foreach ($g->properties() as $property) {
            if (!$property->isPublic()
                || $property->isStatic
                || $property->type !== 'int'
                || !$property->hasDefault
            ) {
                continue;
            }
            $g->property($property->name)->setter('$this->writes++; {{call}}');
        }
    }
}
