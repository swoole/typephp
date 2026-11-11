<?php

namespace TypePhp\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeAbstract;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\TypedArrayPropertyWritePlan;
use TypePhp\Transform\CompileTimeAttribute;
use TypePhp\Type;

/** Compiler-enforced PHP-array contracts. Storage and COW remain php::Array's. */
trait TypedArrayTrait
{
    protected function parseTypedArrayPropertyDefinition(Node\Stmt\Property $property): ?array
    {
        foreach (['StdList' => 'list', 'StdDict' => 'dict'] as $name => $kind) {
            $attribute = CompileTimeAttribute::find($property, $name);
            if ($attribute !== null) {
                $this->validateStdAttributeType($property, $name, 'array');
                return $this->parseTypedArrayDefinition($kind, $attribute->args, $attribute);
            }
        }
        return null;
    }

    protected function prepareTypedArrayPropertyDirectWrite(
        Expr\ArrayDimFetch $left,
        Expr $right,
        string $value,
    ): ?TypedArrayPropertyWritePlan {
        $definition = $this->getNativePropertyDef($left->var)?->typedArray;
        if ($definition === null) {
            return null;
        }
        $value = $this->guardTypedArrayValue($definition, $right, $value);
        if ($left->dim === null) {
            if ($definition['kind'] !== 'list') {
                $this->fatalError($left, 'StdDict properties do not support append writes');
            }
            return new TypedArrayPropertyWritePlan(true, null, $value);
        }
        $key = $this->guardTypedArrayValue($definition, $left->dim, $this->parseExprAsValue($left->dim), true);
        return new TypedArrayPropertyWritePlan(false, $key, $value);
    }

    protected function parseTypedArrayDefinition(string $kind, array $args, NodeAbstract $owner): array
    {
        $count = $kind === 'list' ? 1 : 2;
        if (count($args) !== $count) {
            $this->fatalError($owner, "Std{$kind} expects {$count} type argument(s)");
        }
        foreach ($args as $arg) {
            if ($arg->name !== null || $arg->unpack || $arg->byRef) {
                $this->fatalError($arg, 'Typed arrays require positional type arguments');
            }
        }
        $key = $kind === 'list' ? Type::INT : $this->parseStdMapKeyType($args[0]->value, 'std::dict');
        $value = $this->parseStdValueTypeInfo($args[$count - 1]->value, 'std::' . $kind);
        if (!in_array($value['type'], [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY, Type::OBJECT, Type::VAR], true)) {
            $this->fatalError($owner, 'Typed PHP arrays only support PHP value types or ClassName::class');
        }
        if ($this->isNativeObjectClass($value['class'] ?? '')) {
            $this->fatalError($owner, 'Typed PHP arrays cannot hold Native objects');
        }
        // Integer-key lists/dicts share PHP storage; only list grants append.
        return ['kind' => $kind, 'keyType' => $key, 'type' => $value['type'], 'class' => $value['class']];
    }

    protected function parseTypedArrayFactoryDefinition(string $kind, Expr\StaticCall $call): array
    {
        $initializer = $this->getStdValueInitializer($call);
        if ($initializer === null) {
            return $this->parseTypedArrayDefinition($kind, $call->args, $call);
        }

        $inferred = $this->inferStdFlatInitializer(
            $initializer,
            'std::' . $kind,
            $kind === 'list' ? 'integer' : 'map',
        );
        if (!in_array($inferred['type'], [
            Type::INT,
            Type::FLOAT,
            Type::BOOL,
            Type::STR,
            Type::ARRAY,
            Type::OBJECT,
        ], true)) {
            $this->fatalError($call, 'Typed PHP array value initialization only supports concrete PHP value types');
        }
        if ($this->isNativeObjectClass($inferred['class'] ?? '')) {
            $this->fatalError($call, 'Typed PHP arrays cannot hold Native objects');
        }
        return [
            'kind' => $kind,
            'keyType' => $kind === 'list' ? Type::INT : $inferred['keyType'],
            'type' => $inferred['type'],
            'class' => $inferred['class'],
        ];
    }

    protected function parseTypedArrayParameterDefinition(Node\Param $param): ?array
    {
        foreach (['StdList' => 'list', 'StdDict' => 'dict'] as $name => $kind) {
            $attribute = CompileTimeAttribute::find($param, $name);
            if ($attribute === null) {
                continue;
            }
            $this->validateStdAttributeType($param, $name, 'array');
            if ($param->variadic || $param->default !== null || $param->isPromoted()) {
                $this->fatalError($param, $name . ' does not support variadic, defaulted or promoted parameters');
            }
            return $this->parseTypedArrayDefinition($kind, $attribute->args, $attribute);
        }
        return null;
    }

