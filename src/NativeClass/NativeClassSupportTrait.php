<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\NativeClass;

use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Resolver\Reflection;
use PhpParser\Modifiers;
use TypePhp\Type;
use PhpParser\NodeAbstract;
use PhpParser\Node;

trait NativeClassSupportTrait
{
    private const string NATIVE_VIRTUAL_CLONE_METHOD = '__typephp_native_clone';

    /**
     * Magic methods whose semantics require Zend object handlers, runtime
     * method resolution, dynamic properties, or Zend serialization state.
     * Native classes deliberately have none of those runtime facilities.
     */
    private const UNSUPPORTED_NATIVE_MAGIC_METHODS = [
        '__call' => true,
        '__callstatic' => true,
        '__get' => true,
        '__set' => true,
        '__isset' => true,
        '__unset' => true,
        '__sleep' => true,
        '__wakeup' => true,
        '__serialize' => true,
        '__unserialize' => true,
        '__set_state' => true,
        '__debuginfo' => true,
    ];

    /** Internal interfaces which PHP does not allow an ordinary class to implement directly. */
    private const NON_IMPLEMENTABLE_INTERNAL_INTERFACES = [
        'throwable' => true,
        'traversable' => true,
        'datetimeinterface' => true,
        'unitenum' => true,
        'backedenum' => true,
    ];

    protected function assertNativeMagicMethodSupported(NodeAbstract $node, string $method): void
    {
        if (!$this->classDef?->nativeObject
            || !isset(self::UNSUPPORTED_NATIVE_MAGIC_METHODS[strtolower($method)])
        ) {
            return;
        }
        $this->fatalError(
            $node,
            "Native classes do not support dynamic magic method `{$method}()`",
        );
    }

    /**
     * Native classes have no zend_class_entry, so Zend cannot perform the
     * normal MINIT-time interface verification for them. Convert an internal
     * reflection signature into the compiler's existing MethodDef model and
     * run the same compatibility checker used for project interfaces.
     */
    protected function checkInternalInterfaceImplementation(
        NodeAbstract $node,
        ClassDef $classDef,
        string $interfaceName,
    ): void {
        if (isset(self::NON_IMPLEMENTABLE_INTERNAL_INTERFACES[strtolower(ltrim($interfaceName, '\\'))])) {
            $this->fatalError(
                $node,
                "Native class `{$classDef->getNamespacedName(false)}` cannot implement internal interface `{$interfaceName}`",
            );
        }
        $interface = Reflection::getClass($interfaceName);
        if ($interface === null) {
            $this->fatalError($node, "Internal interface `{$interfaceName}` is not available");
        }

        foreach ($interface->getMethods() as $method) {
            $childMethodDef = $this->findClassMethodDef($classDef, $method->getName(), $classDef->isAbstract());
            if ($childMethodDef === null) {
                if ($classDef->isAbstract()) {
                    continue;
                }
                $this->fatalError(
                    $node,
                    "Class `{$classDef->getNamespacedName(false)}` must implement method " .
                    "`{$interfaceName}::{$method->getName()}()`",
                );
            }
            $this->validateMethodOverrideSignature(
                $childMethodDef->node ?? $node,
                $method->getName(),
                $childMethodDef,
                $this->createInternalInterfaceMethodDef($method),
                $interfaceName,
            );
        }
    }

    private function createInternalInterfaceMethodDef(\ReflectionMethod $method): MethodDef
    {
        $flags = Modifiers::PUBLIC | Modifiers::ABSTRACT;
        if ($method->isStatic()) {
            $flags |= Modifiers::STATIC;
        }

        $methodDef = new MethodDef($flags, $method->getName());
        $functionDef = new FunctionDef($method->getName(), Type::VAR, '');
        $functionDef->method = true;
        $functionDef->declaringClass = $method->getDeclaringClass()->getName();
        $functionDef->returnsByRef = $method->returnsReference();
        $functionDef->returnTypeUndeclared = $method->getReturnType() === null;
        $this->applyReflectedReturnType($functionDef, $method->getReturnType(), $method->getDeclaringClass());

        foreach ($method->getParameters() as $parameter) {
            $argument = new ArgInfo();
            $argument->name = $parameter->getName();
            $argument->phpName = $parameter->getName();
            $argument->byRef = $parameter->isPassedByReference();
            $argument->variadic = $parameter->isVariadic();
            $argument->undeclared = $parameter->getType() === null;
            $argument->nullable = $parameter->allowsNull();
            $this->applyReflectedParameterType($argument, $parameter->getType(), $method->getDeclaringClass());
            if ($parameter->isOptional() || $parameter->isVariadic()) {
                // Compatibility only needs to distinguish required from
                // optional parameters; the concrete default is irrelevant.
                $argument->defaultValue = new Node\Expr\ConstFetch(new Node\Name('null'));
            }
            $functionDef->argInfoList[] = $argument;
        }
        $functionDef->argCountRequired = $method->getNumberOfRequiredParameters();
        $methodDef->functionDef = $functionDef;
        return $methodDef;
    }

    private function applyReflectedReturnType(
        FunctionDef $function,
        ?\ReflectionType $type,
        \ReflectionClass $declaringClass,
    ): void {
        if ($type === null) {
            $function->returnTypeStr = '';
            return;
        }
        $node = $this->reflectionTypeToNode($type, $declaringClass);
        $function->returnTypeStr = $this->typeCheckNodeToString($node);
        if ($node instanceof Node\NullableType
            || $node instanceof Node\UnionType
            || $node instanceof Node\IntersectionType
        ) {
            $typeInfo = $this->buildTypeCheckFromNode($node);
            $function->returnType = Type::VAR;
            $function->returnTypeCheck = $typeInfo['check'];
            $function->returnTypeNode = $node;
            return;
        }
        [$function->returnType, $function->returnClass] = $this->resolveReflectedNamedType($node);
    }

    private function applyReflectedParameterType(
        ArgInfo $argument,
        ?\ReflectionType $type,
        \ReflectionClass $declaringClass,
    ): void {
        if ($type === null) {
            $argument->type = Type::VAR;
            return;
        }
        $node = $this->reflectionTypeToNode($type, $declaringClass);
        $argument->typeStr = $this->typeCheckNodeToString($node);
        if ($node instanceof Node\NullableType
            || $node instanceof Node\UnionType
            || $node instanceof Node\IntersectionType
        ) {
            $typeInfo = $this->buildTypeCheckFromNode($node);
            $argument->type = Type::VAR;
            $argument->typeCheck = $typeInfo['check'];
            $argument->typeNode = $node;
            return;
        }
        [$argument->type, $argument->declaredClass] = $this->resolveReflectedNamedType($node);
        if ($argument->declaredClass !== '' && !$this->isInterface($argument->declaredClass)) {
            $argument->class = $argument->declaredClass;
        }
        $argument->explicitMixed = strtolower($argument->typeStr) === 'mixed';
    }

    /** @return array{string, string} */
    private function resolveReflectedNamedType(NodeAbstract $node): array
    {
        $name = $this->parseIdentifier($node);
        $lower = strtolower(ltrim($name, '\\'));
        if (isset($this->zendTypeMap[$lower])) {
            return [$this->getTypeFromZendType($lower), ''];
        }
        return [Type::OBJECT, ltrim($name, '\\')];
    }

    private function reflectionTypeToNode(
        \ReflectionType $type,
        \ReflectionClass $declaringClass,
        bool $allowNullableWrapper = true,
    ): NodeAbstract {
        if ($type instanceof \ReflectionUnionType) {
            return new Node\UnionType(array_map(
                fn (\ReflectionType $member): NodeAbstract =>
                    $this->reflectionTypeToNode($member, $declaringClass, false),
                $type->getTypes(),
            ));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return new Node\IntersectionType(array_map(
                fn (\ReflectionType $member): NodeAbstract =>
                    $this->reflectionTypeToNode($member, $declaringClass, false),
                $type->getTypes(),
            ));
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException('Unsupported reflection type: ' . $type::class);
        }
        return $this->reflectionNamedTypeToNode($type, $declaringClass, $allowNullableWrapper);
    }

    private function reflectionNamedTypeToNode(
        \ReflectionNamedType $type,
        \ReflectionClass $declaringClass,
        bool $allowNullableWrapper,
    ): NodeAbstract {
        $name = $type->getName();
        $lower = strtolower($name);
        if ($lower === 'self') {
            $node = new Node\Name\FullyQualified($declaringClass->getName());
        } elseif ($lower === 'parent') {
            $parent = $declaringClass->getParentClass();
            $node = $parent === false
                ? new Node\Name('parent')
                : new Node\Name\FullyQualified($parent->getName());
        } elseif ($lower === 'static') {
            $node = new Node\Name('static');
        } elseif ($type->isBuiltin()) {
            $node = new Node\Identifier($name);
        } else {
            $node = new Node\Name\FullyQualified($name);
        }

        if ($allowNullableWrapper
            && $type->allowsNull()
            && !in_array($lower, ['mixed', 'null'], true)
        ) {
            return new Node\NullableType($node);
        }
        return $node;
    }

