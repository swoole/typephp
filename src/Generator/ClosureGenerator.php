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
    /**
     * C++ types a closure parameter may be narrowed to.
     *
     * Anything outside this closed set keeps the boxed php::Var parameter and is
     * enforced by the runtime type check, because the call boundary cannot
     * faithfully produce it.
     */
    private const array NARROWABLE_CLOSURE_PARAM_TYPES = [
        Type::INT,
        Type::FLOAT,
        Type::BOOL,
        Type::STR,
        Type::ARRAY,
    ];

    /**
     * Box subclasses, which a php::Var cannot convert to.
     *
     * php::Decimal / php::BigInt / php::BigFloat have no implicit conversion from
     * php::Variant in either direction, so a Box-typed lambda parameter rejects
     * every argument that is not already a native Box value — and the same gap
     * breaks `return $x` against the lambda's php::Var return type. Both the
     * declaration path and the call-site inference path must fall back to
     * php::Var for these.
     */
    private const array BOX_CLOSURE_PARAM_TYPES = [
        Type::DECIMAL,
        Type::BIGINT,
        Type::BIGFLOAT,
        Type::BOX,
    ];

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
                // No per-parameter check here: parameter binding happens at the
                // call site (parseNativeLocalClosureCall), because PHP checks
                // parameters only after every argument has been evaluated.
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
            $code .= $this->genScopeVarDecl() . $body;
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
                // Box types are refused for the same reason plus a second one:
                // php::Var cannot convert to a Box in either direction, so the
                // lambda's own php::Var return type would also fail to accept it.
                if ($declaredType === Type::ARRAY
                    || $declaredType === Type::OBJECT
                    || $className !== ''
                    || in_array($declaredType, self::BOX_CLOSURE_PARAM_TYPES, true)
                ) {
                    return Type::VAR;
                }
                return $declaredType;
            }
        }
        // Call-site inference may only pick a type the call boundary can really
        // produce; anything else falls back to the boxed php::Var parameter.
        if (in_array($inferredType, self::NARROWABLE_CLOSURE_PARAM_TYPES, true)) {
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

        // A narrowed parameter is emitted as a fixed-type C++ local, while PHP
        // lets the body re-assign a parameter to any other type. Writing to a
        // narrowed parameter either fails to compile (int <- string) or silently
        // truncates the value (int <- float), so such a parameter keeps the
        // boxed php::Var representation instead.
        foreach ($closure->params as $i => $param) {
            if (!is_string($param->var->name)) {
                continue;
            }
            if ($this->closureParamIsWritten($closure, $param->var->name)) {
                $result[$i] = Type::VAR;
            }
        }
        return $result;
    }

    /**
     * Whether the Closure body writes to one of its own parameters.
     *
     * Anything that can replace the parameter's value or alias it disqualifies
     * narrowing: a plain assignment, an in-place operator, an increment, an
     * array-dimension write and a nested Closure capturing it by reference.
     *
     * Passing the parameter to a by-reference parameter is deliberately not
     * listed: the emitted C++ binds the same local, so the callee's write is
     * observed exactly as PHP observes it.
     */
    private function closureParamIsWritten(Expr\ArrowFunction|Expr\Closure $closure, string $paramName): bool
    {
        $nodes = $closure instanceof Expr\ArrowFunction ? [$closure->expr] : $closure->stmts;
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($nodes, Node\Expr::class) as $node) {
            if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) {
                if ($this->exprWritesVariable($node->var, $paramName)) {
                    return true;
                }
            } elseif ($node instanceof Expr\AssignRef) {
                // Both `$x = &$y` and `$y = &$x` make the parameter alias-able.
                if ($this->exprWritesVariable($node->var, $paramName)
                    || $this->isNamedVariable($node->expr, $paramName)
                ) {
                    return true;
                }
            } elseif ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc
                || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec
            ) {
                if ($this->isNamedVariable($node->var, $paramName)) {
                    return true;
                }
            }
        }

        // A nested Closure capturing the parameter by reference writes through it.
        foreach ($finder->findInstanceOf($nodes, Expr\Closure::class) as $nested) {
            foreach ($nested->uses as $use) {
                if ($use->byRef && $this->isNamedVariable($use->var, $paramName)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Whether writing through `$expr` also writes the named variable itself. */
    private function exprWritesVariable(Node\Expr $expr, string $name): bool
    {
        if ($expr instanceof Expr\ArrayDimFetch) {
            return $this->exprWritesVariable($expr->var, $name);
        }
        return $this->isNamedVariable($expr, $name);
    }

    private function isNamedVariable(Node\Expr $expr, string $name): bool
    {
        return $expr instanceof Variable && is_string($expr->name) && $expr->name === $name;
    }

    /**
     * C++ ABI type an argument expression will actually have at the lambda call
     * boundary.
     *
     * This is deliberately NOT the PHP semantic type. detectTypeOfExpr() answers
     * "which type does PHP say this expression is", which differs from the
     * emitted C++ whenever the value crosses a Zend read (boxed by
     * php::deindirect()) or gets materialized into a php::Var temporary. Only a
     * provably native result may narrow a lambda parameter; everything else stays
     * php::Var and is enforced by the runtime type check instead.
     */
    private function detectCallArgCppType(Expr $expr): string
    {
        // A Zend-dispatched read is boxed into php::Var at the call boundary.
        if ($this->shouldMaterializeCallArg($expr)) {
            return Type::VAR;
        }

        if ($this->shouldMaterializeOrderedOperand($expr)) {
            // The argument becomes a temporary whose type is decided by
            // getOrderedOperandTmpType(); that helper already defaults to
            // php::Var for anything it cannot prove is native. The generated
            // expression is not available this early, so pass an empty value and
            // let the property/static-property paths stay conservative.
            $type = $this->getOrderedOperandTmpType($expr, '');
        } else {
            // Leaf expressions (literals, variables, constants, and the unary /
            // binary / cast wrappers directly over them) keep the type of the
            // emitted C++ expression itself.
            $type = $this->detectTypeOfExpr($expr);
        }

        return $this->filterNarrowableCppType($expr, $type);
    }

    /**
     * Closed, type- and operator-level filters applied to a candidate ABI type.
     *
     * These are intentionally not an expression denylist: each rule describes a
     * fixed property of a type or of one operator, so the set cannot keep growing
     * as new syntax is supported, and an unrecognised expression is never
     * wrongly assumed to be native.
     */
    private function filterNarrowableCppType(Expr $expr, string $type): string
    {
        // Box subclasses cannot be constructed from a Variant.
        if (in_array($type, self::BOX_CLOSURE_PARAM_TYPES, true)) {
            return Type::VAR;
        }
        // varint mode: inferred int/float arithmetic stays boxed.
        if ($this->varIntTypes && in_array($type, [Type::INT, Type::FLOAT], true)) {
            return Type::VAR;
        }
        // php::fn::pow() returns Variant.
        if ($expr instanceof Expr\BinaryOp\Pow) {
            return Type::VAR;
        }
        // php::fn::mod() returns Variant for non-int operands.
        if ($expr instanceof Expr\BinaryOp\Mod && $type === Type::FLOAT) {
            return Type::VAR;
        }
        // -true / +false: PHP coerces the bool to int first, so the ABI is int.
        if ($type === Type::BOOL
            && ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus)
        ) {
            return Type::VAR;
        }
        // References and objects have no scalar ABI.
        if ($type === Type::REF || $type === Type::OBJECT || Type::isAnyRefType($type)) {
            return Type::VAR;
        }

        return $type;
    }

    private function inferCallSiteArgType(Expr $expr): string
    {
        return $this->detectCallArgCppType($expr);
    }

    protected function parseNativeLocalClosureCall(Expr\FuncCall $expr, string $name): ?string
    {
        if (!isset($this->context->nativeLocalClosures[$name])) {
            return null;
        }

        // localClosureCandidates is keyed by the PHP source variable name, which
        // differs from the emitted C++ identifier whenever the name had to be
        // escaped (for example a C++ keyword such as `union`). Looking it up by
        // the C++ name silently misses such a closure and falls back to a dynamic
        // call, which cannot accept the native C++ lambda.
        $sourceName = $this->isVarExpr($expr->name) && is_string($expr->name->name)
            ? $expr->name->name
            : $name;
        // Look up candidate for type information
        $candidate = $this->context->localClosureCandidates[$sourceName] ?? null;
        if ($candidate === null) {
            return null;
        }
        $closure = $candidate['closure'];
        $inferredTypes = $this->inferParamTypesFromCallSites($candidate);

        // LocalClosureAnalyzer::isSupportedDirectCall() guarantees that the
        // argument count matches the parameter count. Bail out to the generic
        // dynamic call rather than emitting a truncated one, should a future
        // change ever relax that precondition.
        if (count($closure->params) !== count($expr->args)) {
            return null;
        }

        // PHP evaluates every argument expression left to right first, and only
        // then binds the parameters, converting and checking them in declaration
        // order. Plan both phases up front: a composite-typed parameter keeps its
        // runtime check, so it has to be emitted at the call site too — left
        // inside the lambda body it would run after every later parameter has
        // already been converted, reporting a later argument's TypeError first.
        $plan = [];
        foreach ($closure->params as $i => $param) {
            $inferred = $inferredTypes[$i] ?? Type::VAR;
            $effective = $this->resolveEffectiveClosureParamType($param, $inferred);
            $check = null;
            if ($effective === Type::VAR
                && $param->type !== null
                && !$this->closureParamDeclIsBoxType($param)
            ) {
                $typeInfo = $this->buildTypeCheckFromNode($param->type, true);
                if (!empty($typeInfo['check'])) {
                    $check = $typeInfo;
                }
            }
            $plan[$i] = [
                'param' => $param,
                'effective' => $effective,
                // Convert only when the parameter is native but the argument is not.
                'cast' => $effective !== $inferred && $effective !== Type::VAR
                    ? $this->callSiteCastFunc($effective)
                    : null,
                'check' => $check,
            ];
        }

        // A conversion or check can throw, and C++17 leaves function argument
        // evaluation order unspecified. Materialize operands only once something
        // can actually throw and another argument follows it.
        $canThrow = false;
        foreach ($plan as $item) {
            if ($item['cast'] !== null || $item['check'] !== null) {
                $canThrow = true;
                break;
            }
        }
        $forceMaterialize = $canThrow && count($expr->args) > 1;

        // ---- Phase 1: evaluate every argument expression in PHP order ----
        $values = [];
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
            $values[] = $this->materializeCallArgValue($argument->value, $value);
        }

        // A runtime check references its value several times (condition, coercion
        // and the thrown TypeError), so give it a single-evaluation temporary.
        foreach ($plan as $i => $item) {
            if ($item['check'] !== null && isset($values[$i])) {
                $tmpVar = $this->addTmpVar(Type::VAR);
                $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $values[$i] . ';';
                $values[$i] = $tmpVar;
            }
        }

        // ---- Phase 2: bind — convert and check in declaration order ----
        $arguments = [];
        foreach ($plan as $i => $item) {
            $value = $values[$i];
            $paramName = is_string($item['param']->var->name) ? $item['param']->var->name : '?';
            if ($item['cast'] !== null) {
                $castExpr = $item['cast'] . '(' . $value . ', "{closure}", '
                    . ($i + 1) . ', "' . $paramName . '")';
                // addTmpVar() registers the temporary so genScopeVarDecl() emits
                // its declaration once; only the assignment belongs here.
                $tmpVar = $this->addTmpVar($item['effective']);
                $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $castExpr . ';';
                $value = $tmpVar;
            } elseif ($item['check'] !== null) {
                $this->context->beforeStmtLines[] = $this->genCallSiteParamTypeCheck(
                    $item['check'],
                    $value,
                    $i,
                    $paramName,
                    $item['param']->type,
                );
            }
            $arguments[] = $value;
        }

        return $name . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * Whether the parameter declares a Box type (decimal / bigint / bigfloat).
     *
     * A Box value has no faithful runtime representation yet — it is a resource,
     * so the generated type check rejects even a valid argument. The Zend
     * Closure path performs no such check either, so skip it rather than
     * changing observable behavior depending on whether narrowing applied.
     */
    private function closureParamDeclIsBoxType(Node\Param $param): bool
    {
        $type = $param->type;
        if ($type === null
            || $type instanceof NullableType
            || $type instanceof UnionType
            || $type instanceof IntersectionType
        ) {
            return false;
        }
        [$declaredType] = $this->resolveTypeDecl($type, self::DECL_TYPE_OF_PARAM);
        return in_array($declaredType, self::BOX_CLOSURE_PARAM_TYPES, true);
    }

    /**
     * Strict conversion applied at a native local Closure call boundary.
     * Returns null when the target type has no such helper.
     */
    private function callSiteCastFunc(string $type): ?string
    {
        return match ($type) {
            Type::INT => 'php::toIntArgExact',
            Type::FLOAT => 'php::toFloatArgExact',
            Type::BOOL => 'php::toBoolArgExact',
            Type::STR => 'php::toStringArgExact',
            default => null,
        };
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
