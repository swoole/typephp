<?php

/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Foreach_;
use TypePhp\Type;

trait ForeachTrait
{
    protected function parseForeachItemAsList(string $listTmpVar, array $listItems): string
    {
        $code = '';
        foreach ($listItems as $k => $item) {
            if (!$item) {
                continue;
            }
            if ($item instanceof ArrayItem) {
                if ($item->byRef) {
                    $this->fatalError($item, 'Foreach list destructuring cannot bind items by reference');
                }
                if ($item->value instanceof Expr\List_) {
                    $key = $item->key ? $this->parseArrayKey($item->key, true) : (string) $k;
                    $nestedTmpVar = $this->genTmpVarName();
                    $this->addLocalVar($nestedTmpVar, Type::VAR);
                    $code .= $this->getIndent() . $nestedTmpVar . ' = ' . $listTmpVar . '.item(' . $key . ');' . PHP_EOL;
                    $code .= $this->parseForeachItemAsList($nestedTmpVar, $item->value->items);
                    continue;
                }
                $value = new Expr\ArrayDimFetch(
                    new Expr\Variable($listTmpVar, $item->getAttributes()),
                    $item->key ?? new Int_($k, $item->getAttributes()),
                    $item->getAttributes(),
                );
                $code .= $this->getIndent() . $this->parseDestructuringWrite($item->value, $value) . ';' . PHP_EOL;
            } else {
                $this->fatalError($item, 'Unsupported foreach item type');
            }
        }
        return $code;
    }

    protected function parseForeachBody(Foreach_ $node): string
    {
        return $this->parseStmts($node->stmts);
    }

    protected function parseForeachKeyAssignment(Foreach_ $node, string $keyExpr, string $defaultType = Type::VAR): string
    {
        if (!$node->keyVar) {
            return '';
        }

        $keyVar = $this->parseIdentifier($node->keyVar);
        if ($this->isVarExpr($node->keyVar) && $this->hasVar($keyVar)
            && $this->getRawVarType($keyVar) !== $defaultType
        ) {
            $key = $this->genTmpVarName();
            $this->addLocalVar($key, $defaultType);
            return $this->getIndent() . $key . ' = ' . $keyExpr . ';' . PHP_EOL
                . $this->getIndent() . $this->parseDestructuringWrite(
                    $node->keyVar,
                    new Expr\Variable($key, $node->keyVar->getAttributes()),
                ) . ';' . PHP_EOL;
        }
        $this->checkVar($node, $keyVar, $defaultType);
        return $this->getIndent() . $keyVar . ' = ' . $keyExpr . ';' . PHP_EOL;
    }

    protected function parseForeachValueAssignment(Foreach_ $node, string $valueExpr, ?string $valueRefExpr = null): string
    {
        if ($node->byRef && $valueRefExpr === null) {
            $this->fatalError($node, 'Cannot use & with foreach');
        }

        if ($node->valueVar instanceof Expr\List_) {
            if ($node->byRef) {
                $this->fatalError($node, 'Foreach by reference cannot use list destructuring');
            }
            $listTmpVar = $this->genTmpVarName();
            $this->addLocalVar($listTmpVar, Type::VAR);
            return $this->getIndent() . $listTmpVar . ' = ' . $valueExpr . ';' . PHP_EOL
                . $this->parseForeachItemAsList($listTmpVar, $node->valueVar->items);
        }

        if ($node->byRef and !$this->isVarExpr($node->valueVar)) {
            $this->fatalError($node, 'Foreach by reference only supports variable as value');
        }

        if ($this->isArrayDimFetch($node->valueVar)) {
            if ($node->byRef) {
                $this->fatalError($node, 'Foreach by reference only supports variable as value');
            }
            $array = $this->parseIdentifier($node->valueVar->var);
            if (!$this->hasVar($array) or $node->valueVar->dim === null) {
                $this->unsupportedSyntax($node->valueVar);
            }
            $dim = $this->parseIdentifier($node->valueVar->dim);
            return $this->getIndent() . "{$array}.offsetSet({$dim}, {$valueExpr});";
        }

        $valueVar = $this->isPropertyFetch($node->valueVar)
            ? $this->parseWritableIdentifier($node->valueVar)
            : $this->parseIdentifier($node->valueVar);
        if ($node->byRef) {
            if (!$this->hasVar($valueVar)) {
                $this->addLocalVar($valueVar, Type::REF);
            } elseif ($this->getVarType($valueVar) !== Type::REF && $this->getVarType($valueVar) !== Type::VAR) {
                $this->fatalError($node, 'Cannot bind foreach reference to native variable of type ' . $this->getVarType($valueVar));
            }
            return $this->getIndent() . $valueRefExpr . '(' . $valueVar . ');' . PHP_EOL;
        }

        if ($this->isVarExpr($node->valueVar)) {
            if ($this->hasVar($valueVar)
                && !in_array($this->getRawVarType($valueVar), [Type::VAR, Type::REF], true)
            ) {
                // A reused typed local keeps its storage, just like an ordinary
                // assignment from mixed. Delegate the write to the AST-based
                // assignment path for conversions and declared-type checks.
                $value = $this->genTmpVarName();
                $this->addLocalVar($value, Type::VAR);
                return $this->getIndent() . $value . ' = ' . $valueExpr . ';' . PHP_EOL
                    . $this->getIndent() . $this->parseDestructuringWrite(
                        $node->valueVar,
                        new Expr\Variable($value, $node->valueVar->getAttributes()),
                    ) . ';' . PHP_EOL;
            }
            if (!$this->hasVar($valueVar) || $this->getVarType($valueVar) !== Type::REF) {
                $this->checkVar($node, $valueVar);
            }
        }
        return $this->getIndent() . $valueVar . ' = ' . $valueExpr . ';' . PHP_EOL;
    }

