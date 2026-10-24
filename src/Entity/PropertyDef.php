<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

use PhpParser\NodeAbstract;
use PhpParser\Modifiers;

class PropertyDef
{
    public string $name;
    public string $type;
    public int $flags;
    public ?string $default = null;
    /** Declarations and statements captured while lowering a runtime default. */
    public string $defaultInit = '';
    public string $defaultClean = '';
    public ?ArrayInitPlan $arrayInitPlan = null;
    /** Original declaration AST; lowered to $default only in the convert phase. */
    public ?NodeAbstract $defaultExpr = null;
    public ?array $typedArray = null;
    public ?array $stdContainer = null;
    public bool $nullable = false;
    /** The declared type is TypePHP's unconstrained, reference-capable `any` type. */
    public bool $explicitAny = false;
    public string $class = '';
    public array $typeCheck = [];
    public string $typeStr = '';
    /** Optional fixed-width C++ storage used only by Native Class fields. */
    public string $nativeStorageType = '';
    public bool $promoted = false;
    public bool $readonly = false;
    /** The generated Zend property table cannot represent this default exactly. */
    public bool $requiresRuntimeDefaultInit = false;
    /** Cached declared-property offset used by the runtime default initializer. */
    public string $runtimeDefaultOffset = '';
    public ?string $getter = null;
    public ?string $setter = null;
    public bool $virtual = false;
    /** The source property carries TypePHP's compile-time #[Override] contract. */
    public bool $overrideRequired = false;
    public ?NodeAbstract $node = null;

    public function __construct(string $name, int $flags, string $type, ?string $default = null, bool $nullable = false)
    {
        $this->flags   = $flags;
        $this->name    = $name;
        $this->type    = $type;
        $this->default = $default;
        $this->nullable = $nullable;
    }

    public function isPrivate(): bool
    {
        return ($this->flags & Modifiers::PRIVATE) !== 0;
    }

    public function isProtected(): bool
    {
        return ($this->flags & Modifiers::PROTECTED) !== 0;
    }

    public function isPublic(): bool
    {
        return !$this->isPrivate() && !$this->isProtected();
    }

    public function isStatic(): bool
    {
        return ($this->flags & Modifiers::STATIC) !== 0;
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function isPrivateSet(): bool
    {
        return (bool) ($this->flags & Modifiers::PRIVATE_SET);
    }

    public function isProtectedSet(): bool
    {
        return (bool) ($this->flags & Modifiers::PROTECTED_SET);
    }
}
