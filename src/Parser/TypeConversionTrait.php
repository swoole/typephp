<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\NodeAbstract;

trait TypeConversionTrait
{
    protected function parseExprToString(NodeAbstract $node): string
    {
        $class = $this->detectClassOfExpr($node);
        if ($this->isNativeObjectClass($class)) {
            return $this->parseMethodCall(new Node\Expr\MethodCall(
                $node,
                new Node\Identifier('toString'),
            ));
        }
        return $this->convertExprToStringByType(
            $this->parseExprAsValue($node),
            $this->detectTypeOfExpr($node),
        );
    }

    protected function convertExprToStringByType(string $expr, $type): string
    {
        if ($type === Type::STR) {
            return $expr;
        }
        if ($type === Type::BIGINT) {
            return 'php::BigInt::toString(' . $expr . ')';
        }
        if ($type === Type::BIGFLOAT) {
            return 'php::BigFloat::toString(' . $expr . ')';
        }
        if ($type === Type::DECIMAL) {
            return 'php::Decimal::toString(' . $expr . ')';
        }
        return $this->convertStringExpr($expr);
    }

    protected function convertIntExpr(string $expr, string $fromType = ''): string
    {
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toInt',
            Type::BIGFLOAT => 'php::BigFloat::toInt',
            Type::DECIMAL => 'php::Decimal::toInt',
            default => null,
        };
        if ($bigConversion !== null) {
            // The Big* conversions return a php::Variant; unwrap it to the native scalar.
            return 'php::toInt(' . $bigConversion . '(' . $expr . '))';
        }
        if (!$this->isClosedExpr($expr, 'php::toInt')) {
            return 'php::toInt(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertFloatExpr(string $expr, string $fromType = ''): string
    {
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toFloat',
            Type::BIGFLOAT => 'php::BigFloat::toFloat',
            Type::DECIMAL => 'php::Decimal::toFloat',
            default => null,
        };
        if ($bigConversion !== null) {
            // The Big* conversions return a php::Variant; unwrap it to the native scalar.
            return 'php::toFloat(' . $bigConversion . '(' . $expr . '))';
        }
        if (!$this->isClosedExpr($expr, 'php::toFloat')) {
            return 'php::toFloat(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertDecimalExpr(string $expr, string $fromType = '', ?NodeAbstract $node = null): string
    {
        if ($fromType === Type::FLOAT) {
            if ($node instanceof Node\Scalar\Float_) {
                $rawValue = $node->getAttribute('rawValue');
                $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string) $node->value;
                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
            }
            $this->fatalError($node, 'Cannot convert float expression to Decimal, use a literal value or string instead');
        }
        if ($fromType === Type::STR) {
            if ($node instanceof Node\Scalar\String_) {
                return 'php::toDecimal(' . $this->getLiteralString($node->value) . ')';
            }
            return 'php::toDecimal(php::toString(' . $expr . '))';
        }
        if ($fromType === Type::INT) {
            return 'php::toDecimal(' . $expr . ')';
        }
        if ($fromType === Type::BIGINT) {
            return 'php::toDecimal(php::BigInt::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigIntExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === Type::INT) {
            return 'php::toBigInt(' . $expr . ')';
        }
        if ($fromType === Type::FLOAT) {
            $this->error('Cannot convert float to BigInt, use string or int instead');
        }
        if ($fromType === Type::STR) {
            return 'php::toBigInt(php::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigFloatExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === Type::INT) {
            return 'php::toBigFloat(php::toInt(' . $expr . '))';
        }
        if ($fromType === Type::FLOAT) {
            return 'php::toBigFloat(' . $expr . ')';
        }
        if ($fromType === Type::STR) {
            return 'php::toBigFloat(php::toString(' . $expr . '))';
        }
        if ($fromType === Type::BIGINT) {
            return 'php::BigFloat::newInstance(php::BigInt::toString(' . $expr . '))';
        }
        if ($fromType === Type::DECIMAL) {
            return 'php::BigFloat::newInstance(php::Decimal::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertStringExpr(string $expr): string
    {
        if (preg_match('/^get_str\(\d+\)$/', $expr) === 1) {
            return $expr;
        }
        if (!$this->isClosedExpr($expr, 'php::toString')) {
            return 'php::toString(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertObjectExpr(string $expr, string $class = ''): string
    {
        if (!$this->isClosedExpr($expr, 'php::toObject')) {
            if ($class === '') {
                return 'php::toObject(' . $expr . ')';
            }
            return 'php::toObject(' . $expr . ', ' . $class . ')';
        }

        return $expr;
    }

    protected function convertArrayExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toArray')) {
            return 'php::toArray(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertBoolExpr(string $expr, string $fromType = ''): string
    {
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toBool',
            Type::BIGFLOAT => 'php::BigFloat::toBool',
            Type::DECIMAL => 'php::Decimal::toBool',
            default => null,
        };
        if ($bigConversion !== null) {
            // The Big* conversions return a php::Variant; unwrap it to the native scalar.
            return 'php::toBool(' . $bigConversion . '(' . $expr . '))';
        }
        if (!$this->isClosedExpr($expr, 'php::toBool')) {
            return 'php::toBool(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertConditionExpr(NodeAbstract $node, string $expr): string
    {
        $pythonBool = $this->convertPythonObjectToBool($node, $expr);
        if ($pythonBool !== null) {
            return $pythonBool;
        }
        $type = $this->detectTypeOfExpr($node);
        return $this->convertBoolExpr($expr, $type);
    }

    protected function bigNumberTypeOf(mixed $type): ?string
    {
        return in_array($type, [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true) ? $type : null;
    }

    protected function convertExprType(string $expr, $leftType, $rightType): string
    {
        // A BigInt/BigFloat/Decimal operand is a php::Box resource. The generic
        // php::toFloat()/php::toInt() would read its resource handle, so the
        // conversion must go through the high-precision type itself.
        $bigType = $this->bigNumberTypeOf($rightType) ?? $this->bigNumberTypeOf($leftType) ?? '';
        if ($leftType === Type::FLOAT or $rightType === Type::FLOAT) {
            return $this->convertFloatExpr($expr, $bigType);
        }
        if ($leftType === Type::INT or $rightType === Type::INT) {
            return $this->convertIntExpr($expr, $bigType);
        }
        if ($leftType === Type::BOOL or $rightType === Type::BOOL) {
            return $this->convertBoolExpr($expr, $bigType);
        }

        return $expr;
    }

    protected function getNativeType(string $type): string
    {
        if ($type === Type::INT && $this->bigintTypes) {
            return Type::BIGINT;
        }
        if ($type === Type::FLOAT && $this->decimalTypes) {
            return Type::DECIMAL;
        }
        return $type === Type::INT && $this->varIntTypes ? Type::VAR : $type;
    }

    /**
     * Whether an inferred scalar has fixed C++ storage in the current file.
     * Float and bool are always native; varint_types only boxes integers.
     */
    protected function usesNativeScalarStorage(string $type): bool
    {
        return $this->isNativeType($type)
            && !($type === Type::INT && $this->varIntTypes);
    }

    protected function convertExprFromType(string $type, string $expr): string
    {
        $type = Type::getReferencedType($type);
        if ($type === Type::FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($type === Type::INT) {
            return $this->convertIntExpr($expr);
        }
        if ($type === Type::BOOL) {
            return $this->convertBoolExpr($expr);
        }
        if ($type === Type::STR) {
            return $this->convertStringExpr($expr);
        }
        if ($type === Type::ARRAY) {
            return $this->convertArrayExpr($expr);
        }
        if ($type === Type::OBJECT) {
            return $this->convertObjectExpr($expr);
        }

        return $expr;
    }

    /**
     * php::call() returns a Variant even when the resolved internal function
     * has a fixed Zend return type. Restore the C++ representation promised by
     * static type detection before the value reaches typed native storage.
     */
    protected function convertRuntimeCallResult(?string $type, string $expr): string
    {
        if ($type === null) {
            return $expr;
        }
        $storageType = $this->getNativeType(Type::getReferencedType($type));
        if ($storageType === Type::VAR) {
            return $expr;
        }
        return $this->convertExprFromType($storageType, $expr);
    }

    protected function convertVarType($var, $expr): string
    {
        if ($this->hasVar($var)) {
            return $this->convertExprFromType($this->getVarType($var), $expr);
        }

        return $expr;
    }

    protected function convertToRef(NodeAbstract $expr): string
    {
        $this->assertTypedArrayReferenceForbidden($expr);
        $this->assertNativeObjectReferenceForbidden($expr, $expr);
        $this->checkLeftValue($expr);
        if ($expr instanceof Node\Expr\ArrayDimFetch) {
            return $this->parseArrayDimFetchUpdate($expr) . '.toReference()';
        }
        if ($expr instanceof Node\Expr\PropertyFetch) {
            // A normal property read may return a temporary zval. Turning that
            // temporary into a reference loses the typed-property source and
            // can later detach the wrong source during destruction. Bind the
            // reference to the actual property slot instead.
            return $this->emitDynamicPropertyFetchRef($expr, $expr);
        }
        if ($expr instanceof Node\Expr\StaticPropertyFetch) {
            return $this->emitStaticPropertyFetchRef($expr, $expr);
        }
        $var = $this->parseIdentifier($expr);
        $this->assertVariableReferenceStorage($expr, $expr, $var);
        return $var . '.toReference()';
    }

    /**
     * A reference created from an ordinary local has no Zend type source.
     * Exposing fixed C++ storage through such a reference would therefore let
     * dynamic code replace the value with an incompatible type. Typed object
     * and static properties are deliberately handled before this method: Zend
     * attaches their property_info to the reference and enforces the declared
     * type. PHP array elements are dynamic zval slots and use the same Ref path.
     */
    protected function assertVariableReferenceStorage(
        NodeAbstract $expr,
        NodeAbstract $errorNode,
        ?string $name = null,
    ): void {
        if (!$this->isVarExpr($expr)) {
            return;
        }

        $name ??= $this->parseIdentifier($expr);
        if (!$this->hasVar($name)) {
            return;
        }

        $type = $this->hasStaticVar($name)
            ? $this->context->staticVars[$name]
            : $this->getVarType($name);
        if ($type === Type::VAR || $type === Type::REF) {
            return;
        }

        $this->fatalError(
            $errorNode,
            'Cannot create a reference to variable $' . $this->unescapeVarName($name)
                . ' of fixed type ' . $type
                . '; initialize it with std::any() when reference semantics are required',
        );
    }

}
