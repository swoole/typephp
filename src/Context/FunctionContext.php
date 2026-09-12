<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Context;

use TypePhp\Analysis\SsaBuilder;

class FunctionContext
{
    /** SSA builder for the current function. Built once per function, discarded with the context. */
    public ?SsaBuilder $ssaBuilder = null;

    /** Map of SSA-stable object variable name => class name (SsaPropOptimizer). */
    public array $stableObjects = [];

    /**
     * SSA-stable variables whose sole definition is a concrete `new ClassName()`.
     * Unlike a declared/returned object type, these entries prove the exact
     * runtime class and may be used for conservative method devirtualization.
     *
     * @var array<string, string>
     */
    public array $exactObjects = [];

    /** Map of hoisted property refs: objName => [propName => true] (SsaPropOptimizer). */
    public array $hoistedProps = [];

    /** Map of properties that must not be hoisted: objName => [propName|'*' => true] (SsaPropOptimizer). */
    public array $unsafeObjectProps = [];

    /**
     * @var array<string, string>
     */
    public array $objects = [];

    /** @var array<string, string> Native Object pointer variable => fully-qualified class name. */
    public array $nativeObjects = [];

    /**
     * Native pointer variables proven non-null at the current parse point.
     *
     * Non-null Native parameters enter this set after their single function
     * entry check. Any assignment or unset conservatively removes the proof.
     *
     * @var array<string, true>
     */
    public array $nonNullNativeObjects = [];

    /**
     * Declared object constraints that are not used for native-call dispatch.
     *
     * @var array<string, string>
     */
    public array $declaredObjects = [];

    /**
     * @var array<string, array>
     */
    public array $stdArrays = [];

    /**
     * @var array<string, array>
     */
    public array $stdContainers = [];
    public array $localVars = [];
    /** @var array<string, string> Local variable => forced fallback storage type. */
    public array $varTypeDegradations = [];
    /** @var array<string, true> Locals explicitly created through std::int/float/bool. */
    public array $explicitNativeTypeVars = [];
    /** @var array<string, string> C++ initializers folded into function-scope local declarations. */
    public array $localVarInitializers = [];
    /**
     * Proven non-escaping local Closure candidates. These are declaration-site
     * plans only; the generator moves a successfully lowered entry into
     * nativeLocalClosures when it emits the concrete C++ lambda.
     *
     * @var array<string, array{
     *     assignment: \PhpParser\Node\Expr\Assign,
     *     closure: \PhpParser\Node\Expr\Closure|\PhpParser\Node\Expr\ArrowFunction,
     *     calls: int,
     *     callSites: list<\PhpParser\Node\Expr\FuncCall>,
     * }>
     */
    public array $localClosureCandidates = [];
    /** @var array<string, true> Local variables already emitted as concrete C++ lambdas. */
    public array $nativeLocalClosures = [];
    /** @var array<string, string> typed-ref local => directly referenced local. */
    public array $typedRefBindings = [];
    /** @var array<string, string> typed-ref local => canonical fixed-storage root. */
    public array $typedRefRoots = [];
    /** @var array<string, array<string, true>> canonical root => aliases. */
    public array $typedRefAliases = [];
    /** @var list<array<string, string>> active dynamic-call root => RefWrap variable scopes. */
    public array $typedRefBridgeScopes = [];
    public array $staticVars = [];
    public array $globalVars = [];

    /**
     * @var array<string, string>
     */
    public array $ceWrappers = [];
    /** @var array<string, string> Process-stable class name => function-local zend_class_entry* variable. */
    public array $classEntryPtrs = [];
    /** Reusable php::CallableScope local, created only when this function performs scoped calls. */
    public ?string $callableScopeVar = null;
    /** This function reads the late-static-bound class entry. */
    public bool $needsCalledCe = false;
    /** This function reads the late-static-bound class name. */
    public bool $needsCalledClass = false;
    /** This generated body needs a temporary lexical scope on the nearest user-code frame. */
    public bool $needsUserCodeCallableScope = false;
    public int $tmpVarIndex = 0;
    public array $arguments = [];
    /** @var array<string, true> Bindings protected by #[Immutable]. */
    public array $immutableVars = [];
    /** @var array<string, true> Immutable bindings which may contain object identity. */
    public array $immutableObjectVars = [];
    /** True while parsing a breakable loop or switch. */
    public bool $inLoop = false;
    /** True while parsing a for/foreach/while/do-while body. */
    public bool $inContinuableLoop = false;
    /** Number of breakable constructs (loops and switches) enclosing the statement being parsed. */
    public int $breakableDepth = 0;
    /** True when the innermost enclosing breakable construct is a switch, not a loop. */
    public bool $breakableIsSwitch = false;
    public bool $inClosure = false;
    public ?array $closureReturnTypeCheck = null;
    public string $closureReturnTypeStr = '';

