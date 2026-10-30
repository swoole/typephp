<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Entity::class, target: 'class')]
final class EntityProvider
{
    public function compile(Generator $g): void
    {
        $short = $g->classInfo()->shortName;

        $g->member('public const KIND = ' . var_export(strtolower($short), true) . ';');
        $g->member('public function kind(): string { return self::KIND; }');
        $g->member('use \Shop\HasSku;');
        $g->member('private array $history = [];');
        $g->member('public function history(): array { return $this->history; }');
        $g->member('public function record(string $what): void { $this->history[] = $what; }');

        foreach ($g->properties() as $property) {
            if (!$property->isPublic() || $property->isStatic || !$property->hasDefault) {
                continue;
            }
            $g->member(
                'public function get' . ucfirst($property->name) . '(): ' . ($property->type ?? 'mixed')
                . ' { return $this->' . $property->name . '; }',
            );
        }
    }
}
