<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves object and static method calls, including native and magic fallbacks.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use TypePhp\Exception\DynamicCall;
use TypePhp\Exception\PlaceHolder;
use TypePhp\Generator\Symbol;
use TypePhp\Resolver\Reflection;
use TypePhp\Transform\PropertyHookLowering;

trait MethodCallTrait
{
    private function parseParentPropertyHookCall(Expr\StaticCall $expr): ?string
    {
        if (!$expr->class instanceof Expr\StaticPropertyFetch
            || !$expr->class->class instanceof Node\Name
            || strtolower($expr->class->class->toString()) !== 'parent'
            || !$expr->class->name instanceof Node\VarLikeIdentifier
            || !$expr->name instanceof Node\Identifier
        ) {
            return null;
        }

        $kind = strtolower($expr->name->toString());
        if ($kind !== 'get' && $kind !== 'set') {
            return null;
        }
        if ($expr->isFirstClassCallable()) {
            $this->fatalError($expr, 'Cannot create Closure for parent property hook call');
        }

        $property = $expr->class->name->toString();
        $activeHook = $this->methodDef?->node?->getAttribute(PropertyHookLowering::METHOD_ATTRIBUTE);
        if (!is_array($activeHook)) {
            $this->fatalError(
                $expr,
                "Must not use parent::\${$property}::{$kind}() outside a property hook",
            );
        }
        if (($activeHook['property'] ?? null) !== $property) {
            $this->fatalError(
                $expr,
                "Must not use parent::\${$property}::{$kind}() in a different property (\$"
                    . ($activeHook['property'] ?? '') . ')',
            );
        }
        if (($activeHook['kind'] ?? null) !== $kind) {
            $this->fatalError(
                $expr,
                "Must not use parent::\${$property}::{$kind}() in a different property hook ("
                    . ($activeHook['kind'] ?? '') . ')',
            );
        }
        if (!$this->classDef?->extends) {
            $this->fatalError($expr, 'Cannot use "parent" when current class scope has no parent');
        }

        $parentClass = $this->classDef->extends;
        $declaringClass = $parentClass;
        $parentProperty = null;
        while ($declaringClass !== '') {
            $parentDef = $this->getClassDef($declaringClass);
            if ($parentDef === null) {
                break;
            }
            if ($parentDef->hasProperty($property)) {
                $parentProperty = $parentDef->getProperty($property);
                break;
            }
            $declaringClass = $parentDef->extends;
        }
        if ($parentProperty === null) {
            $this->fatalError($expr, "Undefined property {$parentClass}::\${$property}");
        }
        if ($parentProperty->isPrivate()) {
            $this->fatalError($expr, "Cannot access private property {$declaringClass}::\${$property}");
        }

        $hookKind = $kind === 'get' ? 'ZEND_PROPERTY_HOOK_GET' : 'ZEND_PROPERTY_HOOK_SET';
        $function = 'typephp_get_parent_property_hook('
            . $this->getClassEntryPtr($parentClass) . ', '
            . $this->getLiteralString($property) . ', ' . $hookKind . ')';
        if ($expr->args === []) {
            return 'this_.call(' . $function . ')';
        }
        return 'this_.call(' . $function . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function runtimeMethodRequiresDynamicScope(
        string $class,
        string $method,
        bool $magicMethod = false,
        bool $currentObject = false,
    ): bool {
        if ($method === '' || $magicMethod) {
            return true;
        }

        if ($class !== '') {
            $flags = $this->getMethodFlags($class, $method);
            if ($flags !== 0) {
                return !($flags & Modifiers::PUBLIC);
            }

            $modifiers = Reflection::getClassMethodModifiers($class, $method);
            if ($modifiers !== null) {
                return !($modifiers & \ReflectionMethod::IS_PUBLIC);
            }
        }

        // Late-bound receivers such as `new static()` do not have an exact
        // class in the local type map. A matching current-class method still
        // carries the lexical visibility rules of that class.
        if ($this->classDef !== null) {
            $flags = $this->getMethodFlags($this->getFullClassName(), $method);
            if ($flags !== 0) {
                return !($flags & Modifiers::PUBLIC);
            }
        }

        // An unresolved method on `$this` may be declared by a runtime
        // subclass.  The compiler itself exercises this when a method
        // inherited from CompilerBase calls a protected helper supplied by
        // Translator.  Calls on other receivers retain the public fast path.
        return $currentObject && $this->methodDef !== null;
    }

    protected function isOverrideMethod(string $fullMethodName): bool
    {
        $fullMethodNameLower = strtolower($fullMethodName);
        return isset($this->classMethodOverride[$fullMethodNameLower]) and $this->classMethodOverride[$fullMethodNameLower];
    }

    protected function getOverrideMethodName(string $class, string $method): string
    {
        if (!$this->hasClass($class) && !$this->hasInterface($class)
            && !$this->isInternalClass($class) && !$this->isInternalInterface($class)) {
            $class = $this->getNamespacedClassName($class);
        }
        return $class . '::' . $method;
    }

    protected function hasSubClasses(string $classNameLower): bool
    {
        return !empty($this->classSubClasses[$classNameLower]);
    }

    protected function isCurrentClassFinal(): bool
    {
        return $this->classDef && ($this->classDef->flags & Modifiers::FINAL) !== 0;
    }

    protected function isFinalClass(string $class): bool
    {
        return $this->hasClass($class) && ($this->getClass($class)->flags & Modifiers::FINAL) !== 0;
    }

    protected function getMethodFlags(string $class, string $method): int
    {
        if (!$this->hasClass($class)) {
            return 0;
        }
        $classDef = $this->getClass($class);
        while (true) {
            $flags = $classDef->getMethodFlags($method);
            if ($flags !== 0) {
                return $flags;
            }
            if (!$classDef->extends || !$this->hasClass($classDef->extends)) {
                return 0;
            }
            $classDef = $this->getClass($classDef->extends);
        }
    }

    protected function guardAbstractMethod(string $class, string $method, Node $expr): void
    {
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & Modifiers::ABSTRACT) {
            $this->fatalError($expr, "Cannot call abstract method `{$class}::{$method}()`");
        }
    }