    protected function isNativeObjectClass(string $class): bool
    {
        $class = ltrim($class, '\\');
        if ($class === '') {
            return false;
        }
        if (isset($this->nativeClassDeclarations[strtolower($class)])) {
            return true;
        }
        return $this->hasClass($class) && $this->getClass($class)->nativeObject;
    }

    /**
     * Native objects have identity but no Zend object handler capable of PHP's
     * recursive loose/value comparison. Reject unsupported operators before a
     * raw pointer can reach php::equals() or a C++ arithmetic expression.
     */
    protected function assertNativeObjectBinaryOperatorSupported(Node\Expr\BinaryOp $expr): void
    {
        $leftNative = $this->isNativeObjectClass($this->detectClassOfExpr($expr->left));
        $rightNative = $this->isNativeObjectClass($this->detectClassOfExpr($expr->right));
        if (!$leftNative && !$rightNative) {
            return;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Identical
            || $expr instanceof Node\Expr\BinaryOp\NotIdentical
            || $expr instanceof Node\Expr\BinaryOp\Coalesce
            || $expr instanceof Node\Expr\BinaryOp\Concat
            || $expr instanceof Node\Expr\BinaryOp\BooleanAnd
            || $expr instanceof Node\Expr\BinaryOp\LogicalAnd
            || $expr instanceof Node\Expr\BinaryOp\BooleanOr
            || $expr instanceof Node\Expr\BinaryOp\LogicalOr
            || $expr instanceof Node\Expr\BinaryOp\LogicalXor
            || $expr instanceof Node\Expr\BinaryOp\Pipe
        ) {
            return;
        }

        $operator = match (true) {
            $expr instanceof Node\Expr\BinaryOp\Equal => '==',
            $expr instanceof Node\Expr\BinaryOp\NotEqual => '!=',
            $expr instanceof Node\Expr\BinaryOp\Plus => '+',
            $expr instanceof Node\Expr\BinaryOp\Minus => '-',
            $expr instanceof Node\Expr\BinaryOp\Mul => '*',
            $expr instanceof Node\Expr\BinaryOp\Div => '/',
            $expr instanceof Node\Expr\BinaryOp\Mod => '%',
            $expr instanceof Node\Expr\BinaryOp\Pow => '**',
            $expr instanceof Node\Expr\BinaryOp\Smaller => '<',
            $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual => '<=',
            $expr instanceof Node\Expr\BinaryOp\Greater => '>',
            $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual => '>=',
            $expr instanceof Node\Expr\BinaryOp\Spaceship => '<=>',
            $expr instanceof Node\Expr\BinaryOp\ShiftLeft => '<<',
            $expr instanceof Node\Expr\BinaryOp\ShiftRight => '>>',
            $expr instanceof Node\Expr\BinaryOp\BitwiseAnd => '&',
            $expr instanceof Node\Expr\BinaryOp\BitwiseOr => '|',
            $expr instanceof Node\Expr\BinaryOp\BitwiseXor => '^',
            default => $expr->getType(),
        };
        $suffix = in_array($operator, ['==', '!='], true)
            ? '; use `===` or `!==` for identity comparison'
            : '';
        $this->fatalError($expr, "Native objects do not support the `{$operator}` operator{$suffix}");
    }

    /**
     * A Native object expression is a raw C++ pointer.  Applying arithmetic
     * unary or update operators to it could otherwise become pointer
     * arithmetic, which is valid C++ but has no PHP object semantics.
     */
    protected function assertNativeObjectOperatorOperandSupported(
        NodeAbstract $operand,
        NodeAbstract $errorNode,
        string $operator,
        bool $unary = false,
    ): void {
        if (!$this->isNativeObjectClass($this->detectClassOfExpr($operand))) {
            return;
        }

        $prefix = $unary ? 'unary ' : '';
        $this->fatalError($errorNode, "Native objects do not support the {$prefix}`{$operator}` operator");
    }

    protected function assertNotNativeObjectArrayKey(NodeAbstract $key): void
    {
        if ($this->isNativeObjectClass($this->detectClassOfExpr($key))) {
            $this->fatalError($key, 'Native objects cannot be used as PHP array keys');
        }
    }

    /**
     * Return the statically known Native receiver class for array syntax.
     * PHP only dispatches [] through ArrayAccess; having similarly named
     * methods without the interface is not sufficient.
     */
    protected function getNativeArrayAccessClass(
        NodeAbstract $receiver,
        NodeAbstract $errorNode,
    ): ?string {
        $class = $this->detectClassOfExpr($receiver);
        if (!$this->isNativeObjectClass($class)) {
            return null;
        }

        if (!$this->nativeClassImplementsInterface($class, 'ArrayAccess')) {
            $this->fatalError(
                $errorNode,
                "Native class `{$class}` must implement `ArrayAccess` to use array access syntax",
            );
        }
        return $class;
    }

