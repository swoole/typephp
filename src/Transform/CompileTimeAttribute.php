<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use TypePhp\Exception\CompileTimeAttributeError;
use TypePhp\Exception\SyntaxError;

final class CompileTimeAttribute
{
    public static function validateStdContainerParameterTarget(Node\FunctionLike $node): void
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            return;
        }
        foreach ($node->getParams() as $parameter) {
            foreach (['StdVector', 'StdMap', 'StdOrderedMap', 'StdList', 'StdDict'] as $name) {
                $attribute = self::find($parameter, $name);
                if ($attribute !== null) {
                    throw new CompileTimeAttributeError(
                        $name . ' can only be applied to named function or method parameters',
                        $parameter,
                        $name,
                        $attribute,
                    );
                }
            }
        }
    }

    public static function validateNode(Node $node): void
    {
        if (!property_exists($node, 'attrGroups')) {
            return;
        }

        $found = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $definition = CompileTimeAttributeRegistry::get(self::resolvedName($attribute));
                if ($definition === null) {
                    continue;
                }
                $key = strtolower($definition['name']);
                $found[$key][] = $attribute;
                if (!self::matchesTarget($node, $definition['targets'])) {
                    throw new CompileTimeAttributeError(
                        $definition['target_error'],
                        $node,
                        $definition['name'],
                        $attribute,
                    );
                }
                self::validateArguments($attribute, $definition['name']);
            }
        }

        foreach ($found as $attributes) {
            $definition = CompileTimeAttributeRegistry::get(self::resolvedName($attributes[0]));
            if (!$definition['repeatable'] && count($attributes) > 1) {
                throw new CompileTimeAttributeError(
                    $definition['name'] . ' cannot be repeated on the same declaration',
                    $node,
                    $definition['name'],
                    $attributes[0],
                    $definition['name'],
                    $attributes[1],
                );
            }
            foreach ($definition['conflicts'] as $conflict) {
                if (isset($found[strtolower($conflict)])) {
                    $target = $definition['targets'] === [
                        CompileTimeAttributeRegistry::TARGET_FUNCTION,
                        CompileTimeAttributeRegistry::TARGET_METHOD,
                    ] ? 'function or method' : 'declaration';
                    throw new CompileTimeAttributeError(
                        $definition['name'] . ' and ' . $conflict . ' cannot be applied to the same ' . $target,
                        $node,
                        $definition['name'],
                        $attributes[0],
                        $conflict,
                        $found[strtolower($conflict)][0],
                    );
                }
            }
        }
    }

    public static function find(Node $node, string $name): ?Node\Attribute
    {
        if (!property_exists($node, 'attrGroups')) {
            return null;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::is($attribute, $name)) {
                    return $attribute;
                }
            }
        }
        return null;
    }

    public static function has(Node $node, string $name): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::is($attribute, $name)) {
                    self::validateArguments($attribute, $name);
                    return true;
                }
            }
        }
        return false;
    }

    public static function consume(Node $node, string $name): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        $found = false;
        foreach ($node->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                if (!self::is($attribute, $name)) {
                    continue;
                }
                self::validateArguments($attribute, $name);
                $found = true;
                unset($group->attrs[$attributeIndex]);
            }
            $group->attrs = array_values($group->attrs);
            if ($group->attrs === []) {
                unset($node->attrGroups[$groupIndex]);
            }
        }
        $node->attrGroups = array_values($node->attrGroups);
        return $found;
    }

    public static function remove(Node $node, string $name): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        $found = false;
        foreach ($node->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                if (self::is($attribute, $name)) {
                    $found = true;
                    unset($group->attrs[$attributeIndex]);
                }
            }
            $group->attrs = array_values($group->attrs);
            if ($group->attrs === []) {
                unset($node->attrGroups[$groupIndex]);
            }
        }
        $node->attrGroups = array_values($node->attrGroups);
        return $found;
    }

    public static function is(Node\Attribute $attribute, string $name): bool
    {
        return strcasecmp(self::resolvedName($attribute), ltrim($name, '\\')) === 0;
    }

    public static function resolvedName(Node\Attribute $attribute): string
    {
        $resolvedName = $attribute->name->getAttribute('resolvedName')
            ?? $attribute->name->getAttribute('namespacedName')
            ?? $attribute->name;
        return ltrim($resolvedName->toString(), '\\');
    }

    private static function validateArguments(Node\Attribute $attribute, string $name): void
    {
        $definition = CompileTimeAttributeRegistry::get($name);
        if ($definition !== null
            && $definition['argument_parser'] === CompileTimeAttributeRegistry::ARGUMENTS_NONE
            && $attribute->args !== []) {
            throw new SyntaxError($name . ' does not accept arguments');
        }
    }

    /** @param list<string> $targets */
    private static function matchesTarget(Node $node, array $targets): bool
    {
        if (in_array(CompileTimeAttributeRegistry::TARGET_CLASS, $targets, true)
            && $node instanceof Node\Stmt\Class_) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_NAMED_CLASS, $targets, true)
            && $node instanceof Node\Stmt\Class_ && $node->name !== null) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_CLASS_LIKE, $targets, true)
            && $node instanceof Node\Stmt\ClassLike) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_TRAIT, $targets, true)
            && $node instanceof Node\Stmt\Trait_) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_ENUM, $targets, true)
            && $node instanceof Node\Stmt\Enum_) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_FUNCTION, $targets, true)
            && $node instanceof Node\Stmt\Function_) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_METHOD, $targets, true)
            && $node instanceof Node\Stmt\ClassMethod) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_PROPERTY_HOOK, $targets, true)
            && $node instanceof Node\PropertyHook) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_PROPERTY, $targets, true)
            && ($node instanceof Node\Stmt\Property || ($node instanceof Node\Param && $node->isPromoted()))) {
            return true;
        }
        if (in_array(CompileTimeAttributeRegistry::TARGET_DECLARED_PROPERTY, $targets, true)
            && $node instanceof Node\Stmt\Property) {
            return true;
        }
        return in_array(CompileTimeAttributeRegistry::TARGET_PARAMETER, $targets, true)
            && $node instanceof Node\Param;
    }
}
