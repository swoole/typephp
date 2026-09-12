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
        } elseif ($expr->name->getType() === 'Name' or $expr->name->getType() === 'Name_FullyQualified') {
            $name = $this->parseIdentifier($expr->name);
            $globalName = ltrim($name, '\\');
            $this->compilationStatistics->record(
                CompilationStatistics::FUNCTIONS,
                strtolower($globalName),
            );
            $namedExit = $this->parseNamedExitMessageCall($globalName, $expr);
            if ($namedExit !== null) {
                return $namedExit;
            }
            if ($globalName === 'clone' && !$expr->isFirstClassCallable() && $this->class) {
                // PHP 8.5 clone-with applies property updates in the lexical
                // scope of the call site. Direct AOT method calls do not leave
                // a Zend execute frame on top, so preserve that scope while
                // invoking the builtin clone() implementation.
                $runtimeCallScope = $this->classDef?->trait
                    ? 'php::FakeScopeGuard::current()'
                    : $this->getClassEntryPtr($this->getFullClassName());
            }
            if ($globalName === 'get_called_class' && $this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::class` or a concrete class name',
                );
            }
            if (($globalName === 'get_class' || $globalName === 'get_parent_class')
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
            // Capability policy is determined by the target, not by the
            // extensions loaded into the build-time PHP process. Otherwise a
            // forbidden direct call could bypass validation merely because
            // that host PHP does not expose the function.
            $this->assertWasiFunctionSupported($expr, $globalName);
            $this->assertNanoFunctionSupported($expr, $globalName);
            if ($this->isInternalFunction($globalName)) {
                $this->markInternalFunctionCallbackCall($globalName, $expr->args);
            }
            if (in_array($globalName, Constants::UNSUPPORTED_FUNCTIONS, true)) {
                $this->fatalError($expr, 'Unsupported function: `' . $globalName . '`');
            }
            $nativeFn = $this->findNativeFunction($name);
            if ($nativeFn) {
                $expr->setAttribute('nativeCall', $nativeFn);
                if ($expr->isFirstClassCallable()
                    && $this->functionUsesNativeObject($this->getFunction($nativeFn))
                ) {
                    $this->fatalError($expr, 'Native ABI functions cannot be converted to Zend closures');
                }
                // Function call placeholder, not a real function call
                if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
                $this->checkNativeCallArgs($expr, $this->getFunction($nativeFn), $expr->args, $name);
                if ($this->shouldUseDynamicCallForNativeArgs($nativeFn, $expr->args)) {
                    $functionDef = $this->getFunction($nativeFn);
                    return $this->genRuntimeFunctionCall($this->getFuncPtr($functionDef->getNamespacedName()), $expr->args, $name);
                }
                try {
                    $callee = $expr->getAttribute(self::ATTR_MULTI_RETURN_IMPL, false)
                        ? $this->getMultiReturnImplName($nativeFn)
                        : self::PREFIX . $nativeFn;
                    return $callee . '(' . $this->parseNativeCallArgs($expr->args, $nativeFn) . ')';
                } catch (PlaceHolder) {
                    return $this->genPlaceHolder($this->identifierToStr($expr->name));
                }
            }
            // For dynamically dispatched functions, convert the function name to its fully qualified name including the namespace
            $name = $this->getNamespacedFuncName($name);
            $this->checkInternalFunctionArgCount($name, $expr);
            $resolvedName = $expr->name->getAttribute('resolvedName');
            $targetName = $resolvedName instanceof Node\Name ? $resolvedName->toString() : $name;
            if (strcasecmp(ltrim($targetName, '\\'), 'get_called_class') === 0
                && $expr->args === []
                && $this->methodDef !== null
                && !$this->classDef?->nativeObject
            ) {
                // Direct AOT calls have no Zend method frame for the builtin
                // to inspect. Reuse the runtime scope used by static::class.
                return $this->getCalledClassExpr();
            }
            $code = $this->parseFuncCallWithOptimizer($name, $expr);
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
            $placeHolder = $this->identifierToStr($expr->name);
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
