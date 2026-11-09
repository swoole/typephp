<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves native return types, inheritance compatibility, and converted call arguments.
 */

namespace TypePhp\TypeSystem;

use TypePhp\Type;

use PhpParser\Node;
use TypePhp\Entity\ArgInfo;

trait NativeTypeCompatibilityTrait
{
    protected function getReturnType(): string
    {
        $type = $this->functionDef->returnType;
        if ($type === Type::STREAM) {
            return Type::VAR;
        }
        return $type;
    }

    protected function getReturnClass(): string
    {
        return $this->functionDef->returnClass;
    }

    protected function isInheritedFrom(string $class, string $expected): bool
    {
        // The single entry point for inheritance checks. Callers must not use PHP
        // runtime reflection functions directly to judge ordinary project classes.
        // For project classes/interfaces already scanned by AOT, the extends/
        // implements graph in classDef/interfaceDef must be followed; for PHP
        // built-in classes/interfaces, Zend runtime reflection may be used, since
        // these are fixed capabilities of the target PHP runtime. Returning true
        // for a dynamic class means "cannot be disproven at static time", so a
        // runtime check must be retained as a fallback.
        $class = ltrim($class, '\\');
        $expected = ltrim($expected, '\\');
        if (strcasecmp($class, $expected) === 0) {
            return true;
        }

        $internal = ($this->isInternalClass($expected) or $this->isInternalInterface($expected));
        $isInterface = ($this->hasInterface($expected) or $this->isInternalInterface($expected));

        if ($this->hasInterface($class)) {
            if (!$isInterface) {
                return false;
            }
            return $this->interfaceExtends($class, $expected);
        }

        if ($this->isInternalClass($class) or $this->isInternalInterface($class)) {
            // Zend's inheritance relation is only used between built-in types.
            // This is not a query on an arbitrary user class, so external library
            // classes loaded by the compiler process are never mixed into the
            // project's static type system.
            if (!$internal) {
                return false;
            }
            return is_subclass_of($class, $expected);
        }

        // If the class does not exist, it is a dynamic class; skip the static
        // check and defer to a runtime check
        if (!$this->hasClass($class)) {
            return true;
        }
        $classDef = $this->getClass($class);
        if ($classDef->enum) {
            if (strcasecmp($expected, 'UnitEnum') === 0) {
                return true;
            }
            if ($classDef->enumBackingType !== null
                && strcasecmp($expected, 'BackedEnum') === 0
            ) {
                return true;
            }
        }
        if ($classDef->nativeObject
            && strcasecmp($expected, 'Stringable') === 0
            && $this->findNativeObjectMethod($class, '__toString') !== null
        ) {
            // PHP implicitly marks every class with __toString() as
            // Stringable. Native classes have no zend_class_entry, so preserve
            // that relation in the compile-time class graph instead.
            return true;
        }
        while (true) {
            if ($isInterface) {
                if ($classDef->implements and in_array($expected, $classDef->implements)) {
                    return true;
                }
                // Check transitive interface inheritance (e.g., Iterator extends Traversable)
                foreach ($classDef->implements as $iface) {
                    $stack = [$iface];
                    while ($stack) {
                        $check = array_pop($stack);
                        if (strcasecmp($check, $expected) === 0) {
                            return true;
                        }
                        if (!$this->hasInterface($check)) {
                            if ($internal && $this->isInternalInterface($check) && is_subclass_of($check, $expected)) {
                                return true;
                            }
                            continue;
                        }
                        $interfaceDef = $this->getInterface($check);
                        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentIface) {
                            $stack[] = $parentIface;
                        }
                    }
                }
            } else {
                if (strcasecmp($class, $expected) === 0) {
                    return true;
                }
                if (!$this->hasClass($class)) {
                    // A native class extends a built-in class (e.g. UserError extends
                    // Exception), and $expected is Throwable. In this case ZendVM must
                    // be used to obtain the inheritance relation.
                    if ($this->isInternalClass($class) and $internal) {
                        return $class === $expected or is_subclass_of($class, $expected);
                    }
                    return false;
                }
            }
            if (!$classDef->extends) {
                return false;
            }
            $class = $classDef->extends;
            if ($this->isInternalClass($class)) {
                // Project classes may extend built-in classes. Once the built-in
                // parent chain is entered, further relations are delegated to Zend;
                // however, $expected must also be a built-in class/interface,
                // otherwise runtime reflection cannot cross into the external user
                // class namespace.
                return $internal && (strcasecmp($class, $expected) === 0 || is_subclass_of($class, $expected));
            }
            $classDef = $this->getClass($class);
        }
    }

    private function interfaceExtends(string $interface, string $expected): bool
    {
        // Interface inheritance is handled separately because interfaceDef has no
        // parent chain like classDef. Only the AOT-known interface graph is
        // traversed here; Zend's is_subclass_of() is allowed only when a built-in
        // interface is encountered.
        $stack = [$interface];
        while ($stack) {
            $check = array_pop($stack);
            if (strcasecmp($check, $expected) === 0) {
                return true;
            }
            if (!$this->hasInterface($check)) {
                if ($this->isInternalInterface($check) && $this->isInternalInterface($expected) && is_subclass_of($check, $expected)) {
                    return true;
                }
                continue;
            }
            $interfaceDef = $this->getInterface($check);
            foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentInterface) {
                $stack[] = $parentInterface;
            }
        }
        return false;
    }

    protected function getTypeConvertedArg(
        Node\Arg $arg,
        ArgInfo $argInfo,
        string $callableName = '',
        int $argIndex = 0
    ): string
    {
        $arg = $this->normalizeBareFunctionCallableArgument(
            $arg,
            $this->argInfoAcceptsCallable($argInfo),
        );
        $type = $this->detectTypeOfExpr($arg->value);
        $this->assertExprCanBeUsedAsValue($arg->value, 'function argument');
        if ($this->isVarExpr($arg->value)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects(
                $arg,
                $this->parseIdentifier($arg->value),
            );
        }

        if (!empty($argInfo->typeCheck)) {
            $this->checkCompositeTypeAssignment(
                $arg,
                $argInfo->typeCheck,
                $argInfo->typeStr,
                $arg->value,
                'argument `$' . ($argInfo->phpName ?: $this->unescapeVarName($argInfo->name)) . '`'
            );
        }

        $declaredClass = $argInfo->declaredClass ?: $argInfo->class;
        $argumentClass = $this->detectClassOfExpr($arg->value);
        if ($this->isNativeObjectClass($argumentClass)
            && !$this->isNativeObjectClass($declaredClass)
        ) {
            if ($declaredClass !== '' && $this->isInterface($declaredClass)) {
                $this->fatalError(
                    $arg,
                    "Native objects cannot be converted to interface `{$declaredClass}`",
                );
            }
            $this->fatalError(
                $arg,
                'Native objects cannot cross a PHP/ZendVM argument boundary',
            );
        }
        if ($this->isNativeObjectClass($declaredClass)) {
            if ($argInfo->nullable && $this->isNull($arg->value)) {
                return 'nullptr';
            }
            $class = $argumentClass;
            if ($class === '' || !$this->isNativeObjectClass($class)
                || !$this->isObjectClassStaticallyAssignableTo($class, $declaredClass)
            ) {
                $argName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
                $this->fatalError(
                    $arg,
                    "Argument `{$argName}` must be a native object of type `{$declaredClass}`"
                );
            }
            $expr = $this->parseOrderedArg($arg);
            return $this->materializeCallArgValue($arg->value, $expr);
        }

        if ($argInfo->byRef) {
            // A fixed Native field already is the exact C++ storage required by
            // a typed-reference parameter. Bind the call directly to that field
            // instead of attempting to manufacture a Zend reference around it.
            // Explicit std::ref()/toRef() remains a dynamic-reference request and
            // deliberately follows the ordinary Native reference restrictions.
            if (Type::isTypedRefType($argInfo->type)
                && !$this->isReferenceWrapperCall($arg->value)
            ) {
                $nativeProperty = $this->parseNativeTypedPropertyReferenceArg(
                    $arg->value,
                    $argInfo->type,
                    $arg,
                );
                if ($nativeProperty !== null) {
                    return $nativeProperty;
                }
            }

            if ($this->isReferenceWrapperCall($arg->value)) {
                $inner = $this->unwrapReferenceWrapperCall($arg->value, $arg);
                $this->assertNativeObjectReferenceForbidden($inner, $arg);
                $this->assertReadonlyPropertyReferenceForbidden($inner, $arg, false);
                if ($this->isVarExpr($inner)) {
                    $arg->value = $inner;
                } else {
                    $expr = $this->expandReferenceWrapperExpr($inner, $arg);
                    if ($expr !== null) {
                        return $expr;
                    }
                    $this->fatalError($arg, 'The std::ref function only accepts a variable, array element, or object property');
                }
            } else {
                $this->assertNativeObjectReferenceForbidden($arg->value, $arg);
                $this->assertReadonlyPropertyReferenceForbidden($arg->value, $arg, false);
            }
            if ($this->isVarExpr($arg->value)) {
                $var = $this->parseVariable($arg->value);
                // For a by-reference parameter, an undefined variable may be passed;
                // it is created immediately as a reference
                if (!$this->hasLocalVar($var)) {
                    $this->addLocalVar($var, Type::VAR);
                }
            }

            if (Type::isTypedRefType($argInfo->type)) {
                $expectedType = Type::getReferencedType($argInfo->type);
                $actualType = Type::getReferencedType($this->detectTypeOfExpr($arg->value));
                if ($this->isVarExpr($arg->value)) {
                    $var = $this->parseIdentifier($arg->value);
                    $rawType = $this->getRawVarType($var);
                    if ($actualType === $expectedType
                        && ($rawType === $expectedType || $rawType === $argInfo->type)
                    ) {
                        return $var;
                    }
                }

                if ($actualType !== $expectedType
                    && !in_array($actualType, [Type::VAR, Type::REF], true)
                ) {
                    $this->fatalError(
                        $arg,
                        'Cannot pass value of type ' . $actualType
                            . ' to reference parameter of type ' . $argInfo->type,
                    );
                }

                $reference = $this->addTmpVar(Type::REF);
                $wrapper = $this->genTmpVarName();
                $this->addBeforeStmtLine($reference . ' = ' . $this->convertToRef($arg->value) . ';');
                $this->addBeforeStmtLine('php::RefWrap<' . $expectedType . '> '
                    . $wrapper . '(' . $reference . ');');
                $this->addAfterStmtLine($wrapper . '.commit();');
                return $wrapper . '.typed()';
            }

            // A mixed/union/defaulted reference parameter keeps the php::Ref
            // ABI. When its caller is one of the five fixed native locals,
            // bridge that storage for exactly this call and validate the
            // write-back afterwards instead of weakening the local to Var.
            if ($this->isVarExpr($arg->value)) {
                $var = $this->parseIdentifier($arg->value);
                $rawType = $this->getRawVarType($var);
                $valueType = Type::getReferencedType($rawType);
                if (Type::getReferenceType($valueType) !== null) {
                    return $this->getDynamicTypedRefBridge($var, $valueType) . '.ref()';
                }
            }
            return $this->convertToRef($arg->value);
        }

        $expr = $this->parseOrderedArg($arg);
        $expr = $this->materializeCallArgValue($arg->value, $expr);

        if (($type === Type::VAR || $type === Type::REF) && $this->isStrictScalarType($argInfo->type)) {
            // A native scalar ABI value has already lost its zval type. Preserve
            // the dynamic value until TypePHP's strict validation has completed.
            // The PHPX helper evaluates the expression exactly once and returns
            // the final native ABI type without an immediately-invoked closure.
            $this->checkVarAssignExpr($arg, $argInfo->type, $type);
            return $this->genStrictScalarArgConversion(
                $argInfo,
                $expr,
                $callableName,
                (string) ($argIndex + 1)
            );
        }

        $this->checkVarAssignExpr($arg, $argInfo->type, $type);

        if ($argInfo->type === Type::VAR && $this->isVarExpr($arg->value)) {
            $varName = $this->parseIdentifier($arg->value);
            if ($this->isStdContainer($varName)) {
                return $varName;
            }
        }

        if ($argInfo->type === Type::OBJECT) {
            if ($argInfo->nullable) {
                if ($this->isNullExpr($arg->value)) {
                    return 'php::Object{}';
                }
                $class = $this->detectDeclaredClassOfExpr($arg->value);
                if ($type === Type::OBJECT && $class !== '' && $declaredClass !== ''
                    && $this->isObjectClassStaticallyAssignableTo($class, $declaredClass)) {
                    return $expr;
                }
                return $this->convertNullableObjectExpr(
                    $expr,
                    $declaredClass !== '' ? $this->getClassEntryPtr($declaredClass) : '',
                );
            }
            if ($declaredClass !== '') {
                $class = $this->detectDeclaredClassOfExpr($arg->value);
                if ($class !== '') {
                    // Class and non-null proofs are compile-time facts. Keep
                    // ordinary T -> T calls free of repeated boundary checks.
                    if ($this->isObjectClassStaticallyAssignableTo($class, $declaredClass)) {
                        if ($type === Type::OBJECT && $this->isObjectExpressionKnownNonNull($arg->value)) {
                            return $expr;
                        }
                        return $this->convertObjectExpr($expr, $this->getClassEntryPtr($declaredClass));
                    }
                    if ($this->isKnownConcreteObjectExpr($arg->value, $class)) {
                        $argName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
                        $this->fatalError($arg, "Argument `{$argName}` must be an instance of `{$declaredClass}`, `{$class}` given");
                    }
                }
                return $this->convertObjectExpr($expr, $this->getClassEntryPtr($declaredClass));
            }
            return $type === Type::OBJECT ? $expr : $this->convertObjectExpr($expr);
        }

        return $this->convertExprType($expr, $argInfo->type, $type);
    }

}
