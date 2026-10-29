<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use TypePhp\Transform\CompileTimeAttributeRegistry;

final class ProviderDefinition
{
    public const TARGETS = ['class', 'trait', 'enum', 'property', 'method'];

    public function __construct(
        public readonly string $attribute,
        public readonly string $target,
        public readonly object $provider,
        public readonly string $providerClass,
        public readonly string $file,
    ) {
    }

    public function shortName(): string
    {
        $position = strrpos($this->attribute, '\\');

        return $position === false ? $this->attribute : substr($this->attribute, $position + 1);
    }

    public function registryTarget(): string
    {
        return match ($this->target) {
            'class' => CompileTimeAttributeRegistry::TARGET_NAMED_CLASS,
            'trait' => CompileTimeAttributeRegistry::TARGET_TRAIT,
            'enum' => CompileTimeAttributeRegistry::TARGET_ENUM,
            'property' => CompileTimeAttributeRegistry::TARGET_DECLARED_PROPERTY,
            'method' => CompileTimeAttributeRegistry::TARGET_METHOD,
        };
    }

    /** @return array<string, mixed> */
    public function registryDefinition(): array
    {
        return [
            'name' => $this->attribute,
            'targets' => [$this->registryTarget()],
            'target_error' => '#[' . $this->shortName() . '] can only be applied to '
                . ($this->target === 'enum' ? 'an enum' : 'a ' . $this->target),
            'repeatable' => false,
            'argument_parser' => CompileTimeAttributeRegistry::ARGUMENTS_PROVIDER,
            'conflicts' => [],
            'phase' => CompileTimeAttributeRegistry::PHASE_PROVIDER,
            'preserve_in_library_stub' => false,
        ];
    }
}