    /**
     * Determine whether a method call can be devirtualized to a direct native call.
     *
     * Returns true when the exact class is known at compile time:
     *  1. $this->m() in a final class (no subclass possible)
     *  2. $this->m() where m is final (can't be overridden)
     *  3. $this->m() where m is private (not virtual)
     *  4. $obj->m() where obj's class has no known subclasses
     *  5. $obj->m() where obj is SSA-stable and its class is final
     */
    protected function canDevirtualize(string $object, string $class, string $method): bool
    {
        // Case 1: Calling on 'this_' in a final class
        if ($object === 'this_' && $this->isCurrentClassFinal()) {
            return true;
        }

        // Case 2 & 3: Method is final or private
        $flags = $this->getMethodFlags($class, $method);
        if ($flags & (Modifiers::FINAL | Modifiers::PRIVATE)) {
            return true;
        }

        // Case 4: Typed object whose class has no known subclasses
        if ($object !== 'this_' && $this->hasClass($class)) {
            $classLower = strtolower($class);
            if (!$this->hasSubClasses($classLower) && !$this->isInterface($class) && !$this->isAbstractClass($class)) {
                return true;
            }
        }

        // Case 5: SSA stability proves the variable identity, not necessarily
        // the runtime class. Only final classes are exact enough here.
        if ($object !== 'this_' && isset($this->context->stableObjects[$object])) {
            $stableClass = $this->context->stableObjects[$object];
            if ($this->isFinalClass($stableClass)) {
                return true;
            }
        }

        return false;
    }

    protected function findNativeMethod(CallLike $expr, string $object, string $method): string|false
    {
        $classDef = null;
        if ($object === 'this_') {
            $class = $this->getFullClassName();
            $classDef = $this->classDef;
        } elseif (isset($this->context->objects[$object])) {
            $class = $this->context->objects[$object];
            // SSA-stable: use exact type from stableObjects (more specific than declared type)
            if (isset($this->context->stableObjects[$object])) {
                $class = $this->context->stableObjects[$object];
            }
        } else {
            return false;
        }

        $nativeFunc = $this->getNativeMethod($expr, $class, $method);
        // A Native class exists but the method was not found; this may be a dynamic call
        if (!$nativeFunc) {
            if ($this->hasClass($class) and $this->getNativeMethod($expr, $class, '__call', false)) {
                throw new DynamicCall();
            }
        }

        $fullMethodName = $this->getOverrideMethodName($class, $method);

        // A subclass declares a method with the same name, so try to devirtualize
        if ($this->isOverrideMethod($fullMethodName)) {
            if (!$this->canDevirtualize($object, $class, $method)) {
                return false;
            }
        }
        if ($nativeFunc) {
            if ($this->hasClass($class) && $this->getMethodFlags($class, $method) & Modifiers::ABSTRACT) {
                return false;
            }
            if ($object !== 'this_' && !isset($this->context->stableObjects[$object])
                && ($this->isAbstractClass($class) || $this->isInterface($class))) {
                return false;
            }
            $this->checkFunction($nativeFunc);
            if ($this->hasFunction($nativeFunc)) {
                return $nativeFunc;
            }
        }
        return false;
    }

    /**
     * Directly invoke a TypePHP-compiled __call() only when the receiver's
     * exact runtime class is statically proven. A declared class is not enough:
     * a subclass may provide the requested real method instead of invoking the
     * parent's __call().
     */
    protected function parseDirectNativeMagicCall(
        Expr\MethodCall $expr,
        string $object,
        string $class,
        string $method,
    ): ?string {
        if ($class === '' || !$this->hasClass($class)) {
            return null;
        }

        $exactClass = null;
        if ($object === 'this_' && $this->isCurrentClassFinal()) {
            $exactClass = $this->getFullClassName();
        } elseif (isset($this->context->exactObjects[$object])) {
            $exactClass = $this->context->exactObjects[$object];
        } elseif ($this->isFinalClass($class)) {
            $exactClass = $class;
        }

        if ($exactClass === null || strcasecmp(ltrim($exactClass, '\\'), ltrim($class, '\\')) !== 0) {
            return null;
        }

        // A DynamicCall is also used for real methods inherited from an
        // internal Zend class. Only attempt __call devirtualization when the
        // TypePHP class hierarchy actually provides a compiled __call method;
        // otherwise getNativeMethod() would continue into the internal parent
        // and incorrectly diagnose the absent magic method.
        $currentClass = $exactClass;
        while (true) {
            if (!$this->hasClass($currentClass)) {
                return null;
            }
            $currentDef = $this->getClass($currentClass);
            if ($currentDef->hasMethod('__call')) {
                break;
            }
            if ($currentDef->extends === '' || !$this->hasClass($currentDef->extends)) {
                return null;
            }
            $currentClass = $currentDef->extends;
        }

        // Start from the class that actually declares the compiled method.
        // This keeps getNativeMethod() out of an internal Zend parent: a real
        // inherited internal method also uses DynamicCall, but an internal
        // parent without __call must not be diagnosed while probing this
        // optimization.
        $nativeFunc = $this->getNativeMethod($expr, $currentClass, '__call', false);
        if ($nativeFunc === false || !$this->hasFunction($nativeFunc)) {
            return null;
        }
        $this->checkFunction($nativeFunc);

        if ($this->getVarType($object) !== Type::OBJECT) {
            $tmpObject = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = Type::OBJECT . ' ' . $tmpObject . ' = ' . $object . ';';
            $object = $tmpObject;
        }

        // __call receives one PHP array containing positional and named
        // arguments. Reuse the dynamic call argument builder so evaluation
        // order, unpacking and named keys remain identical to the Zend path.
        $arguments = $this->parseCallArgs(
            $expr->args,
            separateNamedArgs: false,
            forceArrayArgs: true,
        );

        // The argument array is a compiler-owned temporary used only by this
        // direct call. Transfer its zval instead of incrementing/decrementing
        // the array refcount at the generated __call() boundary.
        return self::PREFIX . $nativeFunc . '('
            . $object . ', ' . $method . ', std::move(' . $arguments . '))';
    }

