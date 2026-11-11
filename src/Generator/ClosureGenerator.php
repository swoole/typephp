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
use TypePhp\Context\FunctionContext;
use TypePhp\Transform\CompileTimeAttribute;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\Node\Expr\Variable;

trait ClosureGenerator
{
    protected function genNewClosure(string $callback, string $uses, bool $hasThis, array $params = []): string
    {
        $thisArg = $hasThis ? 'this_' : '{}';
        if ($this->classDef?->trait !== null) {
            // PHP flattens a trait method into the consuming class. A closure
            // declared in that method therefore uses the consuming class as
            // its lexical scope, never the trait's own class entry.
            $scope = $this->getCalledCeExpr();
        } else {
            $scope = $this->class
                ? $this->getClassEntryPtr($this->getFullClassName())
                : 'nullptr';
        }
        $parameterDescriptors = [];
        foreach ($params as $param) {
            $name = is_string($param->var->name)
                ? $param->var->name
                : $this->unescapeVarName($this->parseIdentifier($param->var));
            $parameterDescriptors[] = 'php::ClosureParameter{'
                . $this->genCharPtr($name, true) . ', '
                . $this->escapeBool($param->byRef) . ', '
                . $this->escapeBool($param->variadic) . ', '
                . $this->escapeBool(!$param->variadic && $param->default === null) . '}';
        }
        return 'php::newClosureWithParameters(' . $callback . ', ' . $uses . ', ' . $thisArg . ', ' . $scope
            . ', { ' . implode(', ', $parameterDescriptors) . ' }, php::ClosureStrictTypes::Enabled)';
    }

    protected function parseArrowFunction(Expr\ArrowFunction $expr): string
    {
        return $this->genClosure($expr, $expr->params, $this->collectArrowFunctionUses($expr));
    }

    /** @return list<Node\ClosureUse> */
    private function collectArrowFunctionUses(Expr\ArrowFunction $expr): array
    {
        $nodeFinder = new NodeFinder();
        $vars = $nodeFinder->findInstanceOf($expr->expr, Variable::class);
        $uses = [];
        $params = [];

        foreach ($expr->params as $i => $param) {
            if ($param->var instanceof Variable) {
                $params[$param->var->name] = $i;
            }
        }

        foreach ($vars as $var) {
            $varName = $this->escapeVarName($this->parseVariable($var));
            if ($varName === 'this_'
                or !$this->hasLocalVar($varName)
                or isset($params[$var->name])
                or isset($uses[$varName])) {
                continue;
            }
            $uses[$varName] = new Node\ClosureUse($var);
        }
        return array_values($uses);
    }

    protected function parseClosure(Expr\Closure $expr): string
    {
        return $this->genClosure($expr, $expr->params, $expr->uses);
    }

