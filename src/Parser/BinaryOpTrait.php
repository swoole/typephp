<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use TypePhp\Generator\Symbol;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\NodeAbstract;
use PhpParser\Modifiers;

trait BinaryOpTrait
{
    protected function parseBinaryOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($right, 'binary operand');

        $this->demoteAutoDecimalLiteralAgainstFloat($left, $right);

        // Arithmetic logic: convert to a numeric type first when possible
        $leftExpr  = $this->parseOrderedBinaryOperand($left);
        $rightExpr = $this->parseOrderedBinaryOperand($right);

        $this->checkVarMustExist($left, $leftExpr);
        $this->checkVarMustExist($right, $rightExpr);

        $leftType  = $this->detectTypeOfExpr($left);
        $rightType = $this->detectTypeOfExpr($right);

        if ($leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            // BigFloat cannot implicitly mix with BigInt or Decimal — risk of precision loss
            if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
                $this->fatalError($left, 'Cannot mix BigFloat and BigInt implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
                $this->fatalError($left, 'Cannot mix BigFloat and Decimal implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType !== Type::BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigFloat::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
            // BigInt and Decimal cannot implicitly mix — risk of precision loss
            if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
                $this->fatalError($left, 'Cannot mix BigInt and Decimal implicitly. Use std::decimal() or std::bigInt() to convert explicitly.');
            }
            if ($leftType !== Type::DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $left);
            }
            if ($rightType !== Type::DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $right);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::Decimal::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            // Bitwise shifts: right operand is shift amount, must stay as Int
            if ($op === '<<' || $op === '>>') {
                if ($leftType !== Type::BIGINT) {
                    $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
                }
                if ($rightType === Type::BIGINT) {
                    $rightExpr = 'php::BigInt::toInt(' . $rightExpr . ')';
                } elseif ($rightType !== Type::INT) {
                    $rightExpr = $this->convertExprType($rightExpr, $rightType, Type::INT);
                }
                $method = ($op === '<<') ? 'bitShiftLeft' : 'bitShiftRight';
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod', '&' => 'bitAnd', '|' => 'bitOr', '^' => 'bitXor'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        // Any Big*-typed operand reaching here means no Big* block handled the operator
        $bigTypes = [Type::BIGFLOAT, Type::DECIMAL, Type::BIGINT];
        if (in_array($leftType, $bigTypes, true) || in_array($rightType, $bigTypes, true)) {
            $this->fatalError($left, "Operator '{$op}' is not supported for Big* numeric types");
        }

        // Only promote between native types (Int ↔ Float).  When one side is
        // php::Var, let the Variant operator handle type coercion so that
        // run-time PHP type-juggling rules are followed correctly.
        if ($leftType === Type::FLOAT && $rightType === Type::INT) {
            $rightExpr = $this->convertExprType($rightExpr, Type::FLOAT, $rightType);
        } elseif ($rightType === Type::FLOAT && $leftType === Type::INT) {
            $leftExpr = $this->convertExprType($leftExpr, $leftType, Type::FLOAT);
        }

        $this->guardLiteralDivisionByZero($left, $right, $op);

        $constantDivisionByZero = $this->handleNestedConstantDivisionByZero(
            $left,
            $right,
            $op,
            $leftExpr,
            $rightExpr
        );
        if ($constantDivisionByZero !== null) {
            return $constantDivisionByZero;
        }

        if ($op === '%') {
            if (!($leftType === Type::INT and $rightType === Type::INT)) {
                return 'php::fn::mod(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            // varint_types retains PHP's catchable modulo errors and
            // PHP_INT_MIN % -1 behavior. Native integers use raw C++ rules.
            if ($this->varIntTypes
                && !$this->isExplicitNativeArithmeticExpr($left)
                && !$this->isExplicitNativeArithmeticExpr($right)
                && $this->evaluateConstantIntArithmetic($left, $right, '%') === null
            ) {
                return 'php::fn::mod(' . $leftExpr . ', ' . $rightExpr . ')';
            }
        }

        if ($op === '<<' || $op === '>>') {
            $foldedShift = $this->tryFoldConstantShift($left, $right, $op, $leftExpr, $rightExpr);
            if ($foldedShift !== null) {
                return $foldedShift;
            }

            // PHP shifts by >= the word size yield 0 (or -1 for a negative
            // right-shifted value) and negative shift counts raise a catchable
            // ArithmeticError, while the raw C++ shift is undefined behavior
            // for both; a raw left shift into the sign bit is also undefined.
            // In varint mode, route dynamic shifts through Variant. Constant
            // shifts that C++ defines identically to PHP stay raw.
            if ($this->varIntTypes
                && !$this->isExplicitNativeArithmeticExpr($left)
                && !$this->isExplicitNativeArithmeticExpr($right)
                && $leftType === Type::INT
                && $rightType === Type::INT
            ) {
                $leftValue = $this->constantIntValue($left);
                $shiftValue = $this->constantIntValue($right);
                $safeConstantShift = $leftValue !== null
                    && $shiftValue !== null
                    && $leftValue >= 0
                    && $shiftValue >= 0
                    && $shiftValue < PHP_INT_SIZE * 8
                    && ($op === '>>' || !$this->leftShiftTouchesSignBit($leftValue, $shiftValue));
                if (!$safeConstantShift) {
                    return '((php::Var(' . $leftExpr . ')) ' . $op . ' (php::Var(' . $rightExpr . ')))';
                }
            }
        }

        $folded = $this->tryFoldConstantIntArithmetic($left, $right, $op);
        if ($folded !== null) {
            return $folded;
        }

        // Declared int parameters use the native Int ABI even in ordinary PHP
        // mode. Direct C++ +/−/* can overflow, while C++ integer division
        // truncates and cannot raise PHP's DivisionByZeroError. Route dynamic
        // integer arithmetic through the encapsulated Variant operators only
        // when varint_types explicitly requests PHP widening semantics.
        if ($this->varIntTypes
            && $leftType === Type::INT
            && $rightType === Type::INT
            && in_array($op, ['+', '-', '*', '/'], true)
            && !$this->isExplicitNativeArithmeticExpr($left)
            && !$this->isExplicitNativeArithmeticExpr($right)
            && $this->evaluateConstantIntArithmetic($left, $right, $op) === null
        ) {
            // Keep the potentially widening result boxed, but pass the native
            // RHS directly so PHPX can use its inline checked arithmetic
            // overload without constructing and destroying another zval.
            return '((php::Var(' . $leftExpr . ')) ' . $op . ' (' . $rightExpr . '))';
        }

        // PHP division on native scalar operands cannot be emitted as a raw
        // C++ '/': zend_long division truncates (7 / 2 is 3.5 in PHP, 3 in
        // C++), division by zero must raise the catchable DivisionByZeroError
        // (raw integer division is UB, raw double division yields INF/NAN),
        // and PHP_INT_MIN / -1 promotes to float. varint_types explicitly
        // selects this Variant path; native scalar division is the default.
        if ($this->varIntTypes
            && $op === '/'
            && !$this->isExplicitNativeArithmeticExpr($left)
            && !$this->isExplicitNativeArithmeticExpr($right)
            && in_array($leftType, [Type::INT, Type::FLOAT], true)
            && in_array($rightType, [Type::INT, Type::FLOAT], true)
            && ($this->constantNumericValue($left, false) === null
                || $this->constantNumericValue($right, false) === null)
        ) {
            return '((php::Var(' . $leftExpr . ')) / (php::Var(' . $rightExpr . ')))';
        }

        return '((' . $leftExpr . ') ' . $op . ' (' . $rightExpr . '))';
    }

    /**
     * std::int/float/bool explicitly retain native C++ arithmetic inside a
     * file that selected `use varint_types`.
     */
    protected function isExplicitNativeArithmeticExpr(NodeAbstract $expr): bool
    {
        if ($this->isVarExpr($expr) && is_string($expr->name)) {
            return isset($this->context->explicitNativeTypeVars[$this->parseIdentifier($expr)]);
        }

        return $expr->getAttribute('nativeType') !== null;
    }

    /**
     * Fold constant integer shifts to PHP semantics in non-native mode.
     *
     * PHP shifts by >= word size to 0 (left) or -1/0 (right, arithmetic), and
     * throws a catchable ArithmeticError for negative shift counts. Native C++
     * shifts are undefined for those counts, so the constant case is folded
     * (>= word size) or routed through php::Var (negative, so the Zend shift
     * function raises the catchable error at runtime).
     */
    protected function tryFoldConstantShift(
        NodeAbstract $left,
        NodeAbstract $right,
        string $op,
        string $leftExpr,
        string $rightExpr
    ): ?string {
        $leftValue = $this->constantIntValue($left);
        $shiftValue = $this->constantIntValue($right);
        if ($leftValue === null || $shiftValue === null) {
            return null;
        }

        $wordSize = PHP_INT_SIZE * 8;

        if (!$this->varIntTypes) {
            if ($shiftValue >= $wordSize) {
                $this->fatalError(
                    $right,
                    'Bit shift count ' . $shiftValue . ' is >= ' . $wordSize
                        . ' and is not supported in native mode'
                );
            }
            if ($shiftValue < 0) {
                $this->fatalError(
                    $right,
                    'Bit shift by a negative number is not supported in native mode'
                );
            }
            if ($op === '>>' && $leftValue < 0) {
                $this->fatalError(
                    $left,
                    'Right shift of a negative value is implementation-defined in C++'
                        . ' and is not supported in native mode'
                );
            }
            if ($op === '<<' && $leftValue < 0) {
                $this->fatalError(
                    $left,
                    'Left shift of a negative value is undefined behavior in C++'
                        . ' and is not supported in native mode'
                );
            }
            if ($op === '<<' && $this->leftShiftTouchesSignBit($leftValue, $shiftValue)) {
                $this->fatalError(
                    $left,
                    'Left shift that changes the sign bit is undefined behavior in C++'
                        . ' and is not supported in native mode'
                );
            }
            return null;
        }

        if ($shiftValue >= $wordSize) {
            $result = $op === '<<' ? '0LL' : ($leftValue < 0 ? '-1LL' : '0LL');
            $this->warning(
                $right,
                'Bit shift count ' . $shiftValue . ' is >= ' . $wordSize
                    . '; folding with PHP semantics (left shift to 0, right shift to -1 for negative operands, 0 otherwise)'
            );
            return $result;
        }

        if ($shiftValue < 0) {
            $this->warning(
                $right,
                'Bit shift by a negative number throws ArithmeticError at runtime'
            );
            // Route through php::Var so the Zend shift function raises the catchable error.
            return '((php::Var(' . $leftExpr . ')) ' . $op . ' (php::Var(' . $rightExpr . ')))';
        }

        return null;
    }

    /**
     * Whether a constant left shift of a non-negative value would set the sign
     * bit (overflow the signed range), which is undefined behavior in C++.
     */
    protected function leftShiftTouchesSignBit(int $value, int $shift): bool
    {
        if ($value < 0 || $shift <= 0) {
            return false;
        }
        if ($shift >= PHP_INT_SIZE * 8) {
            return true;
        }
        // Avoid performing the overflowing shift while checking it. Testing
        // the wrapped PHP result misses cases such as 2 << 63, which becomes
        // zero in PHP but is undefined/implementation-defined in C++17.
        return $value > (PHP_INT_MAX >> $shift);
    }

    /**
     * Fold constant int arithmetic that cannot be emitted as a plain C++
     * signed-integer expression.
     *
     * PHP promotes overflowing arithmetic to float. It also defines
     * PHP_INT_MIN % -1 as zero, while the equivalent C++ remainder expression
     * has undefined behavior. Native mode rejects every statically detectable
     * undefined operation instead of relying on compiler-specific behavior.
     */
    protected function tryFoldConstantIntArithmetic(NodeAbstract $left, NodeAbstract $right, string $op): ?string
    {
        $evaluation = $this->evaluateConstantIntArithmetic($left, $right, $op);
        if ($evaluation === null) {
            return null;
        }

        if (!$this->varIntTypes) {
            if ($evaluation['cppUndefined']) {
                $this->fatalError(
                    $left,
                    'Constant integer operation ' . $evaluation['left'] . ' ' . $op . ' '
                        . $evaluation['right'] . ' has undefined behavior in C++ native mode'
                );
            }
            return null;
        }

        if ($op === '%' && $evaluation['cppUndefined']) {
            return $this->genIntegerLiteral($evaluation['result']);
        }

        if (is_int($evaluation['result'])) {
            return null;
        }

        if ($evaluation['cppUndefined']) {
            $this->warning(
                $left,
                'Constant integer arithmetic overflows int64; folding to PHP float result ('
                    . $evaluation['left'] . ' ' . $op . ' ' . $evaluation['right'] . ')'
            );
        }
        return $this->genFloatLiteral($evaluation['result']);
    }

    /**
     * @return array{left: int, right: int, result: int|float, cppUndefined: bool}|null
     */
    protected function evaluateConstantIntArithmetic(
        NodeAbstract $left,
        NodeAbstract $right,
        string $op
    ): ?array {
        if (!in_array($op, ['+', '-', '*', '/', '%'], true)) {
            return null;
        }

        $leftValue = $this->constantIntValue($left);
        $rightValue = $this->constantIntValue($right);
        if ($leftValue === null || $rightValue === null) {
            return null;
        }
        if (($op === '/' || $op === '%') && $rightValue === 0) {
            // Division by zero is rejected by guardLiteralDivisionByZero.
            return null;
        }

        $result = match ($op) {
            '+' => $leftValue + $rightValue,
            '-' => $leftValue - $rightValue,
            '*' => $leftValue * $rightValue,
            '/' => $leftValue / $rightValue,
            '%' => $leftValue % $rightValue,
        };
        $cppUndefined = match ($op) {
            '+', '-', '*' => is_float($result),
            '/', '%' => $leftValue === PHP_INT_MIN && $rightValue === -1,
        };

        return [
            'left' => $leftValue,
            'right' => $rightValue,
            'result' => $result,
            'cppUndefined' => $cppUndefined,
        ];
    }

    /**
     * Resolve a compile-time integer constant value, or null when the
     * expression is not a statically known int constant.
     */
    protected function constantIntValue(NodeAbstract $expr): ?int
    {
        $value = $this->constantNumericValue($expr, !$this->varIntTypes);
        return is_int($value) ? $value : null;
    }

    /**
     * Evaluate a numeric literal tree without touching dynamic expressions.
     * Native mode uses C++17 integer-division semantics so an enclosing
     * operation can still be checked for undefined behavior.
     */
    protected function constantNumericValue(NodeAbstract $expr, bool $nativeSemantics): int|float|null
    {
        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\Float_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Expr\UnaryPlus) {
            return $this->constantNumericValue($expr->expr, $nativeSemantics);
        }
        if ($expr instanceof Node\Expr\UnaryMinus) {
            $value = $this->constantNumericValue($expr->expr, $nativeSemantics);
            return $value === null ? null : -$value;
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            // PHP constants are case-sensitive and unqualified names resolve
            // through the namespace first, so only a fetch that provably
            // names the global constant may fold to its value.
            $name = $this->resolveGlobalFoldableConstantName($expr);
            return match ($name) {
                'PHP_INT_MAX' => PHP_INT_MAX,
                'PHP_INT_MIN' => PHP_INT_MIN,
                default => null,
            };
        }
        if (!$expr instanceof Node\Expr\BinaryOp) {
            return null;
        }

        $left = $this->constantNumericValue($expr->left, $nativeSemantics);
        $right = $this->constantNumericValue($expr->right, $nativeSemantics);
        if ($left === null || $right === null) {
            return null;
        }

        return match (true) {
            $expr instanceof Node\Expr\BinaryOp\Plus => $left + $right,
            $expr instanceof Node\Expr\BinaryOp\Minus => $left - $right,
            $expr instanceof Node\Expr\BinaryOp\Mul => $left * $right,
            $expr instanceof Node\Expr\BinaryOp\Div => $this->constantDivisionValue(
                $left,
                $right,
                $nativeSemantics
            ),
            $expr instanceof Node\Expr\BinaryOp\Mod => is_int($left) && is_int($right) && $right !== 0
                ? $left % $right
                : null,
            $expr instanceof Node\Expr\BinaryOp\BitwiseAnd => is_int($left) && is_int($right)
                ? $left & $right
                : null,
            $expr instanceof Node\Expr\BinaryOp\BitwiseOr => is_int($left) && is_int($right)
                ? $left | $right
                : null,
            $expr instanceof Node\Expr\BinaryOp\BitwiseXor => is_int($left) && is_int($right)
                ? $left ^ $right
                : null,
            $expr instanceof Node\Expr\BinaryOp\ShiftLeft => is_int($left) && is_int($right)
                ? $this->constantShiftValue($left, $right, true)
                : null,
            $expr instanceof Node\Expr\BinaryOp\ShiftRight => is_int($left) && is_int($right)
                ? $this->constantShiftValue($left, $right, false)
                : null,
            default => null,
        };
    }

    /**
     * Resolve a constant fetch to the global constant name it provably
     * denotes, or null when the fetch may refer to something else.
     *
     * A `use const` alias resolves to its target. A fully qualified name is
     * already global. An unqualified name inside a namespace participates in
     * PHP's runtime fallback (Namespace\NAME can be defined before the fetch
     * executes), so it never provably names the global constant. A qualified
     * relative name resolves inside a namespace/import and is never global.
     */
    protected function resolveGlobalFoldableConstantName(Node\Expr\ConstFetch $expr): ?string
    {
        $name = ltrim($expr->name->toString(), '\\');
        if (isset($this->useConstants[$name])) {
            return ltrim($this->useConstants[$name], '\\');
        }
        if ($expr->name instanceof Node\Name\FullyQualified) {
            return $name;
        }
        if (!$expr->name->isUnqualified()) {
            return null;
        }
        if ($this->namespace) {
            return null;
        }
        return $name;
    }

    protected function constantDivisionValue(int|float $left, int|float $right, bool $nativeSemantics): int|float|null
    {
        if ($right == 0) {
            return null;
        }
        if ($nativeSemantics && is_int($left) && is_int($right)) {
            if ($left === PHP_INT_MIN && $right === -1) {
                return null;
            }
            return intdiv($left, $right);
        }
        return $left / $right;
    }

    protected function constantShiftValue(int $value, int $shift, bool $left): ?int
    {
        if ($shift < 0) {
            return null;
        }
        if ($shift >= PHP_INT_SIZE * 8) {
            return $left ? 0 : ($value < 0 ? -1 : 0);
        }
        return $left ? $value << $shift : $value >> $shift;
    }

    protected function handleNestedConstantDivisionByZero(
        NodeAbstract $left,
        NodeAbstract $right,
        string $op,
        string $leftExpr,
        string $rightExpr
    ): ?string {
        if ($op !== '/' && $op !== '%') {
            return null;
        }

        if (!$this->isZeroLiteral($right)) {
            $rightValue = $this->constantNumericValue($right, !$this->varIntTypes);
            if ($rightValue === null || $rightValue != 0) {
                return null;
            }
        }

        if (!$this->usesPhpArithmeticForZeroDivisor($left, $right)) {
            $this->fatalError($right, 'Constant division or modulo by zero has undefined behavior in C++ native mode');
        }

        // Preserve PHP's catchable DivisionByZeroError for a constant zero
        // divisor, whether spelled as a literal or a folded expression. Even
        // statically detectable, the operation only throws when the statement
        // actually executes, so it must not reject compilation.
        return '((php::Var(' . $leftExpr . ')) ' . $op . ' (php::Var(' . $rightExpr . ')))';
    }


    protected function shouldMaterializeOrderedOperand(NodeAbstract $expr): bool
    {
        // A closure or arrow function body does not run when the closure is
        // created, so nothing inside it can execute at this operand position.
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return false;
        }

        if ($expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\New_
            || $expr instanceof Expr\Assign
            || $expr instanceof Expr\AssignRef
            || $expr instanceof Expr\AssignOp
            || $expr instanceof Expr\PostInc
            || $expr instanceof Expr\PostDec
            || $expr instanceof Expr\PreInc
            || $expr instanceof Expr\PreDec
            || $expr instanceof Expr\Print_
            || $expr instanceof Expr\Array_
            || $expr instanceof Expr\ArrayDimFetch
            || $expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\StaticPropertyFetch
            || $expr instanceof Expr\Ternary
            || $expr instanceof Expr\Match_
            || $expr instanceof Expr\NullsafeMethodCall
            || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\Clone_
            || $expr instanceof Expr\Include_
            || $expr instanceof Expr\Eval_
            || $expr instanceof Expr\Throw_
            || $expr instanceof Expr\Yield_
            || $expr instanceof Expr\YieldFrom
            || $expr instanceof Expr\ShellExec
        ) {
            return true;
        }

        // Recurse structurally through every remaining expression wrapper
        // (binary ops, casts, unary plus/minus, boolean/bitwise not, error
        // suppression, instanceof, isset/empty, interpolation, ...). A nested
        // side effect stays a side effect no matter what wraps it, and it is
        // not always hoisted: `(int) ($i = 5)` lowers to the inline C++
        // expression `php::toInt(i = 5LL)`, which mutates `i` at an
        // unsequenced point unless the operand is materialized in order.
        foreach ($expr->getSubNodeNames() as $name) {
            $subNode = $expr->{$name};
            foreach (is_array($subNode) ? $subNode : [$subNode] as $child) {
                if ($child instanceof Expr && $this->shouldMaterializeOrderedOperand($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function parseOrderedBinaryOperand(NodeAbstract $expr): string
    {
        return $this->parseOrderedOperand($expr, true);
    }

    protected function parseOrderedOperand(NodeAbstract $expr, bool $numeric, bool $forceMaterialize = false): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'operand');
        if (!$forceMaterialize && !$this->shouldMaterializeOrderedOperand($expr)) {
            $value = $numeric ? $this->parseNumericIdentifier($expr) : $this->parseIdentifier($expr);
            return $this->normalizeNativeObjectValueExpr($expr, $value);
        }

        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);

        $nativeClass = $this->detectClassOfExpr($expr);
        if ($this->isNativeObjectClass($nativeClass)) {
            $type = $this->getNativeObjectPointerType($nativeClass);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, $type);
            $this->addNativeObject($tmpVar, $nativeClass);
        } else {
            $type = $this->getOrderedOperandTmpType($expr, (string) $value);
            $tmpVar = $this->addTmpVar($type);
        }
        if ($this->usesNativeScalarStorage($type)) {
            // A native temporary has a fixed C++ scalar ABI. The expression
            // can still contain a dynamic operand (for example, an array
            // element), so normalize it at the materialization boundary.
            $value = $this->convertExprFromType($type, (string) $value);
        }
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        if ($this->isNativeObjectClass($nativeClass)) {
            $this->context->afterStmtLines[] = $tmpVar . ' = nullptr;';
        } elseif (in_array($type, [Type::VAR, Type::STR, Type::ARRAY, Type::OBJECT], true)) {
            // The declaration is function-scoped, but PHP releases an owned
            // expression temporary after the statement that consumes it.
            // All zval-owning PHPX wrappers must be cleared here: an Object is
            // directly observable through __destruct(), while an Array may own
            // objects whose destruction would otherwise also be delayed until
            // the native function returns.
            $this->context->afterStmtLines[] = $tmpVar . '.unset();';
        }
        return $tmpVar;
    }

    protected function getOrderedOperandTmpType(NodeAbstract $expr, string $value): string
    {
        if (
            ($expr instanceof Expr\PostInc
                || $expr instanceof Expr\PostDec
                || $expr instanceof Expr\PreInc
                || $expr instanceof Expr\PreDec)
            && $this->isVarExpr($expr->var)
        ) {
            $type = $this->getVarType($this->parseIdentifier($expr->var));
            if ($this->usesNativeScalarStorage($type)) {
                return $type;
            }
            return Type::VAR;
        }

        if (
            $expr instanceof Expr\BinaryOp
            || $expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
        ) {
            $type = $this->detectTypeOfExpr($expr);
            if (
                in_array($type, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT], true)
                || $this->usesNativeScalarStorage($type)
            ) {
                // Calls and nested binary operands are materialized to preserve
                // PHP's left-to-right evaluation order. A statically native
                // scalar result has a fixed C++ representation, so
                // boxing it in a Variant would add dynamic arithmetic and zval
                // lifetime work to otherwise native expressions.
                return $type;
            }
            return Type::VAR;
        }

        if ($expr instanceof Expr\PropertyFetch) {
            $nativePropertyVar = $this->getNativePropertyVar($expr);
            if ($nativePropertyVar !== null && $nativePropertyVar === $value) {
                $info = $this->getObjectPropInfoByVar($nativePropertyVar);
                if ($info !== null) {
                    return $info['type'];
                }
                $def = $this->getNativePropertyDef($expr);
                if ($def && $this->isNativePropertyTypedValue($expr)) {
                    return $def->type;
                }
            }
            return Type::VAR;
        }

        if ($expr instanceof Expr\StaticPropertyFetch) {
            $def = $this->getNativePropertyDef($expr);
            if ($def && $this->isNativePropertyTypedValue($expr)) {
                return $def->type;
            }
            return Type::VAR;
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            return Type::VAR;
        }

        $type = $this->detectTypeOfExpr($expr);
        if ($expr instanceof Expr\Variable
            || in_array($type, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT], true)
            || $this->usesNativeScalarStorage($type)
        ) {
            return $type;
        }
        // A wrapper expression (unary minus, a cast, error suppression, ...)
        // around a side effect is materialized for evaluation order, but its
        // lowered C++ form can still be dynamic — `-strlen($s)` on an
        // unqualified namespaced call lowers to `-(php::call(...))`, a
        // Variant. When its type is not statically fixed the temporary must
        // stay dynamic, matching the call and binary-op policy above.
        return Type::VAR;
    }

    protected function appendCapturedStmtLinesToContext(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->context->beforeStmtLines[] = $stmt;
        }
    }

    protected function parseBinaryOpPlus(Expr\BinaryOp\Plus $expr): string
    {
        $python = $this->parsePythonBinaryOperator($expr);
        if ($python !== null) {
            return $python;
        }

        return $this->tryParseFinalIntPropertyAddChain($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    /**
     * Lower a left-associated chain of stable declared-int property reads into
     * one detached Variant accumulator.
     *
     * This keeps PHP overflow promotion and evaluation order in Variant's
     * encapsulated operator+= while avoiding one owning temporary per binary
     * AST node. The class/property must be final so a subclass cannot replace
     * the declared property with a hook. Nullable, virtual and hooked
     * properties stay on the general path.
     */
    protected function tryParseFinalIntPropertyAddChain(Expr\BinaryOp\Plus $expr): ?string
    {
        if (!$this->varIntTypes) {
            return null;
        }

        $operands = [];
        $cursor = $expr;
        while ($cursor instanceof Expr\BinaryOp\Plus) {
            array_unshift($operands, $cursor->right);
            $cursor = $cursor->left;
        }
        array_unshift($operands, $cursor);

        if (count($operands) < 3) {
            return null;
        }

        foreach ($operands as $operand) {
            if (!$this->isStableFinalIntPropertyRead($operand)) {
                return null;
            }
        }

        $accumulator = $this->addTmpVar(Type::VAR);
        foreach ($operands as $index => $operand) {
            /** @var Expr\PropertyFetch $operand */
            $value = $this->parsePropertyFetch($operand);
            if ($index === 0) {
                // Assignment into an already-declared Variant materializes an
                // independent value. Do not use copy-initialization here:
                // mandatory C++ copy elision could retain an Indirect alias.
                $this->context->beforeStmtLines[] = $accumulator . ' = ' . $value . ';';
            } else {
                $this->context->beforeStmtLines[] = $accumulator . ' += ' . $value . ';';
            }
        }

        return $accumulator;
    }

    protected function isStableFinalIntPropertyRead(NodeAbstract $operand): bool
    {
        if (!$operand instanceof Expr\PropertyFetch
            || !$operand->var instanceof Expr\Variable
            || !$this->isIdExpr($operand->name)
        ) {
            return false;
        }

        $class = $this->resolveObjectClassDef($operand->var);
        $propertyName = $this->parseIdentifier($operand->name);
        if ($class === null || !$class->hasProperty($propertyName)) {
            return false;
        }

        $property = $class->getProperty($propertyName);
        $stableDeclaration = ($class->flags & Modifiers::FINAL) !== 0
            || ($property->flags & Modifiers::FINAL) !== 0;

        return $stableDeclaration
            && ($property->flags & Modifiers::STATIC) === 0
            && $property->type === Type::INT
            && !$property->nullable
            && !$property->virtual
            && $property->getter === null;
    }

    protected function parseBinaryOpMul(Expr\BinaryOp\Mul $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    protected function parseBinaryOpConcat(Expr\BinaryOp\Concat $expr): string
    {
        return $this->parseFlattenedConcat($expr);
    }

    protected function parseFlattenedConcat(NodeAbstract $expr, array $prefixExpressions = []): string
    {
        $items = [];
        $this->flattenConcatExpr($expr, $items);

        // C++ does not order regular function arguments. The two-argument
        // overload is therefore only safe for scalar operands whose parsing
        // and conversion cannot execute user code or mutate state. All other
        // concatenations use the braced-list overload, whose elements are
        // sequenced left-to-right by C++17.
        $useTwoOperandOverload = $prefixExpressions === []
            && $this->canUseTwoOperandConcatOverload($items);

        // Zend lowers the left-associated chain i0.i1.i2... into one CONCAT
        // opcode per node and reads a CV operand when its opcode executes:
        // i0 and i1 are both read at the first op (after the side effects of
        // both), and every later item ik at the k-th op (after the side
        // effects of i0..ik, before those of later items). The flattened
        // braced list hoists all captured side effects ahead of the whole
        // expression, so a plain-variable item that Zend reads before a later
        // item's side effects (`$m . ',' . ($m = 9)` must yield "1,9") is
        // snapshotted into a temporary at its Zend read position.
        $lastHoistingIndex = -1;
        foreach ($items as $index => $item) {
            if ($this->shouldMaterializeOrderedOperand($item)
                || $this->isNativeObjectClass($this->detectClassOfExpr($item))
            ) {
                $lastHoistingIndex = $index;
            }
        }

        // The first item is read together with the second at the first op,
        // i.e. after the second item's side effects. Its snapshot is deferred
        // until the second item has been lowered.
        $deferFirstItemSnapshot = $lastHoistingIndex >= 2
            && isset($items[1])
            && $this->isSnapshotableVariableRead($items[0])
            && !($this->isScalarString($items[1]) && $items[1]->value === '');

        $argList = $prefixExpressions;
        foreach ($items as $index => $item) {
            if ($deferFirstItemSnapshot && $index === 0) {
                continue;
            }

            // Keep one operand so concat still performs PHP string coercion.
            // Prefix expressions are operands too (for example, the left-hand
            // value of `.=`), so an empty RHS literal can be omitted there.
            if ($argList !== [] && $this->isScalarString($item) && $item->value === '') {
                continue;
            }

            $entryPosition = count($argList);
            $itemClass = $this->detectClassOfExpr($item);
            if ($this->isNativeObjectClass($itemClass)) {
                $toString = new Expr\MethodCall($item, new Node\Identifier('toString'));
                $argList[] = $this->parseOrderedOperand($toString, false);
            } else {
                $type = $this->detectTypeOfExpr($item);
                // C++17 evaluates the braced-list elements in order. The
                // temporary is still required because lowering a later operand
                // may append captured beforeStmtLines ahead of the entire
                // concat expression; without it, those statements could
                // overtake an earlier Call.
                $snapshotEarlierRead = $index >= 1
                    && $index < $lastHoistingIndex
                    && $this->isSnapshotableVariableRead($item);
                $parsed = $this->parseOrderedOperand($item, false, $snapshotEarlierRead);
                $argList[] = $this->prepareConcatOperand($parsed, $type);
            }

            if ($deferFirstItemSnapshot && $index === 1) {
                // Snapshot the first item now, after the second item's side
                // effects, and keep its leading position in the operand list.
                $firstType = $this->detectTypeOfExpr($items[0]);
                $firstParsed = $this->parseOrderedOperand($items[0], false, true);
                array_splice($argList, $entryPosition, 0, [
                    $this->prepareConcatOperand($firstParsed, $firstType),
                ]);
            }
        }

        if ($useTwoOperandOverload && count($argList) === 2) {
            return Symbol::concat() . '(' . $argList[0] . ', ' . $argList[1] . ')';
        }

        return Symbol::concat() . '({' . implode(', ', $argList) . '})';
    }

    /**
     * Whether an operand is a plain local variable read whose value can be
     * snapshotted into a temporary to preserve left-to-right evaluation when
     * a later operand hoists side-effecting statements. `$this` cannot be
     * reassigned and $GLOBALS has dedicated lowering; both are left alone.
     */
    protected function isSnapshotableVariableRead(NodeAbstract $expr): bool
    {
        if (!$this->isVarExpr($expr) || !is_string($expr->name)) {
            return false;
        }
        if ($expr->name === 'this' || $expr->name === 'GLOBALS') {
            return false;
        }
        $var = (string) $this->parseIdentifier($expr);
        return $this->hasVar($var) && !$this->isStdContainer($var);
    }

    protected function canUseTwoOperandConcatOverload(array $items): bool
    {
        if (count($items) !== 2) {
            return false;
        }
        foreach ($items as $item) {
            // Even a scalar-typed binary expression may emit a warning or
            // throw (division by zero, failed conversion, overloaded object
            // operation). Restrict the unordered overload to values that are
            // already materialized and cannot execute during argument setup.
            if (!($item instanceof Node\Scalar || $this->isVarExpr($item))
                || $this->shouldMaterializeOrderedOperand($item)
                || !in_array(
                    $this->detectTypeOfExpr($item),
                    [Type::STR, Type::INT, Type::FLOAT, Type::BOOL],
                    true,
                )
            ) {
                return false;
            }
        }
        return true;
    }

    protected function prepareConcatOperand(string $expr, string $type): string
    {
        if (in_array($type, [Type::STR, Type::INT, Type::FLOAT, Type::BOOL], true)) {
            return $expr;
        }

        // Keep conversions of objects/arrays/any values at their original
        // operand position. Moving them into concat() would evaluate all later
        // operands before __toString() or a conversion error is triggered.
        return $this->convertExprToStringByType($expr, $type);
    }

    protected function flattenConcatExpr(NodeAbstract $expr, array &$items): void
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $this->flattenConcatExpr($expr->left, $items);
            $this->flattenConcatExpr($expr->right, $items);
        } else {
            $items[] = $expr;
        }
    }

    protected function parseBinaryOpSmaller(Expr\BinaryOp\Smaller $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parseBinaryOpShiftLeft(Expr\BinaryOp\ShiftLeft $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight(Expr\BinaryOp\ShiftRight $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(Expr\BinaryOp\Mod $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    protected function parseBinaryOpGreater(Expr\BinaryOp\Greater $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(Expr\BinaryOp\Pow $expr): string
    {
        $pythonOperator = $this->parsePythonBinaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->assertExprCanBeUsedAsValue($expr->left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($expr->right, 'binary operand');
        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);
        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL
            || $leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            $this->fatalError($expr, "Operator '**' is not supported for Decimal or BigFloat; use pow() where supported");
        }
        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::pow(' . $leftExpr . ', ' . $rightExpr . ')';
        }
        $left  = $this->parseOrderedOperand($expr->left, false);
        $right = $this->parseOrderedOperand($expr->right, false);
        return 'php::fn::pow(' . $left . ', ' . $right . ')';
    }

    protected function parseBinaryOpBitwiseAnd(Expr\BinaryOp\BitwiseAnd $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(Expr\BinaryOp\BitwiseOr $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(Expr\BinaryOp\BitwiseXor $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseCompareExpr(NodeAbstract $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'comparison operand');
        // Comparing PHPX values with bool causes an overload error, so convert them to bool first
        if ($this->isScalarBool($expr)) {
            return $this->getBoolValue($expr);
        }
        return $this->parseOrderedOperand($expr, false);
    }

    protected function parseBinaryOpEqual(Expr\BinaryOp\Equal $expr): string
    {
        $pythonOperator = $this->parsePythonBinaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        return $this->genBigNumericCmp($expr, ' == 0')
            ?? 'php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(Expr\BinaryOp\NotEqual $expr): string
    {
        $pythonOperator = $this->parsePythonBinaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        return $this->genBigNumericCmp($expr, ' != 0')
            ?? '!php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpIdentical(Expr\BinaryOp $expr): string
    {
        $pythonOperator = $this->parsePythonBinaryOperator($expr);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $this->demoteAutoDecimalLiteralAgainstFloat($expr->left, $expr->right);
        $left  = $this->parseCompareExpr($expr->left);
        $right = $this->parseCompareExpr($expr->right);
        $leftIsNative = $this->isNativeObjectClass($this->detectClassOfExpr($expr->left));
        $rightIsNative = $this->isNativeObjectClass($this->detectClassOfExpr($expr->right));
        if ($leftIsNative && $this->isNull($expr->right)) {
            return '(' . $left . ') == nullptr';
        }
        if ($rightIsNative && $this->isNull($expr->left)) {
            return '(' . $right . ') == nullptr';
        }
        if ($leftIsNative && $rightIsNative) {
            return 'static_cast<const void *>(' . $left . ') == static_cast<const void *>(' . $right . ')';
        }
        if ($leftIsNative || $rightIsNative) {
            // A Native pointer has no zval representation and can never be
            // strictly identical to a Zend value. Explicit void casts retain
            // both PHP side effects in left-to-right order without allowing
            // C++ to coerce the pointer to bool.
            return '(static_cast<void>(' . $left . '), static_cast<void>(' . $right . '), false)';
        }
        if ($right === 'nullptr') {
            // The left operand may itself be an assignment or another compound
            // expression. Parenthesize it before invoking Variant::isNull(), or
            // C++ binds the member access to the assignment's RHS instead.
            return '(' . $left . ').isNull()';
        }
        if ($optimized = $this->optimizeIdenticalOp($expr->left, $expr->right, $left, $right)) {
            return $optimized;
        }
        return 'php::same(' . $left . ', ' . $right . ')';
    }

    /**
     * Use compile-time type info to optimize === and !== .
     * When both sides are the same narrowed primitive type, emit direct C++ == .
     * When both are narrowed but different types, === is always false.
     */
    private function optimizeIdenticalOp(NodeAbstract $astLeft, NodeAbstract $astRight, string $cppLeft, string $cppRight): ?string
    {
        $primitiveTypes = [Type::INT, Type::FLOAT, Type::BOOL];
        $leftType  = $this->detectTypeOfExpr($astLeft);
        $rightType = $this->detectTypeOfExpr($astRight);

        if (!in_array($leftType, $primitiveTypes, true) || !in_array($rightType, $primitiveTypes, true)) {
            return null;
        }
        if ($leftType === $rightType) {
            if ($leftType === Type::BOOL) {
                $cppLeft = $this->nativeBoolLiteral($astLeft) ?? $cppLeft;
                $cppRight = $this->nativeBoolLiteral($astRight) ?? $cppRight;
            }
            return $cppLeft . ' == ' . $cppRight;
        }
        return 'false';
    }

    /**
     * Strict comparisons between native booleans must use C++ bool literals.
     * parseCompareExpr() normally emits php::true_/php::false_ Variants because
     * dynamic comparisons need zvals, but those wrappers are incorrect once
     * optimizeIdenticalOp() selects a direct primitive comparison.
     */
    private function nativeBoolLiteral(NodeAbstract $expr): ?string
    {
        if (!$this->isScalarBool($expr)) {
            return null;
        }
        return strcasecmp($expr->name->toString(), 'true') === 0 ? 'true' : 'false';
    }

    protected function parseBinaryOpLogicalAnd(Expr\BinaryOp\LogicalAnd|Expr\BinaryOp\BooleanAnd $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '&&');
    }

    protected function parseBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr|Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '||');
    }

    protected function parseShortCircuitLogicalOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsCondition($left, 'logical operand');
        $this->assertExprCanBeUsedAsCondition($right, 'logical operand');

        $leftExpr = $this->parseNumericIdentifier($left);
        $this->checkVarMustExist($left, $leftExpr);

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseNumericIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        $leftBool = $this->convertPythonObjectToBool($left, (string) $leftExpr)
            ?? $this->convertBoolExpr((string) $leftExpr, $this->detectTypeOfExpr($left));
        $rightBool = $this->convertPythonObjectToBool($right, (string) $rightExpr)
            ?? $this->convertBoolExpr((string) $rightExpr, $this->detectTypeOfExpr($right));
        if (!$rightBeforeStmts && !$rightAfterStmts) {
            return '(' . $leftBool . ' ' . $op . ' ' . $rightBool . ')';
        }

        $shortCircuitValue = $op === '&&' ? 'false' : 'true';
        $rightCondition = $op === '&&' ? $leftBool : '!(' . $leftBool . ')';

        $code = '[&]() -> bool {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'if (' . $rightCondition . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->formatCapturedStmtLines($rightBeforeStmts);
        if ($rightAfterStmts) {
            $type = $this->detectTypeOfExpr($right) === Type::BOOL ? Type::BOOL : Type::VAR;
            $rightTmpVar = $this->addTmpVar($type);
            if ($type === Type::BOOL) {
                $rightExpr = $this->convertBoolExpr($rightExpr);
            }
            $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightAfterStmts);
            $rightExpr = $rightTmpVar;
            $rightBool = $this->convertPythonObjectToBool($right, $rightExpr)
                ?? $this->convertBoolExpr($rightExpr, $this->detectTypeOfExpr($right));
        }
        $code .= $this->getIndent() . 'return ' . $rightBool . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $code .= $this->getIndent() . 'return ' . $shortCircuitValue . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}()';

        return $code;
    }

    protected function parseBinaryOpLogicalXor(Expr\BinaryOp\LogicalXor $expr): string
    {
        $this->assertExprCanBeUsedAsCondition($expr->left, 'logical operand');
        $this->assertExprCanBeUsedAsCondition($expr->right, 'logical operand');
        $left = $this->parseOrderedBinaryOperand($expr->left);
        $right = $this->parseOrderedBinaryOperand($expr->right);
        $leftBool = $this->convertPythonObjectToBool($expr->left, $left)
            ?? $this->convertBoolExpr($left, $this->detectTypeOfExpr($expr->left));
        $rightBool = $this->convertPythonObjectToBool($expr->right, $right)
            ?? $this->convertBoolExpr($right, $this->detectTypeOfExpr($expr->right));
        return '(' . $leftBool . ' != ' . $rightBool . ')';
    }

    protected function parseBinaryOpSmallerOrEqual(Expr\BinaryOp\SmallerOrEqual $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    protected function parseBinaryOpGreaterOrEqual(Expr\BinaryOp\GreaterOrEqual $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    protected function parseBinaryOpSpaceship(Expr\BinaryOp\Spaceship $expr): string
    {
        return $this->genBigNumericCmp($expr)
            ?? 'php::compare(' . $this->parseOrderedOperand($expr->left, false) . ', ' . $this->parseOrderedOperand($expr->right, false) . ')';
    }

    /**
     * When an auto-Decimal-classified float literal meets a float-typed
     * expression in a binary operation, demote the literal to its exact
     * double. PHP evaluates every float literal as a double, so rejecting
     * the mix ("Cannot convert float expression to Decimal") refuses valid
     * PHP — e.g. `0.1 + 0.2 == 0.30000000000000004` from a var_export round
     * trip — and keeping the Decimal would change comparison semantics.
     */
    protected function demoteAutoDecimalLiteralAgainstFloat(NodeAbstract $left, NodeAbstract $right): void
    {
        if ($this->decimalTypes) {
            return;
        }
        $leftType = $this->detectTypeOfExpr($left);
        $rightType = $this->detectTypeOfExpr($right);
        foreach ([[$left, $leftType, $rightType], [$right, $rightType, $leftType]] as [$node, $type, $otherType]) {
            if ($type === Type::DECIMAL
                && $otherType === Type::FLOAT
                && $node instanceof Node\Scalar\Float_
                && $this->isDecimalLiteral($node)
            ) {
                $node->setAttribute(self::ATTR_FORCE_FLOAT_LITERAL, true);
            }
        }
    }

    protected function genBigNumericCmp(Expr\BinaryOp $expr, string $suffix = ''): ?string
    {
        $this->demoteAutoDecimalLiteralAgainstFloat($expr->left, $expr->right);

        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);

        $bigTypes = [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT];
        if (in_array($leftType, $bigTypes, true) && in_array($rightType, $bigTypes, true)
            && $leftType !== $rightType) {
            $this->fatalError(
                $expr,
                'Cannot compare different Big* types implicitly; convert both operands to the same type explicitly'
            );
        }

        if ($leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            return 'php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $expr->left);
            }
            if ($rightType !== Type::DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $expr->right);
            }
            return 'php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }

        return null;
    }

    protected function parseBinaryOpCoalesce(Expr\BinaryOp\Coalesce $expr): string
    {
        return $this->parseValueSelection($expr, $expr->left, $expr->right, self::OP_ISSET);
    }

    protected function parseBinaryOpNotIdentical(Expr\BinaryOp $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    protected function parseBinaryOpDiv(Expr\BinaryOp\Div $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '/');
    }

    protected function guardLiteralDivisionByZero(
        NodeAbstract $left,
        NodeAbstract $right,
        string $op
    ): void
    {
        if (($op === '/' or $op === '%' or $op === '/=' or $op === '%=') and $this->isZeroLiteral($right)) {
            if (!$this->usesPhpArithmeticForZeroDivisor($left, $right)) {
                $this->fatalError($right, 'Cannot divide or modulo by zero');
            }
            // PHP raises a catchable DivisionByZeroError at runtime, and only
            // when the statement actually executes; dead or guarded code with
            // a literal zero divisor is valid PHP. Warn instead of rejecting.
            $this->warning($right, 'Division or modulo by zero throws DivisionByZeroError at runtime');
        }
    }

    /**
     * A boxed operand already carries Zend arithmetic semantics regardless of
     * the file mode. varint_types additionally opts integer arithmetic into
     * that path; native scalar expressions reject statically known zero
     * divisors before raw C++ arithmetic can produce UB or infinity.
     */
    protected function usesPhpArithmeticForZeroDivisor(NodeAbstract $left, NodeAbstract $right): bool
    {
        return $this->varIntTypes
            || $this->detectTypeOfExpr($left) === Type::VAR
            || $this->detectTypeOfExpr($right) === Type::VAR;
    }

    protected function parseBinaryOpMinus(Expr\BinaryOp\Minus $expr): string
    {
        return $this->parsePythonBinaryOperator($expr)
            ?? $this->parseBinaryOp($expr->left, $expr->right, '-');
    }

}