    protected function getTypedArrayDefinition(NodeAbstract $expr): ?array
    {
        if ($expr instanceof Expr\ErrorSuppress) {
            return $this->getTypedArrayDefinition($expr->expr);
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            return $this->getTypedArrayDefinition($expr->expr);
        }
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $kind = match ($expr->name->name) {
                'toStdList' => 'list',
                'toStdDict' => 'dict',
                default => null,
            };
            if ($kind !== null) {
                return $this->parseTypedArrayDefinition($kind, $expr->args, $expr);
            }
        }
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier && $this->isStdClassExpr($expr->class)
            && in_array(strtolower($expr->name->name), ['list', 'dict'], true)) {
            return $this->parseTypedArrayFactoryDefinition(strtolower($expr->name->name), $expr);
        }
        if ($this->isVarExpr($expr)) {
            return $this->context->getTypedArrayContract($this->parseIdentifier($expr));
        }
        return null;
    }

    protected function parseTypedArrayConversionCall(Expr\MethodCall $call): string
    {
        $kind = $call->name->toString() === 'toStdList' ? 'list' : 'dict';
        $definition = $this->parseTypedArrayDefinition($kind, $call->args, $call);
        if ($this->isVarExpr($call->var)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects($call, $this->parseIdentifier($call->var));
        }
        // A matching typed array already satisfies the contract. Preserve
        // ordinary PHP array assignment and its copy-on-write behavior.
        if ($this->getTypedArrayDefinition($call->var) === $definition) {
            return $this->parseExprAsValue($call->var);
        }

        $stdContainer = $this->isVarExpr($call->var)
            && $this->isStdContainer($this->parseIdentifier($call->var));
        if ($this->detectTypeOfExpr($call->var) === Type::ARRAY && !$stdContainer) {
            $source = $this->parseExprAsValue($call->var);
        } else {
            $class = $this->detectClassOfExpr($call->var);
            if ($this->isNativeObjectClass($class)) {
                // Native objects use their declared toArray() method; their
                // pointer cannot be passed to PHPX's dynamic conversion.
                $source = $this->parseExprAsValue(new Expr\MethodCall($call->var, new Node\Identifier('toArray')));
            } else {
                $source = 'php::toArray(' . $this->parseExprAsValue($call->var) . ')';
            }
        }
        $valueType = match ($definition['type']) {
            Type::INT => 'Int',
            Type::FLOAT => 'Float',
            Type::BOOL => 'Bool',
            Type::STR => 'String',
            Type::ARRAY => 'Array',
            Type::OBJECT => 'Object',
            Type::VAR => 'Any',
            default => throw new \LogicException('Unknown typed array value type: ' . $definition['type']),
        };
        $valueClass = $definition['class'] !== null && $definition['class'] !== ''
            ? $this->getClassEntryPtr($definition['class'])
            : 'nullptr';
        return 'php::toTypedArray(' . $source . ', '
            . ($definition['keyType'] === Type::STR ? 'true' : 'false') . ', '
            . 'php::TypedArrayValueType::' . $valueType . ', ' . $valueClass . ')';
    }

    protected function getTypedArrayAccessDefinition(NodeAbstract $expr): ?array
    {
        return $expr instanceof Expr\ArrayDimFetch ? $this->getTypedArrayDefinition($expr->var) : null;
    }

    protected function getTypedArrayPropertyDefinition(NodeAbstract $expr): ?array
    {
        if (!$expr instanceof Expr\PropertyFetch && !$expr instanceof Expr\StaticPropertyFetch) {
            return null;
        }
        if ($this->containsNullsafeChain($expr)) {
            return null;
        }
        $property = $this->findReadablePropertyDefinition($expr, true);
        return $property !== null && $property->getter === null && $property->setter === null
            ? $property->typedArray : null;
    }

    protected function getTypedArrayReadDefinition(NodeAbstract $expr): ?array
    {
        if (!$expr instanceof Expr\ArrayDimFetch || $expr->dim === null) {
            return null;
        }
        return $this->getTypedArrayAccessDefinition($expr) ?? $this->getTypedArrayPropertyDefinition($expr->var);
    }

    protected function getTypedArrayPropertyReadDefinition(NodeAbstract $expr): ?array
    {
        return $expr instanceof Expr\ArrayDimFetch && $expr->dim !== null
            ? $this->getTypedArrayPropertyDefinition($expr->var) : null;
    }

    protected function assertTypedArrayReferenceForbidden(NodeAbstract $expr): void
    {
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }
        if ($this->getTypedArrayDefinition($expr) !== null || $this->getTypedArrayPropertyDefinition($expr) !== null) {
            $this->fatalError($expr, 'Typed arrays cannot escape through std::ref(), toRef(), or element references');
        }
    }

    protected function parseTypedArrayAssignment(Expr $left, Expr $right): ?string
    {
        if ($this->getTypedArrayAccessDefinition($left) !== null) {
            return $this->parseTypedArrayWrite($left, $right);
        }
        // Nested writes would bypass the declared outer element type.
        if ($left instanceof Expr\ArrayDimFetch && $left->var instanceof Expr\ArrayDimFetch
            && $this->getTypedArrayAccessDefinition($left->var) !== null) {
            $this->fatalError($left, 'Nested typed-array writes must replace a checked element');
        }
        if (!$this->isVarExpr($left)) {
            return null;
        }
        $name = $this->parseWritableIdentifier($left);
        $factory = $right instanceof Expr\StaticCall && $right->class instanceof Node\Name
            && $right->name instanceof Node\Identifier && $this->isStdClassExpr($right->class)
            && in_array(strtolower($right->name->name), ['list', 'dict'], true);
        $definition = $factory
            ? $this->parseTypedArrayFactoryDefinition(strtolower($right->name->name), $right)
            : $this->getTypedArrayDefinition($right);
        $existing = $this->context->getTypedArrayContract($name);
        if ($definition === null && $existing === null) {
            return null;
        }
        if ($existing !== null && $definition !== $existing) {
            $this->fatalError($right, 'Typed array assignment requires an identical list/dict contract');
        }
        if ($this->hasScopeGlobalVar($name) || $this->hasStaticVar($name)
            || isset($this->context->varTypeDegradations[$name])) {
            $this->fatalError($left, 'Typed arrays require fixed function-local storage and cannot be captured by reference');
        }
        if ($factory && $this->hasVar($name)) {
            $this->fatalError($left, 'Typed array factories require a new variable');
        }
        if ($existing === null) {
            if ($this->hasVar($name) && $this->getRawVarType($name) !== Type::ARRAY) {
                $this->fatalError($left, 'Cannot erase a typed array into dynamic or reference storage');
            }
            // Do not retrofit a contract onto an already-live ordinary array:
            // existing aliases or previous paths may still write untyped values.
            if ($this->hasVar($name)) {
                $this->fatalError($left, 'Typed array propagation requires a new variable');
            }
            $this->addLocalVar($name, Type::ARRAY);
            $this->context->registerTypedArrayContract($name, $definition);
        }
        if ($factory) {
            $initializer = $this->getStdValueInitializer($right);
            return $name . ' = ' . ($initializer === null ? 'php::Array{}' : $this->parseArray($initializer));
        }
        return $name . ' = ' . $this->parseExprAsValue($right);
    }

    protected function guardTypedArrayValue(array $def, Expr $expr, string $code, bool $key = false): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'typed array element');
        $expected = $key ? $def['keyType'] : $def['type'];
        $class = $key ? '' : ($def['class'] ?? '');
        $actual = Type::getReferencedType($this->detectTypeOfExpr($expr));
        if ($this->isNativeObjectClass($this->detectClassOfExpr($expr))) {
            $this->fatalError($expr, 'Native objects cannot be stored in typed PHP arrays');
        }
        if ($key && $actual === Type::VAR) {
            // Dynamic keys need a strict check, not a coercing conversion.
            // These are internal PHPX helpers, not user keyword methods.
            return ($expected === Type::INT ? 'php::toIntExact(' : 'php::toStringExact(') . $code . ')';
        }
        if (!$key && $expected === Type::VAR) {
            return $code;
        }
        if ($class !== '') {
            $actualClass = $this->detectClassOfExpr($expr);
            if ($actualClass === '' || !$this->isObjectClassStaticallyAssignableTo($actualClass, $class)) {
                $this->fatalError($expr, 'Typed array value must be an instance of ' . $class);
            }
            if ($actual !== Type::OBJECT) {
                $this->fatalError($expr, 'Typed array value must be an instance of ' . $class);
            }
            return $code;
        }
        if ($actual !== $expected) {
            $this->fatalError($expr, 'Typed array ' . ($key ? 'key' : 'value') . ' must have type ' . $expected);
        }
        if ($expected === Type::INT && $this->varIntTypes && $this->exprCanOverflowInt($expr)) {
            $this->fatalError($expr, 'Typed array integer expressions that may widen require an explicit toInt() or native integer conversion');
        }
        return $code;
    }

    protected function convertTypedArrayRead(array $def, string $code): string
    {
        // Recover the statically proven type; no Exact checks or element scans.
        return $this->convertExprFromType($def['type'], $code);
    }

    /**
     * Whole-property replacement and dynamic Zend writes can bypass the
     * annotation. Check only the element being consumed; no array scan or
     * trusted contract is attached to a copy of the property.
     */
    protected function convertTypedArrayPropertyValue(string $type, string $class, string $code): string
    {
        $helper = match ($type) {
            Type::INT => 'php::toIntExact',
            Type::FLOAT => 'php::toFloatExact',
            Type::BOOL => 'php::toBoolExact',
            Type::STR => 'php::toStringExact',
            Type::ARRAY => 'php::toArrayExact',
            Type::OBJECT => 'php::toObjectExact',
            default => null,
        };
        if ($helper === null) {
            return $code;
        }
        $expectedClass = $class !== '' ? ', ' . $this->getClassEntryPtr($class) : '';
        return $helper . '(' . $code . $expectedClass . ')';
    }

    protected function parseTypedArrayPropertyRead(Expr\ArrayDimFetch $expr, array $definition): string
    {
        $property = $expr->var;
        $orderedKey = $this->shouldMaterializeOrderedOperand($expr->dim);
        if ($orderedKey && $property instanceof Expr\PropertyFetch) {
            $property = $this->stageTypedArrayReadReceiver($property);
        }
        $key = $this->guardTypedArrayValue($definition, $expr->dim, $this->parseExprAsValue($expr->dim), true);
        if ($orderedKey) {
            $keyVar = $this->addTmpVar($definition['keyType']);
            $this->materializeStatementTemporary($keyVar, $key);
            $key = $keyVar;
        }
        $array = $this->parseIdentifier($property);
        $value = $this->parenthesizeOpenOperand($array) . '.offsetGet(' . $key . ')';
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, $definition['type']);
        return $this->convertTypedArrayPropertyValue($definition['type'], $definition['class'] ?? '', $value);
    }

    /**
     * Stage side effects used to address a property, but leave slot reads
     * after the outer key. The key may replace any slot along the path.
     */
    private function stageTypedArrayReadReceiver(Expr $expr): Expr
    {
        if ($expr instanceof Expr\PropertyFetch) {
            $receiver = $this->stageTypedArrayReadReceiver($expr->var);
            $member = $expr->name;
            if ($member instanceof Expr && $this->shouldMaterializeOrderedOperand($member)) {
                $member = new Expr\Variable($this->parseOrderedOperand($member, false, true));
            }
            return new Expr\PropertyFetch($receiver, $member, $expr->getAttributes());
        }
        if ($expr instanceof Expr\ArrayDimFetch) {
            $array = $this->stageTypedArrayReadReceiver($expr->var);
            $key = $expr->dim;
            if ($key !== null && $this->shouldMaterializeOrderedOperand($key)) {
                $key = new Expr\Variable($this->parseOrderedOperand($key, false, true));
            }
            return new Expr\ArrayDimFetch($array, $key, $expr->getAttributes());
        }
        if ($expr instanceof Expr\StaticPropertyFetch || !$this->shouldMaterializeOrderedOperand($expr)) {
            return $expr;
        }
        return new Expr\Variable($this->parseOrderedOperand($expr, false, true), $expr->getAttributes());
    }

    protected function parseTypedArrayKey(Expr\ArrayDimFetch $expr, bool $write = false): string
    {
        $def = $this->getTypedArrayAccessDefinition($expr);
        if ($expr->dim === null) {
            if (!$write || $def['kind'] !== 'list') {
                $this->fatalError($expr, 'Only typed lists support append writes');
            }
            return '';
        }
        $key = $this->guardTypedArrayValue($def, $expr->dim, $this->parseExprAsValue($expr->dim), true);
        return $key;
    }

    protected function parseTypedArrayRead(Expr\ArrayDimFetch $expr): string
    {
        $def = $this->getTypedArrayAccessDefinition($expr);
        return $this->convertTypedArrayRead($def, $this->parseTypedArrayRawRead($expr));
    }

    protected function parseTypedArrayRawRead(Expr\ArrayDimFetch $expr): string
    {
        $array = $this->parseIdentifier($expr->var);
        $key = $this->parseTypedArrayKey($expr);
        return $array . '.offsetGet(' . $key . ')';
    }

    protected function parseTypedArrayPresence(Expr\ArrayDimFetch $expr, string $op, bool $getValue): string
    {
        $raw = $this->parseTypedArrayRawRead($expr);
        if ($getValue) {
            $result = $this->addTmpVar(Type::VAR);
            $expr->setAttribute('chainOpResult', $result);
            $raw = '(' . $result . ' = ' . $raw . ')';
        }
        return match ($op) {
            self::OP_ISSET => 'php::exists(' . $raw . ')',
            self::OP_EMPTY => 'php::empty(' . $raw . ')',
            self::OP_NOT_EMPTY => 'php::notEmpty(' . $raw . ')',
            default => $this->getChainedFunc($op) . '(' . $raw . ')',
        };
    }

    protected function parseTypedArrayKeyExistsCall(Expr\FuncCall $call): ?string
    {
        if (count($call->args) !== 2) {
            return null;
        }
        $arguments = [];
        foreach ($call->args as $index => $argument) {
            if ($argument->unpack || $argument->byRef) {
                return null;
            }
            $name = $argument->name?->name ?? ($index === 0 ? 'key' : 'array');
            if (!in_array($name, ['key', 'array'], true) || isset($arguments[$name])) {
                return null;
            }
            $arguments[$name] = $argument->value;
        }
        if (!isset($arguments['key'], $arguments['array'])
            || $this->getTypedArrayDefinition($arguments['array']) === null) {
            return null;
        }
        $definition = $this->getTypedArrayDefinition($arguments['array']);
        // Snapshot source arguments in order, including named arguments.
        $values = [];
        foreach ($arguments as $name => $argument) {
            if ($name === 'key') {
                $key = $this->guardTypedArrayValue($definition, $argument, $this->parseExprAsValue($argument), true);
                $values[$name] = $this->addTmpVar($definition['keyType']);
                $this->addBeforeStmtLine($values[$name] . ' = ' . $key . ';');
            } else {
                $values[$name] = $this->parseOrderedOperand($argument, false, true);
            }
        }
        // Existing PHPX lookup retains PHP numeric-string normalization.
        return $values['array'] . '.exists(' . $values['key'] . ')';
    }

    protected function parseTypedArrayForeach(Foreach_ $node, array $def, bool $property = false): string
    {
        if ($node->byRef) {
            $this->fatalError($node, 'Typed array elements cannot escape through foreach references');
        }
        [$array, $before, $after] = $this->parseExprWithCapturedStmts($node->expr);
        $iterator = $this->genTmpVarName();
        $assignments = '';
        foreach ([[$node->keyVar, $def['keyType'], '', 'key'],
            [$node->valueVar, $def['type'], $def['class'] ?? '', 'value']] as [$target, $type, $class, $part]) {
            if ($target === null) {
                continue;
            }
            if (!$this->isVarExpr($target)) {
                $this->fatalError($target, 'Typed array foreach requires simple typed key/value variables');
            }
            $this->assertImmutableMutationTarget($target);
            $name = $this->parseWritableIdentifier($target);
            if (isset($this->context->varTypeDegradations[$name])) {
                $this->fatalError($target, 'Typed foreach variables cannot be captured by reference');
            }
            if ($this->hasVar($name) && ($this->getRawVarType($name) !== $type
                || ($class !== '' && $this->getDeclaredObjectType($name) !== $class))) {
                $this->fatalError($target, 'Typed array foreach variable must have the declared key/value type');
            }
            if (!$this->hasVar($name)) {
                $this->addLocalVar($name, $type);
                if ($class !== '') {
                    $this->addObject($name, $class);
                }
            }
            // PHP normalizes numeric string keys to integers. Recover the
            // declared key type here, without changing PHPX or array storage.
            $read = $iterator . '.' . $part . '()';
            $value = $property && ($part === 'value' || $type === Type::INT)
                ? $this->convertTypedArrayPropertyValue($type, $class, $read)
                : $this->convertExprFromType($type, $read);
            $assignments .= $this->getIndent() . '    ' . $name . ' = ' . $value . ';' . PHP_EOL;
        }
        $scope = $this->class ? $this->getLocalClassEntryPtr($this->getFullClassName()) : 'nullptr';
        $code = '{' . PHP_EOL . $this->formatCapturedStmtLines($before)
            . $this->getIndent() . 'php::ForeachIterator ' . $iterator
            . '{' . $array . ', false, ' . $scope . '};' . PHP_EOL
            . $this->formatCapturedStmtLines($after)
            . $this->getIndent() . 'while (' . $iterator . '.next()) {' . PHP_EOL . $assignments;
        $body = $this->withIndentedBlock(fn() => $this->parseForeachBody($node));
        return $code . $this->parseBeforeStmtLines() . $body . $this->getIndent() . '}' . PHP_EOL
            . $this->getIndent() . '}' . PHP_EOL;
    }

    protected function parseTypedArrayWrite(Expr\ArrayDimFetch $left, Expr $right): string
    {
        $def = $this->getTypedArrayAccessDefinition($left);
        $array = $this->parseWritableIdentifier($left->var);
        $key = $this->parseTypedArrayKey($left, true);
        // Snapshot a key before lowering a RHS that might hoist side effects.
        if ($key !== '') {
            $keyVar = $this->addTmpVar($def['keyType']);
            $this->addBeforeStmtLine($keyVar . ' = ' . $key . ';');
            $key = $keyVar;
        }
        $value = $this->guardTypedArrayValue($def, $right, $this->parseExprAsValue($right));
        $result = $this->addTmpVar(Type::VAR);
        // An assignment expression yields the assigned value, not void.
        return '[&]() -> php::Var { ' . $result . ' = ' . $value . '; '
            . $this->genTypedArrayStore($array, $key, $result) . '; return ' . $result . '; }()';
    }

    private function genTypedArrayStore(string $array, string $key, string $value): string
    {
        if ($key === '') {
            return $array . '.appendValue(' . $value . ')';
        }
        return $array . '.offsetSet(' . $key . ', ' . $value . ')';
    }

    protected function parseTypedArrayUnset(Expr\ArrayDimFetch $expr): string
    {
        $array = $this->parseWritableIdentifier($expr->var);
        $key = $this->parseTypedArrayKey($expr);
        return $array . '.offsetUnset(' . $key . ')';
    }

    protected function assertTypedArrayArgument(Node\Arg $arg, ?ArgInfo $parameter, bool $byRef, bool $project): void
    {
        $expr = $arg->value;
        if ($this->isReferenceWrapperCall($expr)) {
            $expr = $this->unwrapReferenceWrapperCall($expr, $arg);
            $this->assertTypedArrayReferenceForbidden($expr);
        }
        $def = $this->getTypedArrayDefinition($expr);
        $expected = $parameter?->typedArray;
        if ($expected !== null && $def !== $expected) {
            $this->fatalError($arg, 'Typed array parameter requires an identical list/dict contract');
        }
        if ($def !== null && ($arg->unpack || ($project && $expected === null) || ($byRef && $expected === null))) {
            $this->fatalError($arg, 'Typed arrays require matching annotated TypePHP parameters or read-only PHP calls');
        }
        if ($byRef && $expected === null) {
            $this->assertTypedArrayReferenceForbidden($expr);
        }
    }

    protected function validateTypedArrayDynamicArgument(Node\Arg $arg, string $function, string $class, int $index): void
    {
        $parameter = $arg->name !== null
            ? $this->getAotCallArgInfoByName($function, $class, $arg->name->name)
            : $this->getAotCallArgInfo($function, $class, $index);
        if ($parameter?->typedArray !== null) {
            $this->fatalError($arg, 'Typed array parameters require a statically resolved native TypePHP call, not Zend dispatch');
        }
        $byRef = $function !== '' && ($arg->name !== null
            ? $this->isReferenceNamedArgument($function, $class, $arg->name->name)
            : $this->isReferenceArgument($function, $class, $index));
        $this->assertTypedArrayArgument($arg, $parameter, $byRef, $parameter !== null);
    }

    protected function functionUsesTypedArray(\TypePhp\Entity\FunctionDef $function): bool
    {
        foreach ($function->argInfoList as $parameter) {
            if ($parameter->typedArray !== null) {
                return true;
            }
        }
        return false;
    }

    protected function functionRequiresNativeAbi(\TypePhp\Entity\FunctionDef $function): bool
    {
        return $this->functionUsesNativeObject($function) || $this->functionUsesTypedArray($function);
    }
}
