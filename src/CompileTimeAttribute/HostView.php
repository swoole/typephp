<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use TypePhp\Transform\PropertyHookLowering;

final class HostView
{
    public static function fqcn(Stmt\ClassLike $node): string
    {
        $namespaced = $node->namespacedName ?? null;
        if ($namespaced instanceof Node\Name) {
            return ltrim($namespaced->toString(), '\\');
        }

        return $node->name?->toString() ?? '';
    }

    public static function shortName(Stmt\ClassLike $node): string
    {
        return $node->name?->toString() ?? '';
    }

    public static function kind(Stmt\ClassLike $node): string
    {
        if ($node instanceof Stmt\Trait_) {
            return 'trait';
        }
        if ($node instanceof Stmt\Enum_) {
            return 'enum';
        }

        return 'class';
    }

    public static function classInfo(Stmt\ClassLike $node): ClassInfo
    {
        $extends = null;
        $implements = [];
        if ($node instanceof Stmt\Class_) {
            $extends = $node->extends !== null ? TypeString::ofName($node->extends) : null;
            $implements = array_map([TypeString::class, 'ofName'], $node->implements);
        } elseif ($node instanceof Stmt\Enum_) {
            $implements = array_map([TypeString::class, 'ofName'], $node->implements);
        }

        $isEnum = $node instanceof Stmt\Enum_;

        return new ClassInfo(
            self::fqcn($node),
            self::shortName($node),
            !$isEnum && $node instanceof Stmt\Class_ && $node->isFinal(),
            !$isEnum && $node instanceof Stmt\Class_ && $node->isAbstract(),
            !$isEnum && $node instanceof Stmt\Class_ && $node->isReadonly(),
            $extends,
            array_values($implements),
            $isEnum,
            $node instanceof Stmt\Enum_ ? TypeString::of($node->scalarType) : null,
        );
    }

    /** @return array<string, MethodSignature> */
    public static function methods(Stmt\ClassLike $node): array
    {
        $result = [];
        foreach ($node->getMethods() as $method) {
            if (!self::isHostMethod($method)) {
                continue;
            }
            $name = $method->name->toString();
            $result[$name] = new MethodSignature(
                $name,
                self::params($method),
                TypeString::of($method->returnType),
                $method->isStatic(),
                $method->isAbstract(),
                $method->isFinal(),
                $method->isPublic(),
                $method->isProtected(),
                $method->isPrivate(),
                $method->byRef,
            );
        }

        return $result;
    }

    /** @return array<string, PropertyInfo> */
    public static function properties(Stmt\ClassLike $node): array
    {
        $result = [];
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Property) {
                continue;
            }
            $hooks = [];
            foreach ($stmt->hooks as $hook) {
                $hooks[$hook->name->toString()] = true;
            }
            foreach ($stmt->props as $item) {
                $name = $item->name->toString();
                $result[$name] = new PropertyInfo(
                    $name,
                    TypeString::of($stmt->type),
                    $stmt->isStatic(),
                    $stmt->isReadonly(),
                    false,
                    $item->default !== null,
                    self::visibility($stmt),
                    $hooks,
                );
            }
        }

        foreach ($node->getMethods() as $method) {
            if ($method->name->toLowerString() !== '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->flags === 0) {
                    continue;
                }
                $name = $param->var->name;
                $result[$name] = new PropertyInfo(
                    $name,
                    TypeString::of($param->type),
                    false,
                    ($param->flags & Modifiers::READONLY) !== 0,
                    true,
                    $param->default !== null,
                    self::paramVisibility($param),
                    [],
                );
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function constants(Stmt\ClassLike $node): array
    {
        $result = [];
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassConst) {
                continue;
            }
            foreach ($stmt->consts as $const) {
                try {
                    $result[$const->name->toString()] = Literal::evaluate($const->value);
                } catch (ProviderException) {
                    continue;
                }
            }
        }

        return $result;
    }

    private static function isHostMethod(Stmt\ClassMethod $method): bool
    {
        if ($method->getAttribute(PropertyHookLowering::INTERNAL_METHOD_ATTRIBUTE) === true) {
            return false;
        }
        $name = $method->name->toString();
        if (str_starts_with($name, '__typephp_')) {
            return false;
        }

        return !PropertyHookLowering::isAsymmetricSetterMarkerName($name);
    }

    /** @return list<ParamInfo> */
    private static function params(Stmt\ClassMethod $method): array
    {
        $params = [];
        foreach ($method->params as $param) {
            if (!is_string($param->var->name)) {
                continue;
            }
            $params[] = new ParamInfo(
                $param->var->name,
                TypeString::of($param->type),
                $param->byRef,
                $param->variadic,
                $param->default !== null,
                $param->default !== null && self::isLiteral($param->default),
                $param->isPromoted(),
            );
        }

        return $params;
    }

    private static function isLiteral(Node\Expr $expr): bool
    {
        try {
            Literal::evaluate($expr);

            return true;
        } catch (ProviderException) {
            return false;
        }
    }

    private static function paramVisibility(Param $param): string
    {
        if (($param->flags & Modifiers::PRIVATE) !== 0) {
            return 'private';
        }
        if (($param->flags & Modifiers::PROTECTED) !== 0) {
            return 'protected';
        }

        return 'public';
    }

    private static function visibility(Stmt\Property $property): string
    {
        if ($property->isPrivate()) {
            return 'private';
        }
        if ($property->isProtected()) {
            return 'protected';
        }

        return 'public';
    }
}
