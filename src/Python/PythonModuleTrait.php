<?php

namespace TypePhp\Python;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Type;

trait PythonModuleTrait
{
    /** @var array<string, string> TypePHP constructor sugar to the existing phpy facade class. */
    private const PYTHON_CONSTRUCTOR_CLASSES = [
        'list' => 'PyList',
        'dict' => 'PyDict',
        'tuple' => 'PyTuple',
        'set' => 'PySet',
        'str' => 'PyStr',
        'object' => 'PyObject',
    ];

    /** @var array<string, string> Python calls whose precise phpy wrapper is known statically. */
    private const PYTHON_BUILTIN_RETURN_CLASSES = [
        ...self::PYTHON_CONSTRUCTOR_CLASSES,
        'int' => 'PyObject',
        'float' => 'PyObject',
        'bytes' => 'PyObject',
        'type' => 'PyType',
    ];

    /** @var array<string, true> Builtins exposed as explicit PyCore methods. */
    private const PYTHON_CORE_FUNCTIONS = [
        'int' => true,
        'float' => true,
        'bytes' => true,
        'scalar' => true,
    ];

    /** @var array<string, string> Builtins with a direct phpy Native ABI constructor. */
    private const PYTHON_NATIVE_CONSTRUCTORS = [
        'list' => 'List',
        'dict' => 'Dict',
        'tuple' => 'Tuple',
        'set' => 'Set',
        'str' => 'Str',
        'object' => 'Object',
        'int' => 'Int',
        'float' => 'Float',
        'bytes' => 'Bytes',
    ];

    /** @var array<string, int> Case-sensitive Python module name to generated slot ID. */
    protected array $pythonModuleMap = [];

    protected int $pythonModuleIndex = 0;

    protected bool $pythonRuntimeUsed = false;

    /** Return true when the expression is statically known to hold a phpy proxy. */
    protected function isPythonObjectExpr(NodeAbstract $expr): bool
    {
        $class = $this->detectClassOfExpr($expr);
        if ($class === '') {
            return false;
        }
        return strcasecmp($class, 'PyObject') === 0
            || $this->isObjectClassStaticallyAssignableTo($class, 'PyObject');
    }

    /**
     * Python members are resolved by the Python VM. Only methods explicitly
     * registered on the phpy wrapper may use a cached Zend method pointer;
     * every other name must reach PyObject::__call().
     */
    protected function isPythonDynamicMethodCall(NodeAbstract $receiver, string $method): bool
    {
        if (!$this->isPythonObjectExpr($receiver)) {
            return false;
        }

        $class = $this->detectClassOfExpr($receiver);
        return $class === '' || !\TypePhp\Resolver\Reflection::hasMethod($class, $method);
    }

    protected function parsePythonNativeFacadeMethodCall(Expr\MethodCall $expr, string $receiver): ?string
    {
        if (!$this->isNamedMethod($expr->name) || !$this->isPythonObjectExpr($expr->var)) {
            return null;
        }
        $method = $expr->name->toString();
        $helper = match ($method) {
            'toValue' => 'toValue',
            'toArray' => 'toArray',
            default => null,
        };
        if ($helper === null) {
            return null;
        }
        if ($expr->args !== []) {
            $this->fatalError($expr, "The {$method} method does not accept parameters");
        }
        $this->markPythonRuntimeUsed();
        return 'php::python::' . $helper . '(' . $receiver . ')';
    }

