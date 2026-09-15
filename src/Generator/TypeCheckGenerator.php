<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

use TypePhp\Type;

use TypePhp\Entity\ArgInfo;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;

trait TypeCheckGenerator
{
    protected const string LATE_BOUND_TYPE_ATTRIBUTE = 'typephp_late_bound_type';

    protected function markLateBoundTypeNodes(?NodeAbstract $type): void
    {
        if ($type === null) {
            return;
        }
        if ($type instanceof NullableType) {
            $this->markLateBoundTypeNodes($type->type);
            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                $this->markLateBoundTypeNodes($member);
            }
            return;
        }
        if ($type instanceof Node\Name) {
            $name = strtolower($type->toString());
            if (in_array($name, ['self', 'static', 'parent'], true)) {
                $type->setAttribute(self::LATE_BOUND_TYPE_ATTRIBUTE, $name);
            }
        }
    }

    protected function isStrictScalarType(string $type): bool
    {
        $type = Type::getReferencedType($type);
        return in_array($type, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR], true);
    }

    protected function strictScalarTypeName(string $type): string
    {
        $type = Type::getReferencedType($type);
        return match ($type) {
            Type::INT => 'int',
            Type::FLOAT => 'float',
            Type::BOOL => 'bool',
            Type::STR => 'string',
            default => throw new \LogicException('Not a strict scalar type: ' . $type),
        };
    }

    protected function genStrictScalarCondition(string $valueExpr, string $type): string
    {
        $type = Type::getReferencedType($type);
        return match ($type) {
            Type::INT => $valueExpr . '.isInt()',
            // PHP permits int values at a float boundary even in strict mode.
            Type::FLOAT => '(' . $valueExpr . '.isFloat() || ' . $valueExpr . '.isInt())',
            Type::BOOL => $valueExpr . '.isBool()',
            Type::STR => $valueExpr . '.isString()',
            default => throw new \LogicException('Not a strict scalar type: ' . $type),
        };
    }

    protected function genStrictScalarParamCheck(
        ArgInfo $argInfo,
        string $valueExpr,
        string $callableName,
        string $argNoExpr
    ): string {
        if (!$this->isStrictScalarType($argInfo->type)) {
            return '';
        }

        $paramName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
        $throwExpr = 'php::throwArgumentTypeError(' . $valueExpr . ', '
            . $this->getLiteralString($callableName) . ', ' . $argNoExpr . ', '
            . $this->getLiteralString($paramName) . ', '
            . $this->getLiteralString($this->strictScalarTypeName($argInfo->type)) . ')';

        $code = $this->getIndent() . 'if (UNEXPECTED(!('
            . $this->genStrictScalarCondition($valueExpr, $argInfo->type) . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        return $code;
    }

    protected function genStrictScalarArgConversion(
        ArgInfo $argInfo,
        string $valueExpr,
        string $callableName,
        string $argNoExpr
    ): string {
        $helper = match ($argInfo->type) {
            Type::INT => 'php::toIntArgExact',
            Type::FLOAT => 'php::toFloatArgExact',
            Type::BOOL => 'php::toBoolArgExact',
            Type::STR => 'php::toStringArgExact',
            default => throw new \LogicException('Not a strict scalar type: ' . $argInfo->type),
        };
        $paramName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);

        return $helper . '(' . $valueExpr . ', '
            . $this->getLiteralString($callableName) . ', ' . $argNoExpr . ', '
            . $this->getLiteralString($paramName) . ')';
    }

    protected function genStrictScalarReturnCheck(string $valueExpr, string $returnType): string
    {
        if (!$this->isStrictScalarType($returnType)) {
            return '';
        }

        $fnName = $this->getTypeCheckCallableName();
        $code = $this->getIndent() . 'if (UNEXPECTED(!('
            . $this->genStrictScalarCondition($valueExpr, $returnType) . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwReturnTypeError(' . $valueExpr . ', '
            . $this->getLiteralString($fnName) . ', '
            . $this->getLiteralString($this->strictScalarTypeName($returnType)) . ', '
            . $this->escapeBool(true) . ');' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        return $code;
    }

    protected function buildTypeCheckFromNode(NodeAbstract $typeNode, bool $includeSimpleType = false): array
    {
        $check = [];
        $typeStr = $this->typeCheckNodeToString($typeNode);

        if ($typeNode instanceof NullableType) {
            $check[] = ['kind' => 'isNull'];
            $innerClause = $this->buildTypeCheckClause($typeNode->type);
            if (!empty($innerClause)) {
                $check[] = count($innerClause) === 1 ? $innerClause[0] : ['kind' => 'allOf', 'types' => $innerClause];
            }
        } elseif ($typeNode instanceof UnionType) {
            foreach ($typeNode->types as $subType) {
                $clause = $this->buildTypeCheckClause($subType);
                if (empty($clause)) {
                    continue;
                }
                $check[] = count($clause) === 1 ? $clause[0] : ['kind' => 'allOf', 'types' => $clause];
            }
        } elseif ($typeNode instanceof IntersectionType) {
            $clause = $this->buildTypeCheckClause($typeNode);
            if (!empty($clause)) {
                $check[] = count($clause) === 1 ? $clause[0] : ['kind' => 'allOf', 'types' => $clause];
            }
        } elseif ($includeSimpleType) {
            $clause = $this->buildTypeCheckClause($typeNode);
            if (!empty($clause)) {
                $check[] = count($clause) === 1 ? $clause[0] : ['kind' => 'allOf', 'types' => $clause];
            }
        }

        if (empty($check)) {
            return ['check' => [], 'typeStr' => $typeStr];
        }

        return ['check' => $check, 'typeStr' => $typeStr];
    }

    private function buildTypeCheckClause(NodeAbstract $typeNode): array
    {
        if ($typeNode instanceof IntersectionType) {
            $clause = [];
            foreach ($typeNode->types as $subType) {
                foreach ($this->buildTypeCheckClause($subType) as $entry) {
                    $clause[] = $entry;
                }
            }
            return $clause;
        }

        $name = $this->parseIdentifier($typeNode);
        $nameLower = strtolower($name);

        if ($nameLower === 'void' || $nameLower === 'never') {
            $this->fatalError($typeNode, "Type '{$nameLower}' cannot be part of a composite type");
        }

        if ($nameLower === 'mixed') {
            return [];
        }

        $entry = match ($nameLower) {
            'int' => ['kind' => 'isInt'],
            'float', 'double' => ['kind' => 'isFloat'],
            'bool' => ['kind' => 'isBool'],
            'string' => ['kind' => 'isString'],
            'array' => ['kind' => 'isArray'],
            'object' => ['kind' => 'isObject'],
            'null' => ['kind' => 'isNull'],
            'true' => ['kind' => 'isTrue'],
            'false' => ['kind' => 'isFalse'],
            'resource' => ['kind' => 'isResource'],
            'callable' => ['kind' => 'callable'],
            'iterable' => ['kind' => 'iterable'],
            default => null,
        };

        if ($entry !== null) {
            return [$entry];
        }

        $lateBound = $typeNode instanceof Node\Name
            ? $typeNode->getAttribute(self::LATE_BOUND_TYPE_ATTRIBUTE, '')
            : '';
        $lateBound = is_string($lateBound) ? $lateBound : '';
        if ($lateBound === 'self') {
            $class = $this->getFullClassLikeName();
        } elseif ($lateBound === 'parent') {
            $class = $this->classDef->extends ?? '';
        } elseif ($lateBound === 'static') {
            $class = 'static';
        } else {
            $class = $this->getNamespacedClassName($name);
        }

        if ($lateBound !== '') {
            $typeNode->setAttribute(self::LATE_BOUND_TYPE_ATTRIBUTE, $lateBound);
        }
        if ($class === '' && !($lateBound === 'parent' && $this->classDef?->trait !== null)) {
            return [];
        }
        $entry = ['kind' => 'instanceof', 'class' => $class];
        if ($lateBound !== '') {
            $entry['lateBound'] = $lateBound;
        }
        return [$entry];
    }

    protected function typeCheckNodeToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof NullableType) {
            return '?' . $this->typeCheckNodeToString($typeNode->type);
        }
        if ($typeNode instanceof UnionType) {
            $parts = [];
            foreach ($typeNode->types as $type) {
                $parts[] = $this->typeCheckNodeToString($type);
            }
            return implode('|', $parts);
        }
        if ($typeNode instanceof IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $type) {
                $parts[] = $this->typeCheckNodeToString($type);
            }
            return implode('&', $parts);
        }

        return $this->printer->prettyPrint([$typeNode]);
    }

    protected function genSingleTypeCondition(string $varName, array $entry): string
    {
        $v = $varName;
        return match ($entry['kind']) {
            'isInt' => $v . '.isInt()',
            'isFloat' => $v . '.isFloat()',
            'isBool' => $v . '.isBool()',
            'isString' => $v . '.isString()',
            'isArray' => $v . '.isArray()',
            'isObject' => $v . '.isObject()',
            'isNull' => $v . '.isNull()',
            'isTrue' => $v . '.isTrue()',
            'isFalse' => $v . '.isFalse()',
            'isResource' => $v . '.isResource()',
            'callable' => $v . '.isCallable()',
            'iterable' => '(' . $v . '.isArray() || (' . $v . '.isObject() && php::instanceOf(' . $v . ', zend_ce_traversable)))',
            'allOf' => $this->genAllOfTypeCondition($varName, $entry['types']),
            'instanceof' => $entry['class'] === 'static'
                ? '(' . $v . '.isObject() && php::instanceOf(' . $v . ', ' . $this->getCalledCeExpr() . '))'
                : '(' . $v . '.isObject() && php::instanceOf(' . $v . ', ' . $this->getClassEntryPtr($entry['class']) . '))',
            default => '',
        };
    }

    private function genAllOfTypeCondition(string $varName, array $types): string
    {
        $conditions = [];
        foreach ($types as $type) {
            $cond = $this->genSingleTypeCondition($varName, $type);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }

        if (empty($conditions)) {
            return '';
        }

        return '(' . implode(' && ', $conditions) . ')';
    }

    protected function compositeTypeNeedsIntToFloatCoercion(array $typeCheck): bool
    {
        return $this->compositeTypeContainsKind($typeCheck, 'isFloat')
            && !$this->compositeTypeContainsKind($typeCheck, 'isInt');
    }

    private function compositeTypeContainsKind(array $typeCheck, string $kind): bool
    {
        foreach ($typeCheck as $entry) {
            if (($entry['kind'] ?? '') === $kind) {
                return true;
            }
            if (($entry['kind'] ?? '') === 'allOf'
                && $this->compositeTypeContainsKind($entry['types'] ?? [], $kind)) {
                return true;
            }
        }
        return false;
    }

    protected function genCompositeIntToFloatCoercion(string $varName, array $typeCheck): string
    {
        if (!$this->compositeTypeNeedsIntToFloatCoercion($typeCheck)) {
            return '';
        }

        $code = $this->getIndent() . 'if (' . $varName . '.isInt()) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $varName . ' = php::toFloat(' . $varName . ');' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        return $code;
    }

    protected function getTypeCheckCallableName(): string
    {
        if ($this->classDef) {
            return $this->classDef->getNamespacedName(false) . '::' . $this->functionDef->name;
        }

        return $this->functionDef->getNamespacedName();
    }

    protected function genUnionParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        if (empty($argInfo->typeCheck)) {
            return '';
        }

        $varName = $argInfo->name;
        if ($argInfo->variadic) {
            return $this->genUnionVariadicParamCheck($argInfo, $argIndex);
        }

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($varName, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $throwExpr = $this->genUnionParamTypeErrorExpr($argInfo, $varName, (string) ($argIndex + 1));

        $code = $this->genCompositeIntToFloatCoercion($varName, $argInfo->typeCheck);
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genUnionVariadicParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        $valueVar = $this->genTmpVarName();
        $iterVar = $this->genTmpVarName();
        $argNoVar = $this->genTmpVarName();

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($valueVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $throwExpr = $this->genUnionParamTypeErrorExpr($argInfo, $valueVar, $argNoVar);

        $code = $this->getIndent() . 'for (auto ' . $iterVar . ' = ' . $argInfo->name . '.begin(); ' . $iterVar . ' != ' . $argInfo->name . '.end(); ++' . $iterVar . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . Type::VAR . ' ' . $valueVar . ' = ' . $iterVar . '.value();' . PHP_EOL;
        if ($this->compositeTypeNeedsIntToFloatCoercion($argInfo->typeCheck)) {
            $code .= $this->getIndent() . 'if (' . $valueVar . '.isInt()) {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . $valueVar . ' = php::toFloat(' . $valueVar . ');' . PHP_EOL;
            $code .= $this->getIndent() . $iterVar . '.valueRef() = ' . $valueVar . ';' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $code .= $this->getIndent() . Type::INT . ' ' . $argNoVar . ' = ' . ($argIndex + 1) . ' + ' . $iterVar . '.index();' . PHP_EOL;
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genUnionParamTypeErrorExpr(ArgInfo $argInfo, string $valueExpr, string $argNoExpr): string
    {
        $fnName = $this->getTypeCheckCallableName();
        $paramName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
        return 'php::throwArgumentTypeError(' . $valueExpr . ', '
            . $this->getLiteralString($fnName) . ', ' . $argNoExpr . ', '
            . $this->getLiteralString($paramName) . ', ' . $this->getLiteralString($argInfo->typeStr) . ')';
    }

    protected function genUnionReturnCheck(string $varName): string
    {
        $typeCheck = $this->functionDef->returnTypeCheck;
        if (empty($typeCheck)) {
            return '';
        }

        $conditions = [];
        foreach ($typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($varName, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $fnName = $this->getTypeCheckCallableName();
        $typeStr = $this->functionDef->returnTypeStr;

        $code = $this->genCompositeIntToFloatCoercion($varName, $typeCheck);
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwReturnTypeError(' . $varName . ', '
            . $this->getLiteralString($fnName) . ', ' . $this->getLiteralString($typeStr) . ', '
            . $this->escapeBool(false) . ');' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genClosureParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        if (empty($argInfo->typeCheck)) {
            return '';
        }

        if ($argInfo->variadic) {
            return $this->genClosureVariadicParamCheck($argInfo, $argIndex);
        }

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($argInfo->name, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $throwExpr = $this->genClosureParamTypeErrorExpr($argInfo, $argInfo->name, (string) ($argIndex + 1));

        $code = $this->genCompositeIntToFloatCoercion($argInfo->name, $argInfo->typeCheck);
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $code .= $this->getIndent() . 'return php::null;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    /**
     * Emit a closure parameter type check at the call site.
     *
     * PHP evaluates all arguments first, then binds parameters in declaration
     * order. A check inside the lambda body runs after conversions, so a later
     * parameter's TypeError can be reported before an earlier one's.
     */
    protected function genCallSiteParamTypeCheck(
        array $typeInfo,
        string $valueVar,
        int $argIndex,
        string $phpName,
        ?NodeAbstract $typeNode = null,
    ): string {
        if (empty($typeInfo['check'])) {
            return '';
        }

        $argInfo = new ArgInfo();
        $argInfo->name = $valueVar;
        $argInfo->phpName = $phpName;
        $argInfo->type = Type::VAR;
        $argInfo->typeCheck = $typeInfo['check'];
        $argInfo->typeStr = $typeInfo['typeStr'] ?? '';
        $argInfo->typeNode = $typeNode;

        $conditions = [];
        foreach ($typeInfo['check'] as $entry) {
            $cond = $this->genSingleTypeCondition($valueVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if ($conditions === []) {
            return '';
        }

        $code = $this->genCompositeIntToFloatCoercion($valueVar, $typeInfo['check']);
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . implode(' || ', $conditions) . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent()
            . $this->genClosureParamTypeErrorExpr($argInfo, $valueVar, (string) ($argIndex + 1)) . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genClosureVariadicParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        $valueVar = $this->genTmpVarName();
        $iterVar = $this->genTmpVarName();
        $argNoVar = $this->genTmpVarName();

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($valueVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $throwExpr = $this->genClosureParamTypeErrorExpr($argInfo, $valueVar, $argNoVar);

        $code = $this->getIndent() . 'for (auto ' . $iterVar . ' = ' . $argInfo->name . '.begin(); ' . $iterVar . ' != ' . $argInfo->name . '.end(); ++' . $iterVar . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . Type::VAR . ' ' . $valueVar . ' = ' . $iterVar . '.value();' . PHP_EOL;
        if ($this->compositeTypeNeedsIntToFloatCoercion($argInfo->typeCheck)) {
            $code .= $this->getIndent() . 'if (' . $valueVar . '.isInt()) {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . $valueVar . ' = php::toFloat(' . $valueVar . ');' . PHP_EOL;
            $code .= $this->getIndent() . $iterVar . '.valueRef() = ' . $valueVar . ';' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $code .= $this->getIndent() . Type::INT . ' ' . $argNoVar . ' = ' . ($argIndex + 1) . ' + ' . $iterVar . '.index();' . PHP_EOL;
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $code .= $this->getIndent() . 'return php::null;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genClosureParamTypeErrorExpr(ArgInfo $argInfo, string $valueExpr, string $argNoExpr): string
    {
        $paramName = $argInfo->phpName ?: $this->unescapeVarName($argInfo->name);
        return 'php::throwArgumentTypeError(' . $valueExpr . ', '
            . $this->getLiteralString('{closure}') . ', ' . $argNoExpr . ', '
            . $this->getLiteralString($paramName) . ', ' . $this->getLiteralString($argInfo->typeStr) . ')';
    }

    protected function genClosureReturnCheck(string $varName): string
    {
        $typeCheck = $this->context->closureReturnTypeCheck;
        if (empty($typeCheck)) {
            return '';
        }

        $conditions = [];
        foreach ($typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($varName, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $typeStr = $this->context->closureReturnTypeStr;
        $code = $this->genCompositeIntToFloatCoercion($varName, $typeCheck);
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwReturnTypeError(' . $varName . ', '
            . $this->getLiteralString('{closure}') . ', ' . $this->getLiteralString($typeStr) . ', '
            . $this->escapeBool(false) . ');' . PHP_EOL;
        $code .= $this->getIndent() . 'return php::null;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

}
