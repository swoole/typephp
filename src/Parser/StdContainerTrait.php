<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use TypePhp\Generator\Symbol;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeAbstract;
use PhpParser\Node;
use TypePhp\Context\FunctionContext;
use TypePhp\Entity\FunctionDef;
use TypePhp\Transform\CompileTimeAttribute;

trait StdContainerTrait
{
    protected function validateStdAttributeType(Node\Param|Node\Stmt\Property $owner, string $name, string $storage): void
    {
        $type = $owner->type;
        if ($type === null) {
            return;
        }
        if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
            [$resolvedType] = $this->resolveTypeDecl($type, $owner instanceof Node\Param
                ? self::DECL_TYPE_OF_PARAM : self::DECL_TYPE_OF_PROPERTY);
            if ($resolvedType === ($storage === 'box' ? Type::BOX : Type::ARRAY)) {
                return;
            }
        }
        $this->fatalError($owner, $name . ': a PHP type cannot also be declared unless it is ' . $storage);
    }

    protected function parseStdParameterDefinition(Node\Param|Node\Stmt\Property $param): ?array
    {
        $arrayAttribute = CompileTimeAttribute::find($param, 'StdArray');
        if ($arrayAttribute !== null) {
            $this->validateStdAttributeType($param, 'StdArray', 'box');
            $this->validateStdContainerParameterShape($param, 'StdArray');
            return $this->parseStdArrayAttributeDefinition($arrayAttribute);
        }
        foreach (['StdVector' => 'vector', 'StdMap' => 'map', 'StdOrderedMap' => 'orderedMap'] as $name => $method) {
            $attribute = CompileTimeAttribute::find($param, $name);
            if ($attribute === null) {
                continue;
            }
            $this->validateStdAttributeType($param, $name, 'box');
            $this->validateStdContainerParameterShape($param, $name);
            $expected = $method === 'vector' ? 1 : 2;
            if (count($attribute->args) !== $expected) {
                $this->fatalError($attribute, $name . ' expects ' . $expected . ' type argument(s)');
            }
            foreach ($attribute->args as $argument) {
                if ($argument->name !== null || $argument->unpack || $argument->byRef) {
                    $this->fatalError($argument, $name . ' requires positional type arguments');
                }
            }
            // Reuse factory parsing, including class-name resolution and the
            // canonical template/type-ID key, without leaking locals into the
            // declaration context. Cache only the contract, not a build's ID.
            $context = $this->context;
            $this->context = new FunctionContext();
            try {
                $call = new StaticCall(new Name('std'), new Identifier($method), $attribute->args);
                if ($method === 'vector') {
                    $this->parseStdVector('__std_parameter', $call);
                } elseif ($method === 'map') {
                    $this->parseStdMap('__std_parameter', $call);
                } else {
                    $this->parseStdOrderedMap('__std_parameter', $call);
                }
                $info = $this->context->stdContainers['__std_parameter'];
                if ($this->isNativeObjectClass($info['class'] ?? '')) {
                    $this->fatalError($attribute, 'Std container parameters cannot hold Native objects across a Box boundary');
                }
                unset($info['typeId']);
                return $info;
            } finally {
                $this->context = $context;
            }
        }
        return null;
    }

    protected function validateStdContainerParameterShape(Node\Param|Node\Stmt\Property $owner, string $name): void
    {
        if ($owner instanceof Node\Param
            && ($owner->byRef || $owner->variadic || $owner->default !== null || $owner->isPromoted())
        ) {
            $this->fatalError($owner, $name . ' does not support reference, variadic, defaulted or promoted parameters');
        }
    }

    protected function parseStdArrayAttributeDefinition(Node\Attribute $attribute): array
    {
        if (count($attribute->args) !== 2) {
            $this->fatalError($attribute, 'StdArray expects an element type and a size or dimensions array');
        }
        foreach ($attribute->args as $argument) {
            if ($argument->name !== null || $argument->unpack || $argument->byRef) {
                $this->fatalError($argument, 'StdArray requires positional type and dimension arguments');
            }
        }

        $typeInfo = $this->parseStdValueTypeInfo($attribute->args[0]->value, 'StdArray');
        if ($this->isNativeObjectClass($typeInfo['class'] ?? '')) {
            $this->fatalError($attribute, 'StdArray parameters cannot hold Native objects across a Box boundary');
        }
        $dimensions = $this->parseStdArrayAttributeDimensions($attribute->args[1]->value);
        $totalElements = 1;
        foreach ($dimensions as $dimension) {
            if ($dimension !== 0 && $totalElements > intdiv(PHP_INT_MAX, $dimension)) {
                $this->fatalError($attribute, 'StdArray dimensions are too large');
            }
            $totalElements *= $dimension;
        }
        $bytesPerElement = $this->getStdValueTypeBytes($typeInfo['type']);
        if ($totalElements !== 0 && $totalElements > intdiv(PHP_INT_MAX, $bytesPerElement)) {
            $this->fatalError($attribute, 'StdArray dimensions exceed the supported storage size');
        }

        return [
            'kind' => 'array',
            'decl' => $this->getStdArrayDecl($typeInfo['type'], $dimensions, $typeInfo['class']),
            'type' => $typeInfo['type'],
            'class' => $typeInfo['class'],
            // Existing std::array lowering stores sizes inner-to-outer. Keep
            // that representation while exposing the canonical declaration
            // order explicitly for caches, diagnostics, and future consumers.
            'sizes' => array_reverse($dimensions),
            'dimensions' => $dimensions,
            'bytes' => $totalElements * $bytesPerElement,
        ];
    }

    /** @return list<int> */
    protected function parseStdArrayAttributeDimensions(NodeAbstract $expr): array
    {
        if ($this->isScalarInt($expr)) {
            $dimensions = [$expr->value];
        } elseif ($expr instanceof Expr\Array_) {
            if ($expr->items === []) {
                $this->fatalError($expr, 'StdArray dimensions cannot be empty');
            }
            $dimensions = [];
            foreach ($expr->items as $item) {
                if ($item->key !== null || $item->unpack || !$this->isScalarInt($item->value)) {
                    $this->fatalError($item, 'StdArray dimensions must be a positional array of integer literals');
                }
                $dimensions[] = $item->value->value;
            }
        } else {
            $this->fatalError($expr, 'StdArray expects an integer size or a dimensions array');
        }
        foreach ($dimensions as $dimension) {
            if ($dimension < 0) {
                $this->fatalError($expr, 'StdArray dimensions cannot be negative');
            }
        }
        return $dimensions;
    }

    protected function initializeStdContainerParameters(FunctionDef $function): string
    {
        $code = '';
        foreach ($function->argInfoList as $argument) {
            if ($argument->stdContainer === null) {
                continue;
            }
            $info = $this->addStdTypeId($argument->stdContainer);
            $info['parameter'] = true;
            $type = match ($info['kind']) {
                'array' => Type::STD_ARRAY,
                'vector' => Type::STD_VECTOR,
                'map' => Type::STD_MAP,
                'ordered_map' => Type::STD_ORDERED_MAP,
                default => throw new \LogicException('Unknown std container kind: ' . (string) $info['kind']),
            };
            if ($type === Type::STD_ARRAY) {
                $this->context->stdArrays[$argument->name] = $info;
            } else {
                $this->context->stdContainers[$argument->name] = $info;
            }
            $this->addLocalVar($argument->name, $type);
            $code .= 'if (UNEXPECTED(!' . $argument->name . '.isBox())) { php::throwStdContainerTypeMismatch(); }' . PHP_EOL;
            $code .= 'auto &' . $argument->name . '_ref = php::toStdContainer<' . $info['decl'] . '>('
                . $argument->name . ', ' . $info['typeId'] . ');' . PHP_EOL;
        }
        return $code;
    }

    protected function isStdContainerParameter(string $name): bool
    {
        return !empty($this->context->stdContainers[$name]['parameter'])
            || !empty($this->context->stdArrays[$name]['parameter']);
    }

    /**
     * Resolve the Native value class of a std container factory without
     * creating container metadata. This is used before assignment lowering so
     * a global/static destination cannot accidentally outlive the temporary
     * NativeContainerRootFrame generated for function-local containers.
     */
    protected function getStdContainerFactoryNativeClass(NodeAbstract $expr): string
    {
        if (!$expr instanceof StaticCall
            || !$this->isNameExpr($expr->class)
            || !$this->isIdExpr($expr->name)
            || !$this->isStdClassExpr($expr->class)
        ) {
            return '';
        }

        $method = strtolower($expr->name->toString());
        if (!in_array($method, ['array', 'vector', 'map', 'orderedmap'], true)) {
            return '';
        }

        $initializer = $this->getStdValueInitializer($expr);
        if ($initializer !== null) {
            $inferred = $method === 'array'
                ? $this->inferStdArrayInitializer($initializer)
                : $this->inferStdFlatInitializer(
                    $initializer,
                    $method === 'orderedmap' ? 'std::orderedMap' : 'std::' . $method,
                    $method === 'vector' ? 'positional' : 'map',
                );
            $class = $inferred['class'] ?? '';
            return is_string($class) && $this->isNativeObjectClass($class) ? $class : '';
        }

        if ($method === 'array') {
            $factory = $expr;
            while ($factory instanceof StaticCall
                && $this->isNameExpr($factory->class)
                && $this->isIdExpr($factory->name)
                && $this->isStdClassExpr($factory->class)
                && strtolower($factory->name->toString()) === 'array'
            ) {
                if (count($factory->args) !== 2) {
                    return '';
                }
                $value = $factory->args[0]->value;
                if (!$value instanceof StaticCall) {
                    $typeInfo = $this->parseStdValueTypeInfo($value, 'std::array');
                    $class = $typeInfo['class'] ?? '';
                    return is_string($class) && $this->isNativeObjectClass($class) ? $class : '';
                }
                $factory = $value;
            }
            return '';
        }

        $valueIndex = $method === 'vector' ? 0 : 1;
        if (!isset($expr->args[$valueIndex])) {
            return '';
        }
        $typeInfo = $this->parseStdValueTypeInfo(
            $expr->args[$valueIndex]->value,
            $method === 'orderedmap' ? 'std::orderedMap' : 'std::' . $method,
        );
        $class = $typeInfo['class'] ?? '';
        return is_string($class) && $this->isNativeObjectClass($class) ? $class : '';
    }

    protected function assertNativeStdContainerFunctionLocal(NodeAbstract $expr): void
    {
        if ($this->getStdContainerFactoryNativeClass($expr) !== '') {
            $this->fatalError(
                $expr,
                'Std containers holding Native objects must be function-local',
            );
        }
    }

    protected function isStdContainerIterating(string $var): bool
    {
        return !empty($this->context->stdContainers[$var]['iterationDepth']);
    }

    protected function assertStdContainerStructureMutable(NodeAbstract $node, string $var): void
    {
        if ($this->isStdContainerIterating($var)) {
            $this->fatalError($node, "Cannot structurally modify std container `\${$var}` during foreach");
        }
    }

    protected function isStdContainer(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->isStdContainerType($this->getVarType($var));
    }

    protected function isStdContainerType(string $type): bool
    {
        return in_array($type, [
            Type::STD_ARRAY,
            Type::STD_VECTOR,
            Type::STD_MAP,
            Type::STD_ORDERED_MAP,
        ], true);
    }

    protected function isStdArray(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === Type::STD_ARRAY;
    }

    protected function isStdVector(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === Type::STD_VECTOR;
    }

    protected function isStdMap(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === Type::STD_MAP;
    }

    protected function isStdOrderedMap(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === Type::STD_ORDERED_MAP;
    }

    protected function getStdTypeKey(array $info): string
    {
        $parts = [
            'kind=' . $info['kind'],
            'decl=' . $info['decl'],
            'type=' . $info['type'],
            'class=' . ($info['class'] ?? ''),
        ];
        if (isset($info['keyType'])) {
            $parts[] = 'keyType=' . $info['keyType'];
        }
        return implode(';', $parts);
    }

    protected function addStdTypeId(array $info): array
    {
        $info['typeId'] = $this->registerStdType($this->getStdTypeKey($info));
        return $info;
    }

    protected function getStdContainerVarInfo(string $var): array
    {
        if ($this->isStdArray($var)) {
            return $this->context->stdArrays[$var];
        }
        return $this->context->stdContainers[$var];
    }

    protected function getStdContainerNativeObjectClass(string $var): string
    {
        if (!$this->isStdContainer($var)) {
            return '';
        }
        $class = $this->getStdContainerVarInfo($var)['class'] ?? '';
        return is_string($class) && $this->isNativeObjectClass($class) ? $class : '';
    }

    protected function assertStdContainerDoesNotEscapeNativeObjects(
        NodeAbstract $node,
        string $var,
    ): void {
        if ($this->getStdContainerNativeObjectClass($var) !== '') {
            $this->fatalError(
                $node,
                'Std containers holding Native objects cannot cross a PHP/ZendVM value boundary',
            );
        }
    }

    protected function getStdContainerKeyType(string $var): string
    {
        if ($this->isStdVector($var) or $this->isStdArray($var)) {
            return Type::INT;
        }
        return $this->getStdContainerVarInfo($var)['keyType'];
    }

    protected function getStdContainerValueType(string $var, string $valueVar): string
    {
        $info = $this->getStdContainerVarInfo($var);
        if ($this->isStdArray($var)) {
            if (count($info['sizes']) > 1) {
                return Type::ARRAY;
            }
        }
        if ($info['type'] === Type::OBJECT && $this->isNativeObjectClass($info['class'] ?? '')) {
            $this->addNativeObject($valueVar, $info['class']);
            unset($this->context->objects[$valueVar]);
            return $this->getNativeObjectPointerType($info['class']);
        }
        if ($info['type'] === Type::OBJECT and $info['class']) {
            $this->addObject($valueVar, $info['class']);
        } else {
            unset($this->context->objects[$valueVar]);
        }
        return $info['type'];
    }

    protected function getStdArrayDecl(string $type, array $sizes, ?string $class = null): string
    {
        $decl = str_repeat(Type::STD_ARRAY . '<', count($sizes));
        $decl .= $this->getStdContainerElementType($type, $class);
        for ($i = count($sizes) - 1; $i >= 0; $i--) {
            $decl .= ', ' . $sizes[$i] . '>';
        }
        return $decl;
    }

    protected function getStdValueTypeBytes(string $type): int
    {
        return match ($type) {
            Type::BOOL => 1,
            Type::INT, Type::FLOAT => 8,
            default => 16,
        };
    }

    protected function getNestedStdArrayInfo(array $info, int $accessLevel): ?array
    {
        $sizes = array_reverse($info['sizes']);
        if ($accessLevel >= count($sizes)) {
            return null;
        }

        $nestedSizes = array_slice($sizes, $accessLevel);
        return [
            'kind' => 'array',
            'decl' => $this->getStdArrayDecl($info['type'], $nestedSizes, $info['class']),
            'type' => $info['type'],
            'class' => $info['class'],
            'sizes' => array_reverse($nestedSizes),
            'dimensions' => $nestedSizes,
            'bytes' => array_product($nestedSizes) * $this->getStdValueTypeBytes($info['type']),
        ];
    }

    protected function getStdArrayDimFetchContainerInfo(Expr\ArrayDimFetch $expr): ?array
    {
        if (!$expr->hasAttribute('stdArrayDimFetch')) {
            $this->parseStdArrayDimFetch($expr);
        }
        $attr = $expr->getAttribute('stdArrayDimFetch');
        return $this->getNestedStdArrayInfo($this->context->stdArrays[$attr['var']], $attr['accessLevel']);
    }

    protected function isSameStdContainerInfo(array $leftInfo, array $rightInfo): bool
    {
        return $this->getStdTypeKey($leftInfo) === $this->getStdTypeKey($rightInfo);
    }

    protected function getStdContainerExprInfo(NodeAbstract $expr): ?array
    {
        if ($this->isVarExpr($expr)) {
            $var = $this->parseVariable($expr);
            if ($this->isStdContainer($var)) {
                return $this->getStdContainerVarInfo($var);
            }
            return null;
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdArrayExpr($expr)) {
            return $this->getStdArrayDimFetchContainerInfo($expr);
        }
        return null;
    }

    protected function assertStdContainerArithmeticOperands(NodeAbstract $left, NodeAbstract $right, string $operator): void
    {
        if (!in_array(rtrim($operator, '='), ['+', '-', '*', '/', '%', '**', '&', '|', '^', '<<', '>>'], true)) {
            return;
        }

        foreach ([$left, $right] as $operand) {
            $info = $this->getStdContainerOperatorInfo($operand);
            if ($info === null) {
                continue;
            }
            $container = match ($info['kind']) {
                'array' => 'std::array',
                'vector' => 'std::vector',
                'map' => 'std::map',
                'ordered_map' => 'std::orderedMap',
            };
            $this->fatalError($operand, "Operator '{$operator}' is not supported for {$container} containers; operate on individual elements instead");
        }
    }

    private function getStdContainerOperatorInfo(NodeAbstract $expr): ?array
    {
        if ($expr instanceof Expr\ErrorSuppress) {
            return $this->getStdContainerOperatorInfo($expr->expr);
        }
        if (($info = $this->getStdContainerExprInfo($expr)) !== null) {
            return $info;
        }
        if ($expr instanceof Expr\ArrayDimFetch) {
            $info = $this->getStdContainerOperatorInfo($expr->var);
            return $info !== null && $info['kind'] === 'array'
                ? $this->getNestedStdArrayInfo($info, 1)
                : null;
        }
        if (($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch)
            && $expr->name instanceof Node\Identifier
        ) {
            $class = $this->detectClassOfExpr($expr->var);
            if ($class === '' && $expr->var instanceof Expr\Variable) {
                $class = $this->getDeclaredObjectType($this->parseVariable($expr->var));
            }
            return $class !== ''
                ? $this->resolveNativeInstanceProperty($expr, $expr->name->name, $class)?->propertyDef?->stdContainer
                : null;
        }
        if ($expr instanceof Expr\StaticPropertyFetch && $expr->name instanceof Node\VarLikeIdentifier) {
            $this->resolveNativeStaticPropertyFetch($expr);
            return $this->getNativePropertyDef($expr)?->stdContainer;
        }
        return null;
    }

    protected function parseStdContainerCopyExpr(NodeAbstract $expr): string
    {
        if ($this->isVarExpr($expr)) {
            return $this->parseVariable($expr) . '_ref';
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdArrayExpr($expr)) {
            return $this->parseStdArrayDimFetch($expr);
        }
        return $this->parseExpr($expr);
    }

    protected function isStdArrayExpr(Expr\ArrayDimFetch $expr): bool
    {
        $info = $this->getStdArrayInfo($expr);
        return $info !== null;
    }

    protected function isStdContainerExpr(Expr\ArrayDimFetch $expr): bool
    {
        return $this->isStdArrayExpr($expr) || $this->getStdContainerInfo($expr) !== null;
    }

    protected function getStdArrayInfo(Expr\ArrayDimFetch $expr): ?array
    {
        $tmp = $expr->var;
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $tmp = $tmp->var;
            } elseif ($this->isVarExpr($tmp) and $this->isStdArray($this->parseVariable($tmp))) {
                return $this->context->stdArrays[$this->parseVariable($tmp)];
            } else {
                return null;
            }
        }
    }

    protected function getStdContainerInfo(Expr\ArrayDimFetch $expr): ?array
    {
        $tmp = $expr->var;
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $tmp = $tmp->var;
            } elseif ($this->isVarExpr($tmp)) {
                $var = $this->parseVariable($tmp);
                if ($this->isStdVector($var) || $this->isStdMap($var) || $this->isStdOrderedMap($var)) {
                    return $this->context->stdContainers[$var];
                }
                return null;
            } else {
                return null;
            }
        }
    }

    protected function parseStdArrayAssign(NodeAbstract $left, NodeAbstract $right): string
    {
        $info = $this->getStdArrayInfo($left);
        $arrayDimFetch = $this->parseStdArrayDimFetch($left);
        $attr = $left->getAttribute('stdArrayDimFetch');
        if ($attr['accessLevel'] < $attr['totalLevel']) {
            $leftInfo = $this->getNestedStdArrayInfo($info, $attr['accessLevel']);
            $rightInfo = $this->getStdContainerExprInfo($right);
            if ($rightInfo !== null and $this->isSameStdContainerInfo($leftInfo, $rightInfo)) {
                return $arrayDimFetch . ' = ' . $this->parseStdContainerCopyExpr($right);
            }
            $this->fatalError($right, 'Cannot assign non-matching value to nested std::array');
        }
        return $arrayDimFetch . ' = ' . $this->convertStdValueExpr($info, $right);
    }

    protected function parseStdContainerAssign(Expr\ArrayDimFetch $left, NodeAbstract $right): string
    {
        if ($this->isStdArrayExpr($left)) {
            return $this->parseStdArrayAssign($left, $right);
        }

        $info = $this->getStdContainerInfo($left);
        $container = $this->parseVariable($left->var);
        if ($info['kind'] === 'vector' && $left->dim === null) {
            if (!$this->isVarExpr($left->var)) {
                $this->fatalError($left, 'std::vector append only supports a vector variable');
            }
            $vector = $this->parseVariable($left->var);
            $this->assertStdContainerStructureMutable($left, $vector);
            return $vector . '_ref.push_back(' . $this->convertStdValueExpr($info, $right) . ')';
        }
        if ($left->dim === null) {
            $this->fatalError($left, 'std map expects a key');
        }

        return $this->parseStdContainerOffsetSet($left, $this->convertStdValueExpr($info, $right));
    }

    protected function parseStdArrayAssignOp(Expr\AssignOp $expr, string $op): string
    {
        $binaryOp = $this->removeAssignOp($op);
        if ($binaryOp === '.') {
            $this->fatalError($expr, 'Cannot concat string to std::array');
        }

        $info = $this->getStdArrayInfo($expr->var);
        $arrayDimFetch = $this->parseStdArrayDimFetch($expr->var);
        $attr = $expr->var->getAttribute('stdArrayDimFetch');
        if ($attr['accessLevel'] < $attr['totalLevel']) {
            $this->fatalError($expr, 'Cannot use assign operator on nested std::array');
        }
        $rightExpr = $this->parseExpr($expr->expr);
        if (in_array($info['type'], [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
            $rightType = $this->detectTypeOfExpr($expr->expr);
            $item = $this->genTmpVarName();
            $bigExpr = $this->parseBigAssignOpExpr(
                $item,
                $info['type'],
                $rightExpr,
                $rightType,
                $binaryOp,
                $expr->var,
                $expr->expr
            );
            return '([&](php::Var &' . $item . ') -> php::Var & { return ' . $item . ' = ' . $bigExpr . '; })('
                . $arrayDimFetch . ')';
        }
        return $arrayDimFetch . ' ' . $binaryOp . '= ' . $this->convertExprFromType($info['type'], $rightExpr);
    }

    protected function parseStdContainerAssignOp(Expr\AssignOp $expr, string $op): string
    {
        if ($this->isStdArrayExpr($expr->var)) {
            return $this->parseStdArrayAssignOp($expr, $op);
        }

        $binaryOp = $this->removeAssignOp($op);
        if ($binaryOp === '.') {
            $this->fatalError($expr, 'Cannot concat string to std container');
        }

        $info = $this->getStdContainerInfo($expr->var);
        $containerDimFetch = $this->parseStdContainerDimFetch($expr->var, true);
        $rightExpr = $this->parseExpr($expr->expr);
        if (in_array($info['type'], [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
            $rightType = $this->detectTypeOfExpr($expr->expr);
            $item = $this->genTmpVarName();
            $bigExpr = $this->parseBigAssignOpExpr(
                $item,
                $info['type'],
                $rightExpr,
                $rightType,
                $binaryOp,
                $expr->var,
                $expr->expr
            );
            return '([&](php::Var &' . $item . ') -> php::Var & { return ' . $item . ' = ' . $bigExpr . '; })('
                . $containerDimFetch . ')';
        }
        return $containerDimFetch . ' ' . $binaryOp . '= ' . $this->convertExprFromType($info['type'], $rightExpr);
    }

    protected function parseStdArrayDimFetch(Expr\ArrayDimFetch $expr): string
    {
        $tmp = $expr;
        $dims = [];
        $info = $this->getStdArrayInfo($expr);

        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                if ($tmp->dim === null) {
                    $this->fatalError($tmp, 'std::array expects an index');
                }
                $dims[] = $tmp->dim;
                $tmp = $tmp->var;
            } else {
                break;
            }
        }
        if (!$this->isVarExpr($tmp)) {
            $this->fatalError($expr, 'std::array expects a variable');
        }

        $dims = array_reverse($dims);
        $sizes = array_reverse($info['sizes']);
        if (count($dims) > count($sizes)) {
            $this->fatalError($expr, 'std::array access level exceeds array dimensions');
        }

        $baseVar = $this->parseVariable($tmp);
        $nesting = [$baseVar . '_ref'];
        foreach ($dims as $level => $dim) {
            $size = $sizes[$level];
            if ($this->isScalarInt($dim)) {
                if ($dim->value < 0 || $dim->value >= $size) {
                    $this->fatalError($dim, "std::array index out of bounds: index {$dim->value}, size {$size}");
                }
            }
            $index = $this->parseExpr($dim);
            $nesting[] = '[' . Symbol::safeIndex($this->convertIntExpr($index), $size) . ']';
        }
        $expr->setAttribute('stdArrayDimFetch', ['var' => $baseVar, 'accessLevel' => count($dims), 'totalLevel' => count($sizes)]);

        return implode('', $nesting);
    }

    protected function parseForeachStdContainer(Foreach_ $node): string
    {
        $container = $this->parseIdentifier($node->expr);
        $mutableContainer = !$this->isStdArray($container);
        if ($mutableContainer) {
            $this->context->stdContainers[$container]['iterationDepth'] =
                ($this->context->stdContainers[$container]['iterationDepth'] ?? 0) + 1;
        }
        $iterator = $this->genTmpVarName();
        $code = '';
        if ($mutableContainer) {
            $guard = $this->genTmpVarName();
            $code .= '{' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . "auto $guard = {$container}_ref.iterationGuard();" . PHP_EOL;
            $code .= $this->getIndent();
        }
        $code .= "for (auto $iterator = {$container}_ref.begin(); $iterator != {$container}_ref.end(); ++$iterator) {" . PHP_EOL;
        $this->indentLevel++;
        if ($node->keyVar) {
            $keyVar = $this->parseIdentifier($node->keyVar);
            $this->checkVar($node, $keyVar, $this->getStdContainerKeyType($container));
            if ($this->isStdVector($container) or $this->isStdArray($container)) {
                $code .= $this->getIndent() . "$keyVar = $iterator - {$container}_ref.begin();" . PHP_EOL;
            } else {
                $code .= $this->getIndent() . "$keyVar = {$iterator}->first;" . PHP_EOL;
            }
        }

        if ($node->byRef) {
            $this->fatalError($node, 'Cannot use & with std container foreach');
        }

        if (!$this->isVarExpr($node->valueVar)) {
            $this->fatalError($node, 'Cannot assign value to std container foreach');
        }

        $valueVar = $this->parseIdentifier($node->valueVar);
        $this->checkVar($node, $valueVar, $this->getStdContainerValueType($container, $valueVar));

        if ($this->isStdVector($container) or $this->isStdArray($container)) {
            $code .= $this->getIndent() . "$valueVar = *$iterator;" . PHP_EOL;
        } else {
            $code .= $this->getIndent() . "$valueVar = {$iterator}->second;" . PHP_EOL;
        }

        try {
            $body = $this->parseForeachBody($node);
        } finally {
            if ($mutableContainer) {
                --$this->context->stdContainers[$container]['iterationDepth'];
            }
        }
        $this->indentLevel--;

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= $body . PHP_EOL;

        $code .= $this->getIndent() . '}';
        if ($mutableContainer) {
            $this->indentLevel--;
            $code .= PHP_EOL . $this->getIndent() . '}';
        }
        unset($this->context->objects[$valueVar]);
        return $code;
    }

    protected function parseStdContainerDimFetch(Expr\ArrayDimFetch $expr, bool $forUpdate = false): string
    {
        if ($this->isStdArrayExpr($expr)) {
            return $this->parseStdArrayDimFetch($expr);
        }

        $info = $this->getStdContainerInfo($expr);
        $tmp = $expr;
        $dims = [];
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $dims[] = $tmp->dim;
                $tmp = $tmp->var;
            } else {
                break;
            }
        }
        if (!$this->isVarExpr($tmp)) {
            $this->fatalError($expr, 'std container expects a variable');
        }
        if (count($dims) !== 1) {
            $this->fatalError($expr, 'Nested std::vector/std::map/std::orderedMap access is not supported');
        }
        $dim = $dims[0];
        if ($dim === null) {
            $this->fatalError($expr, 'std container expects an index');
        }

        $container = $this->parseVariable($tmp);
        $index = $this->parseExpr($dim);
        $key = $info['kind'] === 'vector' ? $this->convertIntExpr($index) : $this->convertStdContainerKey($info, $index);
        $method = $forUpdate && ($info['kind'] === 'map' || $info['kind'] === 'ordered_map')
            ? 'offsetGetForUpdate'
            : 'offsetGet';
        $args = $key;
        if ($method === 'offsetGetForUpdate') {
            $defaultValue = $this->getStdContainerDefaultValueExpr($info['type']);
            if ($defaultValue !== null) {
                $method = 'offsetGetForUpdateLazy';
                $args .= ', []() { return ' . $defaultValue . '; }';
            }
        }
        $access = $container . '_ref.' . $method . '(' . $args . ')';
        $expr->setAttribute('stdContainerDimFetch', ['var' => $container, 'accessLevel' => 1, 'totalLevel' => 1]);

        return $access;
    }

    protected function parseStdContainerOffsetSet(Expr\ArrayDimFetch $expr, string $value): string
    {
        $info = $this->getStdContainerInfo($expr);
        if ($expr->dim === null) {
            $this->fatalError($expr, 'std container expects an index');
        }
        if (!$this->isVarExpr($expr->var)) {
            $this->fatalError($expr, 'std container expects a variable');
        }
        $container = $this->parseVariable($expr->var);
        $indexExpr = $this->parseExpr($expr->dim);
        $index = $info['kind'] === 'vector' ? $this->convertIntExpr($indexExpr) : $this->convertStdContainerKey($info, $indexExpr);
        return $container . '_ref.offsetSet(' . $index . ', ' . $value . ')';
    }

    protected function convertStdContainerKey(array $info, string $index): string
    {
        if ($info['keyType'] === Type::STR) {
            return $this->convertStringExpr($index);
        }
        return $this->convertIntExpr($index);
    }

    protected function getStdContainerDefaultValueExpr(string $type): ?string
    {
        return match ($type) {
            Type::BIGINT => 'php::BigInt::newInstance(0)',
            Type::BIGFLOAT => 'php::BigFloat::newInstance(0)',
            Type::DECIMAL => 'php::Decimal::newInstance(0)',
            default => null,
        };
    }

    /** Return the one-array value-initializer overload, if this call uses it. */
    protected function getStdValueInitializer(Expr\StaticCall $expr): ?Expr\Array_
    {
        if (count($expr->args) !== 1) {
            return null;
        }
        $argument = $expr->args[0];
        $value = $argument->value;
        if (!$value instanceof Expr\Array_) {
            return null;
        }
        if ($argument->name !== null || $argument->unpack || $argument->byRef) {
            $this->fatalError($argument, 'Std container value initialization requires one positional array argument');
        }
        return $value;
    }

    /** @return array{type: string, class: ?string} */
    protected function inferStdInitializerValueType(NodeAbstract $expr, string $owner): array
    {
        $this->assertExprCanBeUsedAsValue($expr, $owner . ' value');
        $type = Type::getReferencedType($this->detectTypeOfExpr($expr));
        if ($type === Type::VAR) {
            $this->fatalError($expr, $owner . ' cannot infer an element type from var/any');
        }
        if (!in_array($type, [
            Type::INT,
            Type::FLOAT,
            Type::BOOL,
            Type::STR,
            Type::ARRAY,
            Type::OBJECT,
            Type::BIGINT,
            Type::BIGFLOAT,
            Type::DECIMAL,
            Type::STREAM,
            Type::BOX,
        ], true)) {
            $this->fatalError($expr, $owner . ' cannot infer a supported element type from this expression');
        }
        if ($type === Type::INT && $this->varIntTypes && $this->exprCanOverflowInt($expr)) {
            $this->fatalError($expr, $owner . ' integer values that may widen require an explicit toInt() or native integer conversion');
        }
        $class = $type === Type::OBJECT ? $this->detectClassOfExpr($expr) : '';
        return ['type' => $type, 'class' => $class !== '' ? $class : null];
    }

    protected function assertSameStdInitializerValueType(
        array $expected,
        array $actual,
        NodeAbstract $expr,
        string $owner,
    ): void {
        if ($expected['type'] !== $actual['type']
            || strcasecmp($expected['class'] ?? '', $actual['class'] ?? '') !== 0
        ) {
            $this->fatalError($expr, $owner . ' initializer values must all have exactly the same type');
        }
    }

    /**
     * Infer a one-dimensional initializer.
     *
     * @param 'positional'|'integer'|'map' $keyMode
     * @return array{type: string, class: ?string, keyType?: string, items: array}
     */
    protected function inferStdFlatInitializer(Expr\Array_ $array, string $owner, string $keyMode): array
    {
        if ($array->items === []) {
            $this->fatalError($array, $owner . ' cannot infer types from an empty array');
        }

        $valueInfo = null;
        $keyType = null;
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef) {
                $this->fatalError($item, $owner . ' initializer does not support unpacking or references');
            }
            if ($keyMode === 'positional' && $item->key !== null) {
                $this->fatalError($item->key, $owner . ' initializer requires positional array elements');
            }
            if ($keyMode === 'map' && $item->key === null) {
                $this->fatalError($item, $owner . ' initializer requires an explicit key for every value');
            }
            if ($item->key !== null && $keyMode !== 'positional') {
                $actualKeyType = Type::getReferencedType($this->detectTypeOfExpr($item->key));
                if (!in_array($actualKeyType, [Type::INT, Type::STR], true)) {
                    $this->fatalError($item->key, $owner . ' initializer keys must have a statically known int or string type');
                }
                if ($actualKeyType === Type::INT && $this->varIntTypes && $this->exprCanOverflowInt($item->key)) {
                    $this->fatalError($item->key, $owner . ' integer keys that may widen require an explicit toInt() or native integer conversion');
                }
                if ($keyMode === 'integer' && $actualKeyType !== Type::INT) {
                    $this->fatalError($item->key, $owner . ' initializer keys must have type int');
                }
                if ($keyType !== null && $keyType !== $actualKeyType) {
                    $this->fatalError($item->key, $owner . ' initializer keys must all have exactly the same type');
                }
                $keyType = $actualKeyType;
            } elseif ($keyMode === 'integer') {
                $keyType ??= Type::INT;
            }

            $actualValueInfo = $this->inferStdInitializerValueType($item->value, $owner);
            if ($valueInfo === null) {
                $valueInfo = $actualValueInfo;
            } else {
                $this->assertSameStdInitializerValueType($valueInfo, $actualValueInfo, $item->value, $owner);
            }
        }

        $result = $valueInfo + ['items' => $array->items];
        if ($keyMode !== 'positional') {
            $result['keyType'] = $keyType ?? Type::INT;
        }
        return $result;
    }

    /**
     * Infer the leaf type and rectangular shape of a nested std::array value.
     *
     * @return array{type: string, class: ?string, dimensions: list<int>, entries: list<array{path: list<int>, value: NodeAbstract}>}
     */
    protected function inferStdArrayInitializer(Expr\Array_ $array, string $owner = 'std::array'): array
    {
        if ($array->items === []) {
            $this->fatalError($array, $owner . ' cannot infer types or dimensions from an empty array');
        }

        $leafInfo = null;
        $childDimensions = null;
        $entries = [];
        $nested = null;
        foreach ($array->items as $index => $item) {
            if ($item->unpack || $item->byRef || $item->key !== null) {
                $this->fatalError($item, $owner . ' initializer must be a positional array without unpacking or references');
            }

            $isNested = $item->value instanceof Expr\Array_;
            if ($nested !== null && $nested !== $isNested) {
                $this->fatalError($item->value, $owner . ' initializer must have a uniform rectangular shape');
            }
            $nested = $isNested;
            if ($isNested) {
                $child = $this->inferStdArrayInitializer($item->value, $owner);
                if ($childDimensions !== null && $childDimensions !== $child['dimensions']) {
                    $this->fatalError($item->value, $owner . ' initializer must have a uniform rectangular shape');
                }
                $childDimensions = $child['dimensions'];
                $actualLeafInfo = ['type' => $child['type'], 'class' => $child['class']];
                foreach ($child['entries'] as $entry) {
                    array_unshift($entry['path'], $index);
                    $entries[] = $entry;
                }
            } else {
                $actualLeafInfo = $this->inferStdInitializerValueType($item->value, $owner);
                $entries[] = ['path' => [$index], 'value' => $item->value];
            }
            if ($leafInfo === null) {
                $leafInfo = $actualLeafInfo;
            } else {
                $this->assertSameStdInitializerValueType($leafInfo, $actualLeafInfo, $item->value, $owner);
            }
        }

        return [
            'type' => $leafInfo['type'],
            'class' => $leafInfo['class'],
            'dimensions' => [count($array->items), ...($childDimensions ?? [])],
            'entries' => $entries,
        ];
    }

    protected function parseStdContainerOffsetUnset(Expr\ArrayDimFetch $expr): string
    {
        if ($expr->dim === null) {
            $this->fatalError($expr, 'std container expects an index');
        }

        if ($this->isStdArrayExpr($expr)) {
            $info = $this->getStdArrayInfo($expr);
            $target = $this->parseStdArrayDimFetch($expr);
            $defaultValue = $this->getStdContainerDefaultValueExpr($info['type']);
            if ($defaultValue !== null) {
                return $target . ' = ' . $defaultValue;
            }

            if ($this->isVarExpr($expr->var)) {
                $parent = $this->parseVariable($expr->var) . '_ref';
            } elseif ($this->isArrayDimFetch($expr->var)) {
                $parent = $this->parseStdArrayDimFetch($expr->var);
            } else {
                $this->fatalError($expr, 'std::array expects a variable');
            }
            $index = $this->convertIntExpr($this->parseExpr($expr->dim));
            return $parent . '.offsetUnset(' . $index . ')';
        }

        $info = $this->getStdContainerInfo($expr);
        if ($info === null || !$this->isVarExpr($expr->var)) {
            $this->fatalError($expr, 'std container expects a variable');
        }
        $container = $this->parseVariable($expr->var);
        $indexExpr = $this->parseExpr($expr->dim);
        $index = $info['kind'] === 'vector'
            ? $this->convertIntExpr($indexExpr)
            : $this->convertStdContainerKey($info, $indexExpr);
        $defaultValue = $this->getStdContainerDefaultValueExpr($info['type']);
        if ($defaultValue !== null && $info['kind'] === 'vector') {
            return $container . '_ref.offsetSet(' . $index . ', ' . $defaultValue . ')';
        }
        return $container . '_ref.offsetUnset(' . $index . ')';
    }

    protected function getStdContainerElementType(string $type, ?string $class = null): string
    {
        if ($class !== null && $this->isNativeObjectClass($class)) {
            return $this->getNativeObjectPointerType($class);
        }
        return match ($type) {
            Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL, Type::STREAM, Type::BOX => Type::VAR,
            default => $type,
        };
    }

    protected function parseStdNativeType(NodeAbstract $expr, string $owner): string
    {
        if (!$this->isClassConstFetch($expr) || !$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)
            || strcasecmp(ltrim($expr->class->toString(), '\\'), 'Type') !== 0) {
            $this->fatalError($expr, "An incorrect `{$owner}` definition");
        }
        return match ($expr->name->name) {
            'Int' => Type::INT,
            'Float' => Type::FLOAT,
            'Bool' => Type::BOOL,
            'BigInt' => Type::BIGINT,
            'BigFloat' => Type::BIGFLOAT,
            'Decimal' => Type::DECIMAL,
            default => $this->fatalError($expr, "An incorrect `{$owner}` definition"),
        };
    }

    protected function parseStdValueTypeInfo(NodeAbstract $expr, string $owner): array
    {
        if (!$this->isClassConstFetch($expr)) {
            $this->fatalError($expr, "{$owner} expects a Type constant or ClassName::class");
        }
        if (!$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)) {
            $this->fatalError($expr, "An incorrect `{$owner}` definition");
        }
        $className = ltrim($expr->class->toString(), '\\');
        if (strcasecmp($className, 'Type') === 0) {
            return [
                'type' => match ($expr->name->name) {
                    'Int', 'Float', 'Bool', 'BigInt', 'BigFloat', 'Decimal' => $this->parseStdNativeType($expr, $owner),
                    'String', 'Str' => Type::STR,
                    'Array' => Type::ARRAY,
                    'Object' => Type::OBJECT,
                    'Any' => Type::VAR,
                    'Stream' => Type::STREAM,
                    'Box' => Type::BOX,
                    default => $this->fatalError($expr, "An incorrect `{$owner}` definition"),
                },
                'class' => null,
            ];
        }
        if ($expr->name->name !== 'class') {
            $this->fatalError($expr, "{$owner} class value only supports ClassName::class");
        }
        $class = $this->parseStdClassValueType($expr, $owner);
        return ['type' => Type::OBJECT, 'class' => $class];
    }

    protected function parseStdClassValueType(Expr\ClassConstFetch $expr, string $owner): string
    {
        $class = $this->parseIdentifier($expr->class);
        if ($class === 'static') {
            $this->fatalError($expr, "{$owner} class value does not support static::class");
        }
        if ($class === 'self' || $class === 'this_') {
            if (!$this->classDef) {
                $this->fatalError($expr, "{$owner} class value cannot use self::class outside class scope");
            }
            $class = $this->getNamespacedClassName($this->class);
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, "{$owner} class value cannot use parent::class because current class does not extend any class");
            }
            $class = $this->getNamespacedClassName('\\' . $this->classDef->extends);
        } else {
            $class = $this->getNamespacedClassName($class);
        }
        return $class;
    }

    protected function convertStdValueExpr(array $info, NodeAbstract $expr): string
    {
        return $this->convertParsedStdValueExpr($info, $expr, $this->parseExpr($expr));
    }

    protected function convertParsedStdValueExpr(array $info, NodeAbstract $expr, string $valueExpr): string
    {
        $class = $info['class'] ?? null;
        if ($class === null) {
            $targetType = $info['type'];
            if ($targetType === Type::BIGINT || $targetType === Type::BIGFLOAT || $targetType === Type::DECIMAL || $targetType === Type::STREAM || $targetType === Type::BOX) {
                return $this->convertStdVarBackedExpr($targetType, $valueExpr, $expr);
            }
            return $this->convertExprFromType($targetType, $valueExpr);
        }
        if ($this->isNativeObjectClass($class)) {
            if ($this->isNull($expr)) {
                return 'nullptr';
            }
            $rightClass = $this->detectClassOfExpr($expr);
            if ($rightClass === '' || !$this->isObjectClassStaticallyAssignableTo($rightClass, $class)) {
                $actual = $rightClass === '' ? $this->detectTypeOfExpr($expr) : $rightClass;
                $this->fatalError(
                    $expr,
                    "Cannot assign value of type `{$actual}` to std container value of native class `{$class}`",
                );
            }
            return $valueExpr;
        }
        $rightClass = $this->detectClassOfExpr($expr);
        if ($rightClass !== '') {
            if (!$this->isObjectClassStaticallyAssignableTo($rightClass, $class)) {
                $this->fatalError($expr, "Cannot assign object of class `{$rightClass}` to std container value of class `{$class}`");
            }
        }

        return 'php::toObject(' . $valueExpr . ', ' . $this->getClassEntryPtr($class) . ')';
    }

    protected function parseOrderedStdInitializerValue(array $info, NodeAbstract $expr): string
    {
        $value = $this->materializeRefReturnAsValue(
            $expr,
            $this->parseOrderedOperand($expr, false),
        );
        return $this->convertParsedStdValueExpr($info, $expr, $value);
    }

    /** @param list<string> $statements */
    private function genStdValueInitializerExpression(string $var, array $statements): string
    {
        $code = '[&]() -> php::Var {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($statements as $statement) {
            $code .= $this->getIndent() . $statement . ';' . PHP_EOL;
        }
        $code .= $this->getIndent() . 'return ' . $var . ';' . PHP_EOL;
        $this->indentLevel--;
        return $code . $this->getIndent() . '}()';
    }

    private function genStdArrayValueInitializer(string $var, array $info, array $entries): string
    {
        $statements = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $last = array_pop($path);
            $target = $var . '_ref';
            foreach ($path as $index) {
                $target .= '.offsetGet(' . $index . ')';
            }
            $value = $this->parseOrderedStdInitializerValue($info, $entry['value']);
            $statements[] = $target . '.offsetSet(' . $last . ', ' . $value . ')';
        }
        return $this->genStdValueInitializerExpression($var, $statements);
    }

    private function genStdVectorValueInitializer(string $var, array $info, array $items): string
    {
        $statements = [];
        foreach ($items as $item) {
            $statements[] = $var . '_ref.push_back('
                . $this->parseOrderedStdInitializerValue($info, $item->value) . ')';
        }
        return $this->genStdValueInitializerExpression($var, $statements);
    }

    private function genStdMapValueInitializer(string $var, array $info, array $items): string
    {
        $statements = [];
        foreach ($items as $item) {
            $key = $this->parseOrderedOperand($item->key, false);
            $key = $this->convertStdContainerKey($info, $key);
            $value = $this->parseOrderedStdInitializerValue($info, $item->value);
            $statements[] = $var . '_ref.offsetSet(' . $key . ', ' . $value . ')';
        }
        return $this->genStdValueInitializerExpression($var, $statements);
    }

    protected function convertStdVarBackedExpr(string $targetType, string $valueExpr, NodeAbstract $expr): string
    {
        $sourceType = $this->detectTypeOfExpr($expr);
        if ($sourceType === $targetType) {
            return $valueExpr;
        }
        if ($targetType === Type::STREAM || $targetType === Type::BOX) {
            return $valueExpr;
        }
        if ($targetType === Type::BIGINT) {
            return $this->convertBigIntExpr($valueExpr, $sourceType);
        }
        if ($targetType === Type::BIGFLOAT) {
            return $this->convertBigFloatExpr($valueExpr, $sourceType);
        }
        if ($targetType === Type::DECIMAL) {
            return $this->convertDecimalExpr($valueExpr, $sourceType, $expr);
        }
        return $valueExpr;
    }

    protected function parseToStdAssign(string $var, Expr\MethodCall $expr): string
    {
        $methodName = $expr->name->toString();
        $containerType = match ($methodName) {
            'toStdArray'        => 'array',
            'toStdVector'       => 'vector',
            'toStdMap'          => 'map',
            'toStdOrderedMap'   => 'ordered_map',
            default => $this->fatalError($expr, "Unknown std conversion method: {$methodName}"),
        };

        if (!$this->isVarExpr($expr->var)) {
            $this->fatalError($expr->var, "{$methodName}() must be called on a variable");
        }
        $sourceVar = $this->parseVariable($expr->var);
        if (!$this->hasVar($sourceVar)) {
            $this->fatalError($expr->var, 'Undefined variable `$' . $sourceVar . '`');
        }

        $name = new Name('std');
        $method = new Identifier($containerType);
        $fakeCall = new StaticCall($name, $method, $expr->args);

        if ($containerType === 'array') {
            $this->addLocalVar($var, Type::STD_ARRAY);
            $this->parseStdArray($var, $fakeCall);
            $this->context->stdArrays[$var]['boxExpr'] = $sourceVar;
            return '// StdContainer<' . $this->context->stdArrays[$var]['decl'] . '>(' . $sourceVar . ')';
        }

        if ($containerType === 'vector') {
            $this->addLocalVar($var, Type::STD_VECTOR);
            $this->parseStdVector($var, $fakeCall);
        } elseif ($containerType === 'map') {
            $this->addLocalVar($var, Type::STD_MAP);
            $this->parseStdMap($var, $fakeCall);
        } else {
            $this->addLocalVar($var, Type::STD_ORDERED_MAP);
            $this->parseStdOrderedMap($var, $fakeCall);
        }
        $this->context->stdContainers[$var]['boxExpr'] = $sourceVar;
        return '// StdContainer<' . $this->context->stdContainers[$var]['decl'] . '>(' . $sourceVar . ')';
    }

    protected function parseStdMapKeyType(NodeAbstract $expr, string $owner): string
    {
        if (!$this->isClassConstFetch($expr) || !$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)) {
            $this->fatalError($expr, "{$owner} expects Type::Int or Type::String");
        }
        $className = ltrim($expr->class->toString(), '\\');
        $constName = $expr->name->name;
        if (strcasecmp($className, 'Type') === 0 && $constName === 'Int') {
            return Type::INT;
        }
        if (strcasecmp($className, 'Type') === 0 && in_array($constName, ['String', 'Str'], true)) {
            return Type::STR;
        }
        $this->fatalError($expr, "{$owner} key only supports Type::Int or Type::String");
    }

    protected function getStdMapDecl(
        string $containerType,
        string $keyType,
        string $valueType,
        ?string $class = null,
    ): string
    {
        return $containerType . '<' . $keyType . ', '
            . $this->getStdContainerElementType($valueType, $class) . '>';
    }

    protected function parseStdArray(string $var, Expr\StaticCall $expr): string
    {
        $initializer = $this->getStdValueInitializer($expr);
        if ($initializer !== null) {
            $inferred = $this->inferStdArrayInitializer($initializer);
            $type = $inferred['type'];
            $dimensions = $inferred['dimensions'];
            $totalElements = array_product($dimensions);
            $info = [
                'kind' => 'array',
                'decl' => $this->getStdArrayDecl($type, $dimensions, $inferred['class']),
                'type' => $type,
                'class' => $inferred['class'],
                'sizes' => array_reverse($dimensions),
                'dimensions' => $dimensions,
                'bytes' => $totalElements * $this->getStdValueTypeBytes($type),
            ];
            $this->context->stdArrays[$var] = $this->addStdTypeId($info);
            return $this->genStdArrayValueInitializer($var, $info, $inferred['entries']);
        }

        $tmp = $expr;
        $nesting = [];
        $totalBytes = 0;

        while (true) {
            if (count($tmp->args) !== 2) {
                $this->fatalError($tmp, 'std::array() expects two arguments');
            }
            if (!$this->isScalarInt($tmp->args[1]->value)) {
                $this->fatalError($tmp, 'std::array() expects second argument to be an integer');
            }
            $byte = 0;
            $size = $tmp->args[1]->value->value;
            $nesting[] = $size;
            $typeExpr = $tmp->args[0]->value;
            if ($this->isClassConstFetch($typeExpr)) {
                $typeInfo = $this->parseStdValueTypeInfo($typeExpr, 'std::array');
                $type = $typeInfo['type'];
                $byte = $this->getStdValueTypeBytes($type);
                break;
            }
            if ($this->isStaticCall($typeExpr)) {
                $tmp = $typeExpr;
                if (!$this->isStdClassExpr($tmp->class) || !$this->isIdExpr($tmp->name) || strtolower($tmp->name->toString()) !== 'array') {
                    $this->fatalError($tmp, 'An incorrect `std::array` definition');
                }
            } else {
                $this->fatalError($tmp, 'std::array() expects first argument to be a class constant');
            }
        }
        $totalBytes = array_product($nesting) * $byte;

        $decl = $this->getStdArrayDecl($type, $nesting, $typeInfo['class']);
        $this->context->stdArrays[$var] = $this->addStdTypeId([
            'kind' => 'array',
            'decl' => $decl,
            'type' => $type,
            'class' => $typeInfo['class'],
            'sizes' => array_reverse($nesting),
            'dimensions' => $nesting,
            'bytes' => $totalBytes,
        ]);
        return '// ' . $decl;
    }

    protected function parseStdVector(string $var, Expr\StaticCall $expr): string
    {
        $initializer = $this->getStdValueInitializer($expr);
        if ($initializer !== null) {
            $inferred = $this->inferStdFlatInitializer($initializer, 'std::vector', 'positional');
            $decl = Type::STD_VECTOR . '<'
                . $this->getStdContainerElementType($inferred['type'], $inferred['class']) . '>';
            $info = [
                'kind' => 'vector',
                'decl' => $decl,
                'type' => $inferred['type'],
                'class' => $inferred['class'],
                'size' => null,
            ];
            $this->context->stdContainers[$var] = $this->addStdTypeId($info);
            return $this->genStdVectorValueInitializer($var, $info, $inferred['items']);
        }

        if (count($expr->args) < 1 || count($expr->args) > 2) {
            $this->fatalError($expr, 'std::vector() expects one or two arguments');
        }
        $typeInfo = $this->parseStdValueTypeInfo($expr->args[0]->value, 'std::vector');
        $type = $typeInfo['type'];
        $size = null;
        if (count($expr->args) === 2) {
            if (!$this->isScalarInt($expr->args[1]->value)) {
                $this->fatalError($expr, 'std::vector() expects second argument to be an integer');
            }
            $size = $expr->args[1]->value->value;
        }
        $decl = Type::STD_VECTOR . '<'
            . $this->getStdContainerElementType($type, $typeInfo['class']) . '>';
        $this->context->stdContainers[$var] = $this->addStdTypeId([
            'kind' => 'vector',
            'decl' => $decl,
            'type' => $type,
            'class' => $typeInfo['class'],
            'size' => $size,
        ]);
        return '// ' . $decl;
    }

    protected function parseStdMap(string $var, Expr\StaticCall $expr): string
    {
        return $this->parseStdMapBase($var, $expr, 'std::map', Type::STD_MAP, 'map');
    }

    protected function parseStdOrderedMap(string $var, Expr\StaticCall $expr): string
    {
        return $this->parseStdMapBase($var, $expr, 'std::orderedMap', Type::STD_ORDERED_MAP, 'ordered_map');
    }

    private function parseStdMapBase(string $var, Expr\StaticCall $expr, string $funcName, string $containerType, string $kind): string
    {
        $initializer = $this->getStdValueInitializer($expr);
        if ($initializer !== null) {
            $inferred = $this->inferStdFlatInitializer($initializer, $funcName, 'map');
            $decl = $this->getStdMapDecl(
                $containerType,
                $inferred['keyType'],
                $inferred['type'],
                $inferred['class'],
            );
            $info = [
                'kind' => $kind,
                'decl' => $decl,
                'type' => $inferred['type'],
                'class' => $inferred['class'],
                'keyType' => $inferred['keyType'],
            ];
            $this->context->stdContainers[$var] = $this->addStdTypeId($info);
            return $this->genStdMapValueInitializer($var, $info, $inferred['items']);
        }

        if (count($expr->args) !== 2) {
            $this->fatalError($expr, $funcName . '() expects two arguments');
        }
        $keyType = $this->parseStdMapKeyType($expr->args[0]->value, $funcName);
        $valueTypeInfo = $this->parseStdValueTypeInfo($expr->args[1]->value, $funcName);
        $valueType = $valueTypeInfo['type'];
        $decl = $this->getStdMapDecl($containerType, $keyType, $valueType, $valueTypeInfo['class']);
        $this->context->stdContainers[$var] = $this->addStdTypeId([
            'kind' => $kind,
            'decl' => $decl,
            'type' => $valueType,
            'class' => $valueTypeInfo['class'],
            'keyType' => $keyType,
        ]);
        return '// ' . $decl;
    }

    /**
     * Generate compile-time count for std containers.
     * Returns the C++ integer literal for the size, or false if not a std container.
     */
    protected function genStdContainerCount(NodeAbstract $expr): string|false
    {
        // Simple variable: $var
        if ($this->isVarExpr($expr)) {
            $var = $this->parseVariable($expr);
            if ($this->isStdArray($var)) {
                $info = $this->context->stdArrays[$var];
                $sizes = array_reverse($info['sizes']);
                return $sizes[0] . $this->getPlatform()->getIntegerLiteralSuffix();
            }
            if ($this->isStdVector($var)) {
                return $var . '_ref.size()';
            }
            if ($this->isStdContainer($var)) {
                return $var . '_ref.size()';
            }
            return false;
        }

        // ArrayDimFetch: $array[idx1][idx2]...
        if ($this->isArrayDimFetch($expr)) {
            $tmp = $expr;
            $dimLevel = 0;
            while ($this->isArrayDimFetch($tmp)) {
                $dimLevel++;
                $tmp = $tmp->var;
            }
            if ($this->isVarExpr($tmp)) {
                $var = $this->parseVariable($tmp);
                if ($this->isStdArray($var)) {
                    $info = $this->context->stdArrays[$var];
                    $outerSizes = array_reverse($info['sizes']);
                    if ($dimLevel < count($outerSizes)) {
                        return $outerSizes[$dimLevel] . $this->getPlatform()->getIntegerLiteralSuffix();
                    }
                }
            }

        }

        return false;
    }
}
