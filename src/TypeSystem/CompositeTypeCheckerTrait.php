<?php
/**
 * This file is part of TypePHP.
 *
 * Evaluates static relations for union, intersection, nullable, and literal types.
 */

namespace TypePhp\TypeSystem;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait CompositeTypeCheckerTrait
{
    protected function checkCompositeTypeAssignment(
        NodeAbstract $errorNode,
        array $typeCheck,
        string $typeStr,
        NodeAbstract $value,
        string $context
    ): int {
        $relation = $this->compositeTypeRelation($value, $typeCheck);
        if ($relation !== self::COMPOSITE_TYPE_MISMATCH) {
            return $relation;
        }

        $valueType = $this->staticTypeNameOfExpr($value);
        $this->fatalError($errorNode, "Cannot assign {$valueType} to {$context} of type `{$typeStr}`");
    }

    protected function compositeTypeRelation(NodeAbstract $value, array $clauses): int
    {
        // TYPE_VAR means that the expression is dynamic or its result cannot
        // be represented by the current scalar type system. It must retain the
        // runtime type check.
        // A reference (TYPE_REF) is a Variant reference whose concrete type is
        // only known at runtime (e.g. an undefined variable auto-created by a
        // by-reference argument), so it is treated the same way.
        $valueType = $this->detectTypeOfExpr($value);
        if (($valueType === Type::VAR || $valueType === Type::REF) && !$this->isNullExpr($value)) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        $hasUnknown = false;
        foreach ($clauses as $clause) {
            $relation = $this->compositeTypeClauseRelation($value, $clause);
            if ($relation === self::COMPOSITE_TYPE_MATCH) {
                return self::COMPOSITE_TYPE_MATCH;
            }
            if ($relation === self::COMPOSITE_TYPE_UNKNOWN) {
                $hasUnknown = true;
            }
        }
        return $hasUnknown ? self::COMPOSITE_TYPE_UNKNOWN : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeTypeClauseRelation(NodeAbstract $value, array $clause): int
    {
        if (($clause['kind'] ?? '') === 'allOf') {
            $hasUnknown = false;
            foreach ($clause['types'] ?? [] as $entry) {
                $relation = $this->compositeTypeEntryRelation($value, $entry);
                if ($relation === self::COMPOSITE_TYPE_MISMATCH) {
                    return self::COMPOSITE_TYPE_MISMATCH;
                }
                if ($relation === self::COMPOSITE_TYPE_UNKNOWN) {
                    $hasUnknown = true;
                }
            }
            return $hasUnknown ? self::COMPOSITE_TYPE_UNKNOWN : self::COMPOSITE_TYPE_MATCH;
        }
        return $this->compositeTypeEntryRelation($value, $clause);
    }

    protected function compositeTypeEntryRelation(NodeAbstract $value, array $entry): int
    {
        $kind = $entry['kind'] ?? '';
        if ($kind === 'isNull') {
            return $this->isNullExpr($value) ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
        }

        $type = $this->detectTypeOfExpr($value);
        return match ($kind) {
            'isInt' => $this->exactCompositeTypeRelation($type, Type::INT),
            // PHP permits int -> float widening. It is compatible but still
            // needs conversion, so retain the runtime normalization path.
            'isFloat' => $type === Type::INT
                ? self::COMPOSITE_TYPE_UNKNOWN
                : $this->exactCompositeTypeRelation($type, Type::FLOAT),
            'isBool' => $this->exactCompositeTypeRelation($type, Type::BOOL),
            'isString' => $this->exactCompositeTypeRelation($type, Type::STR),
            'isArray' => $this->exactCompositeTypeRelation($type, Type::ARRAY),
            'isObject' => $this->exactCompositeTypeRelation($type, Type::OBJECT),
            'isTrue' => $this->compositeLiteralBoolRelation($value, true),
            'isFalse' => $this->compositeLiteralBoolRelation($value, false),
            'isResource' => $this->exactCompositeTypeRelation($type, Type::RESOURCE),
            'callable' => $this->compositeCallableRelation($value, $type),
            'iterable' => $this->compositeIterableRelation($value, $type),
            'instanceof' => $this->compositeObjectEntryRelation($value, $entry),
            default => self::COMPOSITE_TYPE_UNKNOWN,
        };
    }

    protected function exactCompositeTypeRelation(string $actual, string $expected): int
    {
        return $actual === $expected ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeLiteralBoolRelation(NodeAbstract $value, bool $expected): int
    {
        if ($this->isScalarBool($value)) {
            $actual = strcasecmp($value->name->toString(), 'true') === 0;
            return $actual === $expected ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
        }
        return $this->detectTypeOfExpr($value) === Type::BOOL
            ? self::COMPOSITE_TYPE_UNKNOWN
            : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeCallableRelation(NodeAbstract $value, string $type): int
    {
        if ($type === Type::STR || $type === Type::ARRAY || $type === Type::OBJECT) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }
        return self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeIterableRelation(NodeAbstract $value, string $type): int
    {
        if ($type === Type::ARRAY) {
            return self::COMPOSITE_TYPE_MATCH;
        }
        if ($type !== Type::OBJECT) {
            return self::COMPOSITE_TYPE_MISMATCH;
        }
        return $this->compositeObjectTypeRelation($value, 'Traversable');
    }

    protected function compositeObjectEntryRelation(NodeAbstract $value, array $entry): int
    {
        if ($this->detectTypeOfExpr($value) !== Type::OBJECT) {
            return self::COMPOSITE_TYPE_MISMATCH;
        }

        return $this->compositeObjectTypeRelation($value, $entry['class'] ?? '');
    }

    protected function compositeObjectTypeRelation(NodeAbstract $value, string $expected): int
    {
        $class = $this->detectDeclaredClassOfExpr($value);
        if ($class === '') {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        if ($expected === '' || $expected === 'static') {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        $actualKnown = $this->hasClass($class)
            || $this->hasInterface($class)
            || $this->isInternalClass($class)
            || $this->isInternalInterface($class);
        $expectedKnown = $this->hasClass($expected)
            || $this->hasInterface($expected)
            || $this->isInternalClass($expected)
            || $this->isInternalInterface($expected);
        if (!$actualKnown || !$expectedKnown) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        return $this->isObjectClassStaticallyAssignableTo($class, $expected)
            ? self::COMPOSITE_TYPE_MATCH
            : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function isNullExpr(NodeAbstract $expr): bool
    {
        if ($expr instanceof Expr\ErrorSuppress) {
            return $this->isNullExpr($expr->expr);
        }
        return $expr instanceof Expr\ConstFetch
            && strcasecmp($this->parseIdentifier($expr->name), 'null') === 0;
    }

    protected function staticTypeNameOfExpr(NodeAbstract $expr, ?string $type = null): string
    {
        if ($this->isNullExpr($expr)) {
            return 'null';
        }
        $type = Type::getReferencedType($type ?? $this->detectTypeOfExpr($expr));
        return match ($type) {
            Type::INT => 'int',
            Type::FLOAT => 'float',
            Type::BOOL => 'bool',
            Type::STR => 'string',
            Type::ARRAY => 'array',
            Type::OBJECT => 'object',
            Type::RESOURCE, Type::STREAM => 'resource',
            Type::BIGINT => 'bigint',
            Type::BIGFLOAT => 'bigfloat',
            Type::DECIMAL => 'decimal',
            Type::BOX => 'box',
            default => 'mixed',
        };
    }

}