    protected function parsePythonObjectCall(Expr\FuncCall $expr): ?string
    {
        if (!$expr->name instanceof NodeAbstract || !$this->isPythonObjectExpr($expr->name)) {
            return null;
        }
        if ($expr->isFirstClassCallable()) {
            $this->fatalError($expr, 'Python objects do not support first-class callable syntax yet');
        }

        $receiver = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $receiver . ' = ' . $this->parseExpr($expr->name) . ';';
        if ($expr->args === []) {
            return 'php::python::call(' . $receiver . ')';
        }
        return 'php::python::call(' . $receiver . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function parsePythonObjectPropertyFetch(Expr\PropertyFetch $expr): ?string
    {
        if (
            $this->isPropertyFetchUpdate($expr)
            || !$this->isIdExpr($expr->name)
            || !$this->isPythonObjectExpr($expr->var)
        ) {
            return null;
        }

        return 'php::python::getAttr(' . $this->parseIdentifier($expr->var) . ', '
            . $this->propertyNameToStr($expr->name, literal: true) . ')';
    }

    protected function getPythonBinaryOperator(Expr\BinaryOp $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\BinaryOp\Plus => 'add',
            $expr instanceof Expr\BinaryOp\Minus => 'sub',
            $expr instanceof Expr\BinaryOp\Mul => 'mul',
            $expr instanceof Expr\BinaryOp\Div => 'truediv',
            $expr instanceof Expr\BinaryOp\Mod => 'mod',
            $expr instanceof Expr\BinaryOp\Pow => 'pow',
            $expr instanceof Expr\BinaryOp\ShiftLeft => 'lshift',
            $expr instanceof Expr\BinaryOp\ShiftRight => 'rshift',
            $expr instanceof Expr\BinaryOp\BitwiseAnd => 'and_',
            $expr instanceof Expr\BinaryOp\BitwiseOr => 'or_',
            $expr instanceof Expr\BinaryOp\BitwiseXor => 'xor',
            $expr instanceof Expr\BinaryOp\Equal => 'eq',
            $expr instanceof Expr\BinaryOp\NotEqual => 'ne',
            $expr instanceof Expr\BinaryOp\Smaller => 'lt',
            $expr instanceof Expr\BinaryOp\SmallerOrEqual => 'le',
            $expr instanceof Expr\BinaryOp\Greater => 'gt',
            $expr instanceof Expr\BinaryOp\GreaterOrEqual => 'ge',
            $expr instanceof Expr\BinaryOp\Identical => 'is_',
            $expr instanceof Expr\BinaryOp\NotIdentical => 'is_not',
            default => null,
        };
    }

    protected function isPythonBinaryOperatorExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\BinaryOp
            && $this->getPythonBinaryOperator($expr) !== null
            && ($this->isPythonObjectExpr($expr->left) || $this->isPythonObjectExpr($expr->right));
    }

    protected function pythonOperatorReturnsBool(Expr\BinaryOp $expr): bool
    {
        return $expr instanceof Expr\BinaryOp\Equal
            || $expr instanceof Expr\BinaryOp\NotEqual
            || $expr instanceof Expr\BinaryOp\Smaller
            || $expr instanceof Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Expr\BinaryOp\Greater
            || $expr instanceof Expr\BinaryOp\GreaterOrEqual
            || $expr instanceof Expr\BinaryOp\Identical
            || $expr instanceof Expr\BinaryOp\NotIdentical;
    }

    /**
     * Lower a Python operation through the standard operator module.
     * Braced ArgList elements are sequenced by C++17; call operands that can
     * emit statements are materialized first by parseOrderedOperand().
     */
    protected function parsePythonBinaryOperator(Expr\BinaryOp $expr): ?string
    {
        if (!$this->isPythonBinaryOperatorExpr($expr)) {
            return null;
        }

        $left = $this->parseOrderedOperand($expr->left, false);
        $right = $this->parseOrderedOperand($expr->right, false);
        $call = 'php::python::callMember(' . $this->getPythonModuleExpression('operator')
            . ', ' . $this->getLiteralString($this->getPythonBinaryOperator($expr))
            . ', php::ArgList{' . $left . ', ' . $right . '})';

        return $this->pythonOperatorReturnsBool($expr)
            ? $this->convertPythonResultToBool($call)
            : $call;
    }

    protected function convertPythonResultToBool(string $expression): string
    {
        $this->markPythonRuntimeUsed();
        $scalar = 'php::python::toValue(' . $expression . ')';
        return 'php::toBool(' . $scalar . ')';
    }

    protected function detectPythonOperatorReturnType(NodeAbstract $expr): ?string
    {
        if ($this->isPythonBinaryOperatorExpr($expr)) {
            return $this->pythonOperatorReturnsBool($expr) ? Type::BOOL : Type::OBJECT;
        }
        if ($this->isPythonUnaryOperatorExpr($expr)) {
            return $expr instanceof Expr\BooleanNot ? Type::BOOL : Type::OBJECT;
        }
        return null;
    }

    protected function detectPythonOperatorReturnClass(NodeAbstract $expr): ?string
    {
        if ($this->isPythonBinaryOperatorExpr($expr)) {
            return $this->pythonOperatorReturnsBool($expr) ? null : 'PyObject';
        }
        if ($this->isPythonUnaryOperatorExpr($expr)) {
            return $expr instanceof Expr\BooleanNot ? null : 'PyObject';
        }
        return null;
    }

    protected function getPythonUnaryOperator(NodeAbstract $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\UnaryMinus => 'neg',
            $expr instanceof Expr\UnaryPlus => 'pos',
            $expr instanceof Expr\BitwiseNot => 'invert',
            $expr instanceof Expr\BooleanNot => 'not_',
            default => null,
        };
    }

    protected function isPythonUnaryOperatorExpr(NodeAbstract $expr): bool
    {
        return $this->getPythonUnaryOperator($expr) !== null
            && $this->isPythonObjectExpr($expr->expr);
    }

    protected function parsePythonUnaryOperator(NodeAbstract $expr): ?string
    {
        if (!$this->isPythonUnaryOperatorExpr($expr)) {
            return null;
        }

        $operand = $this->parseOrderedOperand($expr->expr, false);
        $call = 'php::python::callMember(' . $this->getPythonModuleExpression('operator')
            . ', ' . $this->getLiteralString($this->getPythonUnaryOperator($expr))
            . ', php::ArgList{' . $operand . '})';
        return $expr instanceof Expr\BooleanNot ? $this->convertPythonResultToBool($call) : $call;
    }

    protected function convertPythonObjectToBool(NodeAbstract $expr, string $parsed): ?string
    {
        if (!$this->isPythonObjectExpr($expr)) {
            return null;
        }
        $call = 'php::python::callMember(' . $this->getPythonModuleExpression('operator')
            . ', ' . $this->getLiteralString('truth') . ', php::ArgList{' . $parsed . '})';
        return $this->convertPythonResultToBool($call);
    }

    protected function getPythonAssignOperator(Expr\AssignOp $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\AssignOp\Plus => 'iadd',
            $expr instanceof Expr\AssignOp\Minus => 'isub',
            $expr instanceof Expr\AssignOp\Mul => 'imul',
            $expr instanceof Expr\AssignOp\Div => 'itruediv',
            $expr instanceof Expr\AssignOp\Mod => 'imod',
            $expr instanceof Expr\AssignOp\Pow => 'ipow',
            $expr instanceof Expr\AssignOp\ShiftLeft => 'ilshift',
            $expr instanceof Expr\AssignOp\ShiftRight => 'irshift',
            $expr instanceof Expr\AssignOp\BitwiseAnd => 'iand',
            $expr instanceof Expr\AssignOp\BitwiseOr => 'ior',
            $expr instanceof Expr\AssignOp\BitwiseXor => 'ixor',
            default => null,
        };
    }

    /**
     * Lower Python compound assignments through operator.i*(). The target
     * receiver and key are materialized before the RHS, then the returned
     * Python object is written back through the original PHP lvalue protocol.
     */
    protected function parsePythonAssignOperator(Expr\AssignOp $expr): ?string
    {
        $method = $this->getPythonAssignOperator($expr);
        if ($method === null || !$this->isPythonObjectExpr($expr->var)) {
            return null;
        }

        $writeBack = null;
        if ($this->isVarExpr($expr->var)) {
            $left = $this->parseWritableIdentifier($expr->var);
            $writeBack = static fn(string $value): string => $left . ' = ' . $value;
        } elseif ($expr->var instanceof Expr\ArrayDimFetch) {
            if ($expr->var->dim === null) {
                $this->fatalError($expr->var, 'Cannot use [] for a Python compound assignment');
            }
            $container = $this->parseOrderedOperand($expr->var->var, false);
            $key = $this->parseOrderedOperand($expr->var->dim, false);
            $left = $container . '.item(' . $key . ', false)';
            $writeBack = static fn(string $value): string => $container . '.offsetSet(' . $key . ', ' . $value . ')';
        } elseif ($expr->var instanceof Expr\PropertyFetch && $this->isIdExpr($expr->var->name)) {
            $receiver = $this->parseOrderedOperand($expr->var->var, false);
            $property = $this->propertyNameToStr($expr->var->name, literal: true);
            $left = 'php::python::getAttr(' . $receiver . ', ' . $property . ')';
            $writeBack = static fn(string $value): string => 'typephp_write_property_scoped('
                . $receiver . ', ' . $property . ', ' . $value . ', nullptr)';
        } else {
            return null;
        }

        $right = $this->parseOrderedOperand($expr->expr, false);
        $call = 'php::python::callMember(' . $this->getPythonModuleExpression('operator')
            . ', ' . $this->getLiteralString($method)
            . ', php::ArgList{' . $left . ', ' . $right . '})';
        if ($this->isVarExpr($expr->var)) {
            return $writeBack($call);
        }

        $result = $this->addTmpVar(Type::OBJECT);
        return '((' . $result . ' = ' . $call . ', ' . $writeBack($result) . '), ' . $result . ')';
    }

    /**
     * Resolve a PHP name as a Python module only after normal namespace resolution.
     */
    protected function resolvePythonModule(NodeAbstract $class): ?string
    {
        if (!$this->isNameExpr($class)) {
            return null;
        }

        $parts = $this->resolvePythonRootNameParts($class);
        if ($parts === null) {
            return null;
        }
        if (count($parts) < 2 || $parts[1] === '') {
            $this->fatalError($class, 'The special `python` namespace must be followed by a module name');
        }

        // Preserve the case of every Python symbol after the special root.
        return implode('.', array_slice($parts, 1));
    }

    /**
     * Resolve a source name according to PHP namespace rules before checking the Python root.
     *
     * @return list<string>|null
     */
    protected function resolvePythonRootNameParts(NodeAbstract $name): ?array
    {
        if (!$this->isNameExpr($name)) {
            return null;
        }

        $sourceName = trim($this->parseIdentifier($name), '\\');
        $resolvedName = $name->getAttribute('resolvedName');
        if ($resolvedName instanceof Node\Name) {
            $resolved = $resolvedName->toString();
        } elseif ($name instanceof Node\Name\FullyQualified || $this->namespace === '') {
            $resolved = $sourceName;
        } else {
            $resolved = $this->namespace . '\\' . $sourceName;
        }

        $parts = explode('\\', trim($resolved, '\\'));
        if (strcasecmp($parts[0] ?? '', 'python') !== 0) {
            return null;
        }
        return $parts;
    }

    protected function getPythonModuleId(string $module): int
    {
        if (isset($this->pythonModuleMap[$module])) {
            return $this->pythonModuleMap[$module];
        }

        $id = $this->pythonModuleIndex++;
        $this->pythonModuleMap[$module] = $id;

        $this->markPythonRuntimeUsed();
        $this->getLiteralString($module);

        return $id;
    }

    protected function markPythonRuntimeUsed(): void
    {
        if ($this->pythonRuntimeUsed) {
            return;
        }
        $this->pythonRuntimeUsed = true;
    }

    protected function withPythonRuntimeConfigured(string $expression): string
    {
        return '(' . self::PREFIX . 'configure_python_runtime(), ' . $expression . ')';
    }

    protected function getPythonModuleExpression(string $module): string
    {
        return 'php_get_python_module(' . $this->getPythonModuleId($module) . ', '
            . $this->getLiteralString($module) . ')';
    }

    protected function resolvePythonBuiltinName(NodeAbstract $name): ?string
    {
        if (!$this->isNameExpr($name) && !$this->isFullNameExpr($name)) {
            return null;
        }
        $parts = $this->resolvePythonRootNameParts($name);
        if ($parts === null) {
            return null;
        }
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }
        return $parts[1];
    }

    /**
     * Resolve namespace syntax into a Python module and one of its members.
     *
     * @return array{module: string, member: string}|null
     */
    protected function resolvePythonModuleMember(NodeAbstract $name): ?array
    {
        if (!$this->isNameExpr($name)) {
            return null;
        }

        $parts = $this->resolvePythonRootNameParts($name);
        if ($parts === null || count($parts) < 3) {
            return null;
        }

        $member = array_pop($parts);
        return [
            'module' => implode('.', array_slice($parts, 1)),
            'member' => $member,
        ];
    }

    protected function parsePythonFunctionCall(Expr\FuncCall $expr): ?string
    {
        $moduleMember = $this->resolvePythonModuleMember($expr->name);
        if ($moduleMember !== null) {
            if ($expr->isFirstClassCallable()) {
                $this->fatalError($expr, 'Python module callables do not support first-class callable syntax yet');
            }
            $target = $this->getPythonModuleExpression($moduleMember['module']);
            $member = $this->getLiteralString($moduleMember['member']);
            if ($expr->args === []) {
                return 'php::python::callMember(' . $target . ', ' . $member . ')';
            }
            return 'php::python::callMember(' . $target . ', ' . $member . ', '
                . $this->parseCallArgs($expr->args) . ')';
        }

        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($expr->isFirstClassCallable()) {
            $this->fatalError($expr, 'Python builtins do not support first-class callable syntax yet');
        }
        $this->markPythonRuntimeUsed();

        if ($builtin === 'scalar') {
            if (count($expr->args) !== 1 || $expr->args[0]->name !== null || $expr->args[0]->unpack) {
                $this->fatalError($expr, 'python\\scalar() expects exactly one positional argument');
            }
            return 'php::python::toValue(' . $this->parseCallArgValue($expr->args[0]) . ')';
        }

        $nativeConstructor = self::PYTHON_NATIVE_CONSTRUCTORS[$builtin] ?? null;
        if ($nativeConstructor !== null && $this->canUsePythonNativeConstructor($expr)) {
            $constructor = 'php::python::Constructor::' . $nativeConstructor;
            $call = $expr->args === []
                ? 'php::python::construct(' . $constructor . ')'
                : 'php::python::construct(' . $constructor . ', '
                . $this->parseCallArgValue($expr->args[0]) . ')';
            return $this->withPythonRuntimeConfigured($call);
        }

        // This is a deliberately closed map. In particular, PyDict's PHP-array
        // constructor is not equivalent to Python's dict(iterable) builtin.
        $constructorClass = self::PYTHON_CONSTRUCTOR_CLASSES[$builtin] ?? null;
        if ($constructorClass !== null) {
            $classEntry = $this->getClassEntryPtr($constructorClass);
            if ($expr->args === []) {
                return $this->withPythonRuntimeConfigured('php::newObject(' . $classEntry . ')');
            }
            return $this->withPythonRuntimeConfigured(
                'php::newObject(' . $classEntry . ', '
                    . $this->parseCallArgs($expr->args, '__construct', $constructorClass) . ')'
            );
        }

        // Explicit PyCore methods either preserve a Python wrapper by design,
        // or (`scalar`) explicitly leave Python's object-preserving rules.
        if (isset(self::PYTHON_CORE_FUNCTIONS[$builtin])) {
            $callable = $this->getClassEntryPtr('PyCore') . ', '
                . $this->getMethodPtr('PyCore', $builtin);
            if ($expr->args === []) {
                return $this->withPythonRuntimeConfigured('php::call(' . $callable . ')');
            }
            return $this->withPythonRuntimeConfigured(
                $this->genRuntimeFunctionCall($callable, $expr->args, $builtin, 'PyCore')
            );
        }
        $target = $this->getPythonModuleExpression('builtins');
        $name = $this->getLiteralString($builtin);
        if ($expr->args === []) {
            return 'php::python::callMember(' . $target . ', ' . $name . ')';
        }
        return 'php::python::callMember(' . $target . ', ' . $name . ', '
            . $this->parseCallArgs($expr->args) . ')';
    }

    /**
     * The Native ABI has a deliberately small zero/one positional argument
     * constructor path. Keep named and unpacked calls on Zend so its regular
     * argument validation and unpacking semantics remain unchanged.
     */
    private function canUsePythonNativeConstructor(Expr\FuncCall $expr): bool
    {
        if (count($expr->args) > 1) {
            return false;
        }
        foreach ($expr->args as $arg) {
            if ($arg->name !== null || $arg->unpack) {
                return false;
            }
        }
        return true;
    }

    protected function parsePythonModuleAttributeFetch(Expr\ConstFetch $expr, bool $constantExpression): ?string
    {
        $moduleMember = $this->resolvePythonModuleMember($expr->name);
        if ($moduleMember === null) {
            return null;
        }
        if ($constantExpression) {
            $this->fatalError($expr, 'Python module attributes cannot be used in constant expressions');
        }

        return 'php::python::getAttr(' . $this->getPythonModuleExpression($moduleMember['module'])
            . ', ' . $this->getLiteralString($moduleMember['member']) . ')';
    }

    protected function detectPythonExpressionReturnType(NodeAbstract $expr): ?string
    {
        if ($expr instanceof Expr\ConstFetch && $this->resolvePythonModuleMember($expr->name) !== null) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\MethodCall && $this->isPythonObjectExpr($expr->var)) {
            if (
                !$this->isIdExpr($expr->name)
                || $this->isPythonDynamicMethodCall($expr->var, $this->parseIdentifier($expr->name))
            ) {
                return Type::OBJECT;
            }
            return null;
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isPythonObjectExpr($expr->var)) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\ArrayDimFetch && $this->isPythonObjectExpr($expr->var)) {
            return Type::OBJECT;
        }
        if (
            $expr instanceof Expr\FuncCall
            && $expr->name instanceof NodeAbstract
            && !$this->isNameExpr($expr->name)
            && $this->isPythonObjectExpr($expr->name)
        ) {
            return Type::OBJECT;
        }
        if (!$expr instanceof Expr\FuncCall) {
            return null;
        }
        if ($this->resolvePythonModuleMember($expr->name) !== null) {
            return Type::OBJECT;
        }
        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($builtin === 'scalar') {
            return Type::VAR;
        }
        return Type::OBJECT;
    }

    protected function detectPythonExpressionReturnClass(NodeAbstract $expr): ?string
    {
        if ($expr instanceof Expr\ConstFetch && $this->resolvePythonModuleMember($expr->name) !== null) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\MethodCall && $this->isPythonObjectExpr($expr->var)) {
            if (
                !$this->isIdExpr($expr->name)
                || $this->isPythonDynamicMethodCall($expr->var, $this->parseIdentifier($expr->name))
            ) {
                return 'PyObject';
            }
            return null;
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isPythonObjectExpr($expr->var)) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\ArrayDimFetch && $this->isPythonObjectExpr($expr->var)) {
            return 'PyObject';
        }
        if (
            $expr instanceof Expr\FuncCall
            && $expr->name instanceof NodeAbstract
            && !$this->isNameExpr($expr->name)
            && $this->isPythonObjectExpr($expr->name)
        ) {
            return 'PyObject';
        }
        if (!$expr instanceof Expr\FuncCall) {
            return null;
        }
        if ($this->resolvePythonModuleMember($expr->name) !== null) {
            return 'PyObject';
        }
        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($builtin === 'scalar') {
            return null;
        }
        return self::PYTHON_BUILTIN_RETURN_CLASSES[$builtin] ?? 'PyObject';
    }

    protected function parsePythonModuleStaticCall(Expr\StaticCall $expr): ?string
    {
        if (!$this->isIdExpr($expr->name)) {
            return null;
        }
        $module = $this->resolvePythonModule($expr->class);
        if ($module === null) {
            return null;
        }

        $alias = $this->parseIdentifier($expr->class);
        $method = $this->parseIdentifier($expr->name);
        $this->fatalError(
            $expr,
            "Python module callable `{$alias}::{$method}()` must use `{$alias}\\{$method}()`",
        );
    }

    protected function parsePythonModuleStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?string
    {
        if (!$this->isIdExpr($expr->name)) {
            return null;
        }
        $module = $this->resolvePythonModule($expr->class);
        if ($module === null) {
            return null;
        }

        $alias = $this->parseIdentifier($expr->class);
        $attribute = $this->parseIdentifier($expr->name);
        $this->fatalError(
            $expr,
            "Python module attribute `{$alias}::\${$attribute}` must use `{$alias}\\{$attribute}`",
        );
    }

    protected function rejectPythonModuleClassConstantFetch(Expr\ClassConstFetch $expr): void
    {
        if (!$this->isIdExpr($expr->name) || $this->resolvePythonModule($expr->class) === null) {
            return;
        }

        $alias = $this->parseIdentifier($expr->class);
        $member = $this->parseIdentifier($expr->name);
        $this->fatalError(
            $expr,
            "Python module member `{$alias}::{$member}` must use `{$alias}\\{$member}`",
        );
    }

    protected function genPythonModuleDataDeclarations(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = 'extern THREAD_LOCAL bool ' . self::PREFIX . 'python_runtime_configured;' . PHP_EOL
            . 'void ' . self::PREFIX . 'configure_python_runtime();' . PHP_EOL;
        if ($this->pythonModuleMap !== []) {
            $code .= 'extern THREAD_LOCAL zval ' . self::PREFIX . 'python_module_map['
                . count($this->pythonModuleMap) . '];' . PHP_EOL
                . 'php::Object ' . self::PREFIX
                . 'get_python_module(int module_id, const php::Str &module_name);' . PHP_EOL;
        }
        return $code;
    }

    protected function genPythonModuleStorage(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = "// python runtime \n"
            . 'THREAD_LOCAL bool ' . self::PREFIX . 'python_runtime_configured = false;' . PHP_EOL;
        if ($this->pythonModuleMap !== []) {
            $code .= 'THREAD_LOCAL zval ' . self::PREFIX . 'python_module_map['
                . count($this->pythonModuleMap) . ']{};' . PHP_EOL;
        }
        return $code;
    }

    protected function genPythonModuleGetter(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = 'void ' . self::PREFIX . 'configure_python_runtime() {' . PHP_EOL
            . 'if (EXPECTED(' . self::PREFIX . 'python_runtime_configured)) {' . PHP_EOL
            . 'return;' . PHP_EOL
            . '}' . PHP_EOL
            . 'php::python::configureRuntime(true);' . PHP_EOL
            . self::PREFIX . 'python_runtime_configured = true;' . PHP_EOL
            . '}' . PHP_EOL . PHP_EOL;

        if ($this->pythonModuleMap === []) {
            return $code;
        }

        return $code
            . 'php::Object ' . self::PREFIX
            . 'get_python_module(int module_id, const php::Str &module_name) {' . PHP_EOL
            . self::PREFIX . 'configure_python_runtime();' . PHP_EOL
            . 'zval *module = &' . self::PREFIX . 'python_module_map[module_id];' . PHP_EOL
            . 'if (UNEXPECTED(Z_ISUNDEF_P(module))) {' . PHP_EOL
            . 'php::Variant imported = php::python::importModule(module_name);' . PHP_EOL
            . '(void) php::Object(imported);' . PHP_EOL
            . 'imported.moveTo(module);' . PHP_EOL
            . '}' . PHP_EOL
            . 'return php::Object(module);' . PHP_EOL
            . '}' . PHP_EOL . PHP_EOL;
    }

    protected function genPythonModuleCleanup(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = '';
        if ($this->pythonModuleMap !== []) {
            $code .= 'for (zval &module : ' . self::PREFIX . 'python_module_map) {' . PHP_EOL
                . 'if (!Z_ISUNDEF(module)) {' . PHP_EOL
                . 'zval_ptr_dtor(&module);' . PHP_EOL
                . 'ZVAL_UNDEF(&module);' . PHP_EOL
                . '}' . PHP_EOL
                . '}' . PHP_EOL;
        }
        return $code . self::PREFIX . 'python_runtime_configured = false;' . PHP_EOL;
    }
}