    protected function parseNativeMethodCall(string $object, string $nativeFunc, array $args): string
    {
        if ($this->getVarType($object) != Type::OBJECT) {
            $tmpVar = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = Type::OBJECT . ' ' . $tmpVar . ' = ' . $object . ';';
            $object = $tmpVar;
        }
        if (count($args) === 0) {
            return self::PREFIX . $nativeFunc . '(' . $object . ')';
        }
        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $this->parseNativeCallArgs($args, $nativeFunc) . ')';
    }

    protected function parseStdCall(Expr\StaticCall $expr): string
    {
        $func = strtolower($this->parseIdentifier($expr->name));
        $type = match ($func) {
            'int' => Type::INT,
            'float' => Type::FLOAT,
            'bool' => Type::BOOL,
            'bigint' => Type::BIGINT,
            'decimal' => Type::DECIMAL,
            'bigfloat' => Type::BIGFLOAT,
            default => '',
        };
        if ($type) {
            $expr->setAttribute('nativeType', $type);
            $valueExpr = $this->parseExpr($expr->args[0]->value);
            if (in_array($type, [Type::INT, Type::FLOAT, Type::BOOL])) {
                return $this->convertExprFromType($type, $valueExpr);
            }
            $argType = $this->detectTypeOfExpr($expr->args[0]->value);
            if ($argType === $type) {
                return $valueExpr;
            }
            if ($type === Type::BIGINT) {
                if ($argType === Type::FLOAT) {
                    $this->fatalError($expr, 'Cannot construct BigInt from float, use string or int instead');
                }
                                if ($argType === Type::INT) {
                    return 'php::toBigInt(' . $valueExpr . ')';
                }
                return 'php::BigInt::newInstance(' . $valueExpr . ')';
            }
            if ($type === Type::DECIMAL) {
                if ($argType === Type::FLOAT) {
                    $argNode = $expr->args[0]->value;
                    if ($argNode instanceof Node\Scalar\Float_) {
                        $rawValue = $argNode->getAttribute('rawValue');
                        $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string)$argNode->value;
                                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
                    }
                    $this->fatalError($expr, 'Cannot construct Decimal from float variable, use string or int instead');
                }
                                if ($argType === Type::INT) {
                    return 'php::toDecimal(' . $valueExpr . ')';
                }
                return 'php::Decimal::newInstance(' . $valueExpr . ')';
            }
            if ($type === Type::BIGFLOAT) {
                                if ($argType === Type::INT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                if ($argType === Type::FLOAT) {
                    return 'php::toBigFloat(' . $valueExpr . ')';
                }
                return 'php::BigFloat::newInstance(' . $valueExpr . ')';
            }
            return $valueExpr;
        } else {
            $this->fatalError($expr, 'Unknown std method: ' . $func);
        }
    }


    protected function parseParentMethodCall(Expr\StaticCall $expr): string
    {
        if (!$this->classDef->extends) {
            $this->fatalError($expr, 'Cannot call parent method because class `' . $this->classDef->name . '` does not extend any class');
        }
        $parentClass = $this->classDef->extends;
        if ($this->classDef->nativeObject) {
            if (!$this->isIdExpr($expr->name)) {
                $this->fatalError($expr, 'Dynamic parent method calls are not supported for native objects');
            }
            $method = $this->parseIdentifier($expr->name);
            if (strtolower($method) === '__destruct') {
                $this->fatalError($expr, 'Explicit calls to native object destructors are not supported');
            }
            $nativeFunc = $this->getNativeMethod($expr, $parentClass, $method);
            if ($nativeFunc === false) {
                $this->fatalError($expr, "Native parent class `{$parentClass}` has no method `{$method}()`");
            }
            if ($expr->args === []) {
                return self::PREFIX . $nativeFunc . '(this_)';
            }
            return self::PREFIX . $nativeFunc . '(this_, '
                . $this->parseNativeCallArgs($expr->args, $nativeFunc) . ')';
        }
        $staticCall = false;
        if ($this->isIdExpr($expr->name)) {
            $method = $this->parseIdentifier($expr->name);
            $this->guardAbstractMethod($parentClass, $method, $expr);
            // A private parent method is not reachable via parent:: — PHP throws
            // "Call to private method" at runtime, so report it at compile time.
            if ($this->getMethodFlags($parentClass, $method) & Modifiers::PRIVATE) {
                $this->fatalError($expr, "Cannot access private method `{$parentClass}::{$method}()` via parent::");
            }
            $staticCall = (bool) ($this->getMethodFlags($parentClass, $method) & Modifiers::STATIC);
            $methodPtr = $this->getMethodPtr($parentClass, $method);
        } else {
            $method = '';
            // parent:: is bound to the lexical parent class, not the runtime
            // object's parent. Resolve the method there; the receiver below is
            // selected from the current static/instance context.
            $methodPtr = 'php::getMethod(' . $this->getClassEntryPtr($parentClass) . ', '
                . $this->methodNameToStr($expr->name) . ')';
            // A dynamic parent call made from a static method cannot have an
            // object receiver. Zend validates the resolved method at runtime.
            $staticCall = (bool) ($this->methodDef->flags & Modifiers::STATIC);
        }
        if ($staticCall) {
            $callable = $this->getCalledCeExpr() . ', ' . $methodPtr;
            if (empty($expr->args)) {
                return 'php::call(' . $callable . ')';
            }
            return $this->genRuntimeFunctionCall($callable, $expr->args, $method, $parentClass);
        }
        if (empty($expr->args)) {
            return 'this_.call(' . $methodPtr . ')';
        }
        // Pass the method name and parent class so the method signature can be resolved when detecting by-reference arguments
        return 'this_.call(' . $methodPtr . ', ' . $this->parseCallArgs($expr->args, $method, $parentClass) . ')';
    }


