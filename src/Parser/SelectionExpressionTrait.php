<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers ternary, match, coalesce-style value selection, and branch temporaries.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait SelectionExpressionTrait
{
    protected function parseTernary(Expr\Ternary $expr): string
    {
        if ($expr->if === null) {
            return $this->parseValueSelection($expr, $expr->cond, $expr->else, self::OP_NOT_EMPTY);
        }
        $this->assertExprCanBeUsedAsCondition($expr->cond, 'ternary condition');
        $this->assertExprCanBeUsedAsValue($expr->if, 'ternary branch');
        $this->assertExprCanBeUsedAsValue($expr->else, 'ternary branch');
        $ifType = $this->detectTypeOfExpr($expr->if);
        $elseType = $this->detectTypeOfExpr($expr->else);
        $nativeClass = $this->detectClassOfExpr($expr);
        $nativeSelection = $this->isNativeObjectClass($nativeClass);
        $typeChanged = !$nativeSelection && $ifType !== $elseType;
        [$cond, $condBeforeStmts, $condAfterStmts] = $this->parseExprWithCapturedStmts($expr->cond);
        $ifBeforeStmtCount = count($this->context->beforeStmtLines);
        $ifAfterStmtCount = count($this->context->afterStmtLines);
        $if = $this->parseExprAsValue($expr->if);
        $ifBeforeStmts = array_slice($this->context->beforeStmtLines, $ifBeforeStmtCount);
        $ifAfterStmts = array_slice($this->context->afterStmtLines, $ifAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $ifBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $ifAfterStmtCount);

        $elseBeforeStmtCount = count($this->context->beforeStmtLines);
        $elseAfterStmtCount = count($this->context->afterStmtLines);
        $else = $this->parseExprAsValue($expr->else);
        $elseBeforeStmts = array_slice($this->context->beforeStmtLines, $elseBeforeStmtCount);
        $elseAfterStmts = array_slice($this->context->afterStmtLines, $elseAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $elseBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $elseAfterStmtCount);

        if ($nativeSelection) {
            $pointerType = $this->getNativeObjectPointerType($nativeClass);
            if ($this->isNull($expr->if)) {
                $if = 'nullptr';
            } else {
                $if = 'static_cast<' . $pointerType . '>(' . $if . ')';
            }
            if ($this->isNull($expr->else)) {
                $else = 'nullptr';
            } else {
                $else = 'static_cast<' . $pointerType . '>(' . $else . ')';
            }
        }

        $hasBranchStmts = $condBeforeStmts || $condAfterStmts || $ifBeforeStmts || $ifAfterStmts || $elseBeforeStmts || $elseAfterStmts;
        $ifStorageType = $this->getLoweredExpressionType($expr->if);
        $elseStorageType = $this->getLoweredExpressionType($expr->else);
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, $nativeSelection
            ? $this->getNativeObjectPointerType($nativeClass)
            : ($typeChanged ? Type::VAR : ($ifStorageType === $elseStorageType ? $ifStorageType : null)));
        if (!$hasBranchStmts && $typeChanged) {
            $if = 'php::Var(' . $if . ')';
            $else = 'php::Var(' . $else . ')';
        }
        if ($hasBranchStmts) {
            // REF and VOID are expression implementation types, not valid
            // by-value result types for the materializing lambda.
            $ternaryType = $nativeSelection
                ? $this->getNativeObjectPointerType($nativeClass)
                : $this->getNormalAssignType($typeChanged ? Type::VAR : $ifType);
            $expr->setAttribute(self::ATTR_LOWERED_TYPE, $ternaryType);
            $code = '[&]() -> ' . $ternaryType . ' {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->formatCapturedStmtLines($condBeforeStmts);
            if ($condAfterStmts) {
                $condTmpVar = $this->addTmpVar(Type::VAR);
                $code .= $this->getIndent() . "{$condTmpVar} = {$cond};" . PHP_EOL;
                $code .= $this->formatCapturedStmtLines($condAfterStmts);
                $cond = $condTmpVar;
            }
            $cond = $this->convertConditionExpr($expr->cond, $cond);
            $code .= $this->getIndent() . 'if (' . $cond . ') {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->formatTernaryReturn($expr->if, $if, $ifBeforeStmts, $ifAfterStmts, $ternaryType, $ifType, $nativeClass);
            $this->indentLevel--;
            $code .= $this->getIndent() . '} else {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->formatTernaryReturn($expr->else, $else, $elseBeforeStmts, $elseAfterStmts, $ternaryType, $elseType, $nativeClass);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}()';
            return $code;
        }
        $cond = $this->convertConditionExpr($expr->cond, $cond);
        return '(' . $cond . ') ? (' . $if . ') : (' . $else . ')';
    }

    protected function formatTernaryReturn(
        NodeAbstract $valueExpr,
        string $value,
        array $beforeStmts,
        array $afterStmts,
        string $returnType,
        string $valueType,
        string $nativeClass = '',
    ): string
    {
        $code = $this->formatCapturedStmtLines($beforeStmts);
        $returnsReference = $valueType === Type::REF
            || ($valueExpr instanceof Expr\CallLike && $this->resolveRefReturningCall($valueExpr) !== false);
        if ($nativeClass === '' && $returnType !== Type::VAR && ($beforeStmts || $afterStmts)) {
            $value = $this->convertExprFromType($returnType, $value);
        }
        if ($afterStmts || $returnsReference) {
            if ($nativeClass !== '') {
                $tmpVar = $this->genTmpVarName();
                $this->addLocalVar($tmpVar, $returnType);
                $this->addNativeObject($tmpVar, $nativeClass);
            } else {
                $tmpVar = $this->addTmpVar($returnType);
            }
            $code .= $this->getIndent() . "{$tmpVar} = {$value};" . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';' . PHP_EOL;
        } else {
            $code .= $returnType === Type::VAR
                ? $this->getIndent() . 'return php::Var(' . $value . ');' . PHP_EOL
                : $this->getIndent() . 'return ' . $value . ';' . PHP_EOL;
        }
        return $code;
    }

    protected function parseMatch(Expr\Match_ $expr): string
    {
        $nativeClass = $this->detectClassOfExpr($expr);
        $nativeSelection = $this->isNativeObjectClass($nativeClass);
        $conditionNativeClass = $this->detectClassOfExpr($expr->cond);
        $nativeCondition = $this->isNativeObjectClass($conditionNativeClass);
        $returnType = $nativeSelection
            ? $this->getNativeObjectPointerType($nativeClass)
            : Type::VAR;
        $this->assertExprCanBeUsedAsValue($expr->cond, 'match condition');
        $var = $this->parseIdentifier($expr->cond);
        if ($this->isVarExpr($expr->cond)) {
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->cond);
            }
        } else {
            if ($nativeCondition) {
                $tmpVar = $this->genTmpVarName();
                $this->addLocalVar($tmpVar, $this->getNativeObjectPointerType($conditionNativeClass));
                $this->addNativeObject($tmpVar, $conditionNativeClass);
            } else {
                $tmpVar = $this->addTmpVar(Type::VAR);
            }
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $var . ';';
            $var = $tmpVar;
        }

        $code = '[&]() -> ' . $returnType . ' {' . PHP_EOL;
        $this->indentLevel++;
        $default = null;
        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;
                continue;
            }
            $matched = $this->genTmpVarName();
            $code .= $this->getIndent() . 'bool ' . $matched . ' = false;' . PHP_EOL;
            foreach ($arm->conds as $cond) {
                if ($this->isMatchExpr($cond)) {
                    $this->fatalError($arm, 'Match expression cannot be used as a condition');
                }
                $this->assertExprCanBeUsedAsValue($cond, 'match arm condition');
                [$condValue, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($cond);
                $code .= $this->getIndent() . 'if (!' . $matched . ') {' . PHP_EOL;
                $this->indentLevel++;
                $code .= $this->formatCapturedStmtLines($beforeStmts);
                if ($afterStmts) {
                    $condClass = $this->detectClassOfExpr($cond);
                    if ($nativeCondition && $this->isNativeObjectClass($condClass)) {
                        $condTmpVar = $this->genTmpVarName();
                        $this->addLocalVar($condTmpVar, $this->getNativeObjectPointerType($condClass));
                        $this->addNativeObject($condTmpVar, $condClass);
                    } else {
                        $condTmpVar = $this->addTmpVar(Type::VAR);
                    }
                    $code .= $this->getIndent() . "{$condTmpVar} = {$condValue};" . PHP_EOL;
                    $code .= $this->formatCapturedStmtLines($afterStmts);
                    $condValue = $condTmpVar;
                }
                if ($nativeCondition) {
                    $condClass = $this->detectClassOfExpr($cond);
                    if ($this->isNull($cond)) {
                        $comparison = $var . ' == nullptr';
                    } elseif ($this->isNativeObjectClass($condClass)
                        && ($this->isObjectClassStaticallyAssignableTo($condClass, $conditionNativeClass)
                            || $this->isObjectClassStaticallyAssignableTo($conditionNativeClass, $condClass))
                    ) {
                        $comparison = $var . ' == ' . $condValue;
                    } else {
                        // PHP match uses strict identity. An unrelated Native
                        // pointer or a Zend value can never be identical, but
                        // its expression must still be evaluated for effects.
                        $comparison = '(static_cast<void>(' . $condValue . '), false)';
                    }
                    $code .= $this->getIndent() . $matched . ' = (' . $comparison . ');' . PHP_EOL;
                } else {
                    $code .= $this->getIndent() . $matched . ' = php::same(' . $var . ', ' . $condValue . ');' . PHP_EOL;
                }
                $this->indentLevel--;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            $code .= $this->getIndent() . 'if (' . $matched . ') {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->formatMatchReturn($arm->body, $nativeClass);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }

        if ($default) {
            $code .= $this->getIndent() . '{' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->formatMatchReturn($default, $nativeClass);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        } else {
            $code .= $this->getIndent() . '{' . PHP_EOL;
            $this->indentLevel++;
            $code .= $nativeSelection
                ? $this->getIndent() . 'php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL
                    . $this->getIndent() . 'return nullptr;' . PHP_EOL
                : $this->getIndent() . 'return php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '}()';

        return $code;
    }

    protected function formatMatchReturn(NodeAbstract $body, string $nativeClass = ''): string
    {
        $this->assertExprCanBeUsedAsValue($body, 'match arm');
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($body);
        if ($nativeClass !== '' && $this->isNull($body)) {
            $value = 'nullptr';
        }
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            if ($nativeClass !== '') {
                $tmpVar = $this->genTmpVarName();
                $this->addLocalVar($tmpVar, $this->getNativeObjectPointerType($nativeClass));
                $this->addNativeObject($tmpVar, $nativeClass);
            } else {
                $tmpVar = $this->addTmpVar(Type::VAR);
            }
            $code .= $this->getIndent() . "{$tmpVar} = {$value};" . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';' . PHP_EOL;
        } else {
            $code .= $this->getIndent() . 'return ' . $value . ';' . PHP_EOL;
        }
        return $code;
    }


    protected function parseValueSelection(NodeAbstract $expr, Expr $left, Expr $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'selection value');
        $this->assertExprCanBeUsedAsValue($right, 'selection value');
        $nativeClass = $this->detectClassOfExpr($expr);
        if ($this->isNativeObjectClass($nativeClass)) {
            return $this->parseNativeValueSelection($left, $right, $nativeClass);
        }
        $nativeArrayAccessLeft = $left instanceof Expr\ArrayDimFetch
            && $this->isNativeObjectClass($this->detectClassOfExpr($left->var));
        // Native ArrayAccess coalesce must let the presence helper evaluate
        // offsetExists() before offsetGet(), with receiver/key evaluated once.
        $leftExpr = $nativeArrayAccessLeft ? '' : $this->parseIdentifier($left);
        if ($this->isVarExpr($left)) {
            $this->checkVarMustExist($left, $leftExpr);
        }

        $leftType = $this->detectTypeOfExpr($left);
        $simpleShorthand = $op === self::OP_NOT_EMPTY
            && $this->isVarExpr($left)
            && $leftType !== Type::REF;
        if ($simpleShorthand) {
            // A local variable is already a stable value. Avoid routing it
            // through the generic operation-chain walker, which otherwise
            // copies the value into a Variant result before testing it.
            $condExpr = $this->convertConditionExpr($left, $leftExpr);
        } else {
            $condExpr = $this->parseChainedExpr($left, $op, true);
            $chainOpResult = $left->getAttribute('chainOpResult');
            if ($chainOpResult) {
                $leftExpr = $chainOpResult;
            }
            if ($op === self::OP_NOT_EMPTY
                && in_array($leftType, [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
                $condExpr = '((' . $condExpr . '), ' . $this->convertBoolExpr($leftExpr, $leftType) . ')';
            }
        }

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        if ($simpleShorthand && !$rightBeforeStmts && !$rightAfterStmts) {
            // C++'s conditional operator evaluates the condition first and
            // only the selected branch. Explicit Variant materialization is
            // needed when the PHP branch types differ; otherwise C++ could
            // choose a common scalar type (for example bool -> int).
            $rightType = $this->detectTypeOfExpr($right);
            if ($leftType !== $rightType) {
                $leftExpr = 'php::Var(' . $leftExpr . ')';
                $rightExpr = 'php::Var(' . $rightExpr . ')';
            }
            return '(' . $condExpr . ') ? (' . $leftExpr . ') : (' . $rightExpr . ')';
        }

        $tmpVar = $this->addTmpVar(Type::VAR);
        $comment = $this->debug
            ? $this->formatCppLineComment('Expr: ', $this->printer->prettyPrintExpr($expr)) . PHP_EOL
            : '';
        if ($rightBeforeStmts || $rightAfterStmts) {
            $code = $comment .
                'if (' . $condExpr . ') {' . PHP_EOL .
                $this->getIndent() . $tmpVar . ' = ' . $leftExpr . ';' . PHP_EOL .
                '} else {' . PHP_EOL;
            if ($rightBeforeStmts) {
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightBeforeStmts) . PHP_EOL;
            }
            if ($rightAfterStmts) {
                $rightTmpVar = $this->addTmpVar(Type::VAR);
                $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightAfterStmts) . PHP_EOL;
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightTmpVar . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
            }
            $code .= '}';
            $this->context->beforeStmtLines[] = $code;
        } else {
            $this->context->beforeStmtLines[] = $comment .
                $tmpVar . ' = ' . $condExpr . ' ? ' . $leftExpr . ' : ' . $rightExpr . ';';
        }
        $expr->setAttribute('replace', $tmpVar);

        return $tmpVar;
    }

    protected function parseNativeValueSelection(Expr $left, Expr $right, string $nativeClass): string
    {
        $pointerType = $this->getNativeObjectPointerType($nativeClass);
        [$leftValue, $leftBefore, $leftAfter] = $this->parseExprWithCapturedStmts($left);
        [$rightValue, $rightBefore, $rightAfter] = $this->parseExprWithCapturedStmts($right);
        if ($this->isNull($left)) {
            $leftValue = 'nullptr';
        }
        if ($this->isNull($right)) {
            $rightValue = 'nullptr';
        }

        $leftTmp = $this->genTmpVarName();
        $this->addLocalVar($leftTmp, $pointerType);
        $this->addNativeObject($leftTmp, $nativeClass);

        $code = '[&]() -> ' . $pointerType . ' {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->formatCapturedStmtLines($leftBefore);
        $code .= $this->getIndent() . $leftTmp . ' = ' . $leftValue . ';' . PHP_EOL;
        $code .= $this->formatCapturedStmtLines($leftAfter);
        $code .= $this->getIndent() . 'if (' . $leftTmp . ' != nullptr) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'return ' . $leftTmp . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $code .= $this->formatCapturedStmtLines($rightBefore);
        if ($rightAfter) {
            $rightTmp = $this->genTmpVarName();
            $this->addLocalVar($rightTmp, $pointerType);
            $this->addNativeObject($rightTmp, $nativeClass);
            $code .= $this->getIndent() . $rightTmp . ' = ' . $rightValue . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightAfter);
            $rightValue = $rightTmp;
        }
        $code .= $this->getIndent() . 'return ' . $rightValue . ';' . PHP_EOL;
        $this->indentLevel--;
        return $code . $this->getIndent() . '}()';
    }

}
