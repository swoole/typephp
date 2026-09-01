<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use PhpParser\Node\Expr;
use TypePhp\Transform\VoidCastValidationVisitor;
use TypePhp\Type;

trait UnaryExpressionTrait
{
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
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'bitwise operand');
        if ($type === Type::BIGINT) {
            return 'php::BigInt::bitNot(' . $this->parseExpr($expr->expr) . ')';
        }
        $var = $this->parseIdentifier($expr->expr);
        return '~' . $this->convertIntExpr($var);
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
                if ($this->nativeTypes) {
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
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        return $this->parseExprAsValue($expr->expr);
    }
}
