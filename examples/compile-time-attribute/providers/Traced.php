<?php

namespace Shop\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Shop\Traced::class, target: 'method')]
final class TracedProvider
{
    public function compile(Generator $g): void
    {
        $name = $g->target()->name;
        $method = $g->methods()[$name];

        $parameters = [];
        foreach ($method->params as $parameter) {
            $text = ($parameter->variadic ? '...' : '') . ($parameter->type ?? 'mixed') . ' $' . $parameter->name;
            if ($parameter->byRef) {
                $text = '&' . $text;
            }
            if ($parameter->hasDefault) {
                $text .= '=?';
            }
            $parameters[] = $text;
        }

        $modifiers = [];
        if ($method->isAbstract) {
            $modifiers[] = 'abstract';
        }
        if ($method->isFinal) {
            $modifiers[] = 'final';
        }
        $modifiers[] = $method->isPublic ? 'public' : ($method->isProtected ? 'protected' : 'private');
        if ($method->isStatic) {
            $modifiers[] = 'static';
        }

        $signature = implode(' ', $modifiers) . ' ' . ($method->returnsByRef ? '&' : '') . $name
            . '(' . implode(', ', $parameters) . '): ' . ($method->returnType ?? 'mixed');
        $g->member(
            'public function trace_' . $name . '(): string { return ' . var_export($signature, true) . '; }',
        );

        $site = $g->site();
        $origin = $site->attribute . ' on ' . $site->target
            . ' at ' . basename($site->file) . ':' . $site->line;
        $g->member(
            'public function traced_at_' . $name . '(): string { return ' . var_export($origin, true) . '; }',
        );
    }
}
