<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Transform\VoidCastValidationVisitor;
use TypePhp\Type;

trait UnaryExpressionTrait
{
    protected function detectOperatorOperandType(NodeAbstract $operand, ?string $type = null): string
    {
        if ($operand instanceof Expr\ErrorSuppress) {
            return $this->detectOperatorOperandType($operand->expr);
        }
        if ($operand instanceof Expr\Assign) {
            if ($operand->var instanceof Expr\Variable
                && is_string($operand->var->name)
                && !$this->hasVar($this->parseVariable($operand->var))) {
                // A fresh local has no target type until assignment registers it.
                return $this->detectOperatorOperandType($operand->expr);
            }
            // Assignment evaluates to the value after conversion to its target
            // type, which can differ from the RHS (e.g. int to float).
            return $this->detectOperatorOperandType($operand->var);
        }
        if ($operand instanceof Expr\Ternary) {
            $ifType = $this->detectOperatorOperandType($operand->if ?? $operand->cond);
            $elseType = $this->detectOperatorOperandType($operand->else);
            return $ifType === $elseType ? $ifType : Type::VAR;
        }
        if ($operand instanceof Expr\ConstFetch) {
            [$name, $runtimeFallback] = $this->resolveConstantFetchName($operand, $this->parseIdentifier($operand->name));
            if (!$runtimeFallback && $this->hasConstant($name)
                && $this->constants[$this->escapeConstVar($name)]->valueExpr instanceof Expr\Array_
            ) {
                // Global constants use Variant storage, but a declared array
                // literal still has a known semantic type.
                return Type::ARRAY;
            }
        }
        if ($operand instanceof Expr\ClassConstFetch
            && $operand->class instanceof \PhpParser\Node\Name
            && $operand->name instanceof \PhpParser\Node\Identifier
            && strcasecmp($operand->class->toString(), 'static') !== 0
        ) {
            $constantType = $this->resolveReferencedConstantType($operand, $this->getFullClassName());
            if ($constantType !== null) {
                return $constantType;
            }
        }
        $type ??= $this->detectTypeOfExpr($operand);
        if ($operand instanceof Expr\PropertyFetch && $this->isIdExpr($operand->name)) {
            $class = $this->resolveObjectClassDef($operand->var);
            $name = $operand->name->toString();
            if ($class !== null && $class->hasProperty($name) && $class->getProperty($name)->nullable) {
                return Type::VAR;
            }
        }
        if ($operand instanceof Expr\StaticPropertyFetch && $this->getNativePropertyDef($operand)?->nullable) {
            return Type::VAR;
        }
        return $type;
    }

    protected function assertPhpArrayUnaryOperand(Expr $operand, string $operator): void
    {
        if (Type::getReferencedType($this->detectOperatorOperandType($operand)) === Type::ARRAY) {
            $this->fatalError($operand, "Operator '{$operator}' is not supported for array operands");
        }
    }

    protected function constantUnaryPlusValue(Expr\UnaryPlus $expr): int|float|null
    {
        if ($expr->expr instanceof \PhpParser\Node\Scalar\String_ && is_numeric($expr->expr->value)) {
            return +$expr->expr->value;
        }
        if ($expr->expr instanceof Expr\ConstFetch) {
            return match (strtolower($expr->expr->name->toString())) {
                'true' => 1,
                'false', 'null' => 0,
                default => null,
            };
        }
        return null;
    }

    protected function parseCastVoid(Expr\Cast\Void_ $node): string
    {
        if (!$node->getAttribute(VoidCastValidationVisitor::ALLOWED_ATTRIBUTE, false)) {
            $this->fatalError($node, 'The (void) cast can only be used as a statement');
        }
        return 'static_cast<void>(' . $this->parseExpr($node->expr) . ')';
    }

    protected function parseBitwiseNot(Expr\BitwiseNot $expr): string
    {
        $pythonOperator = $this->parsePythonUnaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->assertNativeObjectOperatorOperandSupported($expr->expr, $expr, '~', true);
        $this->assertPhpArrayUnaryOperand($expr->expr, '~');
        $type = Type::getReferencedType($this->detectTypeOfExpr($expr->expr));
        $this->assertExprCanBeUsedAsValue($expr->expr, 'bitwise operand');
        if ($type === Type::BIGINT) {
            return 'php::BigInt::bitNot(' . $this->parseExpr($expr->expr) . ')';
        }
        $var = $this->parseExprAsValue($expr->expr);
        if (in_array($type, [Type::BOOL, Type::INT, Type::FLOAT], true)) {
            return '~' . $this->convertIntExpr($var);
        }
        return '(~php::Var(' . $var . '))';
    }