    /**
     * Lower a proven non-escaping local Closure at its PHP creation site.
     * Returning null deliberately selects the ordinary Zend Closure path.
     */
    protected function parseNativeLocalClosureAssignment(Expr\Assign $assign): ?string
    {
        $candidate = $this->context->getLocalClosureCandidate($assign);
        if ($candidate === null) {
            return null;
        }
        $name = $this->parseIdentifier($assign->var);

        $expr = $candidate['closure'];
        $uses = $expr instanceof Expr\ArrowFunction
            ? $this->collectArrowFunctionUses($expr)
            : $expr->uses;
        $capturePlan = $this->buildNativeLocalClosureCapturePlan($uses);
        if ($capturePlan === null) {
            return null;
        }
        $writes = (new \TypePhp\Analysis\ObjectBindingAnalyzer())->analyze(
            $expr instanceof Expr\ArrowFunction ? $expr->expr : $expr->stmts, true,
        );
        foreach ($capturePlan['bindings'] as $binding) {
            if ($binding['type'] === Type::OBJECT && $binding['class'] === ''
                && (isset($writes['*']) || isset($writes[$this->unescapeVarName($binding['name'])]))) {
                return null;
            }
        }

        // The local lambda no longer crosses a Zend boundary, but its declared
        // PHP signature remains observable at each direct call.
        foreach ($expr->params as $param) {
            if (!$param->type instanceof Node\Name) {
                $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
            }
        }
        if (!$expr->returnType instanceof Node\Name) {
            $this->resolveTypeDecl($expr->returnType, self::DECL_TYPE_OF_RETURN);
        }

        $closureMagicName = $this->getClosureMagicName($expr);
        $parameters = [];
        $body = $this->withFunctionContext(new FunctionContext(), function () use ($expr, $closureMagicName, $capturePlan, &$parameters): string {
            $this->context->inClosure = true;
            $this->context->closureMagicName = $closureMagicName;
            $this->inGeneratorBody = false;
            $this->indentLevel++;

            $body = $expr instanceof Expr\ArrowFunction ? $expr->expr : $expr->stmts;
            $this->prepareReferenceCaptureDegradations($body);
            $this->prepareLiteralArrayShapes($body);
            $this->prepareObjectBindingFacts($body);

            $returnType = $expr->returnType;
            $returnTypeName = $returnType instanceof Node\Identifier
                ? strtolower($returnType->name)
                : '';
            if ($returnType !== null && $returnTypeName !== 'void') {
                $returnTypeInfo = $this->buildTypeCheckFromNode($returnType, true);
                if (!empty($returnTypeInfo['check'])) {
                    $this->context->closureReturnTypeCheck = $returnTypeInfo['check'];
                    $this->context->closureReturnTypeStr = $returnTypeInfo['typeStr'];
                }
            }

            $parameterChecks = '';
            foreach ($expr->params as $index => $param) {
                $paramName = $this->parseIdentifier($param->var);
                [$input, $initialization] = $this->prepareClosureParameterStorage($param, $index);
                $parameters[] = Type::VAR . ' ' . $input;
                if (CompileTimeAttribute::consume($param, 'Immutable')) {
                    $this->context->markImmutableBinding($paramName, $this->immutableTypeNodeMayBeObject($param->type));
                }
                $parameterChecks .= $initialization;
            }

            foreach ($capturePlan['bindings'] as $binding) {
                if ($binding['typedArray'] !== null && isset($this->context->varTypeDegradations[$binding['name']])) {
                    $this->fatalError($expr, 'Typed arrays cannot be captured by reference');
                }
                $this->addArgument($binding['name'], $binding['type']);
                if ($binding['typedArray'] !== null) {
                    $this->context->registerTypedArrayContract($binding['name'], $binding['typedArray']);
                }
                if ($binding['class'] !== '') {
                    $this->context->declareObjectClass($binding['name'], $binding['class']);
                    if ($this->isFinalClass($binding['class'])) {
                        $this->addObject($binding['name'], $binding['class']);
                    }
                }
                if ($binding['immutable']) {
                    $this->context->markImmutableBinding($binding['name'], $binding['immutableObject']);
                }
            }

            $body = $this->genClosureBody($expr);
            if ($this->context->needsUserCodeCallableScope()) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $code = $this->genScopeVarDecl() . $parameterChecks . $body;
            if (!str_ends_with($code, PHP_EOL)) {
                $code .= PHP_EOL;
            }
            return $code;
        });

        $this->context->registerNativeLocalClosure($name, $assign, $this->getClosureDeclaredStorage($expr->returnType));
        return 'auto ' . $name . ' = [' . implode(', ', $capturePlan['cpp']) . ']('
            . implode(', ', $parameters) . ') mutable -> ' . Type::VAR . ' {' . PHP_EOL
            . $body . $this->getIndent() . '}';
    }

