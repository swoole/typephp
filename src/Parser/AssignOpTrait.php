<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use TypePhp\Resolver\PropertyWriteTarget;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeAbstract;

trait AssignOpTrait
{
    protected function parseAssignArrayDim(
        NodeAbstract $left,
        NodeAbstract $right,
        bool $resultUnused = false,
    ): string
    {
        if ($left instanceof Expr\ArrayDimFetch && $left->dim !== null) {
            $this->assertNotNativeObjectArrayKey($left->dim);
        }
        if ($this->isPropertyFetch($left)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        }
        if ($this->isVarExpr($left->var) && $left->var->name === 'GLOBALS') {
            $target = $this->parseGlobalsArrayDimFetch($left);
            $tmp = $this->genTmpVarName();
            if ($this->isNativeObjectVar($target)) {
                $targetClass = $this->getNativeObjectVarClass($target);
                $rightClass = $this->detectClassOfExpr($right);
                if ($this->isNull($right)) {
                    $value = 'nullptr';
                } elseif (!$this->isNativeObjectClass($rightClass)) {
                    $this->fatalError($right, "Native object `\${$target}` cannot be converted to var/object");
                } elseif (!$this->isObjectClassStaticallyAssignableTo($rightClass, $targetClass)) {
                    $this->fatalError($right, "Cannot assign native object `{$rightClass}` to `{$targetClass}`");
                } else {
                    $value = $this->parseExprAsValue($right);
                }
                $this->addLocalVar($tmp, $this->getNativeObjectPointerType($targetClass));
                $this->addNativeObject($tmp, $targetClass);
            } else {
                $value = $this->parseExprAsValue($right);
                $this->addLocalVar($tmp, Type::VAR);
            }
            return '((' . $tmp . ' = ' . $value . ', ' . $target . ' = ' . $tmp . '), ' . $tmp . ')';
        }
        $array              = $this->parseWritableIdentifier($left->var);
        $code               = '';
        if (!$this->hasVar($array) and $this->isVarExpr($left->var)) {
            $this->addLocalVar($array, Type::ARRAY);
        }

        $value = $this->parseExprAsValue($right);

        // item(dim, true) updates an existing reference's value, while offsetSet()
        // replaces the array bucket and breaks the reference. Keep offsetSet() for
        // ArrayAccess objects; dynamically typed/reference containers need a
        // runtime array check because either representation is possible.
        $arrayType = $this->getVarType($array);

        if ($left->dim === null) {
            if ($resultUnused
                && $arrayType === Type::ARRAY
                && $this->canEmitDirectArrayWriteOperand($right)
            ) {
                return $code . $array . '.appendValue(' . $value . ')';
            }
            $tmp = $this->addTmpVar(Type::VAR);
            return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet(" . self::VALUE_NULL . ", {$tmp})" . '), ' . $tmp . ')';
        }
        $dim = $this->parseIdentifier($left->dim);

        if ($resultUnused
            && $arrayType === Type::ARRAY
            && !$this->shouldMaterializeOrderedOperand($left->dim)
            && $this->canEmitDirectArrayWriteOperand($right)
        ) {
            return $code . $array . '.item(' . $dim . ', true) = ' . $value;
        }

        $tmp = $this->addTmpVar(Type::VAR);
        if ($arrayType === Type::ARRAY) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.item({$dim}, true) = {$tmp}" . '), ' . $tmp . ')';
        }
        if ($arrayType === Type::VAR || $arrayType === Type::REF) {
            $writeArray = "static_cast<void>({$array}.item({$dim}, true) = {$tmp})";
            $writeOther = "{$array}.offsetSet({$dim}, {$tmp})";
            return $code . '((' . $tmp . ' = ' . $value . ', '
                . "({$array}.isArray() ? {$writeArray} : {$writeOther})"
                . '), ' . $tmp . ')';
        }
        return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet({$dim}, {$tmp})" . '), ' . $tmp . ')';
    }

    protected function parseAssignPropertyFetch(
        NodeAbstract $left,
        NodeAbstract $right,
        ?PropertyWriteTarget $target = null,
        bool $resultUnused = false,
    ): string
    {
        if ($target !== null) {
            $this->assertCanAssignPropertyWrite($target, $right);
        }

        $rightExpr = $this->parseExprAsValue($right);
        if ($target !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($target, $right, $rightExpr);
        } else {
            $rightExpr = $this->wrapObjectPropertyAssignTypeCheck($left, $right, $rightExpr);
        }

        if ($resultUnused
            && $left instanceof Expr\PropertyFetch
            && !$this->shouldMaterializeOrderedOperand($left->name)
            && $this->canEmitDirectArrayWriteOperand($right)
            && $this->canEmitDynamicPropertyTarget($target)
        ) {
            return $this->emitDynamicPropertyFetchWrite($left, $rightExpr, $target);
        }

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, Type::VAR);
        // Comma expression: store RHS → execute side effect → evaluate to stored value
        return '((' . $tmp . ' = ' . $rightExpr . ', ' . $this->emitDynamicPropertyFetchWrite($left, $tmp, $target) . '), ' . $tmp . ')';
    }

    protected function parseRightAssociativeAssign(NodeAbstract $left, Expr\Assign $right): string
    {
        $chain[] = $left;
        $next    = $right;
        while ($this->isAssignExpr($next)) {
            $var = $next->var;
            $chain[] = $var;
            $next    = $next->expr;
        }
        $tmpVar = $this->genTmpVarName();
        $nativeClass = $this->detectClassOfExpr($next);
        if ($this->isNativeObjectClass($nativeClass)) {
            $this->addLocalVar($tmpVar, $this->getNativeObjectPointerType($nativeClass));
            $this->addNativeObject($tmpVar, $nativeClass);
        } else {
            $this->addLocalVar($tmpVar, Type::VAR);
        }

        // Reverse the assignment chain
        $chain = array_reverse($chain);
        $list  = [];

        $list[] = $tmpVar . ' = ' . $this->parseExpr($next);
        $rightVar  = new Variable($tmpVar);
        foreach ($chain as $var) {
            $list[] = $this->parseAssignFinally($var, $rightVar);
            // The synthetic temporary has no PHP-level binding metadata. Use
            // the original RHS to retain immutable object identity across a
            // right-associative assignment chain after validating the write.
            $this->recordImmutableAlias($var, $next);
        }

        return '(' . implode(', ', $list) . ')';
    }

    protected function parseAssign(Expr\Assign $v): string
    {
        $left  = $v->var;
        $right = $v->expr;
        if ($this->isAssignExpr($right)) {
            return $this->parseRightAssociativeAssign($left, $right);
        }
        if ($left instanceof Expr\List_ && $v->getAttribute(self::ATTR_STATEMENT_EXPRESSION, false)) {
            $optimized = $this->parseAssignToMultiReturn($left, $right);
            if ($optimized !== null) {
                return $optimized;
            }
        }
        return $this->parseAssignFinally(
            $left,
            $right,
            $this->canFoldLocalInitializerIntoDeclaration($v),
            $v->getAttribute(self::ATTR_STATEMENT_EXPRESSION, false),
        );
    }

    /**
     * A hoist-safe value assigned to a new function-top-level local can
     * initialize the already-hoisted C++ declaration directly. Keep runtime
     * constants, arrays and compound expressions on the ordinary path: their
     * evaluation order or generated temporaries must remain at the source site.
     */
    private function canFoldLocalInitializerIntoDeclaration(Expr\Assign $assign): bool
    {
        if (!$assign->getAttribute(self::ATTR_STATEMENT_EXPRESSION, false)
            || $this->context->scopeLevel !== 1
            || !$this->isVarExpr($assign->var)
            || !is_string($assign->var->name)
        ) {
            return false;
        }
        $name = $this->parseWritableIdentifier($assign->var);
        if ($name === 'this_' || $this->hasVar($name)) {
            return false;
        }
        return $this->isHoistSafeDeclarationInitializer($assign->expr);
    }

    private function isHoistSafeDeclarationInitializer(Expr $expr): bool
    {
        $literal = $expr instanceof Node\Scalar\Int_
            || $expr instanceof Node\Scalar\Float_
            || $expr instanceof Node\Scalar\String_
            || ($expr instanceof Expr\ConstFetch && $this->isHoistSafeConstFetch($expr))
            || ($expr instanceof Expr\ClassConstFetch && $this->isHoistSafeClassConstFetch($expr))
            || (($expr instanceof Expr\UnaryPlus || $expr instanceof Expr\UnaryMinus)
                && ($expr->expr instanceof Node\Scalar\Int_ || $expr->expr instanceof Node\Scalar\Float_));
        return $literal && in_array(
            $this->detectTypeOfExpr($expr),
            [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::VAR],
            true,
        );
    }

    private function parseAssignToMultiReturn(Expr\List_ $left, Expr $right): ?string
    {
        if (!$right instanceof Expr\FuncCall
            || (!$this->isNameExpr($right->name) && !$this->isFullNameExpr($right->name))) {
            return null;
        }

        $nativeFunc = $this->findNativeFunction($this->parseIdentifier($right->name));
        if ($nativeFunc === false) {
            return null;
        }
        $functionDef = $this->getFunction($nativeFunc);
        if (!$functionDef->hasMultiReturn()
            || $functionDef->multiReturnCount < count($left->items)
            || $this->shouldUseDynamicCallForNativeArgs($nativeFunc, $right->args)) {
            return null;
        }

        $variables = [];
        foreach ($left->items as $item) {
            if (!$item instanceof ArrayItem || $item->key !== null || $item->unpack || $item->byRef
                || !$this->isVarExpr($item->value) || !is_string($item->value->name)) {
                return null;
            }
            $name = $this->parseWritableIdentifier($item->value);
            if ($this->hasVar($name) && $this->getVarType($name) !== Type::VAR) {
                return null;
            }
            $variables[] = $name;
        }

        foreach ($variables as $variableName) {
            if (!$this->hasVar($variableName)) {
                $this->addLocalVar($variableName, Type::VAR);
            }
        }
        $tieItems = array_merge(
            $variables,
            array_fill(0, $functionDef->multiReturnCount - count($variables), 'std::ignore'),
        );
        $right->setAttribute(self::ATTR_MULTI_RETURN_IMPL, true);
        return 'std::tie(' . implode(', ', $tieItems) . ') = ' . $this->parseFuncCall($right);
    }

    protected function parseAssignToList(Expr $left, Expr $right): string
    {
        $items = $left->items;
        $code  = '{' . PHP_EOL;
        $this->indentLevel++;
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, Type::VAR);
        $code .= $this->getIndent() . $tmpVar . ' = ' . $this->parseExpr($right) . ';' . PHP_EOL;
        foreach ($items as $k => $item) {
            if (!$item) {
                continue;
            }
            if ($item instanceof ArrayItem) {
                $key = $item->key ? $this->parseArrayKey($item->key, true) : (string) $k;
                if ($item->value instanceof Expr\List_) {
                    $nestedTmp = $this->genTmpVarName();
                    $this->addLocalVar($nestedTmp, Type::ARRAY);
                    $code .= $this->getIndent() . "{$nestedTmp} = {$tmpVar}.item({$key});" . PHP_EOL;
                    $code .= $this->getIndent()
                        . $this->parseAssignToList($item->value, new Variable($nestedTmp))
                        . PHP_EOL;
                } else {
                    $var = $this->parseWritableIdentifier($item->value);
                    if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
                        $this->addLocalVar($var, Type::VAR);
                    }
                    $code .= $this->getIndent() . "{$var} = {$tmpVar}.item({$key});" . PHP_EOL;
                }
            } else {
                $this->unsupportedSyntax($item);
            }
        }
        $this->indentLevel--;

        return $code . $this->getIndent() . '}';
    }

    protected function parseAssignFinally(
        Expr $left,
        Expr $right,
        bool $foldIntoDeclaration = false,
        bool $resultUnused = false,
    ): string
    {
        $this->assertImmutableMutationTarget($left);
        $this->recordImmutableAlias($left, $right);
        $this->assertNotNullsafeWriteContext($left);
        $this->assertNativeArrayAccessDirectWrite($left, true);
        if ($left instanceof Expr\ArrayDimFetch
            && $this->isNativeObjectClass($this->detectClassOfExpr($left->var))
        ) {
            if ($this->isNativeObjectClass($this->detectClassOfExpr($right))) {
                $this->fatalError(
                    $right,
                    'Native objects cannot cross a PHP/ZendVM argument boundary',
                );
            }
            $result = $this->addTmpVar(Type::VAR);
            $key = $this->addTmpVar(Type::VAR);
            $store = $this->parseNativeArrayAccessCall(
                $left,
                'offsetSet',
                [
                    new Node\Arg(new Variable($key)),
                    new Node\Arg(new Variable($result)),
                ],
            );
            if ($left->dim === null) {
                $keyExpr = self::VALUE_NULL;
                $keyBefore = $keyAfter = [];
            } else {
                [$keyExpr, $keyBefore, $keyAfter] = $this->parseExprWithCapturedStmts($left->dim);
            }
            [$rightExpr, $rightBefore, $rightAfter] = $this->parseExprWithCapturedStmts($right);

            $code = '[&]() -> php::Var {' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($keyBefore);
            $code .= $this->getIndent() . $key . ' = ' . $keyExpr . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($keyAfter);
            $code .= $this->formatCapturedStmtLines($rightBefore);
            $code .= $this->getIndent() . $result . ' = ' . $rightExpr . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightAfter);
            $code .= $this->getIndent() . $store . ';' . PHP_EOL;
            $code .= $this->getIndent() . 'return ' . $result . ';' . PHP_EOL;
            return $code . $this->getIndent() . '}()';
        }
        $rightClass = $this->detectClassOfExpr($right);

        // A Native-element std container owns a PHPX Box but its raw pointer
        // elements are traced only by the function-local container root frame.
        // Copying the whole Box into any other value slot could outlive that
        // frame, so reject the escape once at the assignment boundary. Element
        // reads/writes do not pass the container variable itself as the RHS.
        if ($this->isVarExpr($right)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects(
                $right,
                $this->parseIdentifier($right),
            );
        }

        if ($left instanceof Expr\List_) {
            if ($this->isNativeObjectClass($rightClass)) {
                $this->fatalError($right, 'Native objects cannot be destructured into PHP values');
            }
            return $this->parseAssignToList($left, $right);
        }

        if ($this->isNativeObjectClass($rightClass)) {
            $allowed = false;
            if ($this->isVarExpr($left)) {
                $leftName = $this->parseVariable($left);
                $declaredClass = $this->getDeclaredObjectType($leftName);
                if ($declaredClass !== '' && $this->isInterface($declaredClass)) {
                    $this->fatalError(
                        $left,
                        'Native objects cannot be assigned to interface-typed variables',
                    );
                }
                $allowed = !$this->hasVar($leftName)
                    || $this->isNativeObjectVar($leftName)
                    || $this->hasScopeGlobalVar($leftName)
                    || $this->hasStaticVar($leftName);
                if ($allowed && ($this->hasScopeGlobalVar($leftName) || $this->hasStaticVar($leftName))) {
                    $this->promoteGlobalOrStaticToNativeObject($leftName, $rightClass, $right);
                }
            } elseif (($globalSlot = $this->getStaticGlobalsSlot($left)) !== null) {
                if (!$this->hasGlobalVar($globalSlot)) {
                    $this->addGlobalVar($globalSlot, Type::VAR);
                }
                if (!$this->hasScopeGlobalVar($globalSlot)) {
                    $this->addScopeGlobalVar($globalSlot, $this->globalVars[$globalSlot]);
                }
                $this->promoteGlobalOrStaticToNativeObject($globalSlot, $rightClass, $right);
                $allowed = true;
            } elseif ($left instanceof Expr\PropertyFetch && $this->isIdExpr($left->name)) {
                $receiverClass = $this->detectClassOfExpr($left->var);
                if ($this->isNativeObjectClass($receiverClass)) {
                    $propertyName = $left->name->toString();
                    $resolution = $this->resolveNativeInstanceProperty($left, $propertyName, $receiverClass);
                    if ($resolution !== null) {
                        $this->applyNativePropertyAccessResult($left, $resolution);
                    }
                    $property = $resolution?->propertyDef;
                    if ($property !== null && $property->class !== '' && $this->isInterface($property->class)) {
                        $this->fatalError(
                            $left,
                            'Native objects cannot be assigned to interface-typed properties',
                        );
                    }
                    $allowed = $property !== null
                        && $property->type === Type::OBJECT
                        && $this->isNativeObjectClass($property->class);
                }
            } elseif ($left instanceof Expr\ArrayDimFetch && $this->isStdContainerExpr($left)) {
                $info = $this->isStdArrayExpr($left)
                    ? $this->getStdArrayInfo($left)
                    : $this->getStdContainerInfo($left);
                // A std container with a concrete Native class element type is
                // compile-time storage, not a PHP array/Variant boundary.
                $allowed = $info !== null
                    && isset($info['class'])
                    && $this->isNativeObjectClass($info['class']);
            }
            if (!$allowed) {
                $this->fatalError(
                    $left,
                    'Native objects cannot be stored in PHP arrays, PHP object properties, static properties, or mixed variables',
                );
            }
        }

        // A direct assignment to readonly must go through write_property so
        // Zend can enforce scope, initialization state, type and clone rules.
        $propertyWriteTarget = $this->preparePropertyWriteTarget($left, true);
        $type = $this->detectTypeOfExpr($right);
        $finalVarType = $this->getNormalAssignType($type);
        $runtimeObjectAssignClass = '';
        $assigningNullToTypedObject = false;
        $markNativeObjectNonNull = false;
        if ($type === Type::VOID) {
            $type = Type::VAR;
        }

        if ($left instanceof Expr\PropertyFetch && ($setter = $this->getPropertyHookSetter($left)) !== null) {
            return $this->parseAssignPropertyHook($left, $right, $propertyWriteTarget, $setter);
        }
        if ($left instanceof Expr\PropertyFetch && $this->isReadOnlyPropertyHook($left)) {
            $this->fatalError($left, 'Cannot write to read-only hooked property');
        }

        if ($left instanceof Expr\PropertyFetch && $this->isIdExpr($left->name)) {
            $receiverClass = $this->detectClassOfExpr($left->var);
            if ($this->isNativeObjectClass($receiverClass)) {
                $property = $left->name->toString();
                $access = $this->getNativePropertyAccess($left);
                if ($access === null) {
                    $resolution = $this->resolveNativeInstanceProperty($left, $property, $receiverClass);
                    if ($resolution === null) {
                        $this->fatalError($left, "Native class `{$receiverClass}` has no property `\${$property}`");
                    }
                    $this->applyNativePropertyAccessResult($left, $resolution);
                    $access = $this->getNativePropertyAccess($left);
                }
                $def = $access->getPropertyDef();

                // Parse and materialize the receiver before the right-hand
                // expression. PHP evaluates an object/property target before
                // its assigned value, and C++ operand order must not decide it.
                $leftExpr = $this->parsePropertyFetch($left);
                if ($def->type === Type::OBJECT && $this->isNativeObjectClass($def->class)) {
                    if ($this->isNull($right)) {
                        if (!$def->nullable) {
                            $this->fatalError($right, "Cannot assign null to native property `{$receiverClass}::\${$property}`");
                        }
                        return $leftExpr . ' = nullptr';
                    }
                    $rightClass = $this->detectClassOfExpr($right);
                    if ($rightClass === '' || !$this->isObjectClassStaticallyAssignableTo($rightClass, $def->class)) {
                        $this->fatalError($right, "Cannot assign value to native property `{$receiverClass}::\${$property}`");
                    }
                    return $leftExpr . ' = ' . $this->parseExprAsValue($right);
                }

                $this->assertCanAssignPropertyWrite($propertyWriteTarget, $right);
                $rightExpr = $this->parseExprAsValue($right);
                $rightExpr = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $right, $rightExpr);
                if ($def->type !== Type::VAR) {
                    $rightExpr = $this->convertExprFromType($def->type, $rightExpr);
                }
                return $leftExpr . ' = ' . $rightExpr;
            }
        }

        if ($propertyWriteTarget !== null && $this->shouldUseDynamicNativePropertyWrite($left, $type)) {
            return $this->parseAssignPropertyFetch($left, $right, $propertyWriteTarget, $resultUnused);
        }

        if ($this->isVarExpr($left)) {
            $var = $this->parseWritableIdentifier($left);
            if ($var === 'this_') {
                $this->fatalError($left, 'Cannot re-assign $this');
            }
            $assigningNullToTypedObject = $this->hasVar($var)
                && $this->getVarType($var) === Type::OBJECT
                && $this->isNull($right);
            if ($this->isStdContainer($var)) {
                $copyAssign = $this->parseStdContainerCopyAssign($var, $right);
                if ($copyAssign !== null) {
                    return $copyAssign;
                }
            }
            // Infer the type and obtain the object's class name; return an empty string for non-objects
            $rightClass = $this->detectClassOfExpr($right);
            $markNativeObjectNonNull = $this->context->scopeLevel <= 1
                && !$this->hasScopeGlobalVar($var)
                && !$this->hasStaticVar($var)
                && $this->isNativeObjectClass($rightClass)
                && $this->isNativeObjectExpressionKnownNonNull($right);
            if ($this->isNativeObjectVar($var)) {
                // Assignment rebinds the local pointer slot. Even an
                // assignment nested in a conditional invalidates the simple
                // non-null proof; later reads fall back to nativeDeref().
                $this->forgetNativeObjectNonNull($var);
                $leftClass = $this->getNativeObjectVarClass($var);
                if ($this->isNull($right)) {
                    return $var . ' = nullptr';
                }
                if ($rightClass === '' || !$this->isNativeObjectClass($rightClass)) {
                    $this->fatalError($right, "Native object `\${$var}` cannot be converted to var/object");
                }
                if (!$this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                    $this->fatalError($right, "Cannot assign native object `{$rightClass}` to `{$leftClass}`");
                }
            }
            // The right-hand value is an object and its class name is known; the left-hand side must match the right-hand side's class
            if ($rightClass) {
                if (!$this->hasVar($var)) {
                    if ($this->isNativeObjectClass($rightClass)) {
                        $this->addLocalVar($var, $this->getNativeObjectPointerType($rightClass));
                        $this->addNativeObject($var, $rightClass);
                    } else {
                        $this->addLocalVar($var, Type::OBJECT);
                        $this->addObject($var, $rightClass);
                    }
                } elseif (($leftClass = $this->getDeclaredObjectType($var)) !== '') {
                    if ($this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                        // A child object can be assigned to a parent typed object.
                    } elseif ($this->isInterface($rightClass) || $this->isAbstractClass($rightClass) || $this->isObjectClassStaticallyAssignableTo($leftClass, $rightClass)) {
                        if ($this->isKnownConcreteObjectExpr($right, $rightClass)) {
                            $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                        }
                        // Parent/interface/abstract declarations are not precise enough for a concrete typed object.
                        $runtimeObjectAssignClass = $leftClass;
                    } else {
                        $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                    }
                } else {
                    $this->checkVarAssignExpr($left, $this->getVarType($var), Type::OBJECT);
                }
            } else {
                if ($this->isMethodCall($right) and $this->isNamedMethod($right->name)) {
                    $methodName = $right->name->toString();
                    if (in_array($methodName, ['toStdArray', 'toStdVector', 'toStdMap', 'toStdOrderedMap'], true)) {
                        if ($this->hasVar($var)) {
                            $this->fatalError($left, "Cannot re-assign `\${$var}` to {$methodName}()");
                        }
                        if ($this->context->scopeLevel > 1) {
                            $this->fatalError($left, "Must use {$methodName}() in the top-level scope of the function");
                        }
                        return $this->parseToStdAssign($var, $right);
                    }
                }
                if ($this->isFuncCallExpr($right) and $this->isNameExpr($right->name)) {
                    $type = $type === Type::VOID ? Type::VAR : $type;
                } elseif ($this->isStaticCall($right) and $this->isNameExpr($right->class) and $this->isIdExpr($right->name)) {
                    $class = $this->parseIdentifier($right->class);
                    if ($class === 'std') {
                        if (in_array($right->name->toString(), ['array', 'vector', 'map', 'ordered_map'], true)) {
                            if ($this->hasScopeGlobalVar($var) || $this->hasStaticVar($var)) {
                                $this->assertNativeStdContainerFunctionLocal($right);
                            }
                            if ($this->hasVar($var)) {
                                $this->fatalError($left, "Cannot re-assign `\${$var}` to std::{$right->name->toString()}");
                            }
                            if ($this->context->scopeLevel > 1) {
                                $this->fatalError($left, "Must create std::{$right->name->toString()} in the top-level scope of the function");
                            }
                            if ($right->name->toString() === 'array') {
                                $this->addLocalVar($var, Type::STD_ARRAY);
                                return $this->parseStdArray($var, $right);
                            }
                            if ($right->name->toString() === 'vector') {
                                $this->addLocalVar($var, Type::STD_VECTOR);
                                return $this->parseStdVector($var, $right);
                            }
                            if ($right->name->toString() === 'map') {
                                $this->addLocalVar($var, Type::STD_MAP);
                                return $this->parseStdMap($var, $right);
                            }
                            $this->addLocalVar($var, Type::STD_ORDERED_MAP);
                            return $this->parseStdOrderedMap($var, $right);
                        } else {
                            $valueExpr = $this->parseStdCall($right);
                            if (!$this->hasVar($var)) {
                                $finalVarType = $right->getAttribute('nativeType');
                                $this->addLocalVar($var, $finalVarType);
                            }
                            $this->context->explicitNativeTypeVars[$var] = true;
                            return $var . ' = ' . $valueExpr;
                        }
                    }
                } elseif ($this->isVarExpr($right)) {
                    $rightVar = $this->parseIdentifier($right);
                    if (isset($this->context->explicitNativeTypeVars[$rightVar])) {
                        $this->context->explicitNativeTypeVars[$var] = true;
                    }
                    $this->assertStdContainerDoesNotEscapeNativeObjects($right, $rightVar);
                    $type = $this->isStdContainer($rightVar) ? Type::ARRAY : $this->getVarType($rightVar);
                    $finalVarType = $this->getNormalAssignType($type);
                    $leftClass = $this->getDeclaredObjectType($var);
                    $rightClass = $this->getDeclaredObjectType($rightVar);
                    if ($leftClass !== '' and $rightClass !== '') {
                        if ($this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                            // A child object can be assigned to a parent typed object.
                        } elseif ($this->isInterface($rightClass) || $this->isAbstractClass($rightClass) || $this->isObjectClassStaticallyAssignableTo($leftClass, $rightClass)) {
                            $runtimeObjectAssignClass = $leftClass;
                        } else {
                            $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                        }
                    }
                }
                // On first assignment the variable's type is determined. PHP variable scope is function-level, so variables declared in for/while blocks remain usable outside the block
                if (!$this->hasVar($var)) {
                    $finalVarType = $this->getNormalAssignType($type);
                    $finalVarType = $this->isNativeType($finalVarType) ? $this->getNativeType($finalVarType) : $finalVarType;
                    $this->addLocalVar($var, $finalVarType);
                } else {
                    $finalVarType = $this->getVarType($var);
                    $this->checkVarAssignExpr($left, $finalVarType, $type);
                    $declaredObjectClass = $this->getDeclaredObjectType($var);
                    if (!$assigningNullToTypedObject
                        && $finalVarType === Type::OBJECT
                        && $declaredObjectClass !== ''
                        && ($type === Type::VAR || $type === Type::OBJECT)) {
                        $runtimeObjectAssignClass = $declaredObjectClass;
                    }
                }
            }
        } elseif ($this->isPropertyFetch($left) and !$this->isNativePropertyAccess($left)) {
            return $this->parseAssignPropertyFetch($left, $right, $propertyWriteTarget, $resultUnused);
        } elseif ($this->isArrayDimFetch($left) and $this->isVarExpr($left->var)) {
            $tmp = $this->parseIdentifier($left->var);
            $this->assertArrayDimVariableTypeIsSupported($left, $tmp);
            if ($this->isStdContainerExpr($left)) {
                return $this->parseStdContainerAssign($left, $right);
            }
            return $this->parseAssignArrayDim($left, $right, $resultUnused);
        } elseif ($this->isArrayDimFetch($left) and $this->isPropertyFetch($left->var)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        } elseif ($this->isArrayDimFetch($left) and $this->isStaticPropertyFetch($left->var)) {
            // Keep ordinary static-array writes on their established lowering
            // path. Only ArrayDef needs the specialized key/value plan below.
            $this->preparePropertyWriteTarget($left->var);
            if ($this->getNativePropertyDef($left->var)?->arrayDef !== null) {
                return $this->parseAssignStaticPropertyArrayDim($left, $right);
            }
        }

        if ($propertyWriteTarget !== null) {
            $this->assertCanAssignPropertyWrite($propertyWriteTarget, $right);
        }

        $var = $this->parseWritableIdentifier($left);
        $rightExpr = $this->parseAssignRightExpr($right);
        if ($propertyWriteTarget !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $right, $rightExpr);
        }
        if ($assigningNullToTypedObject) {
            // php::Object intentionally keeps the inferred class as its C++ type,
            // while an empty/null value represents the same state as unset($var).
            $rightExpr = 'php::Object{' . $rightExpr . '}';
        }
        if ($runtimeObjectAssignClass !== '') {
            // A typed object has two valid runtime states: the declared class or
            // null. Evaluate a dynamic RHS once, preserve null, and validate only
            // actual objects against the inferred class constraint.
            $checkedValue = 'typephp_nullable_object_value';
            $rightExpr = '([&](php::Var ' . $checkedValue . ') -> php::Object {'
                . ' if (' . $checkedValue . '.isNull()) { return php::Object{' . $checkedValue . '}; }'
                . ' return php::toObject(' . $checkedValue . ', ' . $this->getClassEntryPtr($runtimeObjectAssignClass) . ');'
                . ' })(' . $rightExpr . ')';
        }
        if ($markNativeObjectNonNull) {
            // Parsing is sequential: this proof affects only subsequent source
            // expressions. Nested control flow is excluded above because its
            // assignment may not execute on every path.
            $this->markNativeObjectNonNull($var);
        }
        $leftExprType = $this->detectTypeOfExpr($left);
        $rightExprType = $this->detectTypeOfExpr($right);
        if ($propertyWriteTarget !== null && ($propertyDef = $this->getNativePropertyDef($left)) !== null) {
            $effectiveRightType = $rightExprType === Type::VAR && $this->getFixedPropertyTypeCheckHelper($propertyDef) !== null
                ? $propertyDef->type
                : $rightExprType;
            return $var . ' = ' . $this->convertNativePropertyWriteExpr($propertyDef->type, $effectiveRightType, $rightExpr);
        }
        $assignedExpr = $finalVarType === Type::VAR
            ? $rightExpr
            : $this->convertExprType($rightExpr, $leftExprType, $rightExprType);
        if ($foldIntoDeclaration) {
            $this->context->localVarInitializers[$var] = $assignedExpr;
            return '';
        }
        return $var . ' = ' . $assignedExpr;
    }

    protected function parseAssignPropertyHook(
        Expr\PropertyFetch $left,
        Expr $right,
        ?PropertyWriteTarget $target,
        string $setter,
    ): string {
        $property = $this->getNativePropertyDef($left);
        $nativeObjectClass = $property !== null
            && $property->type === Type::OBJECT
            && $this->isNativeObjectClass($property->class)
            ? $property->class
            : '';
        if ($target !== null) {
            $this->assertCanAssignPropertyWrite($target, $right);
        }
        if ($nativeObjectClass !== '') {
            if ($this->isNull($right)) {
                if (!$property->nullable) {
                    $this->fatalError($right, "Cannot assign null to native property `{$nativeObjectClass}`");
                }
                $rightExpr = 'nullptr';
            } else {
                $rightClass = $this->detectClassOfExpr($right);
                if ($rightClass === ''
                    || !$this->isNativeObjectClass($rightClass)
                    || !$this->isObjectClassStaticallyAssignableTo($rightClass, $nativeObjectClass)
                ) {
                    $this->fatalError($right, "Cannot assign value to native property of type `{$nativeObjectClass}`");
                }
                $rightExpr = $this->parseExprAsValue($right);
            }
        } else {
            $rightExpr = $this->parseExprAsValue($right);
        }
        if ($target !== null && $nativeObjectClass === '') {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($target, $right, $rightExpr);
        }
        $tmp = $this->genTmpVarName();
        if ($nativeObjectClass !== '') {
            // The synthesized value variable is also the assignment result and
            // the setter argument. Preserve its exact Native type so argument
            // validation and NativeRootFrame generation do not see a mixed
            // Zend value at this compiler-created boundary.
            $this->addLocalVar($tmp, $this->getNativeObjectPointerType($nativeObjectClass));
            $this->addNativeObject($tmp, $nativeObjectClass);
        } else {
            $this->addLocalVar($tmp, Type::VAR);
        }
        $call = $this->emitPropertyHookSetterCall($left, $setter, new Expr\Variable($tmp));
        return '((' . $tmp . ' = ' . $rightExpr . ', ' . $call . '), ' . $tmp . ')';
    }

    protected function shouldUseDynamicNativePropertyWrite(Expr $left, string $rightType): bool
    {
        if (!$this->isPropertyFetch($left)) {
            return false;
        }

        $def = $this->getNativePropertyDef($left);
        if ($def === null) {
            return false;
        }

        if ($def->isReadonly()) {
            return true;
        }

        return !in_array($def->type, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY], true)
            && $rightType === Type::VAR;
    }

    protected function parseStdContainerCopyAssign(string $leftVar, Expr $right): ?string
    {
        $rightInfo = $this->getStdContainerExprInfo($right);
        if ($rightInfo === null) {
            return null;
        }

        $leftInfo = $this->getStdContainerVarInfo($leftVar);
        if (!$this->isSameStdContainerInfo($leftInfo, $rightInfo)) {
            $this->fatalError($right, 'Cannot copy std container with different type');
        }

        if (!$this->isStdArray($leftVar)) {
            $this->assertStdContainerStructureMutable($right, $leftVar);
        }

        return $leftVar . '_ref = ' . $this->parseStdContainerCopyExpr($right);
    }

    protected function parseAssignRightExpr(Expr $right): string
    {
        $rightExpr = $this->parseExprAsValue($right);
        if ($this->isVarExpr($right)) {
            $rightVar = $this->parseIdentifier($right);
            if ($this->isStdContainer($rightVar)) {
                return $this->convertArrayExpr($rightExpr);
            }
        }
        return $rightExpr;
    }

    protected function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    protected function parseAssignOp(Expr\AssignOp $node, string $op): string
    {
        if ($node->var instanceof Expr\ArrayDimFetch
            && !$this->canUpdateKnownArraySlotInPlace($node, $op)
        ) {
            $node = clone $node;
            $node->var = $this->stabilizeAssignOpArrayAccess($node->var);
        }

        if ($node->var instanceof Expr\PropertyFetch && !$this->isVarExpr($node->var->var)) {
            $stableProperty = $this->stabilizeAssignOpPropertyReceiver($node->var);
            if ($stableProperty !== $node->var) {
                $node = clone $node;
                $node->var = $stableProperty;
            }
        }

        $this->assertImmutableMutationTarget($node->var);
        $this->assertNativeArrayAccessDirectWrite($node->var, false);
        $this->assertNativeObjectOperatorOperandSupported($node->var, $node, $op);
        $this->assertNotNullsafeWriteContext($node->var);
        $this->assertNativePropertyHookDirectWriteTarget($node->var);
        $pythonOperator = $this->parsePythonAssignOperator($node);
        if ($pythonOperator !== null) {
            return $pythonOperator;
        }
        $propertyWriteTarget = $this->preparePropertyWriteTarget($node->var);
        $this->guardLiteralDivisionByZero($node->expr, $op);

        // A compound division/modulo on a NATIVE scalar slot with a proven
        // zero divisor cannot fall through to the raw C++ operator (SIGFPE
        // for ints, INF for floats). A zero divisor always throws the
        // catchable DivisionByZeroError before any assignment happens, so
        // lower the whole expression to the PHP-semantics binary operation
        // and leave the target untouched.
        if (($op === '/=' || $op === '%=')
            && !$this->nativeTypes
            && $this->isZeroLiteral($node->expr)
            && $this->isVarExpr($node->var)
            && $this->hasVar((string) $this->parseIdentifier($node->var))
            && in_array($this->detectVarType($node->var), [Type::INT, Type::FLOAT], true)
        ) {
            // std::int()/std::float() values are an explicit opt-in to native
            // C++ arithmetic; changing them to PHP semantics here would be as
            // wrong as the undefined raw operation. Keep the compile-time
            // rejection native_types mode uses.
            if ($this->isExplicitNativeArithmeticExpr($node->var)) {
                $this->fatalError($node->expr, 'Cannot divide or modulo by zero');
            }
            $binOp = $op === '/=' ? '/' : '%';
            return '((php::Var(' . $this->parseExprAsValue($node->var) . ')) '
                . $binOp . ' (php::Var(' . $this->parseExprAsValue($node->expr) . ')))';
        }

        if ($node->var instanceof Expr\PropertyFetch && $this->isNativeObjectPropertyHook($node->var)) {
            $this->fatalError(
                $node->var,
                'Native property hooks only support direct reads and assignments',
            );
        }

        if ($node->var instanceof Expr\PropertyFetch && $this->isReadOnlyPropertyHook($node->var)) {
            $this->fatalError($node->var, 'Cannot write to read-only hooked property');
        }

        if ($node->var instanceof Expr\PropertyFetch
            && ($setter = $this->getPropertyHookSetter($node->var)) !== null
            && ($getter = $this->getPropertyHookGetter($node->var)) !== null) {
            $read = $this->emitPropertyHookGetterCall($node->var, $getter);
            $tmp = $this->genTmpVarName();
            $this->addLocalVar($tmp, Type::VAR);
            $binaryOp = $this->removeAssignOp($op);
            $value = match ($binaryOp) {
                '.' => $this->parseFlattenedConcat($node->expr, [
                    $this->prepareConcatOperand($read, $this->detectTypeOfExpr($node->var)),
                ]),
                '**' => 'php::fn::pow(' . $read . ', ' . $this->parseExprAsValue($node->expr) . ')',
                default => $read . ' ' . $binaryOp . ' (' . $this->parseExprAsValue($node->expr) . ')',
            };
            $call = $this->emitPropertyHookSetterCall($node->var, $setter, new Expr\Variable($tmp));
            return '((' . $tmp . ' = ' . $value . ', ' . $call . '), ' . $tmp . ')';
        }

        $nativePropertyAssignOp = $this->parseNativePropertyAssignOp($node, $op);
        if ($nativePropertyAssignOp !== null) {
            return $nativePropertyAssignOp;
        }

        $arrayDimFetch = $this->isArrayDimFetch($node->var);
        $var          = $arrayDimFetch ? '' : $this->parseWritableIdentifier($node->var);
        $expr         = $this->isAssignOpConcat($op) ? '' : (string) $this->parseIdentifier($node->expr);

        if ($this->isVarExpr($node->var)) {
            if (!$this->hasVar($var)) {
                $this->fatalError($node->var, 'Cannot assign to undefined variable');
            }
            $type         = $this->detectVarType($node->var);
            $rightType    = $this->detectTypeOfExpr($node->expr);

            // Big* types: expand compound assignment to static method call.
            // BigInt/BigDecimal/BigFloat are immutable Box types stored inside
            // php::Var — Variant::operator+= calls ZendVM add_function which
            // cannot handle them.  We must generate `$v = Type::add($v, $x)`.
            if ($type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
                return $this->parseBigAssignOp($node, $var, $type, $expr, $rightType, $op);
            }

            // A dynamic local must retain the RHS runtime type. Variant's
            // compound operators already implement PHP coercion and checked
            // integer overflow; eagerly converting an int-looking expression
            // both adds an unnecessary unbox/rebox pair and can collapse an
            // overflowed float back to int before the assignment.
            $rightExprStr = $type === Type::VAR
                ? $expr
                : $this->convertExprType($expr, $type, $rightType);
            if ($this->isAssignOpConcat($op)) {
                if ($this->isArrayVar($node->var)) {
                    $this->fatalError($node->var, 'Cannot concat string to array');
                }
                if ($type === Type::STR) {
                    return $this->parseInPlaceStringConcatAssign($node, $var);
                }
                return $var . ' = ' . $this->parseFlattenedConcat($node->expr, [
                    $this->prepareConcatOperand($var, $type),
                ]);
            }
            if ($this->isAssignOpPow($op)) {
                $powExpr = 'php::fn::pow(' . $var . ', ' . $rightExprStr . ')';
                return $var . ' = ' . $this->convertVarType($var, $powExpr);
            }
            if ($type === Type::VAR && $op === '+=') {
                return $var . '.addAssign(' . $rightExprStr . ')';
            }
            return $var . ' ' . $op . ' ' . $rightExprStr;
        }

        if ($arrayDimFetch) {
            if ($this->isStdContainerExpr($node->var)) {
                return $this->parseStdContainerAssignOp($node, $op);
            }
            if ($this->canUpdateKnownArraySlotInPlace($node, $op)) {
                if ($this->isVarExpr($node->var->var) && $node->var->var->name === 'GLOBALS') {
                    $slot = $this->parseGlobalsArrayDimFetch($node->var);
                    return $slot . ' ' . $op . ' ' . $this->parseExprAsValue($node->expr);
                }
                $array = $this->parseWritableIdentifier($node->var->var);
                $dim = $this->parseIdentifier($node->var->dim);
                return $array . '.item(' . $dim . ', true) ' . $op . ' '
                    . $this->parseExprAsValue($node->expr);
            }
            /**
             * $count[$r] -= 1;
             * must be lowered to the following statements:
             * $tmp_var = $count[$r] - 1;
             * $count[$r] = $tmp_var;
             */
            $isGlobals = $this->isVarExpr($node->var->var) && $node->var->var->name === 'GLOBALS';
            $type      = $isGlobals ? Type::VAR : $this->detectVarType($node->var);
            $rightType = $this->detectTypeOfExpr($node->expr);
            $tmpVar    = $this->genTmpVarName();
            // PHP arrays are dynamically typed even when SSA can currently
            // infer an element as int. Keep the compound result in Variant so
            // Zend arithmetic promotes overflowing integers to float instead
            // of evaluating a signed C++ expression with undefined behavior.
            $this->addLocalVar($tmpVar, Type::VAR);
            $stableAccess = $node->var;
            $dim = $this->parseIdentifier($node->var->dim);
            $readVar  = $this->parseArrayDimFetchRead($stableAccess);
            $binaryOp = $this->removeAssignOp($op);

            if ($binaryOp === '.') {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->parseFlattenedConcat($node->expr, [
                        $this->prepareConcatOperand($this->convertVarType($tmpVar, $readVar), $type),
                    ]) . ';';
            } elseif ($type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
                $bigAssign = $this->parseBigAssignOpExpr($readVar, $type, $expr, $rightType, $binaryOp, $node->var, $node->expr);
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$bigAssign};";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->convertVarType($tmpVar, $readVar) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }

            if ($isGlobals) {
                return $this->parseGlobalsArrayDimFetch($stableAccess) . ' = ' . $tmpVar;
            }
            return '(' . $this->parseArrayDimStore($stableAccess->var, $dim, $tmpVar) . ', ' . $tmpVar . ')';
        }

        if ($this->isPropertyFetch($node->var) and !$this->isNativePropertyAccess($node->var)) {
            if ($propertyWriteTarget !== null) {
                $this->assertCanAssignPropertyWrite($propertyWriteTarget, $node->expr);
            }
            $binaryOp = $this->removeAssignOp($op);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, Type::VAR);
            $readProperty = $this->emitDynamicPropertyFetchRead($node->var, $propertyWriteTarget);
            if ($this->isAssignOpConcat($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->parseFlattenedConcat($node->expr, [
                        $this->prepareConcatOperand($readProperty, $this->detectTypeOfExpr($node->var)),
                    ]) . ';';
            } elseif ($this->isAssignOpPow($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::fn::pow({$readProperty}, {$expr});";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$readProperty} {$binaryOp} ({$expr});";
            }
            // The write is part of the compound-assignment expression. Apart
            // from matching PHP's sequencing, this ensures a materialized
            // receiver remains alive until the write has completed.
            return '((' . $this->emitDynamicPropertyFetchWrite(
                $node->var,
                $tmpVar,
                $propertyWriteTarget,
            ) . '), ' . $tmpVar . ')';
        }

        if ($this->isAssignOpConcat($op)) {
            if (!($node->expr instanceof Expr\BinaryOp\Concat)) {
                return $var . '.append(' . $this->parseExprAsValue($node->expr) . ')';
            }
            return $var . ' = php::toString(' . $this->parseFlattenedConcat($node->expr, [$var]) . ')';
        }
        return $var . ' ' . $op . ' (' . $expr . ')';
    }

    /**
     * Evaluate every dynamic dimension of a compound-assignment target once,
     * from the innermost access to the outermost access, before evaluating the
     * right-hand side. Both the read and write lowering then reuse the cloned
     * access path and cannot observe keys changed by a later dimension or RHS.
     */
    private function stabilizeAssignOpArrayAccess(Expr\ArrayDimFetch $access): Expr\ArrayDimFetch
    {
        $dimensions = [];
        $base = $access;
        while ($base instanceof Expr\ArrayDimFetch) {
            $dimensions[] = $base;
            $base = $base->var;
        }

        $stableAccess = $base;
        foreach (array_reverse($dimensions) as $dimension) {
            $dim = $dimension->dim;
            $literal = $dim instanceof Node\Scalar\LNumber
                || $dim instanceof Node\Scalar\DNumber
                || $dim instanceof Node\Scalar\String_;
            if ($dim !== null && !$literal) {
                $tmp = $this->parseOrderedOperand($dim, false, true);
                $dim = new Variable($tmp, $dim->getAttributes());
            }
            $stableAccess = new Expr\ArrayDimFetch(
                $stableAccess,
                $dim,
                $dimension->getAttributes(),
            );
        }

        return $stableAccess;
    }

    /**
     * Materialize a statically known object receiver used by a property
     * compound assignment. The read and write phases may be emitted by
     * different helpers, but PHP evaluates the receiver only once.
     */
    private function stabilizeAssignOpPropertyReceiver(Expr\PropertyFetch $property): Expr\PropertyFetch
    {
        $receiverClass = $this->detectClassOfExpr($property->var);
        if ($receiverClass === '') {
            return $property;
        }

        [$receiverExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($property->var);
        $this->appendCapturedStmtLinesToContext($beforeStmts);

        if ($this->isNativeObjectClass($receiverClass)) {
            $tmp = $this->genTmpVarName();
            $this->addLocalVar($tmp, $this->getNativeObjectPointerType($receiverClass));
            $this->addNativeObject($tmp, $receiverClass);
            $cleanup = $tmp . ' = nullptr;';
        } else {
            $tmp = $this->addTmpVar(Type::VAR);
            $this->addObject($tmp, $receiverClass);
            $cleanup = $tmp . '.unset();';
        }

        $this->context->beforeStmtLines[] = $tmp . ' = ' . $receiverExpr . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        $this->context->afterStmtLines[] = $cleanup;

        $stableProperty = clone $property;
        $stableProperty->var = new Variable($tmp, $property->var->getAttributes());
        return $stableProperty;
    }

    /**
     * Preserve PHP's concat-assignment operation for statically typed strings.
     * String::append() calls concat_function() with the target as both the
     * result and left operand, allowing Zend to extend an unshared string in
     * place. Rebuilding `target = concat(target, rhs)` would copy the complete
     * prefix on every iteration and turn repeated `.=` into O(n^2) work.
     *
     * A compound RHS is still evaluated completely before the target changes.
     * The comma expression keeps `.=` usable as a value expression.
     */
    private function parseInPlaceStringConcatAssign(Expr\AssignOp\Concat $node, string $var): string
    {
        $right = $node->expr instanceof Expr\BinaryOp\Concat
            ? $this->parseFlattenedConcat($node->expr)
            : $this->parseExprAsValue($node->expr);

        return '(' . $var . '.append(' . $right . '), ' . $var . ')';
    }

    protected function parseNativePropertyAssignOp(Expr\AssignOp $node, string $op): ?string
    {
        if (!$this->isPropertyFetch($node->var)) {
            return null;
        }

        $def = $this->getNativePropertyDef($node->var);
        if ($def === null) {
            return null;
        }
        if ($def->isReadonly()) {
            // A native scalar reference mutates the property zval directly and
            // bypasses Zend's readonly checks. Keep readonly properties on the
            // normal attr() path so no raw scalar reference escapes the wrapper.
            return null;
        }

        $rightType = $this->detectTypeOfExpr($node->expr);

        // In ordinary PHP mode, an int compound assignment must perform the
        // arithmetic before the typed-property write is validated. The result
        // may therefore be a float (division or integer overflow), in which
        // case Zend rejects the write and leaves the old property value intact.
        // A direct zend_long reference would bypass that behavior completely.
        // Native objects cannot cross the Variant boundary and retain their
        // native C++ property access path.
        if (!$this->nativeTypes
            && $def->type === Type::INT
            && !$this->isNativeObjectClass($this->detectClassOfExpr($node->var->var))
            && in_array($op, ['+=', '-=', '*=', '/=', '%=', '**=', '<<=', '>>=', '&=', '|=', '^='], true)
        ) {
            return $this->parseCheckedIntPropertyAssignOp($node, $op);
        }

        if (in_array($def->type, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT], true)) {
            $binaryOp = $this->removeAssignOp($op);
            $leftExpr = $this->parseWritableIdentifier($node->var);
            $rightExpr = (string) $this->parseIdentifier($node->expr);
            $value = $this->parseBigAssignOpExpr(
                $leftExpr,
                $def->type,
                $rightExpr,
                $rightType,
                $binaryOp,
                $node->var,
                $node->expr,
            );
            return $leftExpr . ' = ' . $value;
        }
        if ($this->isFixedObjectProp($def) && $rightType !== Type::VAR && !$this->canAssignStaticTypeToObjectProperty($def, $rightType)) {
            $this->fatalError(
                $node->var,
                'Cannot assign ' . $this->getPropertyAssignmentTypeName($rightType)
                . ' to property ' . $this->getObjectPropertyTypeCheckDisplayName($node->var)
                . ' of type ' . $this->getObjectPropertyTypeCheckTypeString($def)
            );
        }
        if (!$this->canUseNativePropertyAssignOp($def->type, $rightType, $op)) {
            return null;
        }

        $var = $this->parseWritableIdentifier($node->var);
        if (!$this->isNativePropertyTypedValue($node->var)) {
            $helper = $def->type === Type::FLOAT ? 'typephp_static_float_ref' : 'typephp_static_int_ref';
            $var = $helper . '(' . $var . '.unwrap_ptr())';
        }

        $rightExpr = $this->parseIdentifier($node->expr);
        if ($rightType === Type::VAR) {
            $rightExpr = $this->wrapObjectPropertyAssignTypeCheck($node->var, $node->expr, $rightExpr);
        }
        $effectiveRightType = $rightType === Type::VAR && $this->getFixedPropertyTypeCheckHelper($def) !== null
            ? $def->type
            : $rightType;

        return $var . ' ' . $op . ' (' . $this->convertNativePropertyWriteExpr($def->type, $effectiveRightType, $rightExpr) . ')';
    }

    protected function convertNativePropertyWriteExpr(string $propertyType, string $rightType, string $rightExpr): string
    {
        if ($propertyType === $rightType) {
            return $rightExpr;
        }

        return $this->convertExprFromType($propertyType, $rightExpr);
    }

    protected function canUseNativePropertyAssignOp(string $propertyType, string $rightType, string $op): bool
    {
        if ($rightType !== Type::VAR && !($propertyType === $rightType || ($propertyType === Type::FLOAT && $rightType === Type::INT))) {
            return false;
        }

        return match ($propertyType) {
            Type::INT => in_array($op, ['+=', '-=', '*=', '/=', '%=', '<<=', '>>=', '&=', '|=', '^='], true),
            Type::FLOAT => in_array($op, ['+=', '-=', '*=', '/='], true),
            default => false,
        };
    }

    /**
     * Apply PHP arithmetic first, then let Zend validate the int property
     * write. A complex receiver is materialized before the property read, so
     * both the read and write refer to the same object and the receiver has
     * exactly one observable evaluation.
     */
    private function parseCheckedIntPropertyAssignOp(Expr\AssignOp $node, string $op): string
    {
        /** @var Expr\PropertyFetch $property */
        $property = $node->var;
        $receiver = $property->var;
        $receiverTmp = null;

        if ($this->isVarExpr($receiver)) {
            $objectExpr = $this->parseIdentifier($receiver);
        } else {
            [$receiverExpr, $receiverBefore, $receiverAfter] = $this->parseExprWithCapturedStmts($receiver);
            $this->appendCapturedStmtLinesToContext($receiverBefore);

            // Keep the object in a Variant: this path intentionally uses Zend
            // object handlers, and a Variant also covers dynamically resolved
            // receivers whose concrete class is known only at runtime.
            $receiverTmp = $this->addTmpVar(Type::VAR);
            $this->context->beforeStmtLines[] = $receiverTmp . ' = ' . $receiverExpr . ';';
            $this->appendCapturedStmtLinesToContext($receiverAfter);
            $objectExpr = $receiverTmp;
        }

        $target = new PropertyWriteTarget(
            $property,
            'object property',
            $objectExpr,
            $this->propertyNameToStr($property->name, literal: true),
        );

        // Read before evaluating the RHS, matching PHP compound-assignment
        // order even when the RHS itself emits prerequisite statements.
        $current = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $current . ' = '
            . $this->emitDynamicPropertyTargetRead($target) . ';';

        [$rightExpr, $rightBefore, $rightAfter] = $this->parseExprWithCapturedStmts($node->expr);
        $this->appendCapturedStmtLinesToContext($rightBefore);

        $result = $this->addTmpVar(Type::VAR);
        $binaryOp = $this->removeAssignOp($op);
        $value = $binaryOp === '**'
            ? 'php::fn::pow(' . $current . ', ' . $rightExpr . ')'
            : $current . ' ' . $binaryOp . ' (' . $rightExpr . ')';
        $this->context->beforeStmtLines[] = $result . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($rightAfter);

        $this->context->afterStmtLines[] = $current . '.unset();';
        if ($receiverTmp !== null) {
            $this->context->afterStmtLines[] = $receiverTmp . '.unset();';
        }

        // Execute the checked write at the expression point. If Zend rejects
        // the result, the exception is raised before this expression yields and
        // the original property zval remains unchanged.
        return '((' . $this->emitDynamicPropertyTargetWrite($target, $result) . '), ' . $result . ')';
    }

    protected function parseBigAssignOp(Expr\AssignOp $node, string $var, string $type, string $expr, string $rightType, string $op): string
    {
        $binaryOp = $this->removeAssignOp($op);
        $bigExpr  = $this->parseBigAssignOpExpr($var, $type, $expr, $rightType, $binaryOp, $node->var, $node->expr);
        return $var . ' = ' . $bigExpr;
    }

    protected function parseBigAssignOpExpr(string $leftExpr, string $leftType, string $rightExpr, string $rightType, string $binaryOp, NodeAbstract $errorNode, ?NodeAbstract $rightNode = null): string
    {
        [$class, $opMap] = match ($leftType) {
            Type::BIGINT   => ['BigInt',   ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod', '&' => 'bitAnd', '|' => 'bitOr', '^' => 'bitXor', '<<' => 'bitShiftLeft', '>>' => 'bitShiftRight']],
            Type::DECIMAL  => ['Decimal',  ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod']],
            Type::BIGFLOAT => ['BigFloat', ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div']],
            default => $this->fatalError($errorNode, "Unsupported compound assignment type '{$leftType}'"),
        };

        $method = $opMap[$binaryOp] ?? null;
        if ($method === null) {
            $this->fatalError($errorNode, "Unsupported compound assignment operator '{$binaryOp}' for type {$leftType}");
        }

        // For bitwise shifts, the right operand is a shift amount (Int), not BigInt
        $isShift = ($binaryOp === '<<' || $binaryOp === '>>');
        $convertedRight = match ($leftType) {
            Type::BIGINT   => $isShift ? $rightExpr : $this->convertBigIntExpr($rightExpr, $rightType),
            Type::DECIMAL  => $this->convertDecimalExpr($rightExpr, $rightType, $rightNode),
            Type::BIGFLOAT => $this->convertBigFloatExpr($rightExpr, $rightType),
            default => $this->fatalError($errorNode, "Unsupported compound assignment type '{$leftType}'"),
        };

        return 'php::' . $class . '::' . $method . '(' . $leftExpr . ', ' . $convertedRight . ')';
    }

    private function canUpdateKnownArraySlotInPlace(Expr\AssignOp $node, string $op): bool
    {
        if (!$node->getAttribute(self::ATTR_STATEMENT_EXPRESSION, false)
            || !$node->var instanceof Expr\ArrayDimFetch
            || $node->var->dim === null
            || !$this->isVarExpr($node->var->var)
            || $this->shouldMaterializeOrderedOperand($node->var->dim)
            || !$this->canEmitDirectArrayWriteOperand($node->expr)
        ) {
            return false;
        }

        $array = $this->parseIdentifier($node->var->var);
        return $this->hasVar($array)
            && $this->getVarType($array) === Type::ARRAY
            && in_array($op, ['+=', '-=', '*=', '/=', '%=', '<<=', '>>=', '&=', '|=', '^='], true);
    }

    private function canEmitDirectArrayWriteOperand(NodeAbstract $expr): bool
    {
        if (!$this->shouldMaterializeOrderedOperand($expr)) {
            return true;
        }
        if (!$expr instanceof Expr\ArrayDimFetch
            || $expr->dim === null
            || !$this->isVarExpr($expr->var)
            || $this->shouldMaterializeOrderedOperand($expr->dim)
        ) {
            return false;
        }

        $array = $this->parseIdentifier($expr->var);
        return $this->hasVar($array) && $this->getVarType($array) === Type::ARRAY;
    }

    protected function parseAssignOpConcat(Expr\AssignOp\Concat $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    protected function parseAssignOpPlus(Expr\AssignOp\Plus $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    protected function parseAssignOpMinus(Expr\AssignOp\Minus $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    protected function parseAssignOpMod(Expr\AssignOp\Mod $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    protected function parseAssignOpMul(Expr\AssignOp\Mul $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    protected function parseAssignOpDiv(Expr\AssignOp\Div $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    protected function parseAssignOpBitwiseAnd(Expr\AssignOp\BitwiseAnd $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    protected function parseAssignOpBitwiseOr(Expr\AssignOp\BitwiseOr $expr): string
    {
        return $this->parseAssignOp($expr, '|=');
    }

    protected function parseAssignOpPow(Expr\AssignOp\Pow $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

    protected function parseArrayDimStore($array, $dim, $var): string
    {
        $id = $this->parseWritableIdentifier($array);

        return $id . '.offsetSet(' . $dim . ', ' . $var . ')';
    }

    protected function parseAssignOpShiftLeft(Expr\AssignOp\ShiftLeft $node): string
    {
        return $this->parseAssignOp($node, '<<=');
    }

    protected function parseAssignOpShiftRight(Expr\AssignOp\ShiftRight $node): string
    {
        return $this->parseAssignOp($node, '>>=');
    }

    protected function parseAssignOpBitwiseXor(Expr\AssignOp\BitwiseXor $node): string
    {
        return $this->parseAssignOp($node, '^=');
    }

    protected function parseAssignRef(Expr\AssignRef $expr): string
    {
        $this->assertImmutableMutationTarget($expr->var);
        $this->assertImmutableMutationTarget($expr->expr);
        $this->assertNativeArrayAccessReferenceForbidden($expr->var);
        $this->assertNativeArrayAccessReferenceForbidden($expr->expr);
        $this->assertNotNullsafeWriteContext($expr->var);
        $this->assertNativePropertyHookDirectWriteTarget($expr->var);
        $this->assertNativePropertyHookDirectWriteTarget($expr->expr);
        if ($expr->expr instanceof Expr\NullsafePropertyFetch) {
            $this->fatalError($expr->expr, 'Cannot take reference of a nullsafe chain');
        }

        $this->assertNativeObjectReferenceForbidden($expr->var, $expr);
        $this->assertNativeObjectReferenceForbidden($expr->expr, $expr);
        foreach ([$expr->var, $expr->expr] as $referenceOperand) {
            if ($this->isVarExpr($referenceOperand)) {
                $this->assertStdContainerDoesNotEscapeNativeObjects(
                    $referenceOperand,
                    $this->parseIdentifier($referenceOperand),
                );
            }
        }

        // A reference would outlive the constructor-only write window and
        // make later mutations invisible to the compiler. It is therefore
        // forbidden on either side even inside the declaring constructor.
        $this->assertReadonlyPropertyReferenceForbidden($expr->var, $expr, true);
        $this->assertReadonlyPropertyReferenceForbidden($expr->expr, $expr, false);

        $propertyReferenceTarget = null;
        $nativeObjectProperty = $expr->var instanceof Expr\PropertyFetch
            && $this->getNativePropertyClassDef($expr->var)?->nativeObject === true;
        if ($expr->var instanceof Expr\PropertyFetch && !$nativeObjectProperty) {
            // A property reference assignment must go through Zend's property
            // metadata path. A plain Variant indirect slot cannot maintain
            // typed-property reference sources safely. Parse the complete LHS
            // before the RHS so PHP's source evaluation order is preserved.
            $object = $this->parseOrderedOperand($expr->var->var, false);
            $member = $this->isIdExpr($expr->var->name)
                ? $this->propertyNameToStr($expr->var->name, literal: true)
                : $this->parseOrderedOperand($expr->var->name, false, true);
            $scope = $this->usesTraitPropertyScope($object)
                ? 'php::FakeScopeGuard::current()'
                : ($this->class ? $this->getClassEntryPtr($this->getFullClassName()) : 'nullptr');
            $propertyReferenceTarget = [$object, $member, $scope];
            $left = '';
        } else {
            $left = $this->parseWritableIdentifier($expr->var);
        }
        // Keep this write-context form for every RHS kind. Re-parsing it as a
        // read later breaks append and missing-key targets such as
        // `$array[] =& $source`.

        if ($this->isVarExpr($expr->var)) {
            if (!$this->hasVar($left)) {
                $this->addLocalVar($left, Type::REF);
            } else {
                $type = $this->getVarType($left);
                if ($type !== Type::REF) {
                    $this->fatalError($expr, 'Cannot assign reference to variable of type ' . $type);
                }
            }
        }

        $tmpVar = $this->addTmpVar(Type::REF);
        $rightExpr = '';

        if ($this->isVarExpr($expr->expr)) {
            $rightExpr = $tmpVar . ' = ' . $this->parseIdentifier($expr->expr) . '.toReference()';
        } elseif ($expr->expr instanceof Expr\FuncCall && $this->isNameExpr($expr->expr->name)) {
            $name = $this->parseIdentifier($expr->expr->name);
            $function = $this->findNativeFunction($name);
            if ($function) {
                if (!$this->getFunction($function)->returnsByRef) {
                    $this->fatalError($expr, 'Cannot assign reference to a function that does not return by reference');
                }
            } else {
                $reflection = \TypePhp\Resolver\Reflection::getFunction(ltrim($this->getNamespacedFuncName($name), '\\'));
                if ($reflection === null || !$reflection->isInternal() || !$reflection->returnsReference()) {
                    $this->fatalError($expr, 'Cannot assign reference to a function that does not return by reference');
                }
            }
            $rightExpr = $tmpVar . ' = ' . $this->parseExpr($expr->expr);
        } elseif ($expr->expr instanceof Expr\FuncCall) {
            $rightExpr = $tmpVar . ' = php::toReferenceExact(' . $this->parseExpr($expr->expr) . ')';
        } elseif ($expr->expr instanceof Expr\MethodCall) {
            if (!$this->isNamedMethod($expr->expr->name) || !$this->isVarExpr($expr->expr->var)) {
                $rightExpr = $tmpVar . ' = php::toReferenceExact(' . $this->parseExpr($expr->expr) . ')';
            } else {
                $object = $this->parseIdentifier($expr->expr->var);
                $method = $this->parseIdentifier($expr->expr->name);
                $function = $this->findNativeMethod($expr->expr, $object, $method);
                if (!$function) {
                    $rightExpr = $tmpVar . ' = php::toReferenceExact(' . $this->parseExpr($expr->expr) . ')';
                } else {
                    if (!$this->getFunction($function)->returnsByRef) {
                        $this->fatalError($expr, 'Cannot assign reference to a method that does not return by reference');
                    }
                    $rightExpr = $tmpVar . ' = ' . $this->parseExpr($expr->expr);
                }
            }
        } elseif ($expr->expr instanceof Expr\StaticCall) {
            if (!$this->isNameExpr($expr->expr->class) || !$this->isIdExpr($expr->expr->name)) {
                $rightExpr = $tmpVar . ' = php::toReferenceExact(' . $this->parseExpr($expr->expr) . ')';
            } else {
                $class = $this->parseIdentifier($expr->expr->class);
                if ($class === 'self') {
                    $class = $this->getFullClassName();
                } elseif ($class === 'parent') {
                    if (!$this->classDef || !$this->classDef->extends) {
                        $this->fatalError($expr, 'Cannot use "parent" outside a class or class does not extend any class');
                    }
                    $class = $this->classDef->extends;
                } elseif ($class !== 'static') {
                    $class = $this->getNamespacedClassName($class);
                }
                $method = $this->parseIdentifier($expr->expr->name);
                $function = $class === 'static' ? false : $this->getNativeMethod($expr->expr, $class, $method);
                if (!$function) {
                    $rightExpr = $tmpVar . ' = php::toReferenceExact(' . $this->parseExpr($expr->expr) . ')';
                } else {
                    if (!$this->getFunction($function)->returnsByRef) {
                        $this->fatalError($expr, 'Cannot assign reference to a static method that does not return by reference');
                    }
                    $rightExpr = $tmpVar . ' = ' . $this->parseExpr($expr->expr);
                }
            }
        } elseif ($this->isPropertyFetch($expr->expr)) {
            $rightExpr = $tmpVar . ' = ' . $this->emitDynamicPropertyFetchRef($expr->expr, $expr);
        } elseif ($this->isStaticPropertyFetch($expr->expr)) {
            $rightExpr = $tmpVar . ' = ' . $this->emitStaticPropertyFetchRef($expr->expr, $expr);
        } elseif ($this->isArrayDimFetch($expr->expr)) {
            $array = $this->parseWritableIdentifier($expr->expr->var);
            if ($expr->expr->dim == null) {
                $this->fatalError($expr, 'Cannot assign reference to array dim fetch without dim');
            }
            $rightExpr = $tmpVar . ' = ' . $array . '.itemRef(' . $this->parseIdentifier($expr->expr->dim) . ')';
        } else {
            $this->fatalError($expr, 'Cannot assign reference to ' . $this->parseIdentifier($expr->expr));
        }

        $this->context->beforeStmtLines[] = $rightExpr . ';';
        if ($propertyReferenceTarget !== null) {
            [$object, $member, $scope] = $propertyReferenceTarget;
            return 'typephp_rebind_property_reference('
                . $object . ', ' . $member . ', ' . $tmpVar . ', ' . $scope . ')';
        }
        return $left . ' = &' . $tmpVar;
    }

    protected function parseAssignPropertyArrayDim(NodeAbstract $left, NodeAbstract $right): string
    {
        $this->assertNativePropertyHookDirectWriteTarget($left);
        $propertyWriteTarget = $this->preparePropertyWriteTarget($left->var);
        $code     = '';
        $value    = $this->parseExprAsValue($right);
        $arrayDefWrite = $this->prepareArrayDefDirectWrite($left, $right, $value);
        if ($arrayDefWrite !== null) {
            $value = $arrayDefWrite->value;
        }

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, Type::VAR);

        if ($left->dim === null || ($arrayDefWrite !== null && $arrayDefWrite->append)) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . $this->emitDynamicPropertyFetchAppendArray($left->var, $tmp, $propertyWriteTarget) . '), ' . $tmp . ')';
        }
        $dim = $arrayDefWrite?->key ?? $this->parseIdentifier($left->dim);

        return $code . '((' . $tmp . ' = ' . $value . ', ' . $this->emitDynamicPropertyFetchUpdateArray($left->var, $dim, $tmp, $propertyWriteTarget) . '), ' . $tmp . ')';
    }

    protected function parseAssignStaticPropertyArrayDim(Expr\ArrayDimFetch $left, Expr $right): string
    {
        $value = $this->parseExprAsValue($right);
        $arrayDefWrite = $this->prepareArrayDefDirectWrite($left, $right, $value);
        $array = $this->parseWritableIdentifier($left->var);

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, Type::VAR);
        $value = $arrayDefWrite?->value ?? $value;

        if ($left->dim === null || ($arrayDefWrite !== null && $arrayDefWrite->append)) {
            return '((' . $tmp . ' = ' . $value . ', ' . $array . '.newItem() = ' . $tmp . '), ' . $tmp . ')';
        }
        $dim = $arrayDefWrite?->key ?? $this->parseIdentifier($left->dim);
        return '((' . $tmp . ' = ' . $value . ', ' . $array . '.item(' . $dim . ', true) = ' . $tmp . '), ' . $tmp . ')';
    }

    protected function parseAssignOpCoalesce(Expr\AssignOp\Coalesce $expr): string
    {
        $this->assertImmutableMutationTarget($expr->var);
        $this->assertNativeArrayAccessDirectWrite($expr->var, false);
        $this->checkLeftValue($expr->var);

        // Zend evaluates the target's receiver and array keys exactly once,
        // before the isset check and regardless of its outcome. The lowering
        // below mentions the target several times (isset, read, write), so
        // side-effecting subexpressions of the target are materialized into
        // temporaries first and the target is rewritten to reference them.
        if (!$this->isVarExpr($expr->var)) {
            $expr->var = $this->stabilizeCoalesceTarget($expr->var);
        }

        $rightClass = $this->detectClassOfExpr($expr->expr);
        $nativeRight = $this->isNativeObjectClass($rightClass);

        // An undefined variable must exist before generating its isset check.
        // A Native RHS establishes a typed nullptr slot; it must never be
        // declared as Variant because boxing the raw pointer would coerce it
        // to bool. Other values retain the normal nullable Variant behavior.
        $var = $this->isVarExpr($expr->var) ? $this->parseIdentifier($expr->var) : null;
        $globalSlot = $this->getStaticGlobalsSlot($expr->var);
        if ($globalSlot !== null) {
            if (!$this->hasGlobalVar($globalSlot)) {
                $this->addGlobalVar($globalSlot, Type::VAR);
            }
            if (!$this->hasScopeGlobalVar($globalSlot)) {
                $this->addScopeGlobalVar($globalSlot, $this->globalVars[$globalSlot]);
            }
            if ($nativeRight) {
                $this->promoteGlobalOrStaticToNativeObject($globalSlot, $rightClass, $expr->expr);
            }
            $var = $globalSlot;
        } elseif ($nativeRight
            && $expr->var instanceof Expr\ArrayDimFetch
            && $this->isVarExpr($expr->var->var)
            && $expr->var->var->name === 'GLOBALS'
        ) {
            $this->fatalError(
                $expr->var,
                'Native objects cannot be stored in dynamically addressed `$GLOBALS`',
            );
        }
        if ($var !== null && !$this->hasVar($var)) {
            if ($nativeRight) {
                $this->addLocalVar($var, $this->getNativeObjectPointerType($rightClass));
                $this->addNativeObject($var, $rightClass);
            } else {
                $this->addLocalVar($var, Type::VAR);
            }
        }
        if ($var !== null && $this->isNativeObjectVar($var)) {
            $leftClass = $this->getNativeObjectVarClass($var);
            if ($this->isNull($expr->expr)) {
                // nullptr remains a valid nullable slot value.
            } elseif (!$nativeRight) {
                $this->fatalError($expr->expr, "Native object `\${$var}` cannot be converted to var/object");
            } elseif (!$this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                $this->fatalError(
                    $expr->expr,
                    "Cannot assign native object `{$rightClass}` to `{$leftClass}`",
                );
            }
        }

        $arrayAccessTarget = $this->resolveCoalesceArrayAccessTarget($expr->var);
        $arrayAccessSelectedValue = null;
        $arrayAccessContainer = null;
        $arrayAccessKey = null;
        if ($arrayAccessTarget !== null) {
            $arrayAccessSelectedValue = $this->addTmpVar(Type::VAR);
            $arrayAccessPresence = $this->parseArrayAccessCoalescePresence(
                $arrayAccessTarget,
                $arrayAccessSelectedValue,
            );
            $isset = $arrayAccessPresence['condition'];
            $arrayAccessContainer = $arrayAccessPresence['container'];
            $arrayAccessKey = $arrayAccessPresence['key'];
        } else {
            $isset = $var !== null && $this->isNativeObjectVar($var)
                ? $var . ' != nullptr'
                : $this->parseChainedExpr($expr->var, self::OP_ISSET);
        }

        $var ??= $this->parseWritableIdentifier($expr->var);
        $propertyWriteTarget = $this->preparePropertyWriteTarget($expr->var);

        if ($propertyWriteTarget !== null) {
            $this->assertCanAssignPropertyWrite($propertyWriteTarget, $expr->expr);
        }

        $rightBeforeCount = count($this->context->beforeStmtLines);
        $rightAfterCount = count($this->context->afterStmtLines);
        $right = $this->parseExpr($expr->expr);
        $rightBefore = array_slice($this->context->beforeStmtLines, $rightBeforeCount);
        $rightAfter = array_slice($this->context->afterStmtLines, $rightAfterCount);
        $this->context->beforeStmtLines = array_slice(
            $this->context->beforeStmtLines,
            0,
            $rightBeforeCount,
        );
        $this->context->afterStmtLines = array_slice(
            $this->context->afterStmtLines,
            0,
            $rightAfterCount,
        );
        if ($propertyWriteTarget !== null) {
            $right = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $expr->expr, $right);
        }
        if ($this->isVarExpr($expr->expr) and !$this->hasVar($right)) {
            $this->errorUndefinedVariable($expr->expr);
        }

        if ($arrayAccessTarget !== null) {
            return $this->emitCoalesceArrayAccessAssignment(
                $arrayAccessTarget,
                $isset,
                $arrayAccessSelectedValue,
                $arrayAccessContainer,
                $arrayAccessKey,
                $right,
                $rightBefore,
                $rightAfter,
            );
        }

        $targetClass = $var !== null && $this->isNativeObjectVar($var)
            ? $this->getNativeObjectVarClass($var)
            : $this->detectClassOfExpr($expr->var);
        if (($rightBefore !== [] || $rightAfter !== []) && $this->isNativeObjectClass($targetClass)) {
            $tmp = $this->genTmpVarName();
            $pointerType = $this->getNativeObjectPointerType($targetClass);
            $this->addLocalVar($tmp, $pointerType);
            $this->addNativeObject($tmp, $targetClass);
            $code = '[&]() -> ' . $pointerType . ' {' . PHP_EOL;
            $code .= $this->getIndent() . 'if (' . $isset . ') { return ' . $var . '; }' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightBefore);
            $code .= $this->getIndent() . $tmp . ' = ' . $right . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightAfter);
            $code .= $this->getIndent() . $var . ' = ' . $tmp . ';' . PHP_EOL;
            $code .= $this->getIndent() . 'return ' . $var . ';' . PHP_EOL;
            $code .= $this->getIndent() . '}()';
            return $code;
        }
        if ($rightBefore !== [] || $rightAfter !== []) {
            // PHP evaluates the RHS of ??= only when the target is not set.
            // A compound RHS materializes captured statements (call results,
            // operand temporaries); appending them to the enclosing statement
            // would run its side effects unconditionally. Wrap the not-set
            // branch in an immediately-invoked lambda so they execute only
            // when the assignment actually happens. The RHS is completed —
            // including its deferred write-backs (a postfix ++ on the RHS
            // must finish before the outer assignment, which a set hook on
            // the target can observe) — into a temporary before the target
            // is written, and the assignment expression itself is returned
            // so the target is not read again afterwards.
            $rhsTmp = $this->genTmpVarName();
            $code = '[&]() {' . PHP_EOL;
            $code .= $this->getIndent() . 'if (' . $isset . ') { return ' . $var . '; }' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightBefore);
            $code .= $this->getIndent() . 'auto ' . $rhsTmp . ' = ' . $right . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($rightAfter);
            $code .= $this->getIndent() . 'return (' . $var . ' = ' . $rhsTmp . ');' . PHP_EOL;
            $code .= $this->getIndent() . '}()';
            return $code;
        }
        return '(' . $isset . '?' . $var . ':(' . $var . ' = ' . $right . '))';
    }

    /**
     * Use the separated ArrayAccess read/write path only when the dimension
     * container may be an object at runtime. Fixed arrays keep their existing
     * bucket-lvalue path.
     */
    private function resolveCoalesceArrayAccessTarget(Expr $target): ?Expr\ArrayDimFetch
    {
        if (!$target instanceof Expr\ArrayDimFetch
            || $target->dim === null
            || $this->isStdContainerExpr($target)
            || $this->isNativeObjectClass($this->detectClassOfExpr($target->var))
        ) {
            return null;
        }

        return in_array(
            $this->detectTypeOfExpr($target->var),
            [Type::OBJECT, Type::VAR, Type::REF],
            true,
        ) ? $target : null;
    }

    /**
     * Resolve nested ArrayAccess presence from outermost to innermost. This
     * avoids assigning to the value returned by offsetGet() and avoids the
     * generic exists() chain invoking offsetExists() more than once.
     *
     * @return array{condition: string, container: string, key: string}
     */
    private function parseArrayAccessCoalescePresence(
        Expr\ArrayDimFetch $target,
        string $selectedValue,
    ): array {
        if ($this->isVarExpr($target->var)) {
            $container = $this->parseIdentifier($target->var);
            $this->checkVarMustExist($target->var, $container);
            $containerPresence = null;
        } elseif ($target->var instanceof Expr\ArrayDimFetch && $target->var->dim !== null) {
            $container = $this->addTmpVar(Type::VAR);
            $outerPresence = $this->parseArrayAccessCoalescePresence(
                $target->var,
                $container,
            );
            $containerPresence = $outerPresence['condition'];
        } else {
            $container = $this->parseIdentifier($target->var);
            $containerPresence = null;
        }

        $key = $this->parseIdentifier($target->dim);
        $stableContainer = $this->addTmpVar(Type::VAR);
        $stableKey = $this->addTmpVar(Type::VAR);
        $objectPresence = '(' . $stableContainer . '.offsetExists(' . $stableKey . ')'
            . ' && ((' . $selectedValue . ' = ' . $stableContainer . '.offsetGet(' . $stableKey . ')),'
            . ' !' . $selectedValue . '.isNull()))';
        $otherPresence = 'php::exists(' . $stableContainer . ', '
            . '{{php::ArrayDimFetch, ' . Type::VAR . '(' . $stableKey . ')}}, '
            . $selectedValue . ')';
        $probe = '((' . $stableContainer . ' = ' . $container . '), ('
            . $stableKey . ' = ' . $key . '), (' . $stableContainer . '.isObject() ? '
            . $objectPresence . ' : ' . $otherPresence . '))';

        return [
            'condition' => $containerPresence === null
                ? $probe
                : '(' . $containerPresence . ' && ' . $probe . ')',
            'container' => $stableContainer,
            'key' => $stableKey,
        ];
    }

    /**
     * Arrays expose a writable bucket. Every other supported dynamic receiver
     * goes through PHPX offsetSet(); unsupported scalars fail there with one
     * stable PHPX error rather than silently discarding the write.
     */
    private function parseArrayAccessCoalesceStore(
        Expr\ArrayDimFetch $target,
        string $stableContainer,
        string $stableKey,
        string $value,
    ): string {
        $array = $this->parseWritableIdentifier($target->var);

        return '(' . $stableContainer . '.isObject()'
            . ' ? (' . $stableContainer . '.offsetSet(' . $stableKey . ', ' . $value . '), ' . $value . ')'
            . ' : (' . $array . '.item(' . $stableKey . ', true) = ' . $value . '))';
    }

    /**
     * @param list<string> $rightBefore
     * @param list<string> $rightAfter
     */
    private function emitCoalesceArrayAccessAssignment(
        Expr\ArrayDimFetch $target,
        string $isset,
        string $selectedValue,
        string $stableContainer,
        string $stableKey,
        string $right,
        array $rightBefore,
        array $rightAfter,
    ): string {
        $rhs = $this->addTmpVar(Type::VAR);
        $store = $this->parseArrayAccessCoalesceStore(
            $target,
            $stableContainer,
            $stableKey,
            $rhs,
        );

        if ($rightBefore === [] && $rightAfter === []) {
            return '(' . $isset . ' ? ' . $selectedValue
                . ' : ((' . $rhs . ' = ' . $right . '), ' . $store . '))';
        }

        $code = '[&]() -> php::Var {' . PHP_EOL;
        $code .= $this->getIndent() . 'if (' . $isset . ') { return ' . $selectedValue . '; }' . PHP_EOL;
        $code .= $this->formatCapturedStmtLines($rightBefore);
        $code .= $this->getIndent() . $rhs . ' = ' . $right . ';' . PHP_EOL;
        $code .= $this->formatCapturedStmtLines($rightAfter);
        $code .= $this->getIndent() . 'return ' . $store . ';' . PHP_EOL;
        return $code . $this->getIndent() . '}()';
    }

    /**
     * Rewrite a coalesce-assignment target so that every side-effecting
     * subexpression is evaluated exactly once, in PHP source order —
     * container/receiver first, then a dynamic property name, then the array
     * dimension — before the target is mentioned. Containers of an array
     * write keep their original node when they are plain variables (writing
     * through a copied temporary would write to the copy), while a
     * value-producing container is itself the temporary PHP writes into;
     * object receivers are handles, so a temporary preserves identity.
     */
    private function stabilizeCoalesceTarget(Expr $target): Expr
    {
        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) {
            if (!$this->isCoalesceTargetTrivialSubexpr($target->var)) {
                $target->var = $this->materializeCoalesceTargetSubexpr($target->var, true);
            }
            if ($target->name instanceof Expr && !$this->isCoalesceTargetTrivialSubexpr($target->name)) {
                $target->name = $this->materializeCoalesceTargetSubexpr($target->name, false);
            }
            return $target;
        }
        if ($target instanceof Expr\StaticPropertyFetch) {
            if ($target->class instanceof Expr && !$this->isCoalesceTargetTrivialSubexpr($target->class)) {
                $target->class = $this->materializeCoalesceTargetSubexpr($target->class, false);
            }
            if ($target->name instanceof Expr && !$this->isCoalesceTargetTrivialSubexpr($target->name)) {
                $target->name = $this->materializeCoalesceTargetSubexpr($target->name, false);
            }
            return $target;
        }
        if ($target instanceof Expr\ArrayDimFetch) {
            if ($target->var instanceof Expr\PropertyFetch
                || $target->var instanceof Expr\NullsafePropertyFetch
                || $target->var instanceof Expr\StaticPropertyFetch
                || $target->var instanceof Expr\ArrayDimFetch
            ) {
                $target->var = $this->stabilizeCoalesceTarget($target->var);
            } elseif (!$this->isCoalesceTargetTrivialSubexpr($target->var)) {
                $target->var = $this->materializeCoalesceTargetSubexpr(
                    $target->var,
                    isReceiver: false,
                    writableContainer: true,
                );
            }
            // An object container is a handle, so copying it into a temporary
            // preserves identity and lets ArrayAccess presence/read/write all
            // use the same property or nested-dimension result. Do not do this
            // for arrays: their write must continue through the original
            // bucket/property lvalue.
            if (!$this->isVarExpr($target->var)
                && $this->detectTypeOfExpr($target->var) === Type::OBJECT
                && !$this->isNativeObjectClass($this->detectClassOfExpr($target->var))
            ) {
                $target->var = $this->materializeCoalesceTargetSubexpr(
                    $target->var,
                    isReceiver: true,
                    writableContainer: true,
                );
            }
            if ($target->dim !== null && !$this->isCoalesceTargetTrivialSubexpr($target->dim)) {
                $target->dim = $this->materializeCoalesceTargetSubexpr($target->dim, false);
            }
            return $target;
        }
        return $target;
    }

    private function isCoalesceTargetTrivialSubexpr(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable
            || $expr instanceof Node\Scalar
            || $expr instanceof Expr\ConstFetch
            || $expr instanceof Expr\ClassConstFetch;
    }

    private function materializeCoalesceTargetSubexpr(
        Expr $sub,
        bool $isReceiver,
        bool $writableContainer = false,
    ): Expr\Variable
    {
        [$code, $before, $after] = $this->parseExprWithCapturedStmts($sub);
        $this->appendCapturedStmtLinesToContext($before);

        $class = $isReceiver ? $this->detectClassOfExpr($sub) : '';
        if ($isReceiver && $this->isNativeObjectClass($class)) {
            // Boxing a raw Native pointer in a Variant would coerce it to
            // bool; keep the typed pointer slot instead.
            $tmp = $this->genTmpVarName();
            $this->addLocalVar($tmp, $this->getNativeObjectPointerType($class));
            $this->addNativeObject($tmp, $class);
            $cleanup = $tmp . ' = nullptr;';
        } elseif ($writableContainer && $this->resolveRefReturningCall($sub) !== false) {
            // A call result used as an array write target may be a reference to
            // external storage. Boxing it in a Variant applies normal PHP value
            // semantics and dereferences it, so the later write would modify a
            // detached array copy. A Reference temporary preserves known
            // by-reference returns as well as runtime-resolved dynamic calls;
            // assigning a normal value to it still provides the disposable
            // temporary container required by PHP.
            $tmp = $this->addTmpVar(Type::REF);
            $cleanup = $tmp . '.unset();';
        } else {
            // Keep the value in a Variant: the rewritten target then goes
            // through the generic Zend handlers, which also covers receivers
            // whose concrete class is known only at runtime.
            $tmp = $this->addTmpVar(Type::VAR);
            $cleanup = $tmp . '.unset();';
        }
        $this->context->beforeStmtLines[] = $tmp . ' = ' . $code . ';';
        $this->appendCapturedStmtLinesToContext($after);
        // The temporary must not outlive the statement: PHP destroys the
        // target's receiver/container at the end of the statement, and a
        // function-scoped Variant would defer destructors to function exit.
        $this->context->afterStmtLines[] = $cleanup;
        return new Expr\Variable($tmp, $sub->getAttributes());
    }

    protected function getNormalAssignType(string $type): string
    {
        return $type === Type::REF || $type === Type::VOID ? Type::VAR : $type;
    }

}
