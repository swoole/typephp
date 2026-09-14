<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves pipe targets and ordinary function calls.
 */

namespace TypePhp\Parser;

use TypePhp\Analysis\CompilationStatistics;
use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeAbstract;
use TypePhp\Metadata\Constants;
use TypePhp\Exception\PlaceHolder;

trait FunctionCallTrait
{
    /**
     * Resolve the one static function name used by every call path. Function
     * imports and function names are case-insensitive, unlike constant names.
     *
     * @return array{source: string, target: string, nativeLookup: string, lower: string, definitelyGlobal: bool, namespacedFallback: bool}
     */
    protected function resolveStaticFunctionCallTarget(Node\Name $name): array
    {
        $source = $this->parseIdentifier($name);
        $bare = ltrim($source, '\\');
        $unqualified = !str_contains($bare, '\\');
        $fullyQualified = $name instanceof Node\Name\FullyQualified;
        $import = $unqualified && !$fullyQualified ? strtolower($bare) : '';
        $imported = $import !== '' && isset($this->useFunctions[$import]);
        $resolved = $name->getAttribute('resolvedName');
        $target = $resolved instanceof Node\Name
            ? ltrim($resolved->toString(), '\\')
            : ($imported ? $this->useFunctions[$import] : $bare);
        $lower = strtolower($target);
        $namespacedFallback = !$fullyQualified
            && !$imported
            && $unqualified
            && $this->namespace !== '';

        return [
            'source' => $source,
            'target' => $target,
            // The raw short name is the only form that retains PHP's
            // namespace-to-global lookup fallback. All resolved/imported/
            // qualified names must not be interpreted through imports again.
            'nativeLookup' => !$fullyQualified && !$imported && $unqualified
                ? $source
                : '\\' . $target,
            'lower' => $lower,
            // A namespaced short name can be shadowed at runtime, so only
            // these forms are known to name a global builtin directly.
            'definitelyGlobal' => $fullyQualified
                || ($imported && !str_contains($target, '\\'))
                || ($this->namespace === '' && $unqualified),
            'namespacedFallback' => $namespacedFallback,
        ];
    }

    /**
     * PHP resolves an unqualified namespaced function dynamically before
     * falling back to its global builtin. Resolve on the first execution and
     * cache that target choice for the rest of the request.
     */
    protected function parseNamespacedGetCalledClassFallback(string $function): string
    {
        $function = $this->getLiteralString($function);
        $resolution = $this->getFunctionResolutionCache();
        $this->compilationStatistics->record(CompilationStatistics::DIRECT_FUNCTIONS, 'function_exists');
        return '([&]() -> php::Var { auto &resolution = ' . $resolution
            . '; if (resolution == 0) { resolution = php::fn::function_exists(' . $function
            . ') ? 1 : 2; } return resolution == 1 ? typephp_call_cached('
            . $function . ', ' . $this->getFunctionCallCache() . ') : php::Var('
            . $this->getCalledClassExpr() . '); })()';
    }

    protected function parsePipeOperator(Expr\BinaryOp\Pipe $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->left, 'pipe left operand');
        $this->assertExprCanBeUsedAsValue($expr->right, 'pipe callable');

