<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class Generator
{
    /**
     * @param array<string, MethodSignature> $methods
     * @param array<string, PropertyInfo> $properties
     * @param array<string, mixed> $constants
     * @param array<string, mixed> $args
     */
    public function __construct(
        private readonly Target $target,
        private readonly ClassInfo $classInfo,
        private readonly array $methods,
        private readonly array $properties,
        private readonly array $constants,
        private readonly array $args,
        private readonly ProductBuffer $buffer,
        private readonly Site $site,
    ) {
    }

    public function target(): Target
    {
        return $this->target;
    }

    public function classInfo(): ClassInfo
    {
        return $this->classInfo;
    }

    /** @return array<string, mixed> */
    public function args(): array
    {
        return $this->args;
    }

    /** @return array<string, MethodSignature> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @return array<string, PropertyInfo> */
    public function properties(): array
    {
        return $this->properties;
    }

    /** @return array<string, mixed> */
    public function constants(): array
    {
        return $this->constants;
    }

    public function member(string $source): void
    {
        $this->buffer->addMember($source);
    }

    public function property(?string $name = null): PropertyBuilder
    {
        $name ??= $this->target->name;
        if ($name === null) {
            throw new ProviderException(
                'property() needs an explicit property name when the annotation targets '
                . $this->target->kind . ' ' . $this->target->fqcn,
            );
        }

        return new PropertyBuilder($name, $this->buffer);
    }

    public function error(string $message): never
    {
        throw new ProviderException($message);
    }

    public function site(): Site
    {
        return $this->site;
    }
}
