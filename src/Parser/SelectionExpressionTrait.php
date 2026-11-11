<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers ternary, match, coalesce-style value selection, and branch temporaries.
 */

namespace TypePhp\Parser;

use TypePhp\Type;
use TypePhp\Entity\PropertyDef;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait SelectionExpressionTrait
{
    protected function getCoalesceObjectClass(Expr\BinaryOp\Coalesce $expr): string
    {
        $common = '';
        foreach ([$expr->left, $expr->right] as $branch) {
            if ($this->isNull($branch)) {
                continue;
            }
            $class = $this->detectDeclaredClassOfExpr($branch);
            if ($class === '') {
                $property = $this->getSelectionPropertyDef($branch);
                if ($property?->type === Type::OBJECT) {
                    $class = $property->class;
                }
            }
            if ($class === '') {
                return '';
            }
            if ($common === '') {
                $common = $class;
            } elseif ($this->isNativeObjectClass($common) || $this->isNativeObjectClass($class)) {
                if (!$this->isNativeObjectClass($common) || !$this->isNativeObjectClass($class)) {
                    return '';
                }
                $common = $this->getCommonNativeObjectClass($common, $class);
                if ($common === '') {
                    return '';
                }
            } elseif ($this->isObjectClassStaticallyAssignableTo($class, $common)) {
                continue;
            } elseif ($this->isObjectClassStaticallyAssignableTo($common, $class)) {
                $common = $class;
            } else {
                return '';
            }
        }
        return $common;
    }

    protected function getCoalesceResultType(Expr\BinaryOp\Coalesce $expr): string
    {
        if ($this->getCoalesceObjectClass($expr) !== '') {
            return Type::OBJECT;
        }
        $left = $this->getNonNullSelectionType($expr->left);
        // A normal hop in a nullsafe chain can also be skipped.
        $right = $this->containsNullsafeChain($expr->right)
            ? Type::VAR : $this->detectTypeOfExpr($expr->right);
        if ($this->isNull($expr->left)) {
            $left = $right;
        }
        if ($left !== $right || !in_array($left, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY], true)) {
            return Type::VAR;
        }
        if ($this->varIntTypes && $left === Type::INT) {
            return Type::VAR;
        }
        return $left;
    }

    protected function getSelectionPropertyDef(Expr $expr): ?PropertyDef
    {
        $static = $expr instanceof Expr\StaticPropertyFetch;
        if (($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch)
            && $expr->name instanceof Node\Identifier) {
            $class = $this->detectDeclaredClassOfExpr($expr->var);
        } elseif ($static && $expr->class instanceof Node\Name && $expr->name instanceof Node\VarLikeIdentifier) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'static' || ($class === 'self' && $this->classDef?->trait)) {
                return null;
            }
            $class = match ($class) {
                'self' => $this->getFullClassName(),
                'parent' => $this->classDef?->extends ?? '',
                default => $this->getNamespacedClassName($class),
            };
        } else {
            return null;
        }
        if ($class === '' || $this->isNativeObjectClass($class)) {
            return null;
        }
        return $this->createPropertyAccessResolver()->findReadableProperty(
            $class, $expr->name->toString(), $this->classDef ? $this->getFullClassName() : '', $static,
        );
    }

    /** Read a single nullable contract; heterogeneous unions stay dynamic. */
    protected function getNonNullSelectionType(Expr $expr): string
    {
        if ($expr instanceof Expr\NullsafeMethodCall) {
            $expr = new Expr\MethodCall($expr->var, $expr->name, $expr->args, $expr->getAttributes());
        } elseif ($expr instanceof Expr\NullsafePropertyFetch) {
            $expr = new Expr\PropertyFetch($expr->var, $expr->name, $expr->getAttributes());
        }
        $type = $this->detectTypeOfExpr($expr);
        if ($type !== Type::VAR && $type !== Type::REF) {
            return $type;
        }
        $property = $this->getSelectionPropertyDef($expr);
        $check = $property?->typeCheck;
        if ($property !== null && $property->type !== Type::VAR) {
            return $property->type;
        }
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $class = $this->detectDeclaredClassOfExpr($expr->var);
            if ($class !== '' && $this->hasInaccessibleDeclaredMethod($class, $expr->name->toString())) {
                return Type::VAR;
            }
        }
        $function = $this->resolveCalledFunctionDef($expr);
        if ($function !== null) {
            if ($function->returnType !== Type::VAR && $function->returnType !== Type::VOID) {
                return $function->returnType;
            }
            $check = $function->returnTypeCheck;
        } elseif ($expr instanceof Expr\Variable && is_string($expr->name)) {
            foreach ($this->functionDef?->argInfoList ?? [] as $argument) {
                if ($argument->name === $this->parseVariable($expr)) {
                    $check = $argument->typeCheck;
                    break;
                }
            }
        }
        $result = Type::VAR;
        foreach ($check ?? [] as $entry) {
            if (($entry['kind'] ?? '') === 'isNull') {
                continue;
            }
            $candidate = match ($entry['kind'] ?? '') {
                'isInt' => Type::INT, 'isFloat' => Type::FLOAT, 'isBool' => Type::BOOL,
                'isString' => Type::STR, 'isArray' => Type::ARRAY, default => Type::VAR,
            };
            if ($candidate === Type::VAR || ($result !== Type::VAR && $result !== $candidate)) {
                return Type::VAR;
            }
            $result = $candidate;
        }
        return $result;
    }

    protected function parsePropertyPresenceValue(Expr\PropertyFetch $expr): string
    {
        $class = $this->detectDeclaredClassOfExpr($expr->var);
        if ($this->isNativeObjectClass($class)) {
            return $this->parseIdentifier($expr);
        }
        $receiver = $this->parseOrderedOperand($expr->var, false);
        $this->checkVarMustExist($expr->var, $receiver);
        $receiverType = $this->getLoweredExpressionType($expr->var) ?? $this->detectTypeOfExpr($expr->var);
        if ($this->isNativeType($receiverType)) {
            $receiver = 'php::Var(' . $receiver . ')';
        }
        $property = $this->getSelectionPropertyDef($expr);
        $name = $expr->name instanceof Node\Identifier
            ? $this->getLiteralString($expr->name->toString())
            : $this->parseOrderedOperand($expr->name, false, true);
        if ($property !== null && $this->isFinalClass($class)
            && $this->getClass($class)->extends === '' && $property->getter === null
            && !$this->objectTypeDeclaresMethod($class, '__get')
            && !$this->objectTypeDeclaresMethod($class, '__isset')) {
            $resolution = $this->resolveNativeInstanceProperty($expr, $expr->name->toString(), $class);
            $offset = $this->applyNativePropertyAccessResult($expr, $resolution);
            $value = $receiver . '.attr(' . $offset . ', php::AttrMode::Isset)';
        } else {
            $scope = $this->classDef ? $this->getClassEntryPtr($this->getFullClassName()) : 'nullptr';
            $value = 'typephp_read_property_scoped(' . $receiver . ', ' . $name
                . ', ' . $scope . ', php::AttrMode::Isset)';
        }
        return '(' . $receiver . '.isObject() ? ' . $value . ' : php::Var{})';
    }

    protected function parseCoalesceSelection(Expr\BinaryOp\Coalesce $expr, string $type): string
    {
        $result = $this->addTmpVar($type);
        $objectClass = $type === Type::OBJECT ? $this->getCoalesceObjectClass($expr) : '';
        if ($objectClass !== '') {
            $this->addObject($result, $objectClass);
        }
        $presence = null;
        [$left, $before, $after] = $this->captureStmtLines(function () use ($expr, &$presence): string {
            $left = $expr->left;
            if ($left instanceof Expr\ArrayDimFetch
                || ($left instanceof Expr\PropertyFetch
                    && !$this->containsNullsafeChain($left)
                    && ($left->var instanceof Expr\PropertyFetch || $left->var instanceof Expr\ArrayDimFetch))) {
                $presence = $this->parseChainedExpr($left, self::OP_ISSET, true);
                $value = $left->getAttribute('chainOpResult');
                if ($value !== null) {
                    return $value;
                }
                $presence = null;
                return $this->parseIdentifier($left);
            }
            if ($left instanceof Expr\NullsafePropertyFetch
                || ($left instanceof Expr\PropertyFetch && $this->containsNullsafeChain($left))) {
                return $this->parseNullsafeExpr($left, true);
            }
            if ($left instanceof Expr\PropertyFetch && !$this->containsNullsafeChain($left)) {
                return $this->parsePropertyPresenceValue($left);
            }
            return $this->parseIdentifier($left);
        });
        $this->checkVarMustExist($expr->left, $left);
        $this->appendCapturedStmtLinesToContext($before);
        foreach ($after as $line) {
            $this->addAfterStmtLine($line);
        }
        [$right, $rightBefore, $rightAfter] = $this->parseExprWithCapturedStmts($expr->right);
        $this->checkVarMustExist($expr->right, $right);
        $format = fn(string $value): string => $type === Type::OBJECT
            ? 'php::Object(' . $value . ')'
            : $this->convertExprFromType($type, $value);
        if (!$expr->left instanceof Expr\PropertyFetch && !$expr->left instanceof Expr\StaticPropertyFetch
            && !$expr->left instanceof Expr\ArrayDimFetch && !$this->containsNullsafeChain($expr->left)
            && in_array($this->detectTypeOfExpr($expr->left), [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY], true)) {
            $expr->setAttribute(self::ATTR_LOWERED_TYPE, $type);
            $this->context->registerMaterializedExpression($expr, $result, $result . ' = ' . $format($left) . ';');
            return $result;
        }
        $probe = $presence !== null || $this->isVarExpr($expr->left)
            ? $left : $this->addTmpVar($type === Type::OBJECT ? Type::OBJECT : Type::VAR);
        $code = $probe !== $left ? $probe . ' = ' . $left . ';' . PHP_EOL : '';
        $code .= 'if (' . ($presence ?? 'php::exists(' . $probe . ')') . ') {' . PHP_EOL;
        $code .= $this->withIndentedBlock(fn() => $this->getIndent() . $result . ' = ' . $format($probe) . ';' . PHP_EOL);
        $code .= $this->getIndent() . '} else {' . PHP_EOL;
        $code .= $this->withIndentedBlock(fn() => $this->formatCapturedStmtLines($rightBefore)
            . $this->getIndent() . $result . ' = ' . $format($right) . ';' . PHP_EOL
            . $this->formatCapturedStmtLines($rightAfter));
        $code .= $this->getIndent() . '}';
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, $type);
        $this->context->registerMaterializedExpression($expr, $result, $code);
        if ($probe !== $left) {
            $this->scheduleTemporaryCleanup($probe);
        }
        return $result;
    }

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
        [$if, $ifBeforeStmts, $ifAfterStmts] = $this->parseExprWithCapturedStmts($expr->if);
        [$else, $elseBeforeStmts, $elseAfterStmts] = $this->parseExprWithCapturedStmts($expr->else);

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
                : $this->getSelectionStorageType($this->getNormalAssignType($typeChanged ? Type::VAR : $ifType));
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
        // A captured condition can force a typed lambda even when its branches
        // emit no statements. Typed Zend properties may still lower to Variant;
        // only a proven C++ storage type can cross this return boundary directly.
        // Object's converting constructor preserves null at the return
        // boundary; a PHP object cast would replace it with stdClass.
        if ($nativeClass === '' && $returnType !== Type::VAR && $returnType !== Type::OBJECT
            && ($beforeStmts || $afterStmts || $this->getLoweredExpressionType($valueExpr) !== $returnType)) {
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
        if ($nativeSelection) {
            $returnType = $this->getNativeObjectPointerType($nativeClass);
        } else {
            $inferredType = $this->detectTypeOfExpr($expr);
            $returnType = $this->getSelectionStorageType($this->getNormalAssignType($inferredType));
        }
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, $returnType);
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
            $this->addBeforeStmtLine($tmpVar . ' = ' . $var . ';');
            $var = $tmpVar;
        }

        $code = '[&]() -> ' . $returnType . ' {' . PHP_EOL;
        $code .= $this->withIndentedBlock(function () use (
            $expr, $var, $nativeCondition, $conditionNativeClass,
            $nativeClass, $nativeSelection, $returnType,
        ): string {
            $code = '';
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
                    $code .= $this->withIndentedBlock(function () use (
                        $cond, $condValue, $beforeStmts, $afterStmts,
                        $nativeCondition, $conditionNativeClass, $var, $matched,
                    ): string {
                        $code = $this->formatCapturedStmtLines($beforeStmts);
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
                        return $code;
                    });
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                }
                $code .= $this->getIndent() . 'if (' . $matched . ') {' . PHP_EOL;
                $code .= $this->withIndentedBlock(
                    fn() => $this->formatMatchReturn($arm->body, $nativeClass, $returnType),
                );
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }

            if ($default) {
                $code .= $this->getIndent() . '{' . PHP_EOL;
                $code .= $this->withIndentedBlock(
                    fn() => $this->formatMatchReturn($default, $nativeClass, $returnType),
                );
                $code .= $this->getIndent() . '}' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . '{' . PHP_EOL;
                $code .= $this->withIndentedBlock(function () use ($nativeSelection, $returnType): string {
                    $code = '';
                    if ($nativeSelection) {
                        $code .= $this->getIndent() . 'php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL
                            . $this->getIndent() . 'return nullptr;' . PHP_EOL;
                    } elseif ($returnType === Type::VAR) {
                        $code .= $this->getIndent() . 'return php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL;
                    } else {
                        $code .= $this->getIndent() . 'php::throwException("UnhandledMatchError", "Unhandled match case");' . PHP_EOL
                            . $this->getIndent() . 'return {};' . PHP_EOL;
                    }
                    return $code;
                });
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            return $code;
        });
        $code .= $this->getIndent() . '}()';

        return $code;
    }

    protected function formatMatchReturn(NodeAbstract $body, string $nativeClass = '', string $returnType = Type::VAR): string
    {
        $this->assertExprCanBeUsedAsValue($body, 'match arm');
        if ($body instanceof Expr\Throw_) {
            // parseThrow materializes its operand before cleaning up temporaries.
            [$value, $beforeStmts] = $this->parseExprWithCapturedStmts($body);
            $code = $this->formatCapturedStmtLines($beforeStmts);
            if ($nativeClass !== '') {
                $code .= $this->getIndent() . $value . ';' . PHP_EOL;
                $code .= $this->getIndent() . 'return nullptr;' . PHP_EOL;
            } elseif ($returnType === Type::VAR) {
                $code .= $this->getIndent() . 'return ' . $value . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . $value . ';' . PHP_EOL;
                $code .= $this->getIndent() . 'return {};' . PHP_EOL;
            }
            return $code;
        }
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($body);
        if ($nativeClass !== '' && $this->isNull($body)) {
            $value = 'nullptr';
        } elseif ($nativeClass === '' && $returnType !== Type::VAR && $returnType !== Type::OBJECT) {
            $provenType = $this->getLoweredExpressionType($body);
            if ($provenType === null && $this->isVarExpr($body) && is_string($body->name) && $this->hasVar($body->name)) {
                $provenType = $this->getVarType($body->name);
            }
            if ($provenType !== $returnType) {
                $value = $this->convertExprFromType($returnType, $value);
            }
        }
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            if ($nativeClass !== '') {
                $tmpVar = $this->genTmpVarName();
                $this->addLocalVar($tmpVar, $this->getNativeObjectPointerType($nativeClass));
                $this->addNativeObject($tmpVar, $nativeClass);
            } else {
                $tmpVar = $this->addTmpVar($returnType);
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
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            $type = $this->getCoalesceResultType($expr);
            $nativeProperty = $left instanceof Expr\PropertyFetch
                && $this->isNativeObjectClass($this->detectClassOfExpr($left->var));
            if (!$nativeProperty) {
                return $this->parseCoalesceSelection($expr, $type);
            }
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

        [$rightExpr, $rightBeforeStmts, $rightAfterStmts] = $this->captureStmtLines(
            fn() => $this->parseIdentifier($right),
        );
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
        } else {
            // This path materializes a Variant result. Both operands must
            // have that C++ type: Variant and const String (empty_string)
            // otherwise admit conversions in both directions for ?:.
            $code = $comment .
                $tmpVar . ' = ' . $condExpr . ' ? php::Var(' . $leftExpr . ') : php::Var(' . $rightExpr . ');';
        }
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, Type::VAR);
        $this->context->registerMaterializedExpression($expr, $tmpVar, $code);

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

    protected function getSelectionStorageType(string $type): string
    {
        return match ($type) {
            Type::BIGINT,
            Type::BIGFLOAT,
            Type::DECIMAL,
            Type::STREAM,
            Type::BOX => Type::VAR,
            default => $type,
        };
    }

}
