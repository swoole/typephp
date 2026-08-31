<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use TypePhp\Resolver\Reflection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait TypeDetectionTrait
{
    public function isTypedObject(string $object): bool
    {
        return isset($this->context->objects[$object]) || isset($this->context->stableObjects[$object]);
    }

    protected function isSuperGlobal(string $var): bool
    {
        if (isset($this->superGlobalVars[$var])) {
            return true;
        }
        return false;
    }

    protected function isBigIntLiteral(Node\Scalar $expr): bool
    {
        $rawValue = $expr->getAttribute('rawValue');
        if ($rawValue === null) {
            return false;
        }
        $clean = $this->stripNumericUnderscores($rawValue);
        // Must look like a decimal integer (no dot, no hex/oct/bin prefix, all digits)
        if (!preg_match('/^\d+$/', $clean)) {
            return false;
        }
        // 19+ decimal digits exceed int64 range
        return strlen(ltrim($clean, '0')) >= 19;
    }

    protected function isDecimalLiteral(Node\Scalar $expr): bool
    {
        if ($expr->getAttribute(self::ATTR_FORCE_FLOAT_LITERAL, false)) {
            return false;
        }
        $rawValue = $expr->getAttribute('rawValue');
        if ($rawValue === null) {
            return false;
        }
        $clean = $this->stripNumericUnderscores($rawValue);
        // Hex/octal/binary notation folds to its exact numeric value in Zend
        // (an overflowing hex literal becomes the exact double); only decimal
        // notation participates in the Decimal promotion. A hex literal whose
        // digits contain E would otherwise match the exponent test below.
        if (preg_match('/^[+-]?0[xXbBoO]/', $clean)) {
            return false;
        }
        // Must have a decimal point or exponent (not a pure integer)
        if (!preg_match('/[\.eE]/', $clean)) {
            return false;
        }
        // Documented rule: 16 or more significant digits promote to Decimal.
        // Exponent digits carry no precision, and neither do leading or
        // trailing mantissa zeros (999999999999999.0 has 15).
        if ($this->countSignificantMantissaDigits($clean) < 16) {
            return false;
        }
        // The promotion exists for literals that exceed double precision. A
        // literal the double reproduces exactly — every var_export/serialize
        // round-trip, PHP_FLOAT_EPSILON, ... — has lost nothing and stays a
        // native float.
        return !$this->floatLiteralRoundTripsExactly($clean);
    }

    /**
     * Count the significant decimal digits of a numeric literal's mantissa:
     * sign and exponent are ignored, leading zeros carry no precision, and
     * trailing mantissa zeros do not require more precision than the double.
     */
    protected function countSignificantMantissaDigits(string $literal): int
    {
        $mantissa = ltrim($literal, '+-');
        $mantissa = preg_split('/[eE]/', $mantissa)[0];
        $digits = str_replace('.', '', $mantissa);
        $digits = trim($digits, '0');
        return strlen($digits);
    }

    /**
     * Whether the decimal literal denotes exactly the value of its double
     * representation (i.e. converting to double loses nothing).
     */
    protected function floatLiteralRoundTripsExactly(string $literal): bool
    {
        $value = (float) $literal;
        if (!is_finite($value)) {
            // The double overflowed; Decimal preserves the written value.
            return false;
        }
        $shortest = $this->shortestFloatRepr($value);
        return $this->normalizeDecimalLiteral($literal) === $this->normalizeDecimalLiteral($shortest);
    }

    /**
     * Shortest decimal representation that parses back to exactly $value,
     * independent of the precision/serialize_precision ini settings.
     */
    protected function shortestFloatRepr(float $value): string
    {
        for ($precision = 0; $precision <= 17; $precision++) {
            $candidate = sprintf('%.' . $precision . 'e', $value);
            if ((float) $candidate === $value) {
                return $candidate;
            }
        }
        return sprintf('%.17e', $value);
    }

    /**
     * Normalize a decimal literal to [sign, significant digits, exponent] so
     * two spellings of the same real number compare equal.
     *
     * @return array{string, string, int}|null
     */
    protected function normalizeDecimalLiteral(string $literal): ?array
    {
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', trim($literal), $m)) {
            return null;
        }
        $sign = $m[1] === '-' ? '-' : '+';
        $fraction = $m[3] ?? '';
        $exponent = (int) ($m[4] ?? 0) - strlen($fraction);
        $digits = ltrim($m[2] . $fraction, '0');
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);
        if ($trimmed === '') {
            return ['+', '', 0];
        }
        return [$sign, $trimmed, $exponent];
    }

    protected function isFloatStr(string $str): bool
    {
        return filter_var($str, FILTER_VALIDATE_FLOAT) !== false;
    }

    protected function isIntStr(string $str): bool
    {
        return filter_var($str, FILTER_VALIDATE_INT) !== false;
    }

    protected function isBoolStr(string $str): bool
    {
        return $str === 'true' || $str === 'false';
    }

    protected function isNativeType(string $type): bool
    {
        return in_array($type, [Type::INT, Type::FLOAT, Type::BOOL]);
    }

    protected function isNativeTypeVar(string $var): bool
    {
        return $this->isNativeType($this->getVarType($var));
    }

    protected function isInternalFunction(string $name): bool
    {
        $name = ltrim($name, '\\');

        return array_key_exists($name, $this->internalFunctions);
    }

    protected function isInternalClass(string $name): bool
    {
        return Reflection::isInternalClass($name);
    }

    protected function isNativeClass(string $name): bool
    {
        return $this->hasClass($name);
    }

    protected function isInterface(string $name): bool
    {
        return $this->hasInterface($name) or $this->isInternalInterface($name);
    }

    protected function isAbstractClass(string $name): bool
    {
        if ($this->isInternalClass($name)) {
            return Reflection::isAbstractClass($name);
        }
        if ($this->hasClass($name)) {
            $classDef = $this->getClass($name);
            return $classDef->isAbstract();
        }
        return false;
    }

    protected function isInternalInterface(string $name): bool
    {
        return Reflection::isInternalInterface($name);
    }

    protected function isInternalConstant(string $name): bool
    {
        return array_key_exists($name, $this->internalConstants);
    }

    protected function isAssignOpConcat(string $op): bool
    {
        return $op === '.=';
    }

    protected function isAssignOpPow(string $op): bool
    {
        return $op === '**=';
    }

    protected function isArrayVar($var): bool
    {
        return $this->isVarExpr($var) and $this->hasVar($var->name) and $this->getVarType($var->name) === Type::ARRAY;
    }

}
