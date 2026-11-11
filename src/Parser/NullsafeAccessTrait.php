<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers nullsafe property and method access chains.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait NullsafeAccessTrait
{
    protected function parseNullsafePropertyFetch(Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafePropertyFetchUpdate(Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNodeWithUpdateAttribute(
            $expr,
            self::ATTR_PROPERTY_FETCH_UPDATE,
            true,
            fn() => $this->parseNullsafePropertyFetch($expr)
        );
    }

    protected function parseNullsafeMethodCall(Expr\NullsafeMethodCall $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeExpr(
        Expr\PropertyFetch|Expr\MethodCall|Expr\NullsafePropertyFetch|Expr\NullsafeMethodCall $expr,
        bool $probeProperty = false,
    ): string
    {
        $native = $this->parseNativeNullsafeAccess($expr);
        if ($native !== null) {
            return $native;
        }

        $resultExpr = $expr;
        $resultClass = $this->detectClassOfExpr($expr);
        $resultType = $resultClass !== '' ? Type::OBJECT : Type::VAR;
        $list = [];
        $ownedTmpVars = [];
        $comment = $this->debug
            ? $this->formatCppLineComment('Nullsafe Operator: ', $this->printer->prettyPrint([$expr])) . PHP_EOL
            : '';
        while ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            $list[] = $expr;
            $expr = $expr->var;
        }
        $receiverClass = $this->detectDeclaredClassOfExpr($expr);
        $receiver = $expr;
        $borrowedReceiver = $this->isVarExpr($expr) && $this->getVarType($this->parseVariable($expr)) === Type::OBJECT;
        if ($borrowedReceiver) {
            $object = $this->parseVariable($expr);
        } else {
            $object = $this->addTmpVar(Type::OBJECT);
            $ownedTmpVars[] = $object;
            $base = $this->parseIdentifier($expr);
            $this->checkVarMustExist($expr, $base);
            $this->addBeforeStmtLine($object . ' = ' . $base . ';');
            $receiver = new Expr\Variable($object);
            if ($receiverClass !== '') {
                $this->context->declareObjectClass($object, $receiverClass);
            }
        }
        $list = array_reverse($list);
        $this->checkNullsafePropertyAccesses($expr, $list);
        $last = array_key_last($list);
        // Coalesce probes the trailing property chain. A property used as a
        // method receiver is still a normal read and can throw.
        $probeFrom = count($list);
        while ($probeProperty && $probeFrom > 0) {
            $previous = $list[$probeFrom - 1];
            if (!$previous instanceof Expr\PropertyFetch && !$previous instanceof Expr\NullsafePropertyFetch) {
                break;
            }
            --$probeFrom;
        }
        $tmpFn = $this->genTmpVarName();
        $code = $comment . 'auto ' . $tmpFn . ' = [&]() -> ' . $resultType . ' {' . PHP_EOL;
        [$body, $ownedTmpVars] = $this->withIndentedBlock(function () use (
            $list, $last, $object, $receiver, $receiverClass, $ownedTmpVars, $resultType, $borrowedReceiver, $probeFrom,
        ): array {
            $code = '';
            $receiverNonNull = false;
            foreach ($list as $key => $node) {
                $hopClass = $this->detectClassOfExpr($node);
                $isProbe = $key >= $probeFrom;
                if ($node instanceof Expr\NullsafePropertyFetch || $node instanceof Expr\NullsafeMethodCall) {
                    $guard = $object . '.isNull()';
                    if ($isProbe && $key > $probeFrom) {
                        $guard .= ' || ' . $object . '.isUndef()';
                    }
                    $code .= $this->getIndent() . 'if (' . $guard . ') {' . PHP_EOL;
                    $code .= $this->withIndentedBlock(fn() => $this->getIndent() . 'return '
                        . ($resultType === Type::OBJECT ? 'php::Object{}' : self::VALUE_NULL) . ';' . PHP_EOL);
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                    $receiverNonNull = true;
                }
                if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                    $call = new Expr\MethodCall($receiver, $node->name, $node->args, $node->getAttributes());
                    $function = $this->resolveCalledFunctionDef($call);
                    // A normal hop must still throw when its receiver is null.
                    $snapshot = !$receiverNonNull && $this->isTypedObject($object);
                    if ($borrowedReceiver && $node->name instanceof Expr
                        && $this->shouldMaterializeOrderedOperand($node->name)) {
                        $snapshot = true;
                    }
                    foreach ($node->args as $index => $argument) {
                        if (!$borrowedReceiver || !$argument instanceof Node\Arg) {
                            continue;
                        }
                        $argumentType = $this->detectTypeOfExpr($argument->value);
                        $argumentDef = $function?->argInfoList[$index] ?? null;
                        if ($argument->name !== null) {
                            foreach ($function?->argInfoList ?? [] as $declaredArgument) {
                                if (($declaredArgument->phpName ?: $declaredArgument->name) === $argument->name->toString()) {
                                    $argumentDef = $declaredArgument;
                                    break;
                                }
                            }
                        }
                        $byRef = $argumentDef?->byRef ?? false;
                        if ($argument->unpack || $this->shouldMaterializeOrderedOperand($argument->value)
                            || ($byRef && in_array($argumentType, [Type::OBJECT, Type::VAR, Type::REF], true))) {
                            $snapshot = true;
                        }
                    }
                    if ($snapshot) {
                        $saved = $this->addTmpVar(Type::OBJECT);
                        $ownedTmpVars[] = $saved;
                        $code .= $this->getIndent() . $saved . ' = ' . $object . ';' . PHP_EOL;
                        $object = $saved;
                        $receiver = new Expr\Variable($object);
                        $borrowedReceiver = false;
                    }
                    if (!$borrowedReceiver && $receiverClass !== '') {
                        if ($receiverNonNull) {
                            $this->addObject($object, $receiverClass);
                        } else {
                            $this->context->declareObjectClass($object, $receiverClass);
                        }
                    }
                    $call = new Expr\MethodCall($receiver, $node->name, $node->args, $node->getAttributes());
                    [$value, $before, $after] = $this->parseExprWithCapturedStmts($call);
                    $receiverNonNull = $hopClass !== '' && $function !== null
                        && $function->returnTypeNode instanceof Node\Name
                        && !$this->typeNodeAllowsNull($function->returnTypeNode);
                } else {
                    [$value, $before, $after] = $this->captureStmtLines(function () use ($node, $object, $receiverClass, $isProbe): string {
                        $name = $node->name instanceof Node\Identifier
                            ? $this->getLiteralString($node->name->toString())
                            : $this->parseOrderedOperand($node->name, false, true);
                        $scope = $this->classDef ? $this->getClassEntryPtr($this->getFullClassName()) : 'nullptr';
                        if ($receiverClass !== '' && $node->name instanceof Node\Identifier
                            && $this->findReadablePropertyDefinition($node) !== null) {
                            $resolution = $this->resolveNativeInstanceProperty($node, $node->name->toString(), $receiverClass);
                            if ($resolution !== null) {
                                $scope = $this->getClassEntryPtr($resolution->declaringClass);
                            }
                        }
                        $mode = $isProbe ? 'php::AttrMode::Isset' : $this->escapeAttrMode($this->isPropertyFetchUpdate($node));
                        $read = 'typephp_read_property_scoped(' . $object . ', ' . $name . ', ' . $scope
                            . ', ' . $mode . ')';
                        return $isProbe ? '(' . $object . '.isObject() ? ' . $read . ' : php::Var{})' : $read;
                    });
                    $receiverNonNull = false;
                }
                $hopType = $isProbe && $hopClass === '' ? Type::VAR : Type::OBJECT;
                $tmpVar = $this->addTmpVar($key === $last ? $resultType : $hopType);
                $ownedTmpVars[] = $tmpVar;
                if ($hopClass !== '') {
                    $this->context->declareObjectClass($tmpVar, $hopClass);
                }
                $code .= $this->formatCapturedStmtLines($before);
                $code .= $this->getIndent() . $tmpVar . ' = ' . $value . ';' . PHP_EOL;
                $code .= $this->formatCapturedStmtLines($after);
                $object = $tmpVar;
                $receiver = new Expr\Variable($object);
                $receiverClass = $hopClass;
                $borrowedReceiver = false;
            }
            $code .= $this->getIndent() . 'return ' . $object . ';' . PHP_EOL;
            return [$code, $ownedTmpVars];
        });
        $code .= $body . $this->getIndent() . '};';
        $this->addBeforeStmtLine($code);
        foreach (array_reverse($ownedTmpVars) as $ownedTmpVar) {
            $this->scheduleTemporaryCleanup($ownedTmpVar);
        }
        $resultExpr->setAttribute(self::ATTR_LOWERED_TYPE, $resultType);
        return $tmpFn . '()';
    }

    protected function containsNullsafeChain(NodeAbstract $expr): bool
    {
        while ($expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\NullsafeMethodCall) {
            if ($expr instanceof Expr\NullsafePropertyFetch || $expr instanceof Expr\NullsafeMethodCall) {
                return true;
            }
            $expr = $expr->var;
        }

        return false;
    }

    private function checkNullsafePropertyAccesses(NodeAbstract $baseExpr, array $list): void
    {
        $properties = [];
        foreach ($list as $node) {
            if (!$node instanceof Expr\PropertyFetch && !$node instanceof Expr\NullsafePropertyFetch) {
                break;
            }
            if (!$this->isIdExpr($node->name)) {
                break;
            }

            $properties[] = [
                'node' => $node,
                'property' => $this->parseIdentifier($node->name),
            ];
        }

        if (!$properties) {
            return;
        }

        $scope = $this->class ? $this->getFullClassName() : '';
        $results = $this->createPropertyAccessResolver()->resolveNullsafePropertyChain(
            $this->detectClassOfExpr($baseExpr),
            $properties,
            $scope,
            Type::OBJECT,
        );
        foreach ($results as $index => $result) {
            $this->applyNativePropertyAccessResult($properties[$index]['node'], $result);
        }
    }

}
