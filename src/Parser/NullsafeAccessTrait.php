<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers nullsafe property and method access chains.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

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
        Expr\PropertyFetch|Expr\MethodCall|Expr\NullsafePropertyFetch|Expr\NullsafeMethodCall $expr
    ): string
    {
        $native = $this->parseNativeNullsafeAccess($expr);
        if ($native !== null) {
            return $native;
        }

        $list = [];
        $ownedTmpVars = [];
        $comment = $this->debug
            ? $this->formatCppLineComment('Nullsafe Operator: ', $this->printer->prettyPrint([$expr])) . PHP_EOL
            : '';

        while (1) {
            if ($expr instanceof Expr\NullsafePropertyFetch) {
                $list[] = ['property', $this->propertyNameToStr($expr->name, literal: true), $expr, true];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\NullsafeMethodCall) {
                $list[] = ['method', $this->methodNameToStr($expr->name, literal: true), $expr->args, true, $expr];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\PropertyFetch) {
                $list[] = ['property', $this->propertyNameToStr($expr->name, literal: true), $expr, false];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\MethodCall) {
                $list[] = ['method', $this->methodNameToStr($expr->name, literal: true), $expr->args, false, $expr];
                $expr = $expr->var;
            } else {
                if ($this->isVarExpr($expr)) {
                    $object = $this->parseIdentifier($expr);
                    if (!$this->hasVar($object)) {
                        $this->errorUndefinedVariable($expr);
                    }
                    $type = $this->getVarType($object);
                    if ($type === Type::OBJECT) {
                        break;
                    }
                }
                $object = $this->addTmpVar(Type::OBJECT);
                $ownedTmpVars[] = $object;
                $this->addBeforeStmtLine($this->getIndent() . $object . ' = ' . $this->parseIdentifier($expr) . ';');
                break;
            }
        }

        $list = array_reverse($list);
        $this->checkNullsafePropertyAccesses($expr, $list);
        $last = array_key_last($list);
        $tmpFn = $this->genTmpVarName();

        $code = $comment . 'auto ' . $tmpFn . ' = [&]() -> ' . Type::VAR . ' {' . PHP_EOL;
        $this->indentLevel++;

        foreach ($list as $key => $item) {
            $tmpVar = $this->addTmpVar($key !== $last ? Type::OBJECT : Type::VAR);
            $ownedTmpVars[] = $tmpVar;
            if ($item[3]) {
                $code .= $this->getIndent() . "if ({$object}.isNull()) {" . PHP_EOL;
                $this->indentLevel++;
                $code .= $this->getIndent() . 'return ' . self::VALUE_NULL . ';' . PHP_EOL;
                $this->indentLevel--;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            if ($item[0] == 'property') {
                $update = $this->escapeAttrMode($this->isPropertyFetchUpdate($item[2]));
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.attr({$item[1]}, {$update});" . PHP_EOL;
            } else {
                $methodName = $this->isNamedMethod($item[4]->name)
                    ? $this->parseIdentifier($item[4]->name)
                    : '';
                $receiverClass = $this->detectClassOfExpr($item[4]->var);
                $requiresDynamicScope = $this->runtimeMethodRequiresDynamicScope($receiverClass, $methodName);
                [$args, $argBeforeStmts, $argAfterStmts] = $this->captureStmtLines(
                    fn() => $this->parseCallArgs($item[2]),
                );
                if ($argBeforeStmts) {
                    $code .= $this->formatCapturedStmtLines($argBeforeStmts);
                }
                if ($requiresDynamicScope && $this->methodDef) {
                    if ($this->isNamedMethod($item[4]->name)) {
                        $code .= $this->getIndent()
                            . "{$tmpVar} = typephp_call_method_scoped_cached({$object}, {$item[1]}, "
                            . $this->getCallableScopeExpr() . ', ' . $this->getMethodCallCache()
                            . ", {$args});" . PHP_EOL;
                    } else {
                        $code .= $this->getIndent() . "{$tmpVar} = php::callScoped({$object}, {$item[1]}, "
                            . $this->getCallableScopeExpr() . ", {$args});" . PHP_EOL;
                    }
                } else {
                    if ($this->isNamedMethod($item[4]->name)) {
                        $code .= $this->getIndent() . "{$tmpVar} = typephp_call_method_cached({$object}, {$item[1]}, "
                            . $this->getMethodCallCache() . ", {$args});" . PHP_EOL;
                    } else {
                        $code .= $this->getIndent() . "{$tmpVar} = {$object}.call({$item[1]}, {$args});" . PHP_EOL;
                    }
                }
                if ($argAfterStmts) {
                    $code .= $this->formatCapturedStmtLines($argAfterStmts);
                }
            }
            $object = $tmpVar;
        }
        $code .= $this->getIndent() . "return {$object};" . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '};';
        $this->addBeforeStmtLine($code);

        // C++ temporaries are function-scoped; release their zvals at the PHP statement boundary.
        foreach (array_reverse($ownedTmpVars) as $ownedTmpVar) {
            $this->addAfterStmtLine($ownedTmpVar . '.unset();');
        }
        return "{$tmpFn}()";
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
        foreach ($list as $item) {
            if ($item[0] !== 'property') {
                break;
            }

            /** @var Expr\NullsafePropertyFetch $node */
            $node = $item[2];
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
