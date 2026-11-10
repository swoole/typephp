<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Context;

use PhpParser\Node;
use PhpParser\Node\Expr;
use SplObjectStorage;
use TypePhp\Analysis\SsaBuilder;
use TypePhp\Type;

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
     * Native Object locals whose allocation is proven not to escape this
     * function and is therefore backed by a NativeStackSlot instead of Wren.
     *
     * @var array<string, array{class: string, slot: string, allocationId: int}>
     */
    public array $nativeStackPromotions = [];

    /**
     * Declared object constraints that are not used for native-call dispatch.
     *
     * @var array<string, string>
     */
    public array $declaredObjects = [];

    /**
     * @var array<string, array>
     */
    private array $stdStorage = [];

    /** @var array<string, int> Active foreach depth for resizable container bindings. */
    private array $stdContainerIterationDepths = [];
    /** @var array<string, array{kind: string, keyType: string, type: string, class: ?string}> */
    private array $typedArrays = [];
    public array $localVars = [];
    /** @var array<string, string> Local variable => forced fallback storage type. */
    public array $varTypeDegradations = [];
    /** @var array<string, true> Locals explicitly created through std::int/float/bool. */
    public array $explicitNativeTypeVars = [];
    /** @var array<string, string> C++ initializers folded into function-scope local declarations. */
    public array $localVarInitializers = [];
    /**
     * Proven non-escaping local Closure candidates. These are declaration-site
     * plans only. Keep them after lowering so repeated parses of the same AST
     * still select the local lambda path; emitted bindings are tracked below.
     *
     * @var array<string, array{assignment: \PhpParser\Node\Expr\Assign, closure: \PhpParser\Node\Expr\Closure|\PhpParser\Node\Expr\ArrowFunction, calls: int}>
     */
    private array $localClosureCandidates = [];
    /** @var array<string, true> Local variables already emitted as concrete C++ lambdas. */
    private array $nativeLocalClosures = [];
    /** @var array<string, string> typed-ref local => directly referenced local. */
    public array $typedRefBindings = [];
    /** @var array<string, string> typed-ref local => canonical fixed-storage root. */
    public array $typedRefRoots = [];
    /** @var array<string, array<string, true>> canonical root => aliases. */
    public array $typedRefAliases = [];
    /** @var list<array<string, string>> active dynamic-call root => RefWrap variable scopes. */
    private array $typedRefBridgeScopes = [];
    public array $staticVars = [];
    public array $globalVars = [];

    /** @var array<string, string> Class name => Object local used as a static-call carrier. */
    private array $ceWrappers = [];
    /** @var array<string, string> Process-stable class name => function-local zend_class_entry* variable. */
    private array $classEntryPtrs = [];
    /** Reusable php::CallableScope local, created only when this function performs scoped calls. */
    private ?string $callableScopeVar = null;
    /** This function reads the late-static-bound class entry. */
    private bool $needsCalledCe = false;
    /** This function reads the late-static-bound class name. */
    private bool $needsCalledClass = false;
    /** This generated body needs a temporary lexical scope on the nearest user-code frame. */
    private bool $needsUserCodeCallableScope = false;
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

    /** Lexical PHP closure name; null for named functions, including generated callbacks. */
    public ?string $closureMagicName = null;
    public ?array $closureReturnTypeCheck = null;
    public string $closureReturnTypeStr = '';

    /** True if any break N (N > 1) appears in this function. */
    public bool $hasMultiLevelBreak = false;

    /** True if any continue N (N > 1) appears in this function. */
    public bool $hasMultiLevelContinue = false;

    public StatementBuffer $statements;
    /**
     * Materialized expression results belong to the context that emitted their
     * assignments. Repeated parses in this context reuse the same temporary;
     * another context lowering the same AST must emit its own assignment.
     *
     * @var SplObjectStorage<Node, string>
     */
    private SplObjectStorage $materializedExpressions;
    public array $objectProps;
    /** @var array<string, array{name: string, accessorName: string, resolver: string}> */
    private array $staticPropRefs = [];
    public int $scopeLevel = 0;
    /**
     * @var array<int, ScopeContext>
     */
    public array $scopeLayouts = [];

    public function __construct()
    {
        $this->statements = new StatementBuffer();
        $this->materializedExpressions = new SplObjectStorage();
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
        $this->nativeStackPromotions = [];
        $this->declaredObjects = [];
        $this->stdStorage = [];
        $this->stdContainerIterationDepths = [];
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
        $this->scopeLevel = 0;
        $this->inLoop = false;
        $this->inContinuableLoop = false;
        $this->inClosure = false;
        $this->closureMagicName = null;
        $this->closureReturnTypeCheck = null;
        $this->closureReturnTypeStr = '';
    }

    public function addLocalVar(string $name, string $type): void
    {
        $this->localVars[$name] = $this->varTypeDegradations[$name] ?? $type;
    }

    /** Look up only this AST object's result in the current emission scope. */
    public function getMaterializedExpression(Node $expression): ?string
    {
        return $this->materializedExpressions->offsetExists($expression)
            ? $this->materializedExpressions->offsetGet($expression)
            : null;
    }

    /** Publish the assignment and cached result together, after storage exists. */
    public function registerMaterializedExpression(Node $expression, string $temporary, string $assignment): void
    {
        if (!isset($this->localVars[$temporary]) || $assignment === '') {
            throw new \LogicException('Materialized expressions require local storage and an assignment');
        }
        $this->statements->addBefore($assignment);
        $this->materializedExpressions->offsetSet($expression, $temporary);
    }

    /** @param callable(): string $allocateName */
    public function getOrCreateCallableScope(callable $allocateName): string
    {
        if ($this->callableScopeVar === null) {
            $this->callableScopeVar = $allocateName();
        }
        return $this->callableScopeVar;
    }

    /** Inspect declarations without reserving or marking another scope. */
    public function getCallableScopeVariable(): ?string
    {
        return $this->callableScopeVar;
    }

    /**
     * Commit the guard requirement only after its declaration is reserved.
     *
     * @param callable(): string $allocateName
     */
    public function requireUserCodeCallableScope(callable $allocateName): void
    {
        $this->getOrCreateCallableScope($allocateName);
        $this->needsUserCodeCallableScope = true;
    }

    public function needsUserCodeCallableScope(): bool
    {
        return $this->needsUserCodeCallableScope;
    }

    public function requireCalledCe(): string
    {
        $this->needsCalledCe = true;
        return '_typephp_called_ce';
    }

    /** The called class name always depends on the called class entry. */
    public function requireCalledClass(): string
    {
        $this->needsCalledCe = true;
        $this->needsCalledClass = true;
        return '_typephp_called_class';
    }

    public function needsCalledCe(): bool
    {
        return $this->needsCalledCe;
    }

    public function needsCalledClass(): bool
    {
        return $this->needsCalledClass;
    }

    /**
     * Reserve a class-entry name lazily. The caller decides which classes can
     * be hoisted; these pointers have separate declarations, not Object storage.
     *
     * @param callable(): string $allocateName
     */
    public function getOrCreateLocalClassEntry(string $className, callable $allocateName): string
    {
        if (isset($this->classEntryPtrs[$className])) {
            return $this->classEntryPtrs[$className];
        }
        $entry = $allocateName();
        $this->classEntryPtrs[$className] = $entry;
        return $entry;
    }

    /** @return array<string, string> Entries in first-use declaration order. */
    public function getLocalClassEntries(): array
    {
        return $this->classEntryPtrs;
    }

    /**
     * Register the carrier's local storage with its class mapping. Initialization
     * belongs in the function preamble so it dominates all static-call branches.
     *
     * @param callable(): string $allocateName
     */
    public function getOrCreateClassWrapper(string $className, callable $allocateName): string
    {
        if (isset($this->ceWrappers[$className])) {
            return $this->ceWrappers[$className];
        }
        $object = $allocateName();
        $this->addLocalVar($object, Type::OBJECT);
        $this->ceWrappers[$className] = $object;
        return $object;
    }

    /** @return array<string, string> Carriers in first-use initialization order. */
    public function getClassWrappers(): array
    {
        return $this->ceWrappers;
    }

    /**
     * Install analysis plans before body lowering begins. Keys are PHP source
     * names, whereas emitted bindings use the caller's escaped C++ names.
     *
     * @param array<string, array{assignment: Expr\Assign, closure: Expr\Closure|Expr\ArrowFunction, calls: int}> $candidates
     */
    public function setLocalClosureCandidates(array $candidates): void
    {
        if ($this->nativeLocalClosures !== []) {
            throw new \LogicException('Cannot replace local Closure plans after lambda emission');
        }
        $this->localClosureCandidates = $candidates;
    }

    /** @return array{assignment: Expr\Assign, closure: Expr\Closure|Expr\ArrowFunction, calls: int}|null */
    public function getLocalClosureCandidate(Expr\Assign $assignment): ?array
    {
        if (!$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
            return null;
        }
        $candidate = $this->localClosureCandidates[$assignment->var->name] ?? null;
        return $candidate !== null && $candidate['assignment'] === $assignment ? $candidate : null;
    }

    /** Commit storage only after the matched candidate has lowered successfully. */
    public function registerNativeLocalClosure(string $name, Expr\Assign $assignment): void
    {
        if ($this->getLocalClosureCandidate($assignment) === null) {
            throw new \LogicException('Native local Closures require a matching assignment plan');
        }
        $this->addLocalVar($name, Type::OBJECT);
        $this->nativeLocalClosures[$name] = true;
    }

    public function isNativeLocalClosure(string $name): bool
    {
        return isset($this->nativeLocalClosures[$name]);
    }

    /**
     * Register one lazy static-property accessor per semantic key, retaining
     * its first resolver and declaration order within this function context.
     * Only the raw Zend slot is cached; each access still reads its live value.
     */
    public function registerStaticPropertySlot(string $key, string $resolver): string
    {
        if (isset($this->staticPropRefs[$key])) {
            return $this->staticPropRefs[$key]['accessorName'] . '()';
        }

        $index = count($this->staticPropRefs);
        $accessorName = '_typephp_static_property_' . $index;
        $this->staticPropRefs[$key] = [
            'name' => '_typephp_static_property_slot_' . $index,
            'accessorName' => $accessorName,
            'resolver' => $resolver,
        ];
        return $accessorName . '()';
    }

    /** @return array<string, array{name: string, accessorName: string, resolver: string}> */
    public function getStaticPropertySlots(): array
    {
        return $this->staticPropRefs;
    }

    public function getTypedArrayContract(string $name): ?array
    {
        return $this->typedArrays[$name] ?? null;
    }

    /** Attach a checked contract after the caller has registered fixed array storage. */
    public function registerTypedArrayContract(string $name, array $definition): void
    {
        if (!in_array($this->localVars[$name] ?? null, [Type::ARRAY, Type::ARRAY_REF], true)
            || isset($this->varTypeDegradations[$name])
            || isset($this->globalVars[$name])
            || isset($this->staticVars[$name])) {
            throw new \LogicException('Typed array contracts require fixed local array storage');
        }
        if (isset($this->typedArrays[$name]) && $this->typedArrays[$name] !== $definition) {
            throw new \LogicException('Typed array bindings must retain an identical list/dict contract');
        }
        if (isset($this->typedRefRoots[$name])
            && $this->getTypedArrayContract($this->getTypedRefRoot($name)) !== $definition) {
            throw new \LogicException('Typed array aliases must retain their root contract');
        }
        $this->typedArrays[$name] = $definition;
    }

    /** Import only the contract; destination storage and reference identity belong to its caller. */
    public function importTypedArrayContract(FunctionContext $source, string $name): void
    {
        $definition = $source->getTypedArrayContract($name);
        if ($definition !== null) {
            $this->registerTypedArrayContract($name, $definition);
        }
    }

    /** Commit container metadata and its local storage without changing parameter ABI. */
    public function registerStdStorage(string $name, array $info): void
    {
        $type = match ($info['kind']) {
            'array' => Type::STD_ARRAY,
            'vector' => Type::STD_VECTOR,
            'map' => Type::STD_MAP,
            'ordered_map' => Type::STD_ORDERED_MAP,
            default => throw new \LogicException('Unknown std container kind: ' . (string) $info['kind']),
        };
        if (isset($this->varTypeDegradations[$name]) || $this->getTypedArrayContract($name) !== null) {
            throw new \LogicException('Std containers require fixed container storage');
        }
        $existingType = $this->localVars[$name] ?? null;
        if ($existingType !== null && $existingType !== $type
            && !($existingType === Type::VAR && ($this->arguments[$name] ?? null) === Type::VAR)) {
            throw new \LogicException('Std container metadata must match its local storage type');
        }
        $this->addLocalVar($name, $type);
        $this->stdStorage[$name] = $info;
    }

    public function getStdStorageInfo(string $name): ?array
    {
        return $this->stdStorage[$name] ?? null;
    }

    public function bindStdBoxSource(string $name, string $source): void
    {
        if (!isset($this->stdStorage[$name])) {
            throw new \LogicException('A Box source requires registered std container storage');
        }
        $this->stdStorage[$name]['boxExpr'] = $source;
    }

    public function isStdContainerIterating(string $name): bool
    {
        return !empty($this->stdContainerIterationDepths[$name]);
    }

    /** Protect all foreach lowering, including target validation before the body. */
    public function withStdContainerIteration(string $name, callable $callback): mixed
    {
        $info = $this->getStdStorageInfo($name);
        if ($info === null) {
            throw new \LogicException('Iteration requires registered std container storage');
        }
        if ($info['kind'] === 'array') {
            return $callback();
        }
        $depth = $this->stdContainerIterationDepths[$name] ?? 0;
        $this->stdContainerIterationDepths[$name] = $depth + 1;
        try {
            return $callback();
        } finally {
            if ($depth === 0) {
                unset($this->stdContainerIterationDepths[$name]);
            } else {
                $this->stdContainerIterationDepths[$name] = $depth;
            }
        }
    }

    /** Analysis types are provisional; they do not change the storage plan. */
    public function setInferredLocalType(string $name, string $type): void
    {
        $this->localVars[$name] = $type;
    }

    public function planVariantStorage(string $name): void
    {
        $this->varTypeDegradations[$name] = Type::VAR;
    }

    /**
     * Only the PHP-visible local copy loses its object contract. The original
     * parameter ABI in arguments and the FunctionDef signature remain intact.
     */
    public function degradeArgumentStorageToVariant(string $name): void
    {
        if (!isset($this->arguments[$name])) {
            throw new \LogicException('Argument storage degradation requires a registered parameter');
        }
        $storageType = $this->localVars[$name] ?? Type::VAR;
        if (isset($this->nativeObjects[$name])
            || $storageType === Type::REF
            || Type::isTypedRefType($storageType)
        ) {
            throw new \LogicException('Native pointers and reference bindings cannot degrade to Variant storage');
        }
        $this->planVariantStorage($name);
        $this->addLocalVar($name, Type::VAR);
        $this->forgetObjectDispatchClass($name);
        unset($this->declaredObjects[$name]);
        $this->invalidateObjectTypeProofs($name);
    }

    public function declareObjectClass(string $name, string $class): void
    {
        $this->declaredObjects[$name] = $class;
    }

    public function bindObjectDispatchClass(string $name, string $class): void
    {
        $this->objects[$name] = $class;
    }

    public function bindNativeObjectClass(string $name, string $class): void
    {
        $class = ltrim($class, '\\');
        $this->nativeObjects[$name] = $class;
        $this->bindObjectDispatchClass($name, $class);
    }

    /** Suppress native method dispatch without erasing a declared constraint or pointer ABI. */
    public function forgetObjectDispatchClass(string $name): void
    {
        unset($this->objects[$name]);
    }

    public function recordStableObjectClass(string $name, string $class, bool $exact): void
    {
        $this->stableObjects[$name] = $class;
        if ($exact) {
            $this->exactObjects[$name] = $class;
        } else {
            unset($this->exactObjects[$name]);
        }
    }

    /** Runtime proofs can expire while declared classes and native storage stay fixed. */
    public function invalidateObjectTypeProofs(string $name): void
    {
        unset($this->stableObjects[$name], $this->exactObjects[$name], $this->nonNullNativeObjects[$name]);
    }

    public function markNativeObjectNonNull(string $name): void
    {
        $this->nonNullNativeObjects[$name] = true;
    }

    public function forgetNativeObjectNonNull(string $name): void
    {
        unset($this->nonNullNativeObjects[$name]);
    }

    public function registerReferenceCaptureLocal(string $name): void
    {
        if (!isset($this->localVars[$name]) && !isset($this->staticVars[$name])) {
            $this->localVars[$name] = Type::REF;
        }
    }

    /**
     * Capture a standalone initializer's value, appended locals and statements.
     * Materialized expressions belong to that helper, never to its caller or
     * another helper lowering the same AST. Keep temporary numbering monotonic
     * and retain updates to existing bindings, as this is not analysis rollback.
     *
     * @return array{mixed, array<string, string>, list<string>, list<string>}
     */
    public function captureInitializer(callable $lower): array
    {
        $originalCount = count($this->localVars);
        $materializedExpressions = $this->materializedExpressions;
        $this->materializedExpressions = new SplObjectStorage();
        try {
            [$value, $before, $after] = $this->statements->capture($lower);
            $locals = array_slice($this->localVars, $originalCount, null, true);
            return [$value, $locals, $before, $after];
        } finally {
            $this->localVars = array_slice($this->localVars, 0, $originalCount, true);
            $this->materializedExpressions = $materializedExpressions;
        }
    }

    /** Object identity implies an immutable binding; weaker facts never erase it. */
    public function markImmutableBinding(string $name, bool $mayBeObject = false): void
    {
        $this->immutableVars[$name] = true;
        if ($mayBeObject) {
            $this->immutableObjectVars[$name] = true;
        }
    }

    /** Import only the selected binding; a mutable source cannot create a contract. */
    public function importImmutableBinding(FunctionContext $source, string $name, bool $mayBeObject = false): void
    {
        if (isset($source->immutableVars[$name])) {
            $this->markImmutableBinding($name, $mayBeObject || isset($source->immutableObjectVars[$name]));
        }
    }

    public function registerTypedRefLocal(string $name, string $target, string $type): void
    {
        if (!Type::isTypedRefType($type)) {
            throw new \LogicException('Typed reference local requires a typed-ref type');
        }
        $root = $this->getTypedRefRoot($target);
        $this->addLocalVar($name, $type);
        $this->localVarInitializers[$name] = $target;
        $this->typedRefBindings[$name] = $target;
        $this->typedRefRoots[$name] = $root;
        $this->typedRefAliases[$root][$name] = true;
    }

    public function getTypedRefRoot(string $name): string
    {
        return $this->typedRefRoots[$name] ?? $name;
    }

    public function withTypedRefBridgeScope(callable $lower): mixed
    {
        $this->typedRefBridgeScopes[] = [];
        try {
            return $lower();
        } finally {
            array_pop($this->typedRefBridgeScopes);
        }
    }

    public function findTypedRefBridge(string $name): ?string
    {
        $scope = $this->activeTypedRefBridgeScope();
        return $this->typedRefBridgeScopes[$scope][$this->getTypedRefRoot($name)] ?? null;
    }

    public function registerTypedRefBridge(string $name, string $wrapper): void
    {
        $scope = $this->activeTypedRefBridgeScope();
        $root = $this->getTypedRefRoot($name);
        $existing = $this->typedRefBridgeScopes[$scope][$root] ?? null;
        if ($existing !== null && $existing !== $wrapper) {
            throw new \LogicException('Typed reference aliases must reuse one bridge within a call');
        }
        $this->typedRefBridgeScopes[$scope][$root] = $wrapper;
    }

    private function activeTypedRefBridgeScope(): int
    {
        $scope = array_key_last($this->typedRefBridgeScopes);
        if ($scope === null) {
            throw new \LogicException('Typed reference bridge requires an active call-argument scope');
        }
        return $scope;
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

    /** Parse a lexical scope without leaking its layout on failure. */
    public function withScope(callable $callback): mixed
    {
        $this->enterScope();
        try {
            return $callback();
        } finally {
            $this->leaveScope();
        }
    }

    /**
     * Switches count towards break/continue levels, but do not by themselves
     * permit continue. A switch inside a loop retains that enclosing loop.
     */
    public function withBreakableContext(bool $isSwitch, callable $callback): mixed
    {
        $inLoop = $this->inLoop;
        $inContinuableLoop = $this->inContinuableLoop;
        $breakableIsSwitch = $this->breakableIsSwitch;
        $breakableDepth = $this->breakableDepth;
        $this->inLoop = true;
        $this->inContinuableLoop = $inContinuableLoop || !$isSwitch;
        $this->breakableIsSwitch = $isSwitch;
        $this->breakableDepth = $breakableDepth + 1;
        try {
            return $callback();
        } finally {
            $this->inLoop = $inLoop;
            $this->inContinuableLoop = $inContinuableLoop;
            $this->breakableIsSwitch = $breakableIsSwitch;
            $this->breakableDepth = $breakableDepth;
        }
    }

    /**
     * Run function-entry analysis with provisional storage and emission state.
     * Restore the entry storage and discard lowering temporaries on every exit.
     * SSA, stable/exact class proofs, unsafe-property facts, storage degradation
     * plans and Immutable contracts intentionally survive this boundary.
     * Apply final loop-counter types to the restored storage after returning.
     */
    public function withAnalysisTemporaries(callable $callback): mixed
    {
        $checkpoint = new AnalysisCheckpoint($this);
        try {
            return $callback();
        } finally {
            $checkpoint->restore($this);
            $this->clearAnalysisTemporaries();
        }
    }

    private function clearAnalysisTemporaries(): void
    {
        $this->localVarInitializers = [];
        $this->localClosureCandidates = [];
        $this->nativeLocalClosures = [];
        $this->typedRefBindings = [];
        $this->typedRefRoots = [];
        $this->typedRefAliases = [];
        $this->typedRefBridgeScopes = [];
        $this->nativeStackPromotions = [];
        $this->statements->clear();
        $this->materializedExpressions = new SplObjectStorage();
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