    /**
     * Return a foreach target that can be filled directly by ForeachIterator.
     *
     * The combined iterator API deliberately accepts only ordinary Variant
     * variables. References, native scalar locals, properties, array offsets,
     * and destructuring retain the general assignment path below.
     */
    protected function parseDirectForeachTarget(Foreach_ $node, Expr $target): ?string
    {
        if (!$this->isVarExpr($target)) {
            return null;
        }

        $name = $this->parseIdentifier($target);
        if ($this->hasVar($name) && $this->getVarType($name) !== Type::VAR) {
            return null;
        }
        $this->checkVar($node, $name);
        return $name;
    }

    protected function parseForeachIterable(
        Foreach_ $node,
        string $iterableVar,
        bool $allowDirectArrayTargets = false,
    ): string {
        $iterator = $this->genTmpVarName();
        $byRef = $node->byRef ? 'true' : 'false';
        $scope = $this->class ? $this->getLocalClassEntryPtr($this->getFullClassName()) : 'nullptr';
        $directValue = null;
        $directKey = null;
        if ($allowDirectArrayTargets && !$node->byRef) {
            $directValue = $this->parseDirectForeachTarget($node, $node->valueVar);
            if ($directValue !== null && $node->keyVar !== null) {
                $directKey = $this->parseDirectForeachTarget($node, $node->keyVar);
                if ($directKey === null) {
                    $directValue = null;
                }
            }
        }

        if ($directValue !== null) {
            $next = $directKey === null
                ? "{$iterator}.nextValue({$directValue})"
                : "{$iterator}.nextKeyValue({$directKey}, {$directValue})";
        } else {
            $next = "{$iterator}.next()";
        }
        $code = '{' . PHP_EOL;
        $code .= $this->withIndentedBlock(function () use ($node, $iterator, $iterableVar, $byRef, $scope, $next, $directValue): string {
            $code = $this->getIndent() . "php::ForeachIterator {$iterator}{{$iterableVar}, {$byRef}, {$scope}};" . PHP_EOL;
            $code .= $this->getIndent() . "while ({$next}) {" . PHP_EOL;
            [$assignments, $body] = $this->withIndentedBlock(function () use ($node, $iterator, $directValue): array {
                $assignments = '';
                if ($directValue === null) {
                    $assignments .= $this->parseForeachKeyAssignment($node, $iterator . '.key()');
                    $assignments .= $this->parseForeachValueAssignment(
                        $node,
                        $iterator . '.value()',
                        $iterator . '.assignValueRef',
                    );
                }
                return [$assignments, $this->parseForeachBody($node)];
            });

            $code .= $assignments;
            $code .= $this->parseBeforeStmtLines();
            $code .= $body;
            return $code . $this->getIndent() . '}';
        });
        $code .= PHP_EOL . $this->getIndent() . '}';

        return $code;
    }