    /** Native interfaces are compile-time contracts and have no Zend class entry. */
    protected function nativeClassImplementsInterface(string $class, string $interface): bool
    {
        if (!$this->isNativeObjectClass($class)) {
            return false;
        }
        foreach ($this->getClassImplementedInterfaces($this->getClass($class)) as $implemented) {
            if (strcasecmp(ltrim($implemented, '\\'), ltrim($interface, '\\')) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function nativeIteratorCall(Node\Expr $receiver, string $method): Node\Expr\MethodCall
    {
        return new Node\Expr\MethodCall(
            $receiver,
            new Node\Identifier($method),
            [],
            $receiver->getAttributes(),
        );
    }

    /**
     * Native Iterator is a compile-time protocol. Calls never enter ZendVM and
     * retain PHP's rewind/valid/current/key/body/next ordering. A C++ for-loop
     * is intentional: continue must execute next(), while break must not.
     */
    protected function parseForeachNativeIterator(
        Node\Stmt\Foreach_ $node,
        Node\Expr $iteratorExpr,
        string $iteratorClass,
    ): string {
        if ($node->byRef) {
            $this->fatalError($node, 'Native Iterator foreach does not support references');
        }

        if (!$this->nativeClassImplementsInterface($iteratorClass, 'Iterator')) {
            $this->fatalError(
                $node->expr,
                "Native class `{$iteratorClass}` returned by `getIterator()` must implement `Iterator`",
            );
        }

        // foreach captures its iterable once. Always use a dedicated rooted
        // pointer, even for a variable receiver: assigning null or another
        // object to the source variable inside the loop must not change the
        // active iterator. Validate that captured pointer once before rewind,
        // rather than repeating a null check for every protocol method.
        $iterator = $this->materializeNativeObjectReceiver($iteratorExpr, $iteratorClass);
        $this->context->beforeStmtLines[] = 'php::nativeGcRequireObject('
            . $iterator . ', "' . addslashes($iteratorClass) . '");';
        $this->markNativeObjectNonNull($iterator);
        $iteratorExpr = new Node\Expr\Variable($iterator, $iteratorExpr->getAttributes());

        $rewind = $this->parseMethodCall($this->nativeIteratorCall($iteratorExpr, 'rewind'));
        $valid = $this->parseMethodCall($this->nativeIteratorCall($iteratorExpr, 'valid'));
        $currentNode = $this->nativeIteratorCall($iteratorExpr, 'current');
        $next = $this->parseMethodCall($this->nativeIteratorCall($iteratorExpr, 'next'));

        // Materialized Native receivers schedule precise-root cleanup at the
        // end of the foreach statement. Capture it before parsing the body,
        // whose own statement buffers are independent.
        $setup = $this->parseBeforeStmtLines();
        $cleanup = $this->parseAfterStmtLines();

        $code = $setup . '{' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'for (' . $rewind . '; ' . $valid . '; ' . $next . ') {' . PHP_EOL;
        $this->indentLevel++;

        // PHP invokes current() before key(); key() is skipped entirely when
        // the foreach statement does not bind a key variable.
        $code .= $this->getIndent()
            . $this->parseAssignFinally($node->valueVar, $currentNode) . ';' . PHP_EOL;
        if ($node->keyVar !== null) {
            $keyNode = $this->nativeIteratorCall($iteratorExpr, 'key');
            $code .= $this->getIndent()
                . $this->parseAssignFinally($node->keyVar, $keyNode) . ';' . PHP_EOL;
        }

        $body = $this->parseForeachBody($node);
        $this->indentLevel--;
        $code .= $body;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $code .= $cleanup;
        return $code;
    }

    protected function parseForeachNativeAggregate(
        Node\Stmt\Foreach_ $node,
        Node\Expr $aggregateExpr,
        string $aggregateClass,
    ): string {
        if ($node->byRef) {
            $this->fatalError($node, 'Native Iterator foreach does not support references');
        }

        $method = $this->findNativeObjectMethod($aggregateClass, 'getIterator');
        if ($method === null) {
            $this->fatalError($node->expr, "Native class `{$aggregateClass}` has no method `getIterator()`");
        }
        $function = $method->functionDef;
        $call = $this->nativeIteratorCall($aggregateExpr, 'getIterator');
        $returnClass = $function->returnClass;

        if ($this->isNativeObjectClass($returnClass)) {
            if ($function->returnNullable) {
                $this->fatalError($call, 'Native IteratorAggregate::getIterator() cannot return null');
            }
            return $this->parseForeachNativeIterator($node, $call, $returnClass);
        }

        if ($function->returnType !== Type::OBJECT
            || $returnClass === ''
            || !$this->isInheritedFrom($returnClass, 'Traversable')
        ) {
            $this->fatalError(
                $call,
                'Native IteratorAggregate::getIterator() must return a Traversable object or Native Iterator',
            );
        }

        $iterator = $this->genTmpVarName();
        $this->addLocalVar($iterator, Type::OBJECT);
        $value = $this->parseMethodCall($call);
        $setup = $this->parseBeforeStmtLines();
        $cleanup = $this->parseAfterStmtLines();
        return $setup
            . $iterator . ' = ' . $value . ';' . PHP_EOL
            . $this->parseForeachIterable($node, $iterator) . PHP_EOL
            . $cleanup;
    }

    /** Locate the first Native ArrayAccess dimension inside a writable chain. */
    protected function findNativeArrayAccessDimension(NodeAbstract $expression): ?Node\Expr\ArrayDimFetch
    {
        if ($expression instanceof Node\Expr\ArrayDimFetch) {
            if ($this->isNativeObjectClass($this->detectClassOfExpr($expression->var))) {
                return $expression;
            }
            return $this->findNativeArrayAccessDimension($expression->var);
        }
        if ($expression instanceof Node\Expr\PropertyFetch
            || $expression instanceof Node\Expr\NullsafePropertyFetch
        ) {
            return $this->findNativeArrayAccessDimension($expression->var);
        }
        return null;
    }

    protected function assertNativeArrayAccessDirectWrite(
        NodeAbstract $target,
        bool $allowDirectDimension,
    ): void {
        $dimension = $this->findNativeArrayAccessDimension($target);
        if ($dimension === null) {
            return;
        }
        $this->getNativeArrayAccessClass($dimension->var, $dimension);
        if ($allowDirectDimension && $dimension === $target) {
            return;
        }
        $this->fatalError(
            $target,
            'Indirect modification of Native ArrayAccess elements is not supported',
        );
    }

    protected function assertNativeArrayAccessReferenceForbidden(NodeAbstract $expression): void
    {
        $dimension = $this->findNativeArrayAccessDimension($expression);
        if ($dimension === null) {
            return;
        }
        $this->getNativeArrayAccessClass($dimension->var, $dimension);
        $this->fatalError(
            $expression,
            'References to Native ArrayAccess elements are not supported',
        );
    }

    /**
     * Build a normal MethodCall so Native dispatch, visibility, signature
     * checks, virtual thunks and PHP evaluation ordering remain centralized.
     *
     * @param list<Node\Arg> $arguments
     */
    protected function parseNativeArrayAccessCall(
        Node\Expr\ArrayDimFetch $access,
        string $method,
        array $arguments,
    ): ?string {
        if ($this->getNativeArrayAccessClass($access->var, $access) === null) {
            return null;
        }
        if ($access->dim !== null) {
            $this->assertNotNativeObjectArrayKey($access->dim);
        }
        return $this->parseMethodCall(new Node\Expr\MethodCall(
            $access->var,
            new Node\Identifier($method),
            $arguments,
            $access->getAttributes(),
        ));
    }

    protected function assertNotNativeObjectDynamicClassTarget(
        NodeAbstract $target,
        NodeAbstract $errorNode,
    ): void {
        if ($this->isNativeObjectClass($this->detectClassOfExpr($target))) {
            $this->fatalError($errorNode, 'Native objects cannot be used as dynamic class targets');
        }
    }

    /**
     * Resolve only the `$GLOBALS['literal']` form. A fixed key can share the
     * compiler-owned Native global slot without touching Zend's symbol table;
     * dynamically addressed `$GLOBALS[$name]` remains a Zend value boundary.
     */
    protected function getLiteralGlobalsSlot(NodeAbstract $expression): ?string
    {
        if (!$expression instanceof Node\Expr\ArrayDimFetch
            || !$expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'GLOBALS'
            || !$expression->dim instanceof Node\Scalar\String_
        ) {
            return null;
        }
        return $expression->dim->value;
    }

    /**
     * Resolve a statically known `$GLOBALS[...]` key to the compiler-owned
     * Native global slot. Dynamic keys deliberately return null and remain on
     * the ordinary Zend HashTable path.
     */
    protected function getStaticGlobalsSlot(NodeAbstract $expression): ?string
    {
        $literal = $this->getLiteralGlobalsSlot($expression);
        if ($literal !== null) {
            return $literal;
        }
        if (!$expression instanceof Node\Expr\ArrayDimFetch
            || !$expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'GLOBALS'
            || $expression->dim === null
        ) {
            return null;
        }

        // Preserve PHP visibility diagnostics even though the key itself is
        // folded and never emitted as a runtime class-constant fetch.
        $finder = new \PhpParser\NodeFinder();
        foreach ($finder->findInstanceOf($expression->dim, Node\Expr\ClassConstFetch::class) as $fetch) {
            if (!$fetch->class instanceof Node\Name
                || !$fetch->name instanceof Node\Identifier
                || strcasecmp($fetch->name->toString(), 'class') === 0
            ) {
                continue;
            }
            $class = $fetch->class->toString();
            if (strcasecmp($class, 'self') === 0 || strcasecmp($class, 'static') === 0) {
                $class = $this->getFullClassName();
            } elseif (strcasecmp($class, 'parent') === 0) {
                $class = $this->classDef?->extends ?? '';
            } else {
                $resolved = $fetch->class->getAttribute('resolvedName');
                $class = $resolved instanceof Node\Name
                    ? $resolved->toString()
                    : $this->getNamespacedClassName($class);
            }
            if ($class !== '') {
                $this->getClassConstValue(
                    $fetch,
                    $class,
                    $fetch->name->toString(),
                    $this->getFullClassName(),
                );
            }
        }

        $this->nativeGlobalTypeResolver ??= new NativeGlobalTypeResolver(
            $this->symbols->classes(),
            $this->constants,
        );
        return $this->nativeGlobalTypeResolver->staticString(
            $expression->dim,
            $this->getFullClassName(),
        );
    }

    protected function getNativeObjectCppName(string|ClassDef $class): string
    {
        if ($class instanceof ClassDef) {
            return self::PREFIX . $this->getNativeName('', $class->namespace, $class->name);
        }
        $class = ltrim($class, '\\');
        if ($this->hasClass($class)) {
            $classDef = $this->getClass($class);
            return self::PREFIX . $this->getNativeName('', $classDef->namespace, $classDef->name);
        }

        // The Native declaration catalog is built before semantic
        // preprocessing, so signatures may name a Native class declared in a
        // later file. Its C++ symbol is derivable from the fully-qualified PHP
        // name without requiring the complete ClassDef yet.
        $separator = strrpos($class, '\\');
        $namespace = $separator === false ? '' : substr($class, 0, $separator);
        $name = $separator === false ? $class : substr($class, $separator + 1);
        return self::PREFIX . $this->getNativeName('', $namespace, $name);
    }

    protected function getNativeObjectDescriptorName(string|ClassDef $class): string
    {
        return $this->getNativeObjectCppName($class) . '__type';
    }

    protected function getNativeObjectPointerType(string|ClassDef $class): string
    {
        return $this->getNativeObjectCppName($class) . ' *';
    }

    /**
     * A statically typed base pointer may hold any Native subclass. Only such
     * inheritance hierarchies need a vtable entry for clone; standalone/final
     * object layouts retain the zero-overhead static clone path.
     */
    protected function nativeObjectUsesVirtualClone(string|ClassDef $class): bool
    {
        $classDef = $class instanceof ClassDef ? $class : $this->getClass(ltrim($class, '\\'));
        if ($classDef->extends !== '' && $this->isNativeObjectClass($classDef->extends)) {
            return true;
        }
        $className = $classDef->getNamespacedName(false);
        foreach ($this->symbols->classes() as $candidate) {
            if (!$candidate->nativeObject
                || $this->isSameClassName($candidate->getNamespacedName(false), $className)
            ) {
                continue;
            }
            $parent = $candidate->extends;
            while ($parent !== '' && $this->isNativeObjectClass($parent)) {
                if ($this->isSameClassName($parent, $className)) {
                    return true;
                }
                $parent = $this->getClass($parent)->extends;
            }
        }
        return false;
    }

    protected function getNativeObjectArgumentType(ArgInfo $argument): ?string
    {
        $class = $argument->declaredClass ?: $argument->class;
        if ($argument->type !== Type::OBJECT || !$this->isNativeObjectClass($class)) {
            return null;
        }
        return $this->getNativeObjectPointerType($class);
    }

    /**
     * A virtual adapter may widen value parameters, but C++ cannot safely
     * adapt T*& to Base*&: the callee could replace it with a Base that is not
     * a T. Native objects intentionally carry no runtime class tag, so reject
     * that one PHP variance case at the declaration boundary.
     */
    protected function assertNativeVirtualByRefStorageCompatible(
        NodeAbstract $node,
        FunctionDef $child,
        FunctionDef $parent,
    ): void {
        foreach ($parent->argInfoList as $index => $parentArgument) {
            if (!$parentArgument->byRef || !isset($child->argInfoList[$index])) {
                continue;
            }
            $childArgument = $child->argInfoList[$index];
            $parentStorage = $this->getNativeObjectArgumentType($parentArgument)
                ?? $parentArgument->type;
            $childStorage = $this->getNativeObjectArgumentType($childArgument)
                ?? $childArgument->type;
            if ($parentStorage !== $childStorage) {
                $this->fatalError(
                    $node,
                    'Native virtual by-reference parameters must keep the same storage type',
                );
            }
        }
    }

    protected function resolveNullableNativeObjectType(?NodeAbstract $type, int $declarationKind): ?array
    {
        $inner = $type instanceof Node\NullableType ? $type->type : null;
        if (!$inner instanceof Node\Name) {
            return null;
        }
        [$innerType, $class] = $this->resolveTypeDecl($inner, $declarationKind);
        if ($innerType !== Type::OBJECT || !$this->isNativeObjectClass($class)) {
            return null;
        }
        return [Type::OBJECT, $class];
    }

    /** @return list<string> */
    protected function getNativeObjectClassesFromTypeNode(?NodeAbstract $type, int $declarationKind): array
    {
        if ($type === null || $type instanceof Node\Identifier) {
            return [];
        }
        if ($type instanceof Node\NullableType) {
            return $this->getNativeObjectClassesFromTypeNode($type->type, $declarationKind);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $classes = [];
            foreach ($type->types as $member) {
                foreach ($this->getNativeObjectClassesFromTypeNode($member, $declarationKind) as $class) {
                    $classes[strtolower($class)] = $class;
                }
            }
            return array_values($classes);
        }
        if (!$type instanceof Node\Name) {
            return [];
        }
        [$resolvedType, $class] = $this->resolveTypeDecl($type, $declarationKind);
        return $resolvedType === Type::OBJECT && $this->isNativeObjectClass($class) ? [$class] : [];
    }

    /**
     * Resolve a common Native pointer type for value-selection branches.
     * Null is accepted as the empty state; any Zend/non-object branch makes
     * the expression unsuitable for the Native object model.
     *
     * @param list<NodeAbstract> $expressions
     */
    protected function getCommonNativeObjectExpressionClass(array $expressions): string
    {
        $common = '';
        foreach ($expressions as $expression) {
            if ($this->isNull($expression)) {
                continue;
            }
            $class = $this->detectClassOfExpr($expression);
            if (!$this->isNativeObjectClass($class)) {
                return '';
            }
            if ($common === '') {
                $common = $class;
                continue;
            }
            $common = $this->getCommonNativeObjectClass($common, $class);
            if ($common === '') {
                return '';
            }
        }
        return $common;
    }

    protected function getCommonNativeObjectClass(string $left, string $right): string
    {
        $leftAncestors = [];
        while ($this->isNativeObjectClass($left)) {
            $definition = $this->getClass($left);
            $canonical = $definition->getNamespacedName(false);
            $leftAncestors[strtolower($canonical)] = $canonical;
            $left = $definition->extends;
        }

        while ($this->isNativeObjectClass($right)) {
            $definition = $this->getClass($right);
            $canonical = $definition->getNamespacedName(false);
            $key = strtolower($canonical);
            if (isset($leftAncestors[$key])) {
                return $leftAncestors[$key];
            }
            $right = $definition->extends;
        }
        return '';
    }

    protected function assertSupportedNativeObjectTypeNode(
        ?NodeAbstract $type,
        int $declarationKind,
        NodeAbstract $errorNode,
    ): void {
        if (!$type instanceof Node\UnionType && !$type instanceof Node\IntersectionType) {
            return;
        }
        $nativeClasses = $this->getNativeObjectClassesFromTypeNode($type, $declarationKind);
        if ($nativeClasses !== []) {
            $this->fatalError(
                $errorNode,
                'Native object types do not support union or intersection declarations; use nullable ?Class syntax',
            );
        }
    }

    protected function assertNativeObjectFunctionSignature(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        FunctionDef $function,
    ): void {
        if ($this->classDef?->nativeObject && $function->returnTypeKeyword === 'static') {
            $this->fatalError(
                $node,
                'Native classes do not support late static binding in return types',
            );
        }
        if ($this->isNativeObjectClass($function->returnClass) && $function->returnsByRef) {
            $this->fatalError($node, 'Native objects cannot be returned by reference');
        }
        foreach ($function->argInfoList as $index => $argument) {
            $class = $argument->declaredClass ?: $argument->class;
            if (!$this->isNativeObjectClass($class)) {
                continue;
            }
            $parameter = $node->params[$index] ?? $node;
            if ($argument->byRef) {
                $this->fatalError($parameter, 'Native object parameters cannot be passed by reference');
            }
            if ($argument->variadic) {
                $this->fatalError($parameter, 'Native object parameters cannot be variadic');
            }
            if ($parameter instanceof Node\Param
                && $parameter->default !== null
                && $this->isNull($parameter->default)
                && !$argument->nullable
            ) {
                $this->fatalError(
                    $parameter,
                    'A Native object parameter with a null default must use explicit nullable ?Class syntax',
                );
            }
        }
    }

    /**
     * Validate a Native reference entirely from compile-time metadata.
     *
     * A Native object variable is a typed pointer and must never expose its
     * pointer slot as a PHP reference. A Native property may expose a reference
     * only when it was explicitly declared `any`: that field intentionally
     * permits arbitrary PHP values. Every other declaration, including
     * `mixed`, must reject references because dynamic Zend code could replace
     * the referenced value with one that violates the Native field contract.
     */
    protected function assertNativeObjectReferenceForbidden(
        NodeAbstract $expr,
        NodeAbstract $errorNode,
    ): void {
        if ($expr instanceof Node\Expr\PropertyFetch) {
            $receiverClass = $this->detectClassOfExpr($expr->var);
            if ($this->isNativeObjectClass($receiverClass)) {
                if (!$expr->name instanceof Node\Identifier) {
                    $this->fatalError($errorNode, 'Dynamic native object property access is not supported');
                }
                $property = $expr->name->toString();
                $resolution = $this->resolveNativeInstanceProperty($expr, $property, $receiverClass);
                if ($resolution === null) {
                    $this->fatalError(
                        $errorNode,
                        "Native class `{$receiverClass}` has no property `\${$property}`",
                    );
                }
                $this->applyNativePropertyAccessResult($expr, $resolution);
                $definition = $resolution->propertyDef;
                if (!$definition->explicitAny || $definition->getter !== null || $definition->setter !== null) {
                    $this->fatalError(
                        $errorNode,
                        'Only Native object properties declared as any can be referenced',
                    );
                }
                return;
            }
        }
        $class = $this->detectDeclaredClassOfExpr($expr);
        if ($this->isNativeObjectClass($class)) {
            $this->fatalError(
                $errorNode,
                'Native objects cannot be referenced; object assignment already shares identity',
            );
        }
    }

    /**
     * Reflection constructors accept a class name string, so the ordinary
     * Native-pointer Zend boundary check cannot see this escape. Reject known
     * Native class literals before generating a lookup for a class which is
     * intentionally absent from the Zend class table.
     */
    protected function assertNativeClassNotUsedWithReflection(
        Node\Expr\New_ $expr,
        string $constructedClass,
    ): void {
        $reflectionClass = strtolower(ltrim($constructedClass, '\\'));
        if (!in_array($reflectionClass, [
            'reflectionclass',
            'reflectionmethod',
            'reflectionproperty',
            'reflectionclassconstant',
            'reflectionenum',
            'reflectionenumunitcase',
            'reflectionenumbackedcase',
        ], true) || $expr->args === []) {
            return;
        }

        $target = $expr->args[0]->value;
        
        $nativeClass = '';
        if ($this->isScalarString($target)) {
            // Reflection string arguments are runtime names, not names relative
            // to the current PHP namespace.
            $candidate = ltrim($target->value, '\\');
            if ($this->isNativeObjectClass($candidate)) {
                $nativeClass = $candidate;
            }
        } elseif ($this->isClassConstFetch($target)
            && $this->isNameExpr($target->class)
            && $this->isIdExpr($target->name)
            && strtolower($this->parseIdentifier($target->name)) === 'class'
        ) {
            $candidate = $this->parseIdentifier($target->class);
            if ($candidate === 'self' || $candidate === 'static') {
                $candidate = $this->getFullClassName();
            } elseif ($candidate === 'parent') {
                $candidate = $this->classDef?->extends ?? '';
            } else {
                $candidate = $this->getNamespacedClassName($candidate);
            }
            if ($this->isNativeObjectClass($candidate)) {
                $nativeClass = $candidate;
            }
        }

        if ($nativeClass !== '') {
            $reflectionName = strrchr($constructedClass, '\\');
            $reflectionName = $reflectionName === false
                ? $constructedClass
                : substr($reflectionName, 1);
            $this->fatalError(
                $target,
                "Native class `{$nativeClass}` cannot be used with {$reflectionName}",
            );
        }
    }

    protected function getNativeObjectReturnType(FunctionDef $function): ?string
    {
        if ($function->returnType !== Type::OBJECT || !$this->isNativeObjectClass($function->returnClass)) {
            return null;
        }
        return $this->getNativeObjectPointerType($function->returnClass);
    }

    protected function getNativeObjectMethodThisType(FunctionDef $function): ?string
    {
        if (!$function->method || !$this->isNativeObjectClass($function->declaringClass)) {
            return null;
        }
        return $this->getNativeObjectCppName($function->declaringClass) . ' &';
    }

    protected function functionUsesNativeObject(FunctionDef $function): bool
    {
        if ($this->getNativeObjectReturnType($function) !== null
            || $this->getNativeObjectMethodThisType($function) !== null
        ) {
            return true;
        }
        foreach ($function->argInfoList as $argument) {
            if ($this->getNativeObjectArgumentType($argument) !== null) {
                return true;
            }
        }
        return false;
    }

    protected function genNativeObjectParameterChecks(FunctionDef $function): string
    {
        $code = '';
        foreach ($function->argInfoList as $argument) {
            $class = $argument->declaredClass ?: $argument->class;
            if (!$argument->nullable && $this->isNativeObjectClass($class)) {
                $code .= $this->getIndent() . 'php::nativeGcRequireObject('
                    . $argument->name . ', "' . addslashes($class) . '");' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function addNativeObject(string $name, string $class): void
    {
        $this->context->nativeObjects[$name] = ltrim($class, '\\');
        $this->context->objects[$name] = ltrim($class, '\\');
    }

    protected function isNativeObjectVar(string $name): bool
    {
        return isset($this->context->nativeObjects[$name]);
    }

    protected function getNativeObjectVarClass(string $name): string
    {
        return $this->context->nativeObjects[$name] ?? '';
    }

    protected function markNativeObjectNonNull(string $name): void
    {
        $this->context->nonNullNativeObjects[$name] = true;
    }

    protected function forgetNativeObjectNonNull(string $name): void
    {
        unset($this->context->nonNullNativeObjects[$name]);
    }

    protected function isNativeObjectKnownNonNull(string $name): bool
    {
        return isset($this->context->nonNullNativeObjects[$name]);
    }

    /**
     * Conservative straight-line non-null proof for a Native pointer value.
     * This deliberately excludes properties (a non-nullable Native field has
     * a nullptr zero value) and control-flow expressions. The caller may only
     * retain the proof at function top level, where no branch merge is needed.
     */
    protected function isNativeObjectExpressionKnownNonNull(NodeAbstract $expr): bool
    {
        if ($expr instanceof Node\Expr\ErrorSuppress) {
            return $this->isNativeObjectExpressionKnownNonNull($expr->expr);
        }
        if ($expr instanceof Node\Expr\New_ || $expr instanceof Node\Expr\Clone_) {
            return $this->isNativeObjectClass($this->detectClassOfExpr($expr));
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $this->isNativeObjectKnownNonNull($this->parseIdentifier($expr));
        }
        if ($expr instanceof Node\Expr\FuncCall
            && ($this->isNameExpr($expr->name) || $this->isFullNameExpr($expr->name))
        ) {
            $native = $this->findNativeFunction($this->parseIdentifier($expr->name));
            if ($native !== false) {
                $function = $this->getFunction($native);
                return $this->isNativeObjectClass($function->returnClass)
                    && !$function->returnNullable;
            }
        }
        if ($expr instanceof Node\Expr\MethodCall
            && $this->isIdExpr($expr->name)
        ) {
            $receiverClass = $this->detectClassOfExpr($expr->var);
            if ($this->isNativeObjectClass($receiverClass)) {
                $method = $this->findNativeObjectMethod(
                    $receiverClass,
                    $this->parseIdentifier($expr->name),
                );
                return $method !== null
                    && $this->isNativeObjectClass($method->functionDef->returnClass)
                    && !$method->functionDef->returnNullable;
            }
        }
        return false;
    }

    protected function getNativeObjectReceiver(string $name): string
    {
        if ($name === 'this_') {
            return 'this_';
        }
        if ($this->isNativeObjectKnownNonNull($name)) {
            return '(*' . $name . ')';
        }
        $class = $this->getNativeObjectVarClass($name);
        return 'php::nativeDeref(' . $name . ', "' . addslashes($class) . '")';
    }

    /**
     * Native method bodies receive `this_` by C++ reference so direct member
     * access remains zero-cost. In a PHP value context, however, `$this` is an
     * object handle and must therefore become the address of that reference.
     * Keep this conversion out of parseIdentifier(): receiver contexts need
     * the reference itself, while assignments, returns, arguments and
     * comparisons need the typed pointer.
     */
    protected function normalizeNativeObjectValueExpr(Node $expr, string $value): string
    {
        if ($this->classDef?->nativeObject
            && $this->isVarExpr($expr)
            && $this->parseVariable($expr) === 'this_'
        ) {
            return '&this_';
        }
        return $value;
    }

    protected function getNativeObjectMemberReceiver(string $name): string
    {
        return $this->getNativeObjectReceiver($name) . '.';
    }

    /**
     * Materialize a Native-producing expression as a precisely rooted local.
     * This is shared by chained method and property access so neither path can
     * accidentally pass a Native pointer through php::Variant.
     */
    protected function materializeNativeObjectReceiver(NodeAbstract $expr, string $class): string
    {
        [$receiver, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $object = $this->genTmpVarName();
        $this->addLocalVar($object, $this->getNativeObjectPointerType($class));
        $this->addNativeObject($object, $class);
        $this->context->beforeStmtLines[] = $object . ' = ' . $receiver . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        // Keep the temporary in the precise root frame while the complete PHP
        // statement executes, then release that root at statement end.
        $this->context->afterStmtLines[] = $object . ' = nullptr;';
        return $object;
    }

    /**
     * Lower a nullsafe chain whose root and every intermediate receiver are
     * Native pointers. The generic implementation deliberately uses
     * php::Object/Variant and therefore cannot represent this object model.
     */
    protected function parseNativeNullsafeAccess(
        Node\Expr\PropertyFetch|Node\Expr\MethodCall|Node\Expr\NullsafePropertyFetch|Node\Expr\NullsafeMethodCall $expr,
    ): ?string {
        $steps = [];
        $base = $expr;
        while ($base instanceof Node\Expr\PropertyFetch
            || $base instanceof Node\Expr\MethodCall
            || $base instanceof Node\Expr\NullsafePropertyFetch
            || $base instanceof Node\Expr\NullsafeMethodCall
        ) {
            array_unshift($steps, $base);
            $base = $base->var;
        }

        $baseClass = $this->detectClassOfExpr($base);
        if ($baseClass === '' && $this->isVarExpr($base)) {
            $baseName = $this->parseIdentifier($base);
            if ($this->isNativeObjectVar($baseName)) {
                $baseClass = $this->getNativeObjectVarClass($baseName);
            }
        }
        if (!$this->isNativeObjectClass($baseClass)) {
            return null;
        }

        $current = $this->isVarExpr($base)
            ? $this->parseIdentifier($base)
            : $this->materializeNativeObjectReceiver($base, $baseClass);
        if ($this->isVarExpr($base) && !$this->hasVar($current) && $current !== 'this_') {
            $this->errorUndefinedVariable($base);
        }

        $body = '';
        $last = array_key_last($steps);
        $finalClass = '';
        $finalType = Type::VAR;
        $nullToken = '__TYPEPHP_NATIVE_NULLSAFE_NULL__';

        foreach ($steps as $index => $step) {
            $nullsafe = $step instanceof Node\Expr\NullsafePropertyFetch
                || $step instanceof Node\Expr\NullsafeMethodCall;
            if ($nullsafe) {
                $body .= $this->getIndent() . 'if (' . $current . ' == nullptr) { return '
                    . $nullToken . '; }' . PHP_EOL;
            }

            $receiver = new Node\Expr\Variable($current, $step->var->getAttributes());
            if ($step instanceof Node\Expr\PropertyFetch || $step instanceof Node\Expr\NullsafePropertyFetch) {
                if (!$step->name instanceof Node\Identifier) {
                    $this->fatalError($step, 'Dynamic native object property access is not supported');
                }
                $access = new Node\Expr\PropertyFetch($receiver, clone $step->name, $step->getAttributes());
            } else {
                if (!$step->name instanceof Node\Identifier) {
                    $this->fatalError($step, 'Dynamic native object method calls are not supported');
                }
                $access = new Node\Expr\MethodCall(
                    $receiver,
                    clone $step->name,
                    $step->args,
                    $step->getAttributes(),
                );
            }

            [$value, $before, $after] = $this->parseExprWithCapturedStmts($access);
            $valueClass = $this->detectClassOfExpr($access);
            $valueType = $this->detectTypeOfExpr($access);
            $body .= $this->formatCapturedStmtLines($before);

            if ($index !== $last) {
                if (!$this->isNativeObjectClass($valueClass)) {
                    $this->fatalError(
                        $step,
                        'A Native nullsafe chain cannot continue through a non-Native value',
                    );
                }
                $next = $this->genTmpVarName();
                $this->addLocalVar($next, $this->getNativeObjectPointerType($valueClass));
                $this->addNativeObject($next, $valueClass);
                $body .= $this->getIndent() . $next . ' = ' . $value . ';' . PHP_EOL;
                $body .= $this->formatCapturedStmtLines($after);
                $current = $next;
                continue;
            }

            $finalClass = $valueClass;
            $finalType = $valueType;
            if ($finalType === Type::VOID) {
                $body .= $this->getIndent() . $value . ';' . PHP_EOL;
                $body .= $this->formatCapturedStmtLines($after);
                $body .= $this->getIndent() . 'return php::null;' . PHP_EOL;
                continue;
            }

            if ($after !== []) {
                if ($this->isNativeObjectClass($finalClass)) {
                    $result = $this->genTmpVarName();
                    $this->addLocalVar($result, $this->getNativeObjectPointerType($finalClass));
                    $this->addNativeObject($result, $finalClass);
                    $body .= $this->getIndent() . $result . ' = ' . $value . ';' . PHP_EOL;
                } else {
                    $result = $this->genTmpVarName();
                    $body .= $this->getIndent() . 'auto ' . $result . ' = ' . $value . ';' . PHP_EOL;
                }
                $body .= $this->formatCapturedStmtLines($after);
                $value = $result;
            }
            $body .= $this->getIndent() . 'return '
                . ($this->isNativeObjectClass($finalClass) ? $value : 'php::Var(' . $value . ')')
                . ';' . PHP_EOL;
        }

        $nativeResult = $this->isNativeObjectClass($finalClass);
        $returnType = $nativeResult ? $this->getNativeObjectPointerType($finalClass) : Type::VAR;
        $nullValue = $nativeResult ? 'nullptr' : 'php::null';
        $body = str_replace($nullToken, $nullValue, $body);
        return '[&]() -> ' . $returnType . ' {' . PHP_EOL
            . $body
            . $this->getIndent() . '}()';
    }

    protected function findNativeObjectProperty(string $class, string $property): ?PropertyDef
    {
        while ($class !== '' && $this->hasClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->hasProperty($property)) {
                return $classDef->getProperty($property);
            }
            $class = $classDef->extends;
        }
        return null;
    }

    protected function findNativeObjectMethod(string $class, string $method): ?MethodDef
    {
        while ($class !== '' && $this->isNativeObjectClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->hasMethod($method)) {
                return $classDef->getMethod($method);
            }
            if ($classDef->hasAbstractMethod($method)
                && isset($classDef->abstractMethodDefs[strtolower($method)])
            ) {
                return $classDef->getAbstractMethod($method);
            }
            $class = $classDef->extends;
        }
        return null;
    }

    /**
     * Native objects cannot fall back to PHPX/Zend conversion helpers. A
     * keyword conversion is therefore a statically checked ordinary Native
     * method call. __toString() is accepted as the PHP-compatible spelling of
     * toString().
     */
    protected function resolveNativeObjectKeywordMethod(
        NodeAbstract $node,
        string $class,
        string $method,
    ): string {
        $expectedType = self::KEYWORD_METHOD_MAP[$method] ?? null;
        if ($expectedType === null) {
            return $method;
        }
        $resolvedMethod = $method;
        $methodDef = $this->findNativeObjectMethod($class, $resolvedMethod);
        if ($method === 'toString' && $methodDef === null) {
            $resolvedMethod = '__toString';
            $methodDef = $this->findNativeObjectMethod($class, $resolvedMethod);
        }
        if ($methodDef === null) {
            $this->fatalError($node, "Native class `{$class}` must define `{$method}()` for this conversion");
        }
        $this->assertKeywordConversionMethodSignature(
            $node,
            $class,
            $resolvedMethod,
            $methodDef->functionDef,
            $expectedType,
            true,
        );
        return $resolvedMethod;
    }

    protected function assertKeywordConversionMethodSignature(
        NodeAbstract $node,
        string $class,
        string $method,
        FunctionDef $function,
        string $expectedType,
        bool $nativeClass,
    ): void {
        $kind = $nativeClass ? 'Native conversion method' : 'Conversion method';
        if ($function->argInfoList !== []) {
            $this->fatalError($node, "{$kind} `{$class}::{$method}()` must not accept arguments");
        }
        $hasExactReturnType = $function->returnType === $expectedType;
        if ($expectedType === Type::VAR) {
            // Type::VAR also represents an omitted return type and several
            // other dynamic PHP types internally. A Native toAny() bridge is
            // only valid when the author explicitly opts into mixed/any.
            $hasExactReturnType = in_array(
                strtolower($function->returnTypeStr),
                ['mixed', 'any'],
                true,
            );
        }
        if ($function->returnsByRef || $function->returnNullable || !$hasExactReturnType) {
            $expectedTypeName = match ($expectedType) {
                Type::INT => 'int',
                Type::FLOAT => 'float',
                Type::STR => 'string',
                Type::BOOL => 'bool',
                Type::ARRAY => 'array',
                Type::STREAM => 'Stream',
                Type::BIGINT => 'BigInt',
                Type::BIGFLOAT => 'BigFloat',
                Type::DECIMAL => 'Decimal',
                Type::OBJECT => 'object',
                Type::VAR => 'mixed` or `any',
                default => $expectedType,
            };
            $this->fatalError(
                $node,
                "{$kind} `{$class}::{$method}()` must return exactly `{$expectedTypeName}`",
            );
        }
    }

    protected function parseNativeObjectExplicitConversion(NodeAbstract $expr, string $method): ?string
    {
        $class = $this->detectClassOfExpr($expr);
        if (!$this->isNativeObjectClass($class)) {
            return null;
        }
        return $this->parseMethodCall(new Node\Expr\MethodCall(
            $expr,
            new Node\Identifier($method),
            [],
            $expr->getAttributes(),
        ));
    }

    protected function getNativeVirtualMethodName(string|ClassDef $slotClass, string $method): string
    {
        return '__typephp_virtual_' . strtolower($this->getNativeObjectCppName($slotClass))
            . '__' . strtolower($method);
    }

    /** Whether this declaration owns a virtual dispatch slot. */
    protected function isNativeVirtualMethod(ClassDef $class, MethodDef $method): bool
    {
        if ($method->flags & (Modifiers::STATIC | Modifiers::PRIVATE | Modifiers::FINAL)) {
            return false;
        }
        if ($method->flags & Modifiers::ABSTRACT) {
            return true;
        }
        if (in_array(strtolower($method->name), ['__construct', '__destruct', '__clone'], true)) {
            return false;
        }
        if ($this->isOverrideMethod($class->getNamespacedName(false) . '::' . $method->name)) {
            return true;
        }
        return false;
    }

    /**
     * Return every virtual slot a declaration must implement. PHP permits
     * contravariant parameters and covariant returns, so one C++ virtual
     * signature cannot represent the whole family. Each source declaration
     * owns a stable slot; an override supplies adapters for all ancestor slots.
     *
     * @return list<array{ClassDef, MethodDef}>
     */
    protected function getNativeVirtualMethodSlots(ClassDef $class, MethodDef $method): array
    {
        $slots = [];
        $current = $class;
        while (true) {
            $declaration = null;
            if ($current->hasMethod($method->name)) {
                $declaration = $current->getMethod($method->name);
            } elseif ($current->hasAbstractMethod($method->name)
                && isset($current->abstractMethodDefs[strtolower($method->name)])
            ) {
                $declaration = $current->getAbstractMethod($method->name);
            }
            if ($declaration !== null && $this->isNativeVirtualMethod($current, $declaration)) {
                $slots[] = [$current, $declaration];
            }
            if ($current->extends === '' || !$this->isNativeObjectClass($current->extends)) {
                break;
            }
            $current = $this->getClass($current->extends);
        }
        return $slots;
    }

    protected function getNativeMethodReturnCppType(FunctionDef $function): string
    {
        return $function->returnsByRef
            ? Type::REF
            : ($this->getNativeObjectReturnType($function) ?? $function->returnType);
    }

    protected function getNativeMethodParameterDeclarations(
        FunctionDef $function,
        ?int $parameterCount = null,
    ): string
    {
        $args = [];
        $arguments = $parameterCount === null
            ? $function->argInfoList
            : array_slice($function->argInfoList, 0, $parameterCount);
        foreach ($arguments as $argument) {
            if ($argument->variadic) {
                $declaration = Type::ARRAY . ' ' . $argument->name;
            } else {
                $declaration = $this->genArgumentDeclaration($argument);
            }
            $args[] = $declaration;
        }
        return implode(', ', $args);
    }

    /**
     * C++ binds a default argument from the receiver's static type, while PHP
     * uses the default declared by the dynamically selected override. Emit an
     * overload for every positional arity instead of putting C++ defaults on
     * a virtual declaration. Each override adapter can then call its concrete
     * php_* function with the supplied prefix and let that declaration provide
     * the correct dynamic defaults.
     *
     * @return list<int>
     */
    protected function getNativeVirtualMethodArities(FunctionDef $function): array
    {
        $total = count($function->argInfoList);
        return range(min($function->argCountRequired, $total), $total);
    }

    protected function getNativeObjectPropertyType(PropertyDef $property): string
    {
        if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
            return $this->getNativeObjectPointerType($property->class);
        }
        return match ($property->type) {
            // These language values use PHPX's boxed Variant ABI. Embedding
            // the implementation classes by value would be incompatible with
            // every arithmetic/conversion helper, all of which accepts and
            // returns Variant while preserving immutable value semantics.
            Type::STREAM, Type::BOX, Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL => Type::VAR,
            default => $property->type,
        };
    }

    protected function getNativeObjectInitializerName(string|ClassDef $class): string
    {
        // Keep compiler-owned helpers outside the php_* user symbol namespace.
        // A PHP method named initialize() previously collided with
        // php_<class>__initialize and produced duplicate C++ definitions.
        return 'typephp_native_initialize_fields__' . $this->getNativeObjectCppName($class);
    }

    protected function getNativeObjectPropertyCppName(
        string|PropertyDef $property,
        string|ClassDef|null $declaringClass = null,
    ): string
    {
        $name = $property instanceof PropertyDef ? $property->name : $property;
        if (!$property instanceof PropertyDef || !$property->isPrivate()) {
            return $this->escapeVarName($name);
        }
        if ($declaringClass === null) {
            throw new \LogicException('Native private property field requires its declaring class');
        }
        return '__private_' . $this->getNativeObjectCppName($declaringClass)
            . '__' . $this->escapeVarName($name);
    }

    protected function isNativeObjectForbiddenPropertyType(PropertyDef $property): bool
    {
        if (in_array($property->type, [
            Type::BOX,
            Type::STD_ARRAY,
            Type::STD_VECTOR,
            Type::STD_MAP,
            Type::STD_ORDERED_MAP,
        ], true)) {
            return true;
        }
        return in_array(strtolower(ltrim($property->class, '\\')), [
            'std\\array',
            'std\\vector',
            'std\\map',
            'std\\ordered_map',
        ], true);
    }

    protected function isNativeObjectInheritedPropertyRedeclaration(
        ClassDef $class,
        PropertyDef $property,
    ): bool {
        $parent = $class->extends;
        while ($parent !== '' && $this->isNativeObjectClass($parent)) {
            $parentDef = $this->getClass($parent);
            if ($parentDef->hasProperty($property->name)) {
                $parentProperty = $parentDef->getProperty($property->name);
                if ($property->isPrivate() || $parentProperty->isPrivate()) {
                    return false;
                }
                // Property compatibility is validated separately. TypePHP
                // treats a compatible public/protected redeclaration as the
                // same inherited slot, so a Native child must not emit a
                // second C++ field with the same PHP property name.
                return true;
            }
            $parent = $parentDef->extends;
        }
        return false;
    }

    /**
     * C++ requires a base struct to be complete before defining a derived
     * struct. PHP source order has no such restriction, so emit Native class
     * definitions in inheritance order while retaining source order between
     * unrelated classes.
     *
     * @return list<ClassDef>
     */
    protected function getNativeObjectClassesInDeclarationOrder(): array
    {
        $classes = array_values(array_filter(
            $this->symbols->classes(),
            static fn (ClassDef $class): bool => $class->nativeObject,
        ));
        $byName = [];
        foreach ($classes as $class) {
            $byName[strtolower(ltrim($class->getNamespacedName(false), '\\'))] = $class;
        }

        $ordered = [];
        $visited = [];
        $visit = function (ClassDef $class) use (&$visit, &$ordered, &$visited, $byName): void {
            $key = strtolower(ltrim($class->getNamespacedName(false), '\\'));
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            $parent = strtolower(ltrim($class->extends, '\\'));
            if ($parent !== '' && isset($byName[$parent])) {
                $visit($byName[$parent]);
            }
            $ordered[] = $class;
        };
        foreach ($classes as $class) {
            $visit($class);
        }
        return $ordered;
    }

    protected function genNativeObjectDeclarations(): string
    {
        $classes = $this->getNativeObjectClassesInDeclarationOrder();
        if ($classes === []) {
            return '';
        }

        $code = '// TypePHP Native Object declarations' . PHP_EOL;
        foreach ($classes as $class) {
            $code .= 'struct ' . $this->getNativeObjectCppName($class) . ';' . PHP_EOL;
        }
        $code .= PHP_EOL;

        foreach ($classes as $class) {
            $name = $this->getNativeObjectCppName($class);
            $parent = $class->extends !== '' && $this->isNativeObjectClass($class->extends)
                ? ' : public ' . $this->getNativeObjectCppName($class->extends)
                : '';
            $code .= 'struct ' . $name . $parent . ' {' . PHP_EOL;
            foreach ($class->properties as $property) {
                if ($property->flags & Modifiers::STATIC
                    || $this->isNativeObjectInheritedPropertyRedeclaration($class, $property)
                ) {
                    continue;
                }
                $type = $this->getNativeObjectPropertyType($property);
                // PHPX value types own their storage and must be initialized by
                // their C++ default constructor. PHP-level defaults are applied
                // by the generated allocation/constructor path, not in this
                // shared declaration header (which cannot reference file-local
                // literal tables).
                $default = null;
                if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
                    $default = 'nullptr';
                } elseif (in_array($property->type, [Type::INT, Type::FLOAT, Type::BOOL], true)) {
                    $default = '0';
                }
                $code .= '    ' . $type . ' ' . $this->getNativeObjectPropertyCppName($property, $class);
                if ($default !== null) {
                    $code .= ' = ' . $default;
                }
                $code .= ';' . PHP_EOL;
            }
            foreach ([...$class->methods, ...$class->abstractMethodDefs] as $method) {
                foreach ($this->getNativeVirtualMethodSlots($class, $method) as [$slotClass, $slotMethod]) {
                    $ownsSlot = $this->isSameClassName(
                        $slotClass->getNamespacedName(false),
                        $class->getNamespacedName(false),
                    );
                    foreach ($this->getNativeVirtualMethodArities($slotMethod->functionDef) as $arity) {
                        $code .= '    virtual ' . $this->getNativeMethodReturnCppType($slotMethod->functionDef)
                            . ' ' . $this->getNativeVirtualMethodName($slotClass, $method->name) . '('
                            . $this->getNativeMethodParameterDeclarations($slotMethod->functionDef, $arity) . ')'
                            . ($ownsSlot ? '' : ' override')
                            . (($method->flags & Modifiers::ABSTRACT) ? ' = 0' : '')
                            . ';' . PHP_EOL;
                    }
                }
            }
            if ($this->nativeObjectUsesVirtualClone($class)) {
                $code .= '    virtual ' . $name . ' *' . self::NATIVE_VIRTUAL_CLONE_METHOD . '() const';
                if ($class->extends !== '' && $this->isNativeObjectClass($class->extends)) {
                    $code .= ' override';
                }
                if ($class->isAbstract()) {
                    $code .= ' = 0';
                }
                $code .= ';' . PHP_EOL;
            }
            $code .= '};' . PHP_EOL;
            $code .= 'void ' . $this->getNativeObjectInitializerName($class)
                . '(' . $name . ' &object);' . PHP_EOL;
            $code .= 'void ' . $name . '__gc_trace(void *object, php::NativeMarker &marker);' . PHP_EOL;
            $code .= 'extern const php::NativeTypeDescriptor '
                . $this->getNativeObjectDescriptorName($class) . ';' . PHP_EOL . PHP_EOL;
        }
        return $code;
    }

    protected function genNativeObjectRuntimeDefinition(ClassDef $class): string
    {
        $cpp = $this->getNativeObjectCppName($class);
        $prefix = $cpp . '__gc';
        $code = '';
        $code .= 'void ' . $this->getNativeObjectInitializerName($class)
            . '(' . $cpp . ' &this_) {' . PHP_EOL;
        if ($class->extends !== '' && $this->isNativeObjectClass($class->extends)) {
            $code .= '    ' . $this->getNativeObjectInitializerName($class->extends)
                . '(this_);' . PHP_EOL;
        }
        foreach ($class->properties as $property) {
            if ($property->isStatic() || $property->default === null) {
                continue;
            }
            if ($property->type === Type::OBJECT && $this->isNativeObjectClass($property->class)) {
                $value = 'nullptr';
            } else {
                $value = $property->default;
            }
            $code .= '    this_.' . $this->getNativeObjectPropertyCppName($property, $class) . ' = ' . $value . ';' . PHP_EOL;
        }
        $code .= '}' . PHP_EOL . PHP_EOL;
        foreach ($class->methods as $method) {
            $nativeFunction = self::PREFIX . $this->getNativeName(
                $method->name,
                $class->namespace,
                $class->name,
            );
            foreach ($this->getNativeVirtualMethodSlots($class, $method) as [$slotClass, $slotMethod]) {
                $slotFunction = $slotMethod->functionDef;
                $returnType = $this->getNativeMethodReturnCppType($slotFunction);
                foreach ($this->getNativeVirtualMethodArities($slotFunction) as $arity) {
                    $args = array_map(
                        static fn (ArgInfo $arg): string => $arg->name,
                        array_slice($slotFunction->argInfoList, 0, $arity),
                    );
                    $code .= $returnType . ' ' . $cpp . '::'
                        . $this->getNativeVirtualMethodName($slotClass, $method->name)
                        . '(' . $this->getNativeMethodParameterDeclarations($slotFunction, $arity) . ') {' . PHP_EOL;
                    $call = $nativeFunction . '(*this' . ($args === [] ? '' : ', ' . implode(', ', $args)) . ')';
                    $code .= '    ' . ($returnType === Type::VOID ? '' : 'return ') . $call . ';' . PHP_EOL;
                    $code .= '}' . PHP_EOL . PHP_EOL;
                }
            }
        }

        if ($this->nativeObjectUsesVirtualClone($class) && !$class->isAbstract()) {
            $initializer = '';
            $cloneMethod = $this->findNativeObjectMethod($class->getNamespacedName(false), '__clone');
            if ($cloneMethod !== null) {
                $declaringClass = $this->getClass($cloneMethod->functionDef->declaringClass);
                $clone = self::PREFIX . $this->getNativeName(
                    '__clone',
                    $declaringClass->namespace,
                    $declaringClass->name,
                );
                $initializer = $clone . '(this_); ';
            }
            $code .= $cpp . ' *' . $cpp . '::' . self::NATIVE_VIRTUAL_CLONE_METHOD . '() const {' . PHP_EOL;
            $code .= '    return php::nativeClone<' . $cpp . '>('
                . $this->getNativeObjectDescriptorName($class) . ', *this, '
                . '[&](auto &this_) { ' . $initializer . '});' . PHP_EOL;
            $code .= '}' . PHP_EOL . PHP_EOL;
        }
        $code .= 'void ' . $prefix . '_trace(void *object, php::NativeMarker &marker) {' . PHP_EOL;
        $hasParentTrace = $class->extends !== '' && $this->isNativeObjectClass($class->extends);
        $nativeProperties = array_filter(
            $class->properties,
            fn (PropertyDef $property): bool => !$property->isStatic()
                && !$this->isNativeObjectInheritedPropertyRedeclaration($class, $property)
                && $property->type === Type::OBJECT
                && $this->isNativeObjectClass($property->class),
        );
        if ($hasParentTrace || $nativeProperties !== []) {
            $code .= '    auto &this_ = *static_cast<' . $cpp . ' *>(object);' . PHP_EOL;
        } else {
            $code .= '    (void) object;' . PHP_EOL;
            $code .= '    (void) marker;' . PHP_EOL;
        }
        if ($hasParentTrace) {
            $code .= '    ' . $this->getNativeObjectCppName($class->extends)
                . '__gc_trace(static_cast<' . $this->getNativeObjectCppName($class->extends) . ' *>(&this_), marker);'
                . PHP_EOL;
        }
        foreach ($nativeProperties as $property) {
            $code .= '    marker.mark(this_.' . $this->getNativeObjectPropertyCppName($property, $class) . ');' . PHP_EOL;
        }
        $code .= '}' . PHP_EOL;

        $destructors = [];
        $destructorClass = $class;
        while (true) {
            if ($destructorClass->hasMethod('__destruct')) {
                $destructors[] = [
                    self::PREFIX . $this->getNativeName(
                        '__destruct',
                        $destructorClass->namespace,
                        $destructorClass->name,
                    ),
                    $this->getNativeObjectCppName($destructorClass),
                ];
            }
            if ($destructorClass->extends === '' || !$this->isNativeObjectClass($destructorClass->extends)) {
                break;
            }
            $destructorClass = $this->getClass($destructorClass->extends);
        }
        if ($destructors !== []) {
            $code .= 'static void ' . $prefix . '_finalize(void *object) {' . PHP_EOL;
            if (count($destructors) === 1) {
                [$destructor, $destructorCpp] = $destructors[0];
                $code .= '    ' . $destructor
                    . '(*static_cast<' . $destructorCpp . ' *>(object));' . PHP_EOL;
            } else {
                $code .= '    php::NativeFinalizerChain chain;' . PHP_EOL;
                foreach ($destructors as [$destructor, $destructorCpp]) {
                    $code .= '    chain.run([&] { ' . $destructor
                        . '(*static_cast<' . $destructorCpp . ' *>(object)); });' . PHP_EOL;
                }
                $code .= '    chain.rethrow();' . PHP_EOL;
            }
            $code .= '}' . PHP_EOL;
        }
        $code .= 'static void ' . $prefix . '_destroy(void *object) noexcept {' . PHP_EOL;
        $code .= '    static_cast<' . $cpp . ' *>(object)->~' . $cpp . '();' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        $code .= 'const php::NativeTypeDescriptor ' . $this->getNativeObjectDescriptorName($class) . ' = {' . PHP_EOL;
        $code .= '    "' . addslashes($class->getNamespacedName(false)) . '",' . PHP_EOL;
        $code .= '    sizeof(' . $cpp . '),' . PHP_EOL;
        $code .= '    alignof(' . $cpp . '),' . PHP_EOL;
        $code .= '    ' . $prefix . '_trace,' . PHP_EOL;
        $code .= '    ' . ($destructors !== [] ? $prefix . '_finalize' : 'nullptr') . ',' . PHP_EOL;
        $code .= '    ' . $prefix . '_destroy,' . PHP_EOL;
        $code .= '};' . PHP_EOL . PHP_EOL;
        return $code;
    }
}
