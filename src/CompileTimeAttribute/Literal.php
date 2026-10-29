<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use PhpParser\Node;

final class Literal
{
    public static function evaluate(Node\Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\Int_
            || $expr instanceof Node\Scalar\Float_
            || $expr instanceof Node\Scalar\String_
        ) {
            return $expr->value;
        }
        if ($expr instanceof Node\Expr\UnaryMinus) {
            return -self::asNumber($expr->expr);
        }
        if ($expr instanceof Node\Expr\UnaryPlus) {
            return +self::asNumber($expr->expr);
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            return self::constant($expr->name);
        }
        if ($expr instanceof Node\Expr\Array_) {
            return self::arrayOf($expr);
        }
        if ($expr instanceof Node\Expr\ClassConstFetch) {
            return self::classConstant($expr);
        }

        throw new ProviderException(
            'attribute argument must be a literal, a literal array or ::class, got ' . $expr->getType(),
        );
    }

    private static function asNumber(Node\Expr $expr): int|float
    {
        if ($expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_) {
            return $expr->value;
        }

        throw new ProviderException('attribute argument must be a literal number, got ' . $expr->getType());
    }

    private static function constant(Node\Name $name): mixed
    {
        return match (strtolower($name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new ProviderException(
                'attribute argument must be a literal, got constant ' . $name->toString(),
            ),
        };
    }

    private static function arrayOf(Node\Expr\Array_ $expr): array
    {
        $result = [];
        foreach ($expr->items as $item) {
            if ($item === null) {
                throw new ProviderException('spread in an attribute argument array is not allowed');
            }
            $value = self::evaluate($item->value);
            if ($item->key === null) {
                $result[] = $value;
                continue;
            }
            $key = self::evaluate($item->key);
            if (!is_int($key) && !is_string($key)) {
                throw new ProviderException('array keys in an attribute argument must be int or string');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private static function classConstant(Node\Expr\ClassConstFetch $expr): string
    {
        if (!$expr->name instanceof Node\Identifier || strtolower($expr->name->toString()) !== 'class') {
            throw new ProviderException('only ::class is allowed in attribute arguments');
        }
        if (!$expr->class instanceof Node\Name) {
            throw new ProviderException('::class requires a class name');
        }

        return TypeString::ofName($expr->class);
    }
}