    protected function parseForeachIterableRef(Foreach_ $node): ?string
    {
        $expr = $node->expr;
        if ($expr instanceof Expr\PropertyFetch) {
            return $this->emitDynamicPropertyFetchRef($expr, $node);
        }

        if ($expr instanceof Expr\StaticPropertyFetch) {
            return $this->emitStaticPropertyFetchRef($expr, $node);
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            if ($expr->dim === null) {
                $this->fatalError($expr, 'Cannot use [] for reading');
            }
            $array = $this->parseWritableIdentifier($expr->var);
            return $array . '.itemRef(' . $this->parseIdentifier($expr->dim) . ')';
        }

        return null;
    }

    protected function parseForeach(Foreach_ $node): string
    {
        if (($typedArray = $this->getTypedArrayDefinition($node->expr)) !== null) {
            return $this->parseTypedArrayForeach($node, $typedArray);
        }
        $typedProperty = $this->getTypedArrayPropertyDefinition($node->expr);
        if ($typedProperty !== null && !$node->byRef && $this->canBindTypedPropertyForeachTargets($node, $typedProperty)) {
            return $this->parseTypedArrayForeach($node, $typedProperty, true);
        }
        if ($node->byRef) {
            $this->assertImmutableMutationTarget($node->expr);
            $this->assertTypedArrayReferenceForbidden($node->expr);
        }
        $nativeClass = $this->detectClassOfExpr($node->expr);
        if ($this->isNativeObjectClass($nativeClass)) {
            if ($this->nativeClassImplementsInterface($nativeClass, 'Iterator')) {
                return $this->parseForeachNativeIterator($node, $node->expr, $nativeClass);
            }
            if ($this->nativeClassImplementsInterface($nativeClass, 'IteratorAggregate')) {
                return $this->parseForeachNativeAggregate($node, $node->expr, $nativeClass);
            }
            $this->fatalError(
                $node->expr,
                "Native class `{$nativeClass}` must implement `Iterator` or `IteratorAggregate` to use foreach",
            );
        }
        if ($this->isVarExpr($node->expr)) {
            $name = $this->parseIdentifier($node->expr);
            if ($this->hasVar($name)) {
                $type = $this->getVarType($name);
                // A by-reference foreach must operate on the original variable.
                // Copying a dynamically typed iterable into a temporary triggers
                // normal PHP array COW, so references would update only that
                // temporary instead of the source variable. ForeachIterator
                // performs the runtime array/object validation itself.
                if ($type === Type::ARRAY
                    || $type === Type::OBJECT
                    || ($node->byRef && ($type === Type::VAR || $type === Type::REF))
                ) {
                    return $this->parseForeachIterable($node, $name, $type === Type::ARRAY);
                } elseif ($this->isStdContainerType($type)) {
                    return $this->parseForeachStdContainer($node);
                }
            }
        }

        $code = '';
        $expr = $node->byRef ? $this->parseForeachIterableRef($node) : null;
        $iterableType = $expr === null ? Type::VAR : Type::REF;
        $expr ??= $this->parseIdentifier($node->expr);
        $code .= $this->parseBeforeStmtLines() . PHP_EOL;

        $iterableVar = $this->genTmpVarName();
        $this->addLocalVar($iterableVar, $iterableType);

        $code .= $iterableVar . ' = ' . $expr . ';' . PHP_EOL;
        $code .= $this->parseForeachIterable($node, $iterableVar);

        return $code;
    }

    private function canBindTypedPropertyForeachTargets(Foreach_ $node, array $definition): bool
    {
        $keyName = null;
        foreach ([[$node->keyVar, $definition['keyType'], ''],
            [$node->valueVar, $definition['type'], $definition['class'] ?? '']] as [$target, $type, $class]) {
            if ($target === null) {
                continue;
            }
            if (!$this->isVarExpr($target)) {
                return false;
            }
            $name = $this->parseVariable($target);
            if ($name === $keyName || isset($this->context->varTypeDegradations[$name])) {
                return false;
            }
            if ($this->hasVar($name) && ($this->getRawVarType($name) !== $type
                || ($class !== '' && $this->getDeclaredObjectType($name) !== $class))) {
                return false;
            }
            $keyName = $name;
        }
        return true;
    }
}