    /** @return array{type: string, class: string} */
    private function getClosureDeclaredStorage(?NodeAbstract $type): array
    {
        if (($type instanceof UnionType || $type instanceof IntersectionType || $type instanceof NullableType)
            && $this->isObjectOnlyTypeNode($type) && $this->getNullableObjectTypeNode($type) === null) {
            return ['type' => Type::OBJECT, 'class' => ''];
        }
        if ($type === null || ($type instanceof Node\Identifier
            && in_array(strtolower($type->name), ['void', 'never', 'mixed', 'any'], true))) {
            return ['type' => Type::VAR, 'class' => ''];
        }
        $entries = $this->buildTypeCheckFromNode($type, true)['check'];
        $class = '';
        $storage = Type::VAR;
        $nullable = false;
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'isNull') {
                $nullable = true;
                continue;
            }
            if ($storage !== Type::VAR) {
                return ['type' => Type::VAR, 'class' => ''];
            }
            $storage = match ($entry['kind']) {
                'isInt' => Type::INT, 'isFloat' => Type::FLOAT, 'isBool' => Type::BOOL,
                'isString' => Type::STR, 'isArray' => Type::ARRAY,
                'isObject', 'instanceof' => Type::OBJECT,
                default => Type::VAR,
            };
            if ($storage === Type::VAR) {
                return ['type' => Type::VAR, 'class' => ''];
            }
            $class = $entry['class'] ?? '';
        }
        if ($nullable && $storage !== Type::OBJECT) {
            return ['type' => Type::VAR, 'class' => ''];
        }
        return ['type' => $storage, 'class' => $class === 'static' ? '' : $class];
    }

    /** @return array{string, string} Raw ABI name and checked typed initialization. */
    private function prepareClosureParameterStorage(Node\Param $param, int $index): array
    {
        $name = $this->parseIdentifier($param->var);
        $storage = $this->getClosureDeclaredStorage($param->type);
        $type = isset($this->context->varTypeDegradations[$name]) ? Type::VAR : $storage['type'];
        if ($type === Type::OBJECT && $storage['class'] === ''
            && $this->context->getObjectBindingWriteCount($name) > 0) {
            $type = Type::VAR;
        }
        $input = $type === Type::VAR ? $name : $this->genTmpVarName();
        $this->addArgument($name, $type);
        $code = $this->genNativeLocalClosureParamTypeCheck($param, $input, $index);
        if ($input !== $name) {
            $value = $type === Type::OBJECT ? 'php::Object(' . $input . ')' : $this->convertExprFromType($type, $input);
            $code .= $this->getIndent() . $type . ' ' . $name . ' = ' . $value . ';' . PHP_EOL;
            // The typed value owns the original argument before user code runs.
            $code .= $this->getIndent() . $input . '.unset();' . PHP_EOL;
        }
        if ($type === Type::OBJECT && $storage['class'] !== '') {
            $this->context->declareObjectClass($name, $storage['class']);
            if ($this->isFinalClass($storage['class'])) {
                $this->addObject($name, $storage['class']);
            }
        }
        return [$input, $code];
    }

    private function importCapturedClosureValue(FunctionContext $outer, string $name, string $value, bool $byRef): string
    {
        $type = Type::getReferencedType($outer->localVars[$name] ?? Type::VAR);
        $class = $outer->declaredObjects[$name] ?? $outer->objects[$name] ?? $outer->stableObjects[$name] ?? '';
        if ($byRef || isset($this->context->varTypeDegradations[$name])
            || ($type === Type::OBJECT && $class === '' && $this->context->getObjectBindingWriteCount($name) > 0)
            || !in_array($type, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY, Type::OBJECT], true)) {
            $type = Type::VAR;
        }
        $this->addArgument($name, $type);
        if ($type === Type::OBJECT) {
            if ($class !== '') {
                $this->context->declareObjectClass($name, $class);
                if ($this->isFinalClass($class)) {
                    $this->addObject($name, $class);
                }
            }
        }
        $converted = $type === Type::OBJECT ? 'php::Object(' . $value . ')' : $this->convertExprFromType($type, $value);
        return $this->getIndent() . $type . ' ' . $name . ' = ' . $converted . ';' . PHP_EOL;
    }

    /**
     * @param list<Node\ClosureUse> $uses
     * @return array{
     *     cpp: list<string>,
     *     bindings: list<array{name: string, type: string, class: string, immutable: bool, immutableObject: bool, typedArray: ?array}>
     * }|null
     */
    private function buildNativeLocalClosureCapturePlan(array $uses): ?array
    {
        $cpp = [];
        $bindings = [];
        foreach ($uses as $useItem) {
            if (!$this->isVarExpr($useItem->var) || !is_string($useItem->var->name)) {
                return null;
            }
            $name = $this->parseIdentifier($useItem->var);
            if (!$this->hasLocalVar($name)
                || $this->isNativeObjectVar($name)
                || $this->isStdContainer($name)
            ) {
                return null;
            }

            $rawType = $this->getRawVarType($name);
            $valueType = Type::getReferencedType($rawType);
            if ($useItem->byRef) {
                // Reference-captured locals are pre-degraded to php::Var. A
                // native, non-escaping lambda can bind that slot directly;
                // it does not need to manufacture a Zend reference wrapper.
                $captureType = $valueType === Type::VAR
                    ? Type::VAR
                    : Type::getReferenceType($valueType);
                if ($captureType === null) {
                    return null;
                }
                $cpp[] = '&' . $name;
            } else {
                if ($rawType === Type::REF || !in_array($valueType, [
                    Type::VAR,
                    Type::BOOL,
                    Type::INT,
                    Type::FLOAT,
                    Type::OBJECT,
                    Type::ARRAY,
                    Type::STR,
                ], true)) {
                    return null;
                }
                $captureType = $valueType;
                $cpp[] = $name . ' = ' . $name;
            }

            $bindings[] = [
                'name' => $name,
                'type' => $captureType,
                'class' => $valueType === Type::OBJECT ? $this->getDeclaredObjectType($name) : '',
                'immutable' => isset($this->context->immutableVars[$name]),
                'immutableObject' => isset($this->context->immutableObjectVars[$name]),
                'typedArray' => $this->context->getTypedArrayContract($name),
            ];
        }
        return ['cpp' => $cpp, 'bindings' => $bindings];
    }

    private function genNativeLocalClosureParamTypeCheck(Node\Param $param, string $var, int $index): string
    {
        if ($param->type === null) {
            return '';
        }
        $typeInfo = $this->buildTypeCheckFromNode($param->type, true);
        if (empty($typeInfo['check'])) {
            return '';
        }

        $argInfo = new ArgInfo();
        $argInfo->name = $var;
        $argInfo->phpName = is_string($param->var->name)
            ? $param->var->name
            : $this->unescapeVarName($var);
        $argInfo->type = Type::VAR;
        $argInfo->typeCheck = $typeInfo['check'];
        $argInfo->typeStr = $typeInfo['typeStr'];
        $argInfo->typeNode = $param->type;
        return $this->genClosureParamCheck($argInfo, $index);
    }

    protected function parseNativeLocalClosureCall(Expr\FuncCall $expr, string $name): ?string
    {
        if (!$this->context->isNativeLocalClosure($name)) {
            return null;
        }

        $arguments = [];
        $forceMaterialize = count($expr->args) > 1;
        foreach ($expr->args as $argument) {
            $this->assertExprCanBeUsedAsValue($argument->value, 'function argument');
            if ($this->isVarExpr($argument->value)) {
                $this->assertStdContainerDoesNotEscapeNativeObjects(
                    $argument,
                    $this->parseIdentifier($argument->value),
                );
            }
            $class = $this->detectClassOfExpr($argument->value);
            if ($class !== '' && $this->isNativeObjectClass($class)) {
                $this->fatalError(
                    $argument,
                    'Native objects cannot cross a local Closure php::Var parameter boundary',
                );
            }

            if ($this->isVarExpr($argument->value)
                && $this->isStdContainer($this->parseIdentifier($argument->value))
            ) {
                $value = $this->parseOrderedArg($argument);
            } else {
                $value = $this->parseOrderedOperand($argument->value, false, $forceMaterialize);
            }
            $arguments[] = $this->materializeCallArgValue($argument->value, $value);
        }
        $call = $name . '(' . implode(', ', $arguments) . ')';
        $storage = $this->context->getNativeLocalClosureResult($name);
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, $storage['type']);
        return $storage['type'] === Type::OBJECT
            ? 'php::Object(' . $call . ')' : $this->convertExprFromType($storage['type'], $call);
    }

    protected function isReturnStmtInLastLine(array $stmts): bool
    {
        if (count($stmts) === 0) {
            return false;
        }
        return $stmts[array_key_last($stmts)] instanceof Node\Stmt\Return_;
    }

    protected function genUserCodeCallableScopeGuard(): string
    {
        $tmpScope = $this->genTmpVarName();
        return 'php::UserCodeScopeGuard ' . $tmpScope . '{' . $this->getCallableScopeExpr() . '};' . PHP_EOL;
    }

    private function getClosureMagicName(Expr\ArrowFunction|Expr\Closure $expr): string
    {
        $parent = $this->context->closureMagicName;
        if ($parent === null) {
            if ($this->methodDef !== null) {
                $class = $this->methodDef->traitOrigin !== ''
                    ? $this->methodDef->traitOrigin
                    : $this->classDef->getNamespacedName(false);
                $method = $this->methodDef->node?->getAttribute('typephp_trait_method', $this->method)
                    ?? $this->method;
                $parent = $class . '::' . $method . '()';
            } elseif ($this->function !== '') {
                $parent = ($this->namespace !== '' ? $this->namespace . '\\' : '') . $this->function . '()';
            } else {
                $parent = $this->file;
            }
        }
        return '{closure:' . $parent . ':' . $expr->getStartLine() . '}';
    }

    protected function genClosure(Expr\ArrowFunction|Expr\Closure $expr, array $params, array $uses = []): string
    {
        return $this->withFunctionContext(
            $this->context,
            fn() => $this->doGenClosure($expr, $params, $uses),
        );
    }

    private function doGenClosure(Expr\ArrowFunction|Expr\Closure $expr, array $params, array $uses = []): string
    {
        // Closure signatures flow through the same declaration validation in
        // parseTypeDecl() as named functions (e.g. callable inside an
        // intersection or DNF member). Bare class names are skipped here: the
        // native-object walk below already resolves each of them through
        // parseTypeDecl() and owns the trait-context name rewrite, so
        // resolving them twice would re-qualify an already qualified name.
        foreach ($params as $param) {
            if (!$param->type instanceof Node\Name) {
                $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
            }
        }
        if (!$expr->returnType instanceof Node\Name) {
            $this->resolveTypeDecl($expr->returnType, self::DECL_TYPE_OF_RETURN);
        }
        if ($this->classDef?->nativeObject && !$expr->static) {
            $this->fatalError($expr, 'Native objects cannot be bound as $this to Zend closures');
        }
        foreach ($params as $param) {
            if ($this->getNativeObjectClassesFromTypeNode($param->type, self::DECL_TYPE_OF_PARAM) !== []) {
                $this->fatalError($param, 'Zend closures cannot declare native object parameters or return types');
            }
        }
        if ($this->getNativeObjectClassesFromTypeNode($expr->returnType, self::DECL_TYPE_OF_RETURN) !== []) {
            $this->fatalError($expr, 'Zend closures cannot declare native object parameters or return types');
        }
        foreach ($uses as $useItem) {
            if (!$this->isVarExpr($useItem->var)) {
                continue;
            }
            $name = $this->parseIdentifier($useItem->var);
            if ($this->isNativeObjectVar($name)) {
                $this->fatalError($useItem, 'Native objects cannot be captured by Zend closures');
            }
            if ($this->getStdContainerNativeObjectClass($name) !== '') {
                $this->fatalError(
                    $useItem,
                    'Std containers holding Native objects cannot be captured by Zend closures',
                );
            }
        }
        if ($expr instanceof Expr\ArrowFunction
            && $this->isNativeObjectClass($this->detectClassOfExpr($expr->expr))
        ) {
            $this->fatalError($expr->expr, 'Zend closures cannot return native objects');
        }

        $isGenerator = $this->closureContainsYield($expr);
        if ($isGenerator) {
            $this->validateGeneratorClosure($expr, $params);
        } elseif ($expr->byRef) {
            $this->fatalError($expr, 'Closure and arrow functions cannot return by reference');
        }
        foreach ($params as $param) {
            if ($param->byRef && $param->variadic) {
                $this->fatalError(
                    $param,
                    'By-reference variadic parameters are not supported on dynamic Closures',
                );
            }
            if ($param->byRef && $param->type !== null) {
                [$paramType, $paramClass] = $this->resolveTypeDecl(
                    $param->type,
                    self::DECL_TYPE_OF_PARAM,
                );
                if ($paramClass !== '' || (!($param->type instanceof UnionType || $param->type instanceof IntersectionType
                        || $param->type instanceof NullableType)
                    && in_array($paramType, [
                    Type::OBJECT,
                    Type::STREAM,
                    Type::BOX,
                    Type::STD_ARRAY,
                    Type::STD_VECTOR,
                    Type::STD_MAP,
                    Type::STD_ORDERED_MAP,
                ], true))) {
                    $this->fatalError(
                        $param,
                        'References are only supported for int, string, float, bool, array, mixed, or union types',
                    );
                }
            }
        }
        $tmpVar = $this->genTmpVarName();

        $code = $this->getIndent() .
            'php::ClosureFn ' . $tmpVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . Type::OBJECT . ' &this_, '
            . Type::ARGS . ' &vars_) ' .
            '-> ' . Type::VAR . ' {' . PHP_EOL;

        $closureMagicName = $this->getClosureMagicName($expr);
        $oriContext = $this->context;
        $code .= $this->withFunctionContext(new FunctionContext(), function () use ($expr, $params, $uses, $isGenerator, $closureMagicName, $oriContext): string {
            $code = '';
            $this->context->inClosure = true;
            $this->context->closureMagicName = $closureMagicName;
            $body = $expr instanceof Expr\ArrowFunction ? $expr->expr : $expr->stmts;
            $this->prepareReferenceCaptureDegradations($body);
            $this->prepareLiteralArrayShapes($body);
            $this->prepareObjectBindingFacts($body);
            if (!$isGenerator && $expr->returnType !== null
                && !($expr->returnType instanceof Node\Identifier
                    && in_array(strtolower($expr->returnType->name), ['void', 'never'], true))) {
                $returnTypeInfo = $this->buildTypeCheckFromNode($expr->returnType, true);
                if (!empty($returnTypeInfo['check'])) {
                    $this->context->closureReturnTypeCheck = $returnTypeInfo['check'];
                    $this->context->closureReturnTypeStr = $returnTypeInfo['typeStr'];
                }
            }
            $this->indentLevel++;

            $requiredArgCount = 0;
            foreach ($params as $param) {
                if ($param->variadic || $param->default !== null) {
                    break;
                }
                $requiredArgCount++;
            }

            $hasVariadic = $params !== [] && $params[array_key_last($params)]->variadic;
            $code .= $this->genParameterCountCheck($requiredArgCount, count($params), $hasVariadic);

            foreach ($params as $i => $param) {
                $var = $this->parseIdentifier($param->var);
                $phpName = is_string($param->var->name) ? $param->var->name : $this->unescapeVarName($var);
                if ($param->variadic) {
                    $code .= $this->getIndent() . Type::ARRAY . ' ' . $var . ';' . PHP_EOL;
                    $code .= $this->getIndent() . 'for (uint32_t i = ' . $i . '; i < php::getCallArgNum(); i++) {' . PHP_EOL;
                    $this->indentLevel++;
                    $code .= $this->getIndent() . $var . '.appendValue(php::getCallArg(i));' . PHP_EOL;
                    $this->indentLevel--;
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                    $code .= $this->genExtraNamedVariadicArgs($var);
                    $this->addArgument($var, Type::ARRAY);
                    if (CompileTimeAttribute::consume($param, 'Immutable')) {
                        $this->context->markImmutableBinding($var);
                    }
                    $code .= $this->genClosureParamTypeCheck($param, $var, $phpName, $i, true);
                    continue;
                }
                if ($param->byRef) {
                    $argExpr = $param->default === null
                        ? 'php::getCallArgByRef(' . $i . ')'
                        : 'php::getCallArgByRef(' . $i . ', php::newReference('
                            . $this->parseParamDefaultValue($param->default) . '))';
                    $code .= $this->getIndent() . Type::REF . ' ' . $var . ' = ' . $argExpr . ';' . PHP_EOL;
                    $this->addArgument($var, Type::REF);
                } else {
                    $argExpr = $param->default === null
                        ? 'php::getCallArg(' . $i . ')'
                        : 'php::getCallArg(' . $i . ', ' . $this->parseParamDefaultValue($param->default) . ')';
                    [$input, $initialization] = $this->prepareClosureParameterStorage($param, $i);
                    $code .= $this->getIndent() . 'auto ' . $input . ' = ' . $argExpr . ';' . PHP_EOL;
                    $code .= $initialization;
                }
                if (CompileTimeAttribute::consume($param, 'Immutable')) {
                    $this->context->markImmutableBinding($var, $this->immutableTypeNodeMayBeObject($param->type));
                }
                if ($param->byRef) {
                    $code .= $this->genClosureParamTypeCheck($param, $var, $phpName, $i, false);
                }
            }

            foreach ($uses as $i => $useItem) {
                $var = $this->parseIdentifier($useItem->var);
                if ($oriContext->getTypedArrayContract($var) !== null) {
                    if ($useItem->byRef || isset($this->context->varTypeDegradations[$var])) {
                        $this->fatalError($useItem, 'Typed arrays cannot be captured by reference');
                    }
                    $code .= 'php::Array ' . $var . ' = php::toArray(vars_.get(' . $i . '));' . PHP_EOL;
                    $this->addArgument($var, Type::ARRAY);
                    $this->context->importTypedArrayContract($oriContext, $var);
                } else {
                    $code .= $this->importCapturedClosureValue($oriContext, $var, 'vars_.get(' . $i . ')', $useItem->byRef);
                }
                $this->context->importImmutableBinding($oriContext, $var);
            }

            if ($this->methodDef && !$expr->static) {
                $this->addArgument('this_', Type::OBJECT);
                $this->context->importImmutableBinding($oriContext, 'this_', true);
            }

            $body = $isGenerator
                ? $this->genGeneratorClosureFactoryBody($expr, $params, $uses)
                : $this->genClosureBody($expr);
            if ($this->context->needsUserCodeCallableScope()) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $code .= $this->genScopeVarDecl() . $body;

            $this->indentLevel--;
            $this->context->inClosure = false;
            $code .= '};' . PHP_EOL;
            return $code;
        });

        // Capture expressions belong to the restored enclosing context. Its
        // type table must validate references before importing captured values
        // into the closure's independent type table.
        $useVars = [];
        if ($uses) {
            foreach ($uses as $useItem) {
                $var = $this->parseIdentifier($useItem->var);
                if (!$this->isVarExpr($useItem->var)) {
                    $this->fatalError($useItem->var, 'Incorrect Closure use syntax, only variable names are allowed');
                }
                if ($useItem->byRef) {
                    // For a closure use clause, a by-reference capture may create
                    // the variable in place if it does not exist yet
                    $oriContext->registerReferenceCaptureLocal($var);
                    $useVars[] = $this->convertToRef($useItem->var);
                } else {
                    $this->checkVarMustExist($useItem->var, $var);
                    $useVars[] = $var;
                }
            }
        }

        $this->addBeforeStmtLine($code);

        // Even a static closure inherits the outer called scope for late
        // static binding. It still cannot access $this because it was not
        // registered in the closure compilation context above.
        return $this->genNewClosure(
            $tmpVar,
            '{ ' . implode(', ', $useVars) . ' }',
            $this->methodDef !== null,
            $params
        );
    }

    protected function closureContainsYield(Expr\ArrowFunction|Expr\Closure $expr): bool
    {
        if ($expr instanceof Expr\ArrowFunction) {
            return $this->containsYieldInNode($expr->expr);
        }
        return $this->containsYieldInNodes($expr->stmts);
    }

    protected function validateGeneratorClosure(Expr\ArrowFunction|Expr\Closure $expr, array $params): void
    {
        if ($this->isWasiTarget()) {
            $this->fatalError($expr, 'Fiber and Generator are not supported by the WASI target');
        }
        if ($expr->byRef) {
            $this->fatalError($expr, 'Generator closures returning by reference are not supported yet');
        }
        foreach ($params as $param) {
            if ($param->byRef || $param->variadic) {
                $this->fatalError($param, 'Generator closures with by-reference or variadic parameters are not supported yet');
            }
        }
        if (!$this->generatorReturnTypeAcceptsFiber($expr->returnType)) {
            $this->fatalError(
                $expr,
                'Generator closure return type must accept \\FiberGenerator; use Iterator, Traversable, iterable, object, mixed, or omit the return type'
            );
        }
    }

    protected function genGeneratorClosureFactoryBody(
        Expr\ArrowFunction|Expr\Closure $expr,
        array $params,
        array $uses
    ): string {
        $capturedNames = [];
        $capturedArgs = [];
        foreach ($params as $param) {
            $name = $this->parseIdentifier($param->var);
            $capturedNames[] = $name;
            $capturedArgs[] = $name;
        }
        foreach ($uses as $useItem) {
            $name = $this->parseIdentifier($useItem->var);
            $capturedNames[] = $name;
            // Building an initializer_list copies Variants by value. Re-wrap
            // reference captures so the delayed Fiber callback keeps identity.
            $capturedArgs[] = $useItem->byRef ? $name . '.toReference()' : $name;
        }

        $outerContext = $this->context;
        $callbackVar = $this->genTmpVarName();

        $code = $this->getIndent() . 'php::ClosureFn ' . $callbackVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . Type::OBJECT . ' &this_, '
            . Type::ARGS . ' &vars_) -> ' . Type::VAR . ' {' . PHP_EOL;

        $code .= $this->withFunctionContext(new FunctionContext(), function () use ($expr, $capturedNames, $outerContext): string {
            $code = '';
            $this->context->inClosure = true;
            $this->context->closureMagicName = $outerContext->closureMagicName;
            $this->inGeneratorBody = true;
            $this->indentLevel++;

            $body = $expr instanceof Expr\ArrowFunction ? $expr->expr : $expr->stmts;
            $this->prepareReferenceCaptureDegradations($body);
            $this->prepareLiteralArrayShapes($body);
            $this->prepareObjectBindingFacts($body);

            foreach ($capturedNames as $i => $capturedName) {
                if ($outerContext->getTypedArrayContract($capturedName) !== null) {
                    if (isset($this->context->varTypeDegradations[$capturedName])) {
                        $this->fatalError($expr, 'Typed arrays cannot be captured by reference');
                    }
                    $code .= $this->getIndent() . Type::ARRAY . ' ' . $capturedName . ' = php::toArray(vars_.get(' . $i . '));' . PHP_EOL;
                    $this->addArgument($capturedName, Type::ARRAY);
                    $this->context->importTypedArrayContract($outerContext, $capturedName);
                } else {
                    $rawType = $outerContext->localVars[$capturedName] ?? Type::VAR;
                    $code .= $this->importCapturedClosureValue(
                        $outerContext, $capturedName, 'vars_.get(' . $i . ')', Type::isAnyRefType($rawType),
                    );
                }
                $this->context->importImmutableBinding($outerContext, $capturedName);
            }
            if ($this->methodDef) {
                $this->addArgument('this_', Type::OBJECT);
                $this->context->importImmutableBinding($outerContext, 'this_', true);
            }

            $this->indentLevel++;
            $body = '';
            if ($expr instanceof Expr\ArrowFunction) {
                [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr->expr);
                $body .= $this->formatCapturedStmtLines($beforeStmts);
                if ($afterStmts) {
                    $resultVar = $this->addTmpVar(Type::VAR);
                    $body .= $this->getIndent() . $resultVar . ' = ' . $value . ';' . PHP_EOL;
                    $body .= $this->formatCapturedStmtLines($afterStmts);
                    $value = $resultVar;
                }
                $body .= $this->getIndent() . 'return ' . $value . ';' . PHP_EOL;
            } else {
                $body .= $this->parseStmts($expr->stmts);
                if (!$this->isReturnStmtInLastLine($expr->stmts)) {
                    $body .= $this->getIndent() . 'return php::null;' . PHP_EOL;
                }
            }
            if ($this->context->needsUserCodeCallableScope()) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $this->indentLevel--;

            $code .= $this->genScopeVarDecl();
            $code .= $this->getIndent() . 'try {' . PHP_EOL;
            $code .= $body;
            $code .= $this->getIndent() . '} catch (zend_object *) {' . PHP_EOL;
            $code .= $this->getIndent() . '    return php::null;' . PHP_EOL;
            $code .= $this->getIndent() . '}' . PHP_EOL;
            return $code;
        });

        $code .= $this->getIndent() . '};' . PHP_EOL;
        $args = $capturedArgs ? '{ ' . implode(', ', $capturedArgs) . ' }' : '{}';
        $callback = $this->genNewClosure($callbackVar, $args, $this->methodDef !== null, $params);
        $code .= $this->getIndent() . 'return typephp_new_fiber_generator(' . $callback . ');' . PHP_EOL;
        return $code;
    }

    protected function genClosureBody(NodeAbstract $expr): string
    {
        if ($expr instanceof Node\Expr\ArrowFunction) {
            return $this->genArrowFunctionBody($expr);
        }
        if ($expr instanceof Node\Expr\Closure) {
            return $this->genAnonymousClosureBody($expr);
        }
        $this->fatalError($expr, 'Unsupported closure expression');
    }

    protected function genArrowFunctionBody(Node\Expr\ArrowFunction $expr): string
    {
        if (!empty($this->context->closureReturnTypeCheck)) {
            $this->checkCompositeTypeAssignment(
                $expr,
                $this->context->closureReturnTypeCheck,
                $this->context->closureReturnTypeStr,
                $expr->expr,
                'closure return value'
            );
        }
        $code = $this->parseExpr($expr->expr);
        if ($this->context->statements->hasBefore()) {
            $beforeCode = implode(PHP_EOL, $this->context->statements->peekBefore());
        } else {
            $beforeCode = '';
        }
        if ($this->isCallExpr($expr->expr)) {
            $nativeCall = $expr->expr->getAttribute('nativeCall');
            if ($nativeCall and $this->getFunction($nativeCall)->returnType === Type::VOID) {
                return $this->genArrowFunctionVoidReturn($beforeCode, $code);
            }
        }
        if ($this->detectTypeOfExpr($expr->expr) === Type::VOID) {
            return $this->genArrowFunctionVoidReturn($beforeCode, $code);
        }
        return $beforeCode . PHP_EOL . $this->genClosureReturnValue($code);
    }

    protected function genArrowFunctionVoidReturn(string $beforeCode, string $exprCode): string
    {
        $code = $beforeCode . PHP_EOL . $exprCode . ';' . PHP_EOL;
        return $code . $this->genClosureReturnNull();
    }

    protected function genAnonymousClosureBody(Node\Expr\Closure $expr): string
    {
        $fnCode = $this->parseStmts($expr->stmts);
        if (!$this->isReturnStmtInLastLine($expr->stmts)) {
            $fnCode .= $this->genClosureReturnNull() . PHP_EOL;
        }
        return $fnCode;
    }

    private function genClosureParamTypeCheck(Node\Param $param, string $var, string $phpName, int $index, bool $variadic): string
    {
        if (!$param->byRef
            && !$param->type instanceof NullableType
            && !$param->type instanceof UnionType
            && !$param->type instanceof IntersectionType
        ) {
            return '';
        }

        if ($param->type === null) {
            return '';
        }

        $typeInfo = $this->buildTypeCheckFromNode($param->type, $param->byRef);
        if (empty($typeInfo['check'])) {
            return '';
        }

        $argInfo = new ArgInfo();
        $argInfo->name = $var;
        $argInfo->phpName = $phpName;
        $argInfo->type = Type::VAR;
        $argInfo->variadic = $variadic;
        $argInfo->typeCheck = $typeInfo['check'];
        $argInfo->typeStr = $typeInfo['typeStr'];
        $argInfo->typeNode = $param->type;

        return $this->genClosureParamCheck($argInfo, $index);
    }
}