    protected function parseBooleanNot(Expr\BooleanNot $expr): string
    {
        $pythonOperator = $this->parsePythonUnaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->assertExprCanBeUsedAsCondition($expr->expr, 'boolean operand');
        return '!(' . $this->convertBoolExpr(
            $this->parseExprAsValue($expr->expr),
            $this->detectTypeOfExpr($expr->expr)
        ) . ')';
    }

    protected function parseCastInt(Expr\Cast\Int_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        $native = $this->parseNativeObjectExplicitConversion($node->expr, 'toInt');
        if ($native !== null) {
            return $native;
        }
        return $this->convertIntExpr(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastString(Expr\Cast\String_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->parseExprToString($node->expr);
    }

    protected function parseCastBool(Expr\Cast\Bool_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        $native = $this->parseNativeObjectExplicitConversion($node->expr, 'toBool');
        if ($native !== null) {
            return $native;
        }
        return $this->convertBoolExpr(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastObject(Expr\Cast\Object_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        if ($this->isNativeObjectClass($this->detectClassOfExpr($node->expr))) {
            $this->fatalError($node, 'Native objects cannot be converted to Zend objects');
        }
        return $this->convertObjectExpr($this->parseExprAsValue($node->expr));
    }

    protected function parseUnaryMinus(Expr\UnaryMinus $expr): string
    {
        $pythonOperator = $this->parsePythonUnaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->assertNativeObjectOperatorOperandSupported($expr->expr, $expr, '-', true);
        $this->assertPhpArrayUnaryOperand($expr->expr, '-');
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        if ($type === Type::BIGFLOAT) {
            return 'php::BigFloat::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === Type::BIGINT) {
            return 'php::BigInt::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === Type::DECIMAL) {
            return 'php::Decimal::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === Type::INT) {
            $value = $this->constantIntValue($expr->expr);
            if ($value === PHP_INT_MIN) {
                if (!$this->varIntTypes) {
                    $this->fatalError(
                        $expr,
                        'Negating PHP_INT_MIN has undefined behavior in C++ native mode'
                    );
                }
                // -PHP_INT_MIN overflows int64 and promotes to float in PHP.
                return $this->genFloatLiteral(-(float) PHP_INT_MIN);
            }
        }
        $code = $this->parseExprAsValue($expr->expr);
        if (Type::getReferencedType($type) === Type::BOOL) {
            return '-(' . $this->convertIntExpr($code) . ')';
        }

        // A bare numeric literal is a single C++ token; negating it directly
        // cannot change the parse, and keeps the emitted code (and the test
        // snapshots built on it) readable.
        if (preg_match('/^(?:\d[\d\'.]*(?:[eE][+-]?\d+)?|0[xX][0-9a-fA-F\']+|0[bB][01\']+)(?:[uU]?[lL]{0,2})?$/', $code)) {
            return '-' . $code;
        }

        // Parenthesize every other operand. An unparenthesized operand can
        // change the C++ parse: `-($a ? $b : $c)` would emit
        // `-cond ? b : c`, binding the minus to the condition and possibly
        // selecting the wrong branch, and `- -$a` would paste into the C++
        // pre-decrement token `--a`.
        return '-(' . $code . ')';
    }

    protected function parseUnaryPlus(Expr\UnaryPlus $expr): string
    {
        $pythonOperator = $this->parsePythonUnaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->assertNativeObjectOperatorOperandSupported($expr->expr, $expr, '+', true);
        $this->assertPhpArrayUnaryOperand($expr->expr, '+');
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        $type = $this->detectOperatorOperandType($expr->expr);
        $constant = $this->constantUnaryPlusValue($expr);
        if ($constant !== null) {
            return is_float($constant)
                ? $this->genFloatLiteral($constant)
                : $this->genIntegerLiteral($constant);
        }
        $code = $this->parseExprAsValue($expr->expr);
        if ($type === Type::BOOL) {
            return $this->convertIntExpr($code);
        }
        if ($type === Type::INT || $type === Type::FLOAT) {
            // A declared constant can have a known scalar type while its
            // runtime lookup still returns Variant storage.
            return $this->convertExprType($code, $type, $this->detectTypeOfExpr($expr->expr));
        }
        if (in_array($type, [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
            return $code;
        }
        // Zend lowers unary plus to multiplication by one, preserving numeric
        // conversion, warnings, TypeError, and the sign of floating-point zero.
        return '(php::Variant(' . $code . ') * 1)';
    }
}