        [$leftExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr->left);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $value = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $value . ' = ' . $leftExpr . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);

        $directCall = $this->parsePipeFirstClassCallable($expr->right, $value);
        if ($directCall !== null) {
            return $directCall;
        }

        $callable = $this->parseExprAsValue($expr->right);
        return 'typephp_call_cached(' . $callable . ', ' . $this->getFunctionCallCache()
            . ', php::VarList{' . $value . '})';
    }

    /**
     * Lower a first-class callable used as a pipe target to its direct call.
     *
     * `trim(...)`, `ClassName::method(...)`, and `$object->method(...)` do
     * not need a Closure when the pipe immediately invokes them. Reusing the
     * ordinary call parsers preserves native-call optimization, argument
     * validation, visibility checks, and the left-to-right evaluation order.
     */
    protected function parsePipeFirstClassCallable(NodeAbstract $callable, string $value): ?string
    {
        if (!$callable instanceof CallLike || !$callable->isFirstClassCallable()) {
            return null;
        }

        $directCall = clone $callable;
        $directCall->args = [new Node\Arg(new Variable($value))];

        if ($directCall instanceof Expr\FuncCall) {
            return $this->parseFuncCall($directCall);
        }
        if ($directCall instanceof Expr\StaticCall) {
            return $this->parseStaticCall($directCall);
        }
        if ($directCall instanceof Expr\MethodCall) {
            return $this->parseMethodCall($directCall);
        }

        return null;
    }

    protected function parseFuncCall(Expr\FuncCall $expr): string
    {
        $runtimeCallScope = null;
        $this->validateImmutableCall($expr);
        $pythonCall = $this->parsePythonFunctionCall($expr);
        if ($pythonCall !== null) {
            return $pythonCall;
        }
        $pythonObjectCall = $this->parsePythonObjectCall($expr);
        if ($pythonObjectCall !== null) {
            return $pythonObjectCall;
        }

        if ($this->isVarExpr($expr->name) && is_string($expr->name->name)) {
            $localName = $this->parseIdentifier($expr->name);
            $nativeClosureCall = $this->parseNativeLocalClosureCall($expr, $localName);
            if ($nativeClosureCall !== null) {
                return $nativeClosureCall;
            }
        }

        $callableClass = $this->detectClassOfExpr($expr->name);
        if ($this->isNativeObjectClass($callableClass)) {
            if ($expr->isFirstClassCallable()) {
                $this->fatalError($expr, 'Native object callables cannot be converted to Zend closures');
            }
            return $this->parseMethodCall(new Expr\MethodCall(
                $expr->name,
                new Node\Identifier('__invoke'),
                $expr->args,
            ));
        }

        if ($this->isVarExpr($expr->name)) {
            $this->compilationStatistics->record(
                CompilationStatistics::DYNAMIC_CAPABILITIES,
                'function-call',
            );
            $fn   = $this->parseIdentifier($expr->name);
            $placeHolder = $fn;
            $name = '';
        } elseif ($expr->name instanceof Node\Name) {
            $functionTarget = $this->resolveStaticFunctionCallTarget($expr->name);
            $name = $functionTarget['target'];
            $globalName = $functionTarget['lower'];
            $this->compilationStatistics->record(
                CompilationStatistics::FUNCTIONS,
                $globalName,
            );
            $namedExit = $functionTarget['definitelyGlobal']
                ? $this->parseNamedExitMessageCall($globalName, $expr)
                : null;
            if ($namedExit !== null) {
                return $namedExit;
            }
            if ($functionTarget['definitelyGlobal']
                && $globalName === 'clone'
                && !$expr->isFirstClassCallable()
                && $this->class
            ) {
                // PHP 8.5 clone-with applies property updates in the lexical
                // scope of the call site. Direct AOT method calls do not leave
                // a Zend execute frame on top, so preserve that scope while
                // invoking the builtin clone() implementation.
                $runtimeCallScope = $this->classDef?->trait
                    ? 'php::FakeScopeGuard::current()'
                    : $this->getClassEntryPtr($this->getFullClassName());
            }
            $nativeFn = $this->findNativeFunction($functionTarget['nativeLookup']);
            if ($nativeFn) {
                $functionDef = $this->getFunction($nativeFn);
                $resolvedTarget = $functionDef->getNamespacedName();
                $expr->setAttribute('nativeCall', $nativeFn);
                if ($expr->isFirstClassCallable()
                    && $this->functionUsesNativeObject($functionDef)
                ) {
                    $this->fatalError($expr, 'Native ABI functions cannot be converted to Zend closures');
                }
                // Function call placeholder, not a real function call
                if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                    return $this->genPlaceHolder($this->getLiteralString($resolvedTarget));
                }
                $this->checkNativeCallArgs($expr, $functionDef, $expr->args, $resolvedTarget);
                if ($this->shouldUseDynamicCallForNativeArgs($nativeFn, $expr->args)) {
                    return $this->genRuntimeFunctionCall($this->getFuncPtr($resolvedTarget), $expr->args, $resolvedTarget);
                }
                try {
                    $callee = $expr->getAttribute(self::ATTR_MULTI_RETURN_IMPL, false)
                        ? $this->getMultiReturnImplName($nativeFn)
                        : self::PREFIX . $nativeFn;
                    return $callee . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->getLiteralString($resolvedTarget));
                }
            }
            $mayCallGlobalBuiltin = $functionTarget['definitelyGlobal'] || $functionTarget['namespacedFallback'];
            $isNamespacedGetCalledClassFallback = $functionTarget['namespacedFallback']
                && $globalName === 'get_called_class'
                && $expr->args === []
                && $this->methodDef !== null
                && !$this->classDef?->nativeObject;
            // Policy applies only after compiled namespace shadows/imported
            // user functions have had a chance to resolve. A missing
            // namespaced short name can still fall back to a global builtin.
            if ($mayCallGlobalBuiltin) {
                $this->assertWasiFunctionSupported($expr, $globalName);
                $this->assertNanoFunctionSupported($expr, $globalName);
                if (!$isNamespacedGetCalledClassFallback && $this->isInternalFunction($globalName)) {
                    $this->markInternalFunctionCallbackCall($globalName, $expr->args);
                }
                if (in_array($globalName, Constants::UNSUPPORTED_FUNCTIONS, true)) {
                    $this->fatalError($expr, 'Unsupported function: `' . $globalName . '`');
                }
            }
            if ($mayCallGlobalBuiltin
                && ($globalName === 'get_class' || $globalName === 'get_parent_class')
                && (($expr->args === [] && $this->classDef?->nativeObject)
                    || ($expr->args !== []
                        && $this->isNativeObjectClass($this->detectClassOfExpr($expr->args[0]->value))))
            ) {
                $replacement = $globalName === 'get_class'
                    ? '`self::class` or a concrete class name'
                    : '`parent::class` or a concrete class name';
                $this->fatalError(
                    $expr,
                    "Native classes do not support runtime class introspection; use {$replacement}",
                );
            }
            if ($mayCallGlobalBuiltin
                && $globalName === 'get_called_class'
                && $this->classDef?->nativeObject
            ) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::class` or a concrete class name',
                );
            }
            // For dynamically dispatched functions, convert the function name to its fully qualified name including the namespace
            $name = $functionTarget['target'];
            if ($functionTarget['definitelyGlobal']) {
                $name = strtolower($name);
            }
            if ($mayCallGlobalBuiltin && !$isNamespacedGetCalledClassFallback) {
                $this->checkInternalFunctionArgCount($name, $expr);
            }
            if ($functionTarget['definitelyGlobal']
                && $globalName === 'get_called_class'
                && $expr->args === []
                && $this->methodDef !== null
                && !$this->classDef?->nativeObject
            ) {
                // Direct AOT calls have no Zend method frame for the builtin
                // to inspect. Reuse the runtime scope used by static::class.
                return $this->getCalledClassExpr();
            }
            if ($functionTarget['namespacedFallback']
                && $globalName === 'get_called_class'
                && $expr->args === []
                && $this->methodDef !== null
                && !$this->classDef?->nativeObject
            ) {
                return $this->parseNamespacedGetCalledClassFallback(
                    $this->namespace . '\\' . ltrim($functionTarget['source'], '\\'),
                );
            }
            $canOptimizeBuiltinFallback = $functionTarget['definitelyGlobal']
                || ($functionTarget['namespacedFallback'] && $globalName !== 'get_called_class');
            $code = $canOptimizeBuiltinFallback
                ? $this->parseFuncCallWithOptimizer($globalName, $expr)
                : false;
            if ($code !== false) {
                // Constant folding and native container operations do not
                // retain the PHP function. Record only emitted stdlib calls.
                if (str_contains($code, 'php::fn::')) {
                    $this->compilationStatistics->record(
                        CompilationStatistics::DIRECT_FUNCTIONS,
                        strtolower($globalName),
                    );
                }
                return $code;
            }
            $this->compilationStatistics->record(
                CompilationStatistics::RUNTIME_FUNCTIONS,
                strtolower($globalName),
            );
            $placeHolder = $this->getLiteralString($functionTarget['target']);
            $fn = $this->getFuncPtr($name);
            if ($this->debug) {
                $this->context->beforeStmtLines[] = $this->formatCppLineComment('Func Call: ', $name . '()');
            }
        } else {
            $this->compilationStatistics->record(
                CompilationStatistics::DYNAMIC_CAPABILITIES,
                'function-call',
            );
            $tmpVar = $this->addTmpVar(Type::VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $this->parseExpr($expr->name) . ';';
            $placeHolder = $fn = $tmpVar;
            $name = '';
        }
        if (empty($expr->args)) {
            if ($name === '' && $runtimeCallScope === null) {
                return 'typephp_call_cached(' . $fn . ', ' . $this->getFunctionCallCache() . ')';
            }
            $scopeArg = $runtimeCallScope === null ? '' : $runtimeCallScope . ', ';
            return 'php::call(' . $scopeArg . $fn . ')';
        }
        try {
            if ($name === '' && $runtimeCallScope === null) {
                return 'typephp_call_cached(' . $fn . ', ' . $this->getFunctionCallCache() . ', '
                    . $this->parseCallArgs($expr->args) . ')';
            }
            return $this->genRuntimeFunctionCall(
                $fn,
                $expr->args,
                $name,
                scope: $runtimeCallScope ?? '',
            );
        } catch (PlaceHolder) {
            return $this->genPlaceHolder($placeHolder);
        }
    }

    /**
     * Erase the static type of a value through std::any(). An omitted value
     * explicitly creates dynamic storage initialized to null.
     */
    protected function parseAnyCompileTimeCall(CallLike $expr): string
    {
        if (count($expr->args) === 0) {
            return self::VALUE_NULL;
        }
        if (count($expr->args) !== 1
            || !$expr->args[0] instanceof Node\Arg
            || $expr->args[0]->unpack
        ) {
            $this->fatalError($expr, 'The std::any function expects zero or one non-unpacked argument');
        }
        $value = $expr->args[0]->value;
        if ($this->isNativeObjectClass($this->detectClassOfExpr($value))) {
            $this->fatalError(
                $value,
                'Native objects cannot be converted to mixed with std::any(); use an explicitly typed Native variable',
            );
        }
        if ($this->isVarExpr($value)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects(
                $value,
                $this->parseIdentifier($value),
            );
        }
        return $this->parseExprAsValue($value);
    }

    /**
     * Restore a concrete Zend object type through std::object().
     */
    protected function parseObjectCompileTimeCall(Expr\StaticCall $expr): string
    {
        if (count($expr->args) !== 2
            || !$expr->args[0] instanceof Node\Arg
            || !$expr->args[1] instanceof Node\Arg
            || $expr->args[0]->unpack
            || $expr->args[1]->unpack
        ) {
            $this->fatalError($expr, 'The std::object function expects exactly two non-unpacked arguments');
        }

        $value = $this->parseExprAsValue($expr->args[0]->value);
        $className = $this->resolveClassNameArg($expr->args[1]->value);
        return 'php::toObject(' . $value . ', ' . $this->getClassEntryPtr($className) . ')';
    }

    private function parseNamedExitMessageCall(string $name, Expr\FuncCall $expr): ?string
    {
        if (!in_array(strtolower($name), ['exit', 'die'], true)
            || $expr->isFirstClassCallable()
            || count($expr->args) !== 1
        ) {
            return null;
        }

        $arg = $expr->args[0];
        if (!$arg instanceof Node\Arg
            || $arg->unpack
            || $arg->name?->toString() !== 'message'
        ) {
            return null;
        }

        // PHP 8.4 exposes this builtin argument as $status. TypePHP also
        // accepts the clearer $message alias and lowers it to the same AOT
        // exit path without changing php-parser's call representation.
        return $this->parseExit(new Expr\Exit_($arg->value, $expr->getAttributes()));
    }
}