    /** True if any break N (N > 1) appears in this function. */
    public bool $hasMultiLevelBreak = false;

    /** True if any continue N (N > 1) appears in this function. */
    public bool $hasMultiLevelContinue = false;

    public array $beforeStmtLines = [];
    public array $afterStmtLines = [];
    public array $objectProps;
    /** Map of lazily resolved, function-local static-property zval slots. */
    public array $staticPropRefs = [];
    public int $scopeLevel = 0;
    /**
     * @var array<int, ScopeContext>
     */
    public array $scopeLayouts = [];

    public function __construct()
    {
        $this->localVars = [];
        $this->varTypeDegradations = [];
        $this->explicitNativeTypeVars = [];
        $this->localVarInitializers = [];
        $this->localClosureCandidates = [];
        $this->nativeLocalClosures = [];
        $this->typedRefBindings = [];
        $this->typedRefRoots = [];
        $this->typedRefAliases = [];
        $this->typedRefBridgeScopes = [];
        $this->staticVars = [];
        $this->arguments = [];
        $this->immutableVars = [];
        $this->immutableObjectVars = [];
        $this->objects = [];
        $this->nativeObjects = [];
        $this->nonNullNativeObjects = [];
        $this->declaredObjects = [];
        $this->stdArrays = [];
        $this->stdContainers = [];
        $this->objectProps = [];
        $this->ssaBuilder = null;
        $this->stableObjects = [];
        $this->exactObjects = [];
        $this->hoistedProps = [];
        $this->unsafeObjectProps = [];
        $this->staticPropRefs = [];
        $this->ceWrappers = [];
        $this->classEntryPtrs = [];
        $this->callableScopeVar = null;
        $this->needsCalledCe = false;
        $this->needsCalledClass = false;
        $this->tmpVarIndex = 0;
        $this->scopeLayouts = [];
        $this->callableScopeVar = null;
        $this->scopeLevel = 0;
        $this->inLoop = false;
        $this->inContinuableLoop = false;
        $this->inClosure = false;
        $this->closureReturnTypeCheck = null;
        $this->closureReturnTypeStr = '';
    }

    public function enterScope(): void
    {
        $this->scopeLayouts[$this->scopeLevel] = new ScopeContext();
        $this->scopeLevel++;
    }

    public function leaveScope(): void
    {
        $this->scopeLevel--;
        unset($this->scopeLayouts[$this->scopeLevel]);
    }

    public function resetAnalysisTemporaries(
        array $localVars,
        int $tmpVarIndex,
        array $declaredObjects,
        array $nativeObjects = [],
        array $nonNullNativeObjects = [],
    ): void
    {
        $this->localVars = $localVars;
        $this->localVarInitializers = [];
        $this->localClosureCandidates = [];
        $this->nativeLocalClosures = [];
        $this->typedRefBindings = [];
        $this->typedRefRoots = [];
        $this->typedRefAliases = [];
        $this->typedRefBridgeScopes = [];
        $this->tmpVarIndex = $tmpVarIndex;
        $this->declaredObjects = $declaredObjects;
        $this->nativeObjects = $nativeObjects;
        $this->nonNullNativeObjects = $nonNullNativeObjects;
        $this->beforeStmtLines = [];
        $this->afterStmtLines = [];
        $this->objectProps = [];
        $this->hoistedProps = [];
        $this->staticPropRefs = [];
        $this->classEntryPtrs = [];
        $this->needsCalledCe = false;
        $this->needsCalledClass = false;
        $this->scopeLayouts = [];
        $this->scopeLevel = 0;
        $this->inLoop = false;
        $this->inContinuableLoop = false;
    }
}
