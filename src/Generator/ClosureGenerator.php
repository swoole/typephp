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
        if (!$this->isVarExpr($assign->var) || !is_string($assign->var->name)) {
            return null;
        }

        $sourceName = $assign->var->name;
        $name = $this->parseIdentifier($assign->var);
        $candidate = $this->context->localClosureCandidates[$sourceName] ?? null;
        if ($candidate === null || $candidate['assignment'] !== $assign) {
            return null;
        }

        $expr = $candidate['closure'];
        $uses = $expr instanceof Expr\ArrowFunction
            ? $this->collectArrowFunctionUses($expr)
            : $expr->uses;
        $capturePlan = $this->buildNativeLocalClosureCapturePlan($uses);
        if ($capturePlan === null) {
            return null;
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

        $entryContext = $this->context;
        $entryIndent = $this->indentLevel;
        $entryInGeneratorBody = $this->inGeneratorBody;

        // Infer parameter types from call sites using compiler's type analysis
        $inferredTypes = $this->inferParamTypesFromCallSites($candidate);

        $parameters = [];
        foreach ($expr->params as $i => $param) {
            $inferredType = $inferredTypes[$i] ?? Type::VAR;
            $paramType = $this->resolveEffectiveClosureParamType($param, $inferredType);

            $parameters[] = $paramType . ' ' . $this->parseIdentifier($param->var);
        }

        $code = 'auto ' . $name . ' = [' . implode(', ', $capturePlan['cpp']) . ']('
            . implode(', ', $parameters) . ') mutable -> ' . Type::VAR . ' {' . PHP_EOL;

        try {
            $this->context = new FunctionContext();
            $this->context->inClosure = true;
            $this->inGeneratorBody = false;
            $this->indentLevel = $entryIndent + 1;

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
                $inferredType = $inferredTypes[$index] ?? Type::VAR;
                $effectiveType = $this->resolveEffectiveClosureParamType($param, $inferredType);

                $this->addArgument($paramName, $effectiveType);
                if (CompileTimeAttribute::consume($param, 'Immutable')) {
                    $this->context->immutableVars[$paramName] = true;
                    if ($this->immutableTypeNodeMayBeObject($param->type)) {
                        $this->context->immutableObjectVars[$paramName] = true;
                    }
                    if ($param->type !== null) {
                        [, $parameterClass] = $this->resolveTypeDecl(
                            $param->type,
                            self::DECL_TYPE_OF_PARAM,
                        );
                        if ($parameterClass !== '') {
                            $this->addObject($paramName, $parameterClass);
                        }
                    }
                }
                $parameterChecks .= $this->genNativeLocalClosureParamTypeCheck($param, $paramName, $index, $effectiveType);
            }

            foreach ($capturePlan['bindings'] as $binding) {
                $this->addArgument($binding['name'], $binding['type']);
                if ($binding['class'] !== '') {
                    $this->addObject($binding['name'], $binding['class']);
                }
                if ($binding['immutable']) {
                    $this->context->immutableVars[$binding['name']] = true;
                    if ($binding['immutableObject']) {
                        $this->context->immutableObjectVars[$binding['name']] = true;
                    }
                }
            }

            $body = $this->genClosureBody($expr);
            if ($this->context->needsUserCodeCallableScope) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $code .= $this->genScopeVarDecl() . $parameterChecks . $body;
            if (!str_ends_with($code, PHP_EOL)) {
                $code .= PHP_EOL;
            }
            $code .= $this->getIndent(0) . '}';
        } finally {
            $this->context = $entryContext;
            $this->indentLevel = $entryIndent;
            $this->inGeneratorBody = $entryInGeneratorBody;
        }

        $this->addLocalVar($name, Type::OBJECT);
        $this->context->nativeLocalClosures[$name] = true;
        return $code;
    }

    /**
     * @param list<Node\ClosureUse> $uses
     * @return array{
     *     cpp: list<string>,
     *     bindings: list<array{name: string, type: string, class: string, immutable: bool, immutableObject: bool}>
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
                $captureType = Type::getReferenceType($valueType);
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
            ];
        }
        return ['cpp' => $cpp, 'bindings' => $bindings];
    }

    private function genNativeLocalClosureParamTypeCheck(Node\Param $param, string $var, int $index, string $inferredType): string
    {
        if ($param->type === null) {
            return '';
        }

        // Native-typed lambda uses C++ type directly; skip runtime check.
        if (in_array($inferredType, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR, Type::ARRAY], true)) {
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

    /**
     * Resolve the effective C++ type for a closure parameter.
     * Type declaration takes priority over call-site inference.
     * Call-site inference is used only when no type declaration exists.
     * Nullable/Union/Intersection declarations always resolve to VAR — the
     * runtime typeCheck must enforce the composite constraint.
     */
    private function resolveEffectiveClosureParamType(Node\Param $param, string $inferredType): string
    {
        if ($param->type !== null) {
            // Composite type declarations (?int, int|string, int&string) are
            // uniformly treated as VAR at the static stage; the runtime
            // typeCheck enforces the constraint.
            if ($param->type instanceof NullableType || $param->type instanceof UnionType || $param->type instanceof IntersectionType) {
                return Type::VAR;
            }
            [$declaredType, $className] = $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
            if ($declaredType !== Type::VAR) {
                // Array/Object/class parameters: the call boundary cannot safely
                // convert from native scalars (zend_long, double) to these types.
                // Keep as VAR so the runtime typeCheck inside the lambda enforces
                // PHP semantics (TypeError on wrong argument type).
                if ($declaredType === Type::ARRAY || $declaredType === Type::OBJECT || $className !== '') {
                    return Type::VAR;
                }
                return $declaredType;
            }
        }
        if ($inferredType !== Type::VAR) {
            return $inferredType;
        }
        return Type::VAR;
    }

    /**
     * Infer parameter types from call sites using the compiler's canonical
     * type detection. Returns Type::VAR for a parameter position when call
     * sites disagree or no call sites exist.
     */
    private function inferParamTypesFromCallSites(array $candidate): array
    {
        $closure = $candidate['closure'];
        $paramCount = count($closure->params);
        $callSites = $candidate['callSites'] ?? [];

        if (count($callSites) === 0) {
            return array_fill(0, $paramCount, Type::VAR);
        }

        // Collect detected types per parameter position across all call sites
        $allTypes = [];
        foreach ($callSites as $callSite) {
            $siteTypes = [];
            foreach ($callSite->args as $i => $arg) {
                $siteTypes[$i] = $this->inferCallSiteArgType($arg->value);
            }
            $allTypes[] = $siteTypes;
        }

        // Narrow only when every call site agrees on the same type
        $result = [];
        for ($i = 0; $i < $paramCount; $i++) {
            $firstType = $allTypes[0][$i] ?? Type::VAR;
            $agree = true;
            foreach ($allTypes as $perSite) {
                if (($perSite[$i] ?? Type::VAR) !== $firstType) {
                    $agree = false;
                    break;
                }
            }
            $result[$i] = $agree ? $firstType : Type::VAR;
        }
        return $result;
    }

    /**
     * Detect native type for call-site arguments, with edge-case overrides
     * that detectTypeOfExpr does not cover for closure narrowing.
     */
    private function inferCallSiteArgType(Expr $expr): string
    {
        $type = $this->detectTypeOfExpr($expr);

        // -true / +false: PHP coerces bool to int first, not bool.
        if ($type === Type::BOOL && ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus)) {
            return Type::VAR;
        }

        // Box subclasses (Decimal/BigInt/BigFloat) cannot be constructed from Variant.
        if (in_array($type, [Type::DECIMAL, Type::BIGINT, Type::BIGFLOAT], true)) {
            return Type::VAR;
        }

        // php::fn::pow() returns Variant.
        if ($expr instanceof Expr\BinaryOp\Pow) {
            return Type::VAR;
        }

        // php::fn::mod() returns Variant (non-INT operands).
        if ($expr instanceof Expr\BinaryOp\Mod && $type === Type::FLOAT) {
            return Type::VAR;
        }

        // varint_types: all inferred locals and non-constant ops use php::Var.
        if ($this->varIntTypes && in_array($type, [Type::INT, Type::FLOAT], true)) {
            return Type::VAR;
        }

        return $type;
    }

    protected function parseNativeLocalClosureCall(Expr\FuncCall $expr, string $name): ?string
    {
        if (!isset($this->context->nativeLocalClosures[$name])) {
            return null;
        }

        // Look up candidate for type information
        $candidate = $this->context->localClosureCandidates[$name] ?? null;
        if ($candidate === null) {
            return null;
        }
        $closure = $candidate['closure'] ?? null;
        $inferredTypes = $this->inferParamTypesFromCallSites($candidate);

        $arguments = [];
        $forceMaterialize = count($expr->args) > 1;
        foreach ($expr->args as $i => $argument) {
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
            $value = $this->materializeCallArgValue($argument->value, $value);

            // Cast variable args when effective type is native but inferred type is VAR.
            // e.g. fn(float $x)($var) → call site generates toFloatArgExact($var, ...)
            $inferredType = $inferredTypes[$i] ?? Type::VAR;
            $param = $closure->params[$i] ?? null;
            if ($param !== null) {
                $effectiveType = $this->resolveEffectiveClosureParamType($param, $inferredType);
                if ($effectiveType !== $inferredType) {
                    // effectiveType differs from inferred — need to cast at call site
                    $castFunc = match ($effectiveType) {
                        Type::INT => 'php::toIntArgExact',
                        Type::FLOAT => 'php::toFloatArgExact',
                        Type::BOOL => 'php::toBoolArgExact',
                        Type::STR => 'php::toStringArgExact',
                        default => null,
                    };
                    if ($castFunc !== null) {
                        $paramName = is_string($param->var->name) ? $param->var->name : '?';
                        $value = $castFunc . '(' . $value . ', "{closure}", ' . ($i + 1) . ', "' . $paramName . '")';
                    }
                }
            }

            $arguments[] = $value;
        }
        return $name . '(' . implode(', ', $arguments) . ')';
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

    protected function genClosure(Expr\ArrowFunction|Expr\Closure $expr, array $params, array $uses = []): string
    {
        $entryContext = $this->context;
        $entryIndent = $this->indentLevel;
        $entryInGeneratorBody = $this->inGeneratorBody;

        try {
            return $this->doGenClosure($expr, $params, $uses);
        } finally {
            $this->context = $entryContext;
            $this->indentLevel = $entryIndent;
            $this->inGeneratorBody = $entryInGeneratorBody;
        }
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
                if ($paramClass !== '' || in_array($paramType, [
                    Type::OBJECT,
                    Type::STREAM,
                    Type::BOX,
                    Type::STD_ARRAY,
                    Type::STD_VECTOR,
                    Type::STD_MAP,
                    Type::STD_ORDERED_MAP,
                ], true)) {
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

        $oriContext = $this->context;
        $this->context = new FunctionContext();

        $this->context->inClosure = true;
        if (!$isGenerator
            && ($expr->returnType instanceof NullableType
                || $expr->returnType instanceof UnionType
                || $expr->returnType instanceof IntersectionType)) {
            $returnTypeInfo = $this->buildTypeCheckFromNode($expr->returnType);
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
                    $this->context->immutableVars[$var] = true;
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
                $code .= $this->getIndent() . 'auto ' . $var . ' = ' . $argExpr . ';' . PHP_EOL;
                $this->addArgument($var, Type::VAR);
            }
            if (CompileTimeAttribute::consume($param, 'Immutable')) {
                $this->context->immutableVars[$var] = true;
                if ($this->immutableTypeNodeMayBeObject($param->type)) {
                    $this->context->immutableObjectVars[$var] = true;
                }
                if ($param->type !== null) {
                    [, $parameterClass] = $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
                    if ($parameterClass !== '') {
                        $this->addObject($var, $parameterClass);
                    }
                }
            }
            $code .= $this->genClosureParamTypeCheck($param, $var, $phpName, $i, false);
        }

        foreach ($uses as $i => $useItem) {
            $var = $this->parseIdentifier($useItem->var);
            $code .= 'auto ' . $var . ' = vars_.get(' . $i . ');' . PHP_EOL;
            $this->addArgument($var, Type::VAR);
            if (isset($oriContext->immutableVars[$var])) {
                $this->context->immutableVars[$var] = true;
                if (isset($oriContext->immutableObjectVars[$var])) {
                    $this->context->immutableObjectVars[$var] = true;
                }
            }
        }

        if ($this->methodDef && !$expr->static) {
            $this->addArgument('this_', Type::OBJECT);
            if (isset($oriContext->immutableVars['this_'])) {
                $this->context->immutableVars['this_'] = true;
                $this->context->immutableObjectVars['this_'] = true;
            }
        }

        $body = $isGenerator
            ? $this->genGeneratorClosureFactoryBody($expr, $params, $uses)
            : $this->genClosureBody($expr);
        if ($this->context->needsUserCodeCallableScope) {
            $body = $this->genUserCodeCallableScopeGuard() . $body;
        }
        $code .= $this->genScopeVarDecl() . $body;

        $this->indentLevel--;
        $this->context->inClosure = false;
        $code .= '};' . PHP_EOL;

        // Capture expressions belong to the enclosing function. Restore its
        // type table before validating references; the closure body registers
        // every captured value as php::Var and must not hide a native outer
        // variable that cannot legally acquire reference semantics.
        $this->context = $oriContext;
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
                    if (!isset($oriContext->localVars[$var])
                        && !isset($oriContext->staticVars[$var])) {
                        $oriContext->localVars[$var] = Type::REF;
                    }
                    $useVars[] = $this->convertToRef($useItem->var);
                } else {
                    $this->checkVarMustExist($useItem->var, $var);
                    $useVars[] = $var;
                }
            }
        }

        $this->context->beforeStmtLines[] = $code;

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
        $outerIndent = $this->indentLevel;
        $outerInGeneratorBody = $this->inGeneratorBody;
        $callbackVar = $this->genTmpVarName();

        $code = $this->getIndent() . 'php::ClosureFn ' . $callbackVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . Type::OBJECT . ' &this_, '
            . Type::ARGS . ' &vars_) -> ' . Type::VAR . ' {' . PHP_EOL;

        $this->context = new FunctionContext();
        $this->context->inClosure = true;
        $this->inGeneratorBody = true;
        $this->indentLevel++;

        try {
            foreach ($capturedNames as $i => $capturedName) {
                $code .= $this->getIndent() . Type::VAR . ' ' . $capturedName . ' = vars_.get(' . $i . ');' . PHP_EOL;
                $this->addArgument($capturedName, Type::VAR);
            }
            if ($this->methodDef) {
                $this->addArgument('this_', Type::OBJECT);
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
            if ($this->context->needsUserCodeCallableScope) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $this->indentLevel--;

            $code .= $this->genScopeVarDecl();
            $code .= $this->getIndent() . 'try {' . PHP_EOL;
            $code .= $body;
            $code .= $this->getIndent() . '} catch (zend_object *) {' . PHP_EOL;
            $code .= $this->getIndent() . '    return php::null;' . PHP_EOL;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        } finally {
            $this->context = $outerContext;
            $this->indentLevel = $outerIndent;
            $this->inGeneratorBody = $outerInGeneratorBody;
        }

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
        if ($this->context->beforeStmtLines) {
            $beforeCode = implode(PHP_EOL, $this->context->beforeStmtLines);
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