    protected function genToObjectCall(Expr\MethodCall $expr, string $receiver): string
    {
        if (empty($expr->args)) {
            return 'php::toObject(' . $receiver . ')';
        }
        $className = $this->resolveClassNameArg($expr->args[0]->value);
        return 'php::toObject(' . $receiver . ', ' . $this->getClassEntryPtr($className) . ')';
    }

    protected function genToRefCall(Expr\MethodCall $expr): string
    {
        if (!empty($expr->args)) {
            $this->fatalError($expr, 'The toRef method does not accept parameters');
        }
        return $this->parseChainedExpr($expr->var, self::OP_REFVAL);
    }

    protected function parseMethodCall(Expr\MethodCall $expr): string
    {
        $this->validateImmutableCall($expr);
        if ($this->containsNullsafeChain($expr->var)) {
            return $this->parseNullsafeExpr($expr);
        }

        $class = '';
        $materializedNativeReceiver = false;
        // C++17 sequences a member-call receiver before its arguments, but
        // lowering an argument may hoist captured beforeStmtLines ahead of the
        // whole call. Materialize an effectful receiver before parsing args.
        $receiverClass = !$this->isVarExpr($expr->var) ? $this->detectClassOfExpr($expr->var) : '';
        if ($this->isNativeObjectClass($receiverClass)) {
            $object = $this->materializeNativeObjectReceiver($expr->var, $receiverClass);
            $class = $receiverClass;
            $materializedNativeReceiver = true;
        } else {
            $object = empty($expr->args)
                ? $this->parseIdentifier($expr->var)
                : $this->parseOrderedOperand($expr->var, false);
            // Preserve the receiver expression boundary for no-argument
            // calls. Without these parentheses, C++ member access binds more
            // tightly than assignment, so `($b = $a)->method()` was emitted
            // as `b = a.call(...)` instead of `(b = a).call(...)`.
            if (empty($expr->args) && !$this->isVarExpr($expr->var)) {
                $object = '(' . $object . ')';
            }
        }
        if ($this->isVarExpr($expr->var)) {
            if (!$this->hasVar($object)) {
                $this->errorUndefinedVariable($expr->var);
            }
            if ($this->isTypedObject($object)) {
                $class = $this->getObjectType($object);
            } elseif ($object === 'this_') {
                // $this is statically typed as the current class inside a constructor/method, so abstract methods and other by-reference parameter signatures can be resolved
                $class = $this->classDef !== null ? $this->classDef->getNamespacedName(false) : $this->class;
            } else {
                // Variables of interface or abstract-class type have no concrete object type, but by-reference parameters can still be resolved from the declared signature.
                $class = $this->getDeclaredObjectType($object);
            }
        }

        if ($this->isNamedMethod($expr->name)
            && in_array(strtolower($expr->name->toString()), ['call', 'bind', 'bindto'], true)
            && strtolower(ltrim($class, '\\')) === 'closure') {
            $closureMethod = $expr->name->toString();
            $this->fatalError(
                $expr,
                'Closure::' . $closureMethod . '() is not supported'
            );
        }

        if ($class !== '' && $this->isNativeObjectClass($class)) {
            if ($expr->isFirstClassCallable()) {
                $this->fatalError($expr, 'Native object methods cannot be converted to Zend closures');
            }
            if (!$this->isNamedMethod($expr->name)) {
                $this->fatalError($expr, 'Dynamic native object method calls are not supported');
            }
            $nativeMethodName = strtolower($expr->name->toString());
            if ($nativeMethodName === '__construct') {
                $this->fatalError($expr, 'Explicit calls to native object constructors are not supported');
            }
            if ($nativeMethodName === '__destruct') {
                $this->fatalError($expr, 'Explicit calls to native object destructors are not supported');
            }
            $nativeKeyword = $expr->name->toString();
            if ($nativeKeyword === 'toRef') {
                $this->assertNativeObjectReferenceForbidden($expr->var, $expr);
            }
            if (isset(self::KEYWORD_METHOD_MAP[$nativeKeyword])) {
                if (!isset(self::KEYWORD_METHOD_WITH_ARGUMENTS[$nativeKeyword]) && $expr->args !== []) {
                    $this->fatalError($expr, "The {$nativeKeyword} method does not accept parameters");
                }
                $resolvedKeyword = $this->resolveNativeObjectKeywordMethod($expr, $class, $nativeKeyword);
                if ($resolvedKeyword !== $nativeKeyword) {
                    $expr->name = new Node\Identifier($resolvedKeyword, $expr->name->getAttributes());
                }
                $expr->setAttribute('nativeKeywordCall', true);
            }
        }

        $magicMethod = false;
        $method = $this->methodNameToStr($expr->name, literal: true);
        // Keep the statically named method available to every later branch.
        // Re-checking isNamedMethod() does not prove the earlier assignment to
        // static analyzers and previously left the object path uninitialized.
        $methodName = $this->isNamedMethod($expr->name) ? $expr->name->toString() : '';

        $pythonFacadeCall = $this->parsePythonNativeFacadeMethodCall($expr, $object);
        if ($pythonFacadeCall !== null) {
            return $pythonFacadeCall;
        }

        // Keyword methods are dispatched before all receiver-specific logic.
        if ($this->isNamedMethod($expr->name)) {
            $receiverType = $this->isVarExpr($expr->var) ? $this->getVarType($object) : $this->detectTypeOfExpr($expr->var);
            if ($receiverType === Type::VOID) {
                $receiverType = Type::VAR;
            }
            $keywordType = $this->findKeywordMethod($methodName);
            if ($keywordType !== null
                && isset(self::KEYWORD_METHOD_MAP[$methodName])
                && !$expr->getAttribute('nativeKeywordCall', false)
            ) {
                if ($this->isVarExpr($expr->var)) {
                    $this->assertStdContainerDoesNotEscapeNativeObjects($expr, $object);
                }
                if (!isset(self::KEYWORD_METHOD_WITH_ARGUMENTS[$methodName]) && $expr->args !== []) {
                    $this->fatalError($expr, "The {$methodName} method does not accept parameters");
                }
                if ($methodName === 'toObject') {
                    return $this->genToObjectCall($expr, $object);
                }
                if ($methodName === 'toRef') {
                    return $this->genToRefCall($expr);
                }
                $receiverClass = $class;
                if ($receiverClass === '' && !$this->isVarExpr($expr->var)) {
                    $receiverClass = $this->detectClassOfExpr($expr->var);
                }
                // A declared conversion method is called directly. Otherwise
                // php::toArray() applies the PHP-compatible object-property
                // fallback (and invokes a real toArray() method when present).
                $useDeclaredToArray = $methodName === 'toArray'
                    && $receiverClass !== ''
                    && $this->objectTypeDeclaresMethod($receiverClass, $methodName);
                if (!$useDeclaredToArray) {
                    return $this->genToConvertCall($object, $methodName, $receiverType);
                }
            }
            // MethodsFor('*') extensions apply to every receiver type.
            $kwExt = $this->findKeywordExtensionMethod($methodName);
            if ($kwExt) {
                return $this->parseUniversalMethodCall($expr, $object, $methodName, $kwExt, $this->isVarExpr($expr->var));
            }
            // A provider targeting Type::Any only applies when
            // the receiver's static type is actually mixed/any.
            if ($receiverType === Type::VAR) {
                $anyExtension = $this->findExtensionMethod(Type::VAR, $methodName);
                if ($anyExtension) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $anyExtension, $this->isVarExpr($expr->var));
                }
            }
        }

        // A statically named Python member can bypass PyObject::__call and
        // zend_call_function(). Variable method names remain fully dynamic and
        // intentionally continue through ZendVM.
        if ($this->isNamedMethod($expr->name)
            && $this->isPythonDynamicMethodCall($expr->var, $expr->name->toString())
        ) {
            $name = $this->getLiteralString($expr->name->toString());
            if ($expr->args === []) {
                return 'php::python::callMember(' . $object . ', ' . $name . ')';
            }
            return 'php::python::callMember(' . $object . ', ' . $name . ', '
                . $this->parseCallArgs($expr->args) . ')';
        }

        // Method calls that can be lowered to a native call
        if (($this->isVarExpr($expr->var) || $materializedNativeReceiver) and $this->isNamedMethod($expr->name)) {
            $type = $this->getVarType($object);
            if ($class !== '' && $this->isNativeObjectClass($class)) {
                // Native objects have their own C++ virtual thunk for an
                // overridden family; do not let the Zend-object devirtualizer
                // downgrade this call to the dynamic path.
                $nativeMethodDef = $this->findNativeObjectMethod($class, $methodName);
                $nativeFunc = $this->getNativeMethod($expr, $class, $methodName);
                if ($nativeFunc === false) {
                    if ($nativeMethodDef === null || !($nativeMethodDef->flags & Modifiers::ABSTRACT)) {
                        $this->fatalError($expr, "Native class `{$class}` has no method `{$methodName}()`");
                    }
                    $declaringClassName = $nativeMethodDef->functionDef->declaringClass;
                    $declaringClass = $this->getClass($declaringClassName);
                    if (!$this->checkAccessible($declaringClass, $nativeMethodDef->flags)) {
                        $this->fatalError(
                            $expr,
                            "Method `{$declaringClassName}::{$methodName}()` is not accessible",
                        );
                    }
                    $nativeFunc = $this->getNativeName(
                        $methodName,
                        $declaringClass->namespace,
                        $declaringClass->name,
                    );
                    $this->checkNativeCallArgs(
                        $expr,
                        $nativeMethodDef->functionDef,
                        $expr->args,
                        $declaringClassName . '::' . $methodName,
                    );
                }
                $expr->setAttribute('nativeCall', $nativeFunc);
                $nativeFunctionDef = $this->getFunction($nativeFunc);
                $declaringClass = $this->getClass($nativeFunctionDef->declaringClass);
                $declaringMethod = $nativeMethodDef ?? $declaringClass->getMethod($methodName);
                if ($this->isNativeVirtualMethod($declaringClass, $declaringMethod)) {
                    $call = $this->getNativeObjectMemberReceiver($object)
                        . $this->getNativeVirtualMethodName($declaringClass, $methodName);
                    if ($expr->args === []) {
                        return $call . '()';
                    }
                    return $call . '(' . $this->parseNativeCallArgs(
                        $expr->args,
                        $nativeFunc,
                        deferTrailingDefaults: true,
                    ) . ')';
                }
                $receiver = $this->getNativeObjectReceiver($object);
                if ($expr->args === []) {
                    return self::PREFIX . $nativeFunc . '(' . $receiver . ')';
                }
                return self::PREFIX . $nativeFunc . '(' . $receiver . ', '
                    . $this->parseNativeCallArgs($expr->args, $nativeFunc) . ')';
            }
            // Method calls are allowed on references: use a native call when class info is available, otherwise a dynamic call
            if (!$this->checkArgType($type, Type::OBJECT) and $type !== Type::REF) {
                // Non-object types can use built-in methods
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    if ($type === Type::STREAM) {
                        return $this->genStreamNullGuard($expr, $object, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $fn);
                }
                $this->fatalError($expr, "Cannot call method `{$methodName}()` on variable of type {$type}");
            }
            if ($this->debug) {
                $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                    'Method Call: ',
                    $object . '->' . $this->parseIdentifier($expr->name) . '()'
                );
            }
            $nativeFunc = false;
            try {
                $nativeFunc = $this->findNativeMethod($expr, $object, $this->parseIdentifier($expr->name));
                if ($nativeFunc) {
                    $expr->setAttribute('nativeCall', $nativeFunc);
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genRuntimeObjectMethodCall($object, $this->getMethodPtr($class, $methodName), $expr->args, $methodName, $class);
                        }
                        return $this->parseNativeMethodCall($object, $nativeFunc, $expr->args);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray([$object, $method]));
                    }
                }
            } catch (DynamicCall) {
                $extension = $this->findObjectExtensionMethod(
                    $class,
                    $methodName,
                    $this->isDefinitelyObjectReceiver($expr->var, $object, $class, $type),
                );
                if ($extension !== null) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension);
                }
                try {
                    $directMagicCall = $this->parseDirectNativeMagicCall($expr, $object, $class, $method);
                    if ($directMagicCall !== null) {
                        return $directMagicCall;
                    }
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->genArray([$object, $method]));
                }
                $magicMethod = true;
            }
            if (!$nativeFunc) {
                if ($this->isPythonDynamicMethodCall($expr->var, $methodName)) {
                    $magicMethod = true;
                }
                $extension = $this->findObjectExtensionMethod(
                    $class,
                    $methodName,
                    $this->isDefinitelyObjectReceiver($expr->var, $object, $class, $type),
                );
                if ($extension !== null) {
                    return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension);
                }
            }
        }

        // Expression results can also use built-in methods: fn()->method(), $obj->fn()->method(), Foo::fn()->method(), $obj->prop->method()
        if (!$this->isVarExpr($expr->var) and $this->isNamedMethod($expr->name)) {
            $type = $this->detectTypeOfExpr($expr->var);
            if ($type === Type::VOID) {
                $type = Type::VAR;
            }
            if ($type !== Type::VAR && !$this->checkArgType($type, Type::OBJECT)) {
                $fn = $this->findUniversalMethodAnyType($type, $methodName);
                if ($fn) {
                    // Wrap receiver in type conversion for direct_method handlers
                    // since the raw expression (often from php::call()) is php::Variant
                    $receiver = $object;
                    if ($fn['handler'] === 'direct_method') {
                        $receiver = $this->wrapUniversalReceiver($type, $object);
                    }
                    if ($type === Type::STREAM) {
                        return $this->genStreamNullGuard($expr, $receiver, $methodName, $fn);
                    }
                    return $this->parseUniversalMethodCall($expr, $receiver, $methodName, $fn, false);
                }
            }

            $extensionClass = $this->detectClassOfExpr($expr->var);
            $extension = $this->findObjectExtensionMethod(
                $extensionClass,
                $methodName,
                $this->isDefinitelyObjectReceiver($expr->var, $object, $extensionClass, $type),
            );
            if ($extension !== null) {
                return $this->parseUniversalMethodCall($expr, $object, $methodName, $extension, false);
            }
        }

        if ($this->isNamedMethod($expr->name)) {
            $funcName = $this->parseIdentifier($expr->name);
        } else {
            $funcName = '';
        }

        $requiresDynamicScope = $this->runtimeMethodRequiresDynamicScope(
            $class,
            $funcName,
            $magicMethod,
            $this->isVarExpr($expr->var) && $this->parseIdentifier($expr->var) === 'this_',
        );
        $resolvedMethodPtr = false;
        if ($class && $funcName && !$magicMethod) {
            if ($this->isInternalClass($class)) {
                $methodPtr = $this->getMethodPtr($class, $funcName);
                $resolvedMethodPtr = true;
            } else {
                $methodPtr = $method;
            }
        } else {
            $methodPtr = $method;
        }

        if (empty($expr->args)) {
            if ($requiresDynamicScope && $this->methodDef) {
                if (!$resolvedMethodPtr) {
                    return 'typephp_call_method_scoped_cached(' . $object . ', ' . $methodPtr . ', '
                        . $this->getCallableScopeExpr() . ', ' . $this->getMethodCallCache() . ')';
                }
                // The method is already a stable zend_function* from the
                // project symbol cache. A second callable cache would only
                // add guards before the same direct call.
                return 'php::callScoped(' . $object . ', ' . $methodPtr . ', ' . $this->getCallableScopeExpr() . ')';
            }
            if (!$resolvedMethodPtr) {
                return 'typephp_call_method_cached(' . $object . ', ' . $methodPtr . ', '
                    . $this->getMethodCallCache() . ')';
            }
            return $object . '.call(' . $methodPtr . ')';
        }
        try {
            $class = empty($class) ? self::DYNAMIC_CALLED_CLASS : $class;
            if (!$resolvedMethodPtr) {
                $callArgs = $this->parseCallArgs($expr->args, $funcName, $class);
                if ($requiresDynamicScope && $this->methodDef) {
                    return 'typephp_call_method_scoped_cached(' . $object . ', ' . $methodPtr . ', '
                        . $this->getCallableScopeExpr() . ', ' . $this->getMethodCallCache() . ', '
                        . $callArgs . ')';
                }
                return 'typephp_call_method_cached(' . $object . ', ' . $methodPtr . ', '
                    . $this->getMethodCallCache() . ', ' . $callArgs . ')';
            }
            return $this->genRuntimeObjectMethodCall(
                $object,
                $methodPtr,
                $expr->args,
                $funcName,
                $class,
                $requiresDynamicScope,
            );
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($this->genArray([$object, $method]));
        }
    }

    private function isDefinitelyObjectReceiver(
        Expr $receiver,
        string $object,
        string $class,
        string $type,
    ): bool {
        if ($type !== Type::OBJECT && $class === '') {
            return false;
        }

        if ($this->isVarExpr($receiver)) {
            foreach ($this->functionDef?->argInfoList ?? [] as $argument) {
                if ($argument->name === $object && $argument->nullable) {
                    return false;
                }
            }
        }

        if ($this->isPropertyFetch($receiver) && $this->getNativePropertyDef($receiver)?->nullable) {
            return false;
        }

        $calledFunction = $this->resolveCalledFunctionDef($receiver);
        if ($calledFunction !== null && $this->typeNodeAllowsNull($calledFunction->returnTypeNode)) {
            return false;
        }

        return true;
    }

    private function typeNodeAllowsNull(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }
        if (!$type instanceof Node\UnionType) {
            return false;
        }
        foreach ($type->types as $member) {
            if ($member instanceof Node\Identifier && strtolower($member->name) === 'null') {
                return true;
            }
            if ($member instanceof Node\Name && strtolower($member->toString()) === 'null') {
                return true;
            }
        }
        return false;
    }

    /**
     * Materialize a dynamic static-call target exactly once before evaluating
     * arguments. The snapshot is required even for a plain variable because
     * an argument may mutate that variable by reference.
     *
     * PHP permits both an object and a class-name string before `::`. A
     * declared object type is only an upper bound, so using it directly would
     * lose late static binding when the runtime object is a subclass.
     */
    private function materializeDynamicStaticCallTarget(Expr $target): string
    {
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($target);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $classVar = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $classVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);

        return $classVar;
    }

    /**
     * Fast-path a zero-argument late-static call when the runtime called class
     * is exactly the lexical TypePHP class.
     *
     * `static::method()` cannot normally be devirtualized because a subclass
     * may override the method. The exact-class guard makes the direct branch
     * provably safe, while inherited/subclass calls retain normal Zend
     * dispatch. Calls with arguments and special return representations stay
     * on the general path until they can share one materialized argument list.
     */
    private function parseExactLateStaticCall(
        Expr\StaticCall $expr,
        string $method,
        string $methodPtr,
    ): ?string {
        if ($expr->args !== [] || !$this->classDef || !$this->methodDef) {
            return null;
        }

        $class = $this->getFullClassName();
        try {
            $nativeFunc = $this->getNativeMethod($expr, $class, $method);
        } catch (DynamicCall) {
            return null;
        }
        if ($nativeFunc === false || !$this->hasFunction($nativeFunc)) {
            return null;
        }

        $function = $this->getFunction($nativeFunc);
        if ($function->returnsByRef
            || $function->generator
            || $function->hasMultiReturn()
            || $function->returnType === Type::VOID
            || $this->isStdContainerType($function->returnType)
            || ($function->returnClass !== '' && $this->isNativeObjectClass($function->returnClass))
        ) {
            return null;
        }

        $calledCe = $this->getCalledCeExpr();
        $direct = 'php::Var(' . self::PREFIX . $nativeFunc . '(this_))';
        $fallback = 'php::call(' . $calledCe . ', php::getMethod(' . $calledCe . ', ' . $methodPtr . '))';
        return '(EXPECTED(' . $calledCe . ' == ' . $this->getClassEntryPtr($class) . ')'
            . ' ? ' . $direct . ' : ' . $fallback . ')';
    }

    protected function parseStaticCall(Expr\StaticCall $expr): string
    {
        $this->validateImmutableCall($expr);
        $parentPropertyHookCall = $this->parseParentPropertyHookCall($expr);
        if ($parentPropertyHookCall !== null) {
            return $parentPropertyHookCall;
        }
        if (!$this->isNameExpr($expr->class)) {
            $this->assertNotNativeObjectDynamicClassTarget($expr->class, $expr);
        }
        $pythonCall = $this->parsePythonModuleStaticCall($expr);
        if ($pythonCall !== null) {
            return $pythonCall;
        }

        $self = false;
        $callScope = [];
        $rtFunc = '';
        $rtClass = '';
        $cacheCallable = false;
        $directStaticCall = false;
        $staticCallTarget = '';
        $staticCallMethod = '';
        $canUseDirectCallScope = $this->isNameExpr($expr->class) && $this->isIdExpr($expr->name);
        $class = ($this->isNameExpr($expr->class) || $this->isVarExpr($expr->class))
            ? $this->parseIdentifier($expr->class)
            : '';

        if ($this->isNameExpr($expr->class)
            && $this->isIdExpr($expr->name)
            && strtolower(ltrim($expr->class->toString(), '\\')) === 'closure'
            && in_array(strtolower($expr->name->toString()), ['bind', 'bindto', 'call'], true)) {
            $closureMethod = $expr->name->toString();
            $this->fatalError(
                $expr,
                'Closure::' . $closureMethod . '() is not supported'
            );
        }

        // parent::$method() still has a lexical parent class even when the
        // method name itself is dynamic. Handle it before the generic dynamic
        // static-call branch below.
        if ($this->isNameExpr($expr->class) && $class === 'parent') {
            return $this->parseParentMethodCall($expr);
        }

        if (!$this->isNameExpr($expr->class)) {
            if ($this->isVarExpr($expr->class) && $this->isStableObject($class)) {
                $class = $this->getObjectType($class);
                goto _do_call;
            }
            $classTarget = $this->materializeDynamicStaticCallTarget($expr->class);
            $staticCallTarget = $classTarget;
            $staticCallMethod = $this->methodNameToStr($expr->name, literal: true);
            $fn = 'php::concat({(' . $classTarget . '.isObject() ? php::fn::get_class(' . $classTarget
                . ') : php::toString(' . $classTarget . ')), "::", ' . $staticCallMethod . '})';
            if ($this->isVarExpr($expr->class) && $this->isIdExpr($expr->name)) {
                $declaredClass = $this->getDeclaredObjectType($class);
                if ($declaredClass !== '') {
                    // Dispatch remains runtime-bound, but PHP requires an
                    // overriding method to keep the reference signature
                    // compatible with the declared base method.
                    $rtFunc = $this->parseIdentifier($expr->name);
                    $rtClass = $declaredClass;
                }
            }
            $placeHolder = $fn;
            $directStaticCall = true;
        } elseif ($this->isVarExpr($expr->name)) {
            $staticCallMethod = $this->methodNameToStr($expr->name, literal: true);
            if ($class === 'static') {
                $staticCallTarget = $this->getCalledCeExpr();
            } elseif ($class !== 'self') {
                $resolvedClass = $this->getNamespacedClassName($class);
                $staticCallTarget = $this->getLocalClassEntryPtr($resolvedClass);
            }
            $fn = 'php::concat({' . $this->identifierToStr($expr->class) . ', "::", ' . $staticCallMethod . '})';
            $placeHolder = $fn;
            if ($staticCallTarget !== '') {
                $directStaticCall = true;
            } else {
                // `self::$method()` carries a lexical lookup class and a
                // potentially different late-bound called scope. Keep the
                // existing scoped callable resolution until the lookup class
                // and called scope can both be represented explicitly.
                $cacheCallable = true;
            }
        } elseif ($class === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            $method = $this->parseIdentifier($expr->name);
            $methodPtr = $this->methodNameToStr($expr->name, literal: true);
            $exactCall = $this->parseExactLateStaticCall($expr, $method, $methodPtr);
            if ($exactCall !== null) {
                return $exactCall;
            }
            $calledCe = $this->getCalledCeExpr();
            $fn = $calledCe . ', php::getMethod(' . $calledCe . ', ' . $methodPtr . ')';
            if ($this->debug) {
                $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                    'Static Method Call: ',
                    'static::' . $method . '()'
                );
            }
            $placeHolder = $this->genArray([$this->getCalledClassExpr(), $methodPtr]);
            // Used to resolve the method signature when detecting by-reference arguments (late static binding is resolved within the current class hierarchy)
            $rtFunc = $method;
            $rtClass = $this->getFullClassName();
        } else {
            if ($class === 'self') {
                $class = $this->getFullClassName();
                $self = true;
            } elseif ($class === 'std') {
                return $this->parseStdCall($expr);
            } else {
                $class = $this->getNamespacedClassName($class);
            }

            _do_call:
            $method = $this->parseIdentifier($expr->name);
            $rtFunc = $method;
            $rtClass = $class;
            if ($this->debug) {
                $this->context->beforeStmtLines[] = $this->formatCppLineComment(
                    'Static Method Call: ',
                    $class . '::' . $method . '()'
                );
            }

            if ($canUseDirectCallScope) {
                $callScope = [$this->genCharPtr($class, true), $this->genCharPtr($method)];
            }

            if ($callScope) {
                $nativeFunc = $this->getNativeMethod($expr, $class, $method);
                if ($nativeFunc) {
                    try {
                        if ($this->shouldUseDynamicCallForNativeArgs($nativeFunc, $expr->args)) {
                            return $this->genRuntimeFunctionCall(
                                $this->getClassEntryPtr($class) . ', ' . $this->getMethodPtr($class, $method),
                                $expr->args,
                                $method,
                                $class
                            );
                        }
                        $args = $this->parseNativeCallArgs($expr->args, $nativeFunc);
                        $expr->setAttribute('nativeCall', $nativeFunc);
                    } catch (PlaceHolder) {
                        return $this->genPlaceHolder($this->genArray($callScope));
                    }
                    // When a method definition calls a current-class method via self::method(), the this_ pointer must still be passed
                    if ($this->methodDef and $self) {
                        $object = 'this_';
                    } else {
                        $object = $this->getCeWrapper($class);
                    }
                    if ($args) {
                        return self::PREFIX . $nativeFunc . '(' . $object . ', ' . $args . ')';
                    } else {
                        return self::PREFIX . $nativeFunc . '(' . $object . ')';
                    }
                }
            }

            // Reaching this fallback means no concrete native method was
            // proven above. Keep the callable dynamic so Zend can resolve a
            // runtime-defined method or __callStatic(). PHPX only caches real,
            // reusable handlers and never stores transient trampolines.
            $fn = $this->getLiteralString($class . '::' . $method);
            $placeHolder = $this->genArray($callScope);
            $cacheCallable = true;
        }

        if (empty($expr->args)) {
            if ($directStaticCall) {
                return 'php::callStaticMethod(' . $staticCallTarget . ', ' . $staticCallMethod . ')';
            }
            if ($cacheCallable) {
                return 'typephp_call_cached(' . $fn . ', ' . $this->getFunctionCallCache() . ')';
            }
            return 'php::call(' . $fn . ')';
        }
        try {
            if ($directStaticCall) {
                return 'php::callStaticMethod(' . $staticCallTarget . ', ' . $staticCallMethod . ', '
                    . $this->parseCallArgs($expr->args, $rtFunc, $rtClass) . ')';
            }
            if ($cacheCallable) {
                return 'typephp_call_cached(' . $fn . ', ' . $this->getFunctionCallCache() . ', '
                    . $this->parseCallArgs($expr->args, $rtFunc, $rtClass) . ')';
            }
            return $this->genRuntimeFunctionCall($fn, $expr->args, $rtFunc, $rtClass);
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

}
