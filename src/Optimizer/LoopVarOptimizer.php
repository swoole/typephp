<?php
/**
 * Range-proven loop variable optimizer.
 *
 * This pass narrows common PHP loop counters to php::Int even under
 * `use varint_types`. It is intentionally pattern-based: PHP arithmetic can
 * widen integers to floats on overflow, so only monotonic counters with a
 * statically bounded range are accepted.
 */

namespace TypePhp\Optimizer;

use TypePhp\Type;

use TypePhp\Analysis\SsaBuilder;
use TypePhp\Analysis\SsaFlags;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeAbstract;

trait LoopVarOptimizer
{
    protected const array LOOP_NON_NEGATIVE_INT_FUNCTIONS = [
        'strlen' => true,
        'count' => true,
        'sizeof' => true,
        'mb_strlen' => true,
        'substr_count' => true,
        'mb_substr_count' => true,
    ];

    /**
     * @return array<string, string> Escaped local name => narrowed C++ type.
     */
    protected function optimizeLoopVars(SsaBuilder $ssa): array
    {
        $stmts = $ssa->getStmts();
        if (!$stmts) {
            return [];
        }

        $candidates = [];
        $optimized = [];
        $this->collectLoopVarCandidates($stmts, [], $candidates);

        foreach ($candidates as $varName => $candidate) {
            $escapedName = $this->escapeVarName($varName);
            if ($this->hasArgument($escapedName)
                || $this->hasScopeGlobalVar($escapedName)
                || $this->isSuperGlobal($escapedName)) {
                continue;
            }
            if (!$this->isLoopSsaVarStable($ssa, $varName)) {
                continue;
            }
            if ($this->loopVarHasUnsafeUsage($varName, $stmts, $candidate['allowed'] ?? [])) {
                continue;
            }
            $depFailed = false;
            foreach ($candidate['deps'] ?? [] as $depName => $_) {
                if (!$this->isLoopSsaVarStable($ssa, $depName)
                    || $this->loopVarHasUnsafeUsage($depName, $stmts, $candidates[$depName]['allowed'] ?? [])) {
                    $depFailed = true;
                    break;
                }
            }
            if ($depFailed) {
                continue;
            }
            $this->context->setInferredLocalType($escapedName, Type::INT);
            $optimized[$escapedName] = Type::INT;
        }

        return $optimized;
    }

    /**
     * @param array<string, array{allowed?: array<int, bool>, deps?: array<string, bool>}> $candidates
     */
    protected function allowLoopNode(array &$candidates, string $varName, int $nodeId): void
    {
        $candidates[$varName]['allowed'][$nodeId] = true;
    }

    /**
     * @param array<string, array{allowed?: array<int, bool>, deps?: array<string, bool>}> $candidates
     */
    protected function requireLoopVar(array &$candidates, string $varName, string $depName): void
    {
        if ($varName !== $depName) {
            $candidates[$varName]['deps'][$depName] = true;
        }
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @param array<string, array{allowed?: array<int, bool>, deps?: array<string, bool>}> $candidates
     */
    protected function collectLoopVarCandidates(array $stmts, array $safeVars, array &$candidates): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression) {
                $this->trackLoopSafeAssignment($stmt->expr, $safeVars);
            }

            if ($stmt instanceof Stmt\While_) {
                $this->tryCollectWhilePostDecCandidate($stmt, $safeVars, $candidates);
                $this->collectLoopVarCandidates($stmt->stmts, $safeVars, $candidates);
                continue;
            }

            if ($stmt instanceof Stmt\For_) {
                $this->tryCollectForCounterCandidate($stmt, $safeVars, $candidates);
                $innerSafeVars = $safeVars;
                foreach ($stmt->init as $init) {
                    $this->trackLoopSafeAssignment($init, $innerSafeVars);
                }
                $this->collectLoopVarCandidates($stmt->stmts, $innerSafeVars, $candidates);
                continue;
            }

            if ($stmt instanceof Stmt\If_) {
                $this->collectLoopVarCandidates($stmt->stmts, $safeVars, $candidates);
                foreach ($stmt->elseifs as $elseif) {
                    $this->collectLoopVarCandidates($elseif->stmts, $safeVars, $candidates);
                }
                if ($stmt->else) {
                    $this->collectLoopVarCandidates($stmt->else->stmts, $safeVars, $candidates);
                }
                continue;
            }

            if ($stmt instanceof Stmt\Do_) {
                $this->collectLoopVarCandidates($stmt->stmts, $safeVars, $candidates);
                continue;
            }

            if ($stmt instanceof Stmt\Foreach_) {
                $this->collectLoopVarCandidates($stmt->stmts, $safeVars, $candidates);
                continue;
            }

            if ($stmt instanceof Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    $this->collectLoopVarCandidates($case->stmts, $safeVars, $candidates);
                }
                continue;
            }

            if ($stmt instanceof Stmt\TryCatch) {
                $this->collectLoopVarCandidates($stmt->stmts, $safeVars, $candidates);
                foreach ($stmt->catches as $catch) {
                    $this->collectLoopVarCandidates($catch->stmts, $safeVars, $candidates);
                }
                if ($stmt->finally) {
                    $this->collectLoopVarCandidates($stmt->finally->stmts, $safeVars, $candidates);
                }
            }
        }
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     */
    protected function trackLoopSafeAssignment(NodeAbstract $expr, array &$safeVars): void
    {
        if ($expr instanceof Expr\Assign
            && $expr->var instanceof Expr\Variable
            && is_string($expr->var->name)) {
            $varName = $expr->var->name;
            $info = $this->detectLoopIntExprInfo($expr->expr, $safeVars);
            if ($info !== null) {
                $safeVars[$varName] = [
                    'id' => spl_object_id($expr),
                    'nonNegative' => $info['nonNegative'],
                    'inclusiveSafe' => $info['inclusiveSafe'],
                ];
            } else {
                unset($safeVars[$varName]);
            }
            return;
        }

        if ($expr instanceof Expr\AssignOp
            && $expr->var instanceof Expr\Variable
            && is_string($expr->var->name)) {
            unset($safeVars[$expr->var->name]);
            return;
        }

        if (($expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc
            || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec)
            && $expr->var instanceof Expr\Variable
            && is_string($expr->var->name)) {
            unset($safeVars[$expr->var->name]);
        }
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @param array<string, array{allowed?: array<int, bool>, deps?: array<string, bool>}> $candidates
     */
    protected function tryCollectWhilePostDecCandidate(Stmt\While_ $stmt, array $safeVars, array &$candidates): void
    {
        if (!$stmt->cond instanceof Expr\PostDec
            || !$stmt->cond->var instanceof Expr\Variable
            || !is_string($stmt->cond->var->name)) {
            return;
        }

        $varName = $stmt->cond->var->name;
        if (!isset($safeVars[$varName]) || !$safeVars[$varName]['nonNegative']) {
            return;
        }

        if ($this->loopBodyMutatesAny($stmt->stmts, [$varName => true])) {
            return;
        }

        $this->allowLoopNode($candidates, $varName, $safeVars[$varName]['id']);
        $this->allowLoopNode($candidates, $varName, spl_object_id($stmt->cond));
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @param array<string, array{allowed?: array<int, bool>, deps?: array<string, bool>}> $candidates
     */
    protected function tryCollectForCounterCandidate(Stmt\For_ $stmt, array $safeVars, array &$candidates): void
    {
        if (count($stmt->init) !== 1 || count($stmt->cond) !== 1 || count($stmt->loop) !== 1) {
            return;
        }

        $init = $stmt->init[0];
        if (!$init instanceof Expr\Assign
            || !$init->var instanceof Expr\Variable
            || !is_string($init->var->name)
            || $this->detectLoopIntExprInfo($init->expr, $safeVars) === null) {
            return;
        }

        $counterName = $init->var->name;
        $step = $this->detectLoopUnitStep($stmt->loop[0], $counterName);
        if ($step === null) {
            return;
        }

        $bound = $this->matchLoopBound($stmt->cond[0], $counterName, $safeVars, $step);
        if ($bound === null) {
            return;
        }

        $watchedVars = [$counterName => true];
        foreach ($bound['vars'] as $varName => $_) {
            $watchedVars[$varName] = true;
        }
        if ($this->loopBodyMutatesAny($stmt->stmts, $watchedVars)) {
            return;
        }

        $conditionId = spl_object_id($stmt->cond[0]);
        $this->allowLoopNode($candidates, $counterName, spl_object_id($init));
        $this->allowLoopNode($candidates, $counterName, $conditionId);
        $this->allowLoopNode($candidates, $counterName, spl_object_id($stmt->loop[0]));

        foreach ($bound['intVars'] as $varName => $_) {
            $this->requireLoopVar($candidates, $counterName, $varName);
            $this->allowLoopNode($candidates, $varName, $safeVars[$varName]['id']);
            $this->allowLoopNode($candidates, $varName, $conditionId);
        }
    }

    protected function detectLoopUnitStep(NodeAbstract $expr, string $counterName): ?int
    {
        if (($expr instanceof Expr\PostInc || $expr instanceof Expr\PreInc)
            && $this->isVarNamed($expr->var, $counterName)) {
            return 1;
        }

        if (($expr instanceof Expr\PostDec || $expr instanceof Expr\PreDec)
            && $this->isVarNamed($expr->var, $counterName)) {
            return -1;
        }

        if ($expr instanceof Expr\AssignOp\Plus
            && $this->isVarNamed($expr->var, $counterName)
            && $expr->expr instanceof Node\Scalar\LNumber
            && $expr->expr->value === 1) {
            return 1;
        }

        if ($expr instanceof Expr\AssignOp\Minus
            && $this->isVarNamed($expr->var, $counterName)
            && $expr->expr instanceof Node\Scalar\LNumber
            && $expr->expr->value === 1) {
            return -1;
        }

        return null;
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @return array{vars: array<string, bool>, intVars: array<string, bool>}|null
     */
    protected function matchLoopBound(NodeAbstract $expr, string $counterName, array $safeVars, int $step): ?array
    {
        if (!$expr instanceof Expr\BinaryOp\Smaller
            && !$expr instanceof Expr\BinaryOp\SmallerOrEqual
            && !$expr instanceof Expr\BinaryOp\Greater
            && !$expr instanceof Expr\BinaryOp\GreaterOrEqual) {
            return null;
        }

        $inclusive = $expr instanceof Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Expr\BinaryOp\GreaterOrEqual;
        $boundExpr = null;
        if ($step > 0) {
            if (($expr instanceof Expr\BinaryOp\Smaller || $expr instanceof Expr\BinaryOp\SmallerOrEqual)
                && $this->isVarNamed($expr->left, $counterName)) {
                $boundExpr = $expr->right;
            } elseif (($expr instanceof Expr\BinaryOp\Greater || $expr instanceof Expr\BinaryOp\GreaterOrEqual)
                && $this->isVarNamed($expr->right, $counterName)) {
                $boundExpr = $expr->left;
            }
        } else {
            if (($expr instanceof Expr\BinaryOp\Greater || $expr instanceof Expr\BinaryOp\GreaterOrEqual)
                && $this->isVarNamed($expr->left, $counterName)) {
                $boundExpr = $expr->right;
            } elseif (($expr instanceof Expr\BinaryOp\Smaller || $expr instanceof Expr\BinaryOp\SmallerOrEqual)
                && $this->isVarNamed($expr->right, $counterName)) {
                $boundExpr = $expr->left;
            }
        }

        if (!$boundExpr instanceof NodeAbstract) {
            return null;
        }

        $info = $this->detectLoopIntExprInfo($boundExpr, $safeVars);
        if ($info === null || ($inclusive && !$info['inclusiveSafe'])) {
            return null;
        }

        return [
            'vars' => $this->collectLoopExprVars($boundExpr),
            'intVars' => $this->collectLoopExprSafeIntVars($boundExpr, $safeVars),
        ];
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @return array{nonNegative: bool, inclusiveSafe: bool}|null
     */
    protected function detectLoopIntExprInfo(NodeAbstract $expr, array $safeVars): ?array
    {
        if ($expr instanceof Node\Scalar\LNumber) {
            return [
                'nonNegative' => $expr->value >= 0,
                'inclusiveSafe' => $expr->value > PHP_INT_MIN && $expr->value < PHP_INT_MAX,
            ];
        }

        if ($expr instanceof Expr\Cast\Int_) {
            return [
                'nonNegative' => false,
                'inclusiveSafe' => false,
            ];
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            if (isset($safeVars[$expr->name])) {
                return $safeVars[$expr->name];
            }

            // Typed parameters already use a native php::Int slot. They are
            // safe as exclusive loop bounds: `for ($i = 0; $i < $n; $i++)`
            // cannot increment past PHP_INT_MAX. Their sign and distance from
            // the integer limits are unknown, so do not accept them for the
            // non-negative post-decrement or inclusive-bound cases.
            $argumentName = $this->escapeVarName($expr->name);
            if (($this->context->arguments[$argumentName] ?? null) === Type::INT) {
                return [
                    'nonNegative' => false,
                    'inclusiveSafe' => false,
                ];
            }

            return null;
        }

        if ($this->isLoopIntCall($expr)) {
            $knownNonNegative = $this->isLoopKnownNonNegativeIntCall($expr);
            return [
                'nonNegative' => $knownNonNegative,
                // PHP lengths/counts are bounded by addressable memory in
                // supported runtimes, so inclusive loops over them stay int.
                'inclusiveSafe' => $knownNonNegative,
            ];
        }

        return null;
    }

    protected function isLoopIntCall(NodeAbstract $expr): bool
    {
        if (!$expr instanceof Expr\FuncCall
            && !$expr instanceof Expr\MethodCall
            && !$expr instanceof Expr\StaticCall
            && !$expr instanceof Expr\NullsafeMethodCall) {
            return false;
        }

        // Local assignments have not been converted yet. A function's declared
        // return type is available, but argument-dependent math inference may
        // read a local property whose receiver is not registered in this scope.
        // Range proofs only need a definite int signature; polymorphic math
        // results are deliberately left to normal statement conversion.
        if ($expr instanceof Expr\FuncCall) {
            if (!$expr->name instanceof Node\Name || $expr->isFirstClassCallable()) {
                return false;
            }
            $target = $this->resolveStaticFunctionCallTarget($expr->name);
            $native = $this->findNativeFunction($target['nativeLookup']);
            if ($native !== false) {
                return $this->getFunction($native)->returnType === Type::INT;
            }
            if ($target['namespacedFallback']) {
                return false;
            }
            return $this->detectFuncCallReturnType($this->parseIdentifier($expr->name)) === Type::INT;
        }

        // This pass runs before statement conversion has populated all local
        // variable types. Avoid asking the general expression detector to
        // resolve chained/dynamic receivers here: apart from being needlessly
        // expensive for unrelated assignments, that can report an undefined
        // receiver before its preceding assignment has been converted. Direct
        // calls such as `$object->toInt()` remain eligible.
        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
            && (!$expr->var instanceof Expr\Variable || !is_string($expr->var->name))) {
            return false;
        }

        return $this->detectTypeOfExpr($expr) === Type::INT;
    }

    protected function isLoopKnownNonNegativeIntCall(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\FuncCall
            && $expr->name instanceof Node\Name
            && isset(self::LOOP_NON_NEGATIVE_INT_FUNCTIONS[strtolower($expr->name->toString())]);
    }

    /**
     * @return array<string, bool>
     */
    protected function collectLoopExprVars(NodeAbstract $expr): array
    {
        $vars = [];
        $this->collectLoopExprVarsInto($expr, $vars);
        return $vars;
    }

    /**
     * @param array<string, bool> $vars
     */
    protected function collectLoopExprVarsInto($node, array &$vars): void
    {
        if (!$node instanceof Node) {
            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $vars[$node->name] = true;
            return;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                $this->collectLoopExprVarsInto($value, $vars);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $this->collectLoopExprVarsInto($item, $vars);
                }
            }
        }
    }

    /**
     * @param array<string, array{id: int, nonNegative: bool, inclusiveSafe: bool}> $safeVars
     * @return array<string, bool>
     */
    protected function collectLoopExprSafeIntVars(NodeAbstract $expr, array $safeVars): array
    {
        $vars = [];
        foreach ($this->collectLoopExprVars($expr) as $varName => $_) {
            if (isset($safeVars[$varName])) {
                $vars[$varName] = true;
            }
        }
        return $vars;
    }

    /**
     * @param array<string, bool> $vars
     */
    protected function loopBodyMutatesAny(array $stmts, array $vars): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->loopNodeMutatesAny($stmt, $vars)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, bool> $vars
     */
    protected function loopNodeMutatesAny($node, array $vars): bool
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef) {
            if ($this->loopExprTargetsAny($node->var, $vars)
                || ($node instanceof Expr\AssignRef && $this->loopExprUsesAny($node->expr, $vars))) {
                return true;
            }
        }

        if (($node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec)
            && $this->loopExprTargetsAny($node->var, $vars)) {
            return true;
        }

        if ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall) {
            foreach ($node->args as $arg) {
                if ($arg instanceof Node\Arg
                    && $arg->byRef
                    && $this->loopExprUsesAny($arg->value, $vars)) {
                    return true;
                }
            }
            if ($this->isStdRefCall($node)) {
                foreach ($node->args as $arg) {
                    if ($arg instanceof Node\Arg && $this->loopExprUsesAny($arg->value, $vars)) {
                        return true;
                    }
                }
            }
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                if ($this->loopExprTargetsAny($var, $vars)) {
                    return true;
                }
            }
        }

        if ($node instanceof Stmt\Foreach_) {
            if ($this->loopExprTargetsAny($node->valueVar, $vars)
                || ($node->keyVar instanceof Node && $this->loopExprTargetsAny($node->keyVar, $vars))) {
                return true;
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                if ($this->loopNodeMutatesAny($value, $vars)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->loopNodeMutatesAny($item, $vars)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $vars
     */
    protected function loopExprTargetsAny($expr, array $vars): bool
    {
        while ($expr instanceof Expr\ArrayDimFetch || $expr instanceof Expr\PropertyFetch) {
            $expr = $expr->var;
        }
        return $expr instanceof Expr\Variable
            && is_string($expr->name)
            && isset($vars[$expr->name]);
    }

    /**
     * @param array<string, bool> $vars
     */
    protected function loopExprUsesAny($node, array $vars): bool
    {
        if (!$node instanceof Node) {
            return false;
        }
        if ($node instanceof Expr\Variable && is_string($node->name) && isset($vars[$node->name])) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                if ($this->loopExprUsesAny($value, $vars)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->loopExprUsesAny($item, $vars)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function isLoopSsaVarStable(SsaBuilder $ssa, string $varName): bool
    {
        foreach ($ssa->ssaVars as $ssaVar) {
            if ($ssaVar->origName !== $varName) {
                continue;
            }
            if ($ssaVar->flags & (SsaFlags::REFERENCE | SsaFlags::ESCAPED | SsaFlags::KILLED)) {
                return false;
            }
        }
        // SsaBuilder does not currently materialize all variables defined only
        // in `for` headers. Those candidates are still checked by the AST
        // whitelist and whole-function hazard scan in this optimizer.
        return true;
    }

    /**
     * @param array<int, bool> $allowedNodes
     */
    protected function loopVarHasUnsafeUsage(string $varName, array $stmts, array $allowedNodes): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->loopNodeHasIntHazard($stmt, $varName, $allowedNodes)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, bool> $allowedNodes
     */
    protected function loopNodeHasIntHazard($node, string $varName, array $allowedNodes): bool
    {
        if (!$node instanceof Node) {
            return false;
        }
        if (isset($allowedNodes[spl_object_id($node)])) {
            return false;
        }

        if ($this->loopExprHasIntHazard($node, $varName, $allowedNodes)) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                if ($this->loopNodeHasIntHazard($value, $varName, $allowedNodes)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->loopNodeHasIntHazard($item, $varName, $allowedNodes)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, bool> $allowedNodes
     */
    protected function loopExprHasIntHazard($expr, string $varName, array $allowedNodes): bool
    {
        if (!$expr instanceof Node) {
            return false;
        }
        if (isset($allowedNodes[spl_object_id($expr)])) {
            return false;
        }

        if ($expr instanceof Expr\BinaryOp) {
            if (isset(self::SAFE_INT_BINARY_OPS[$expr->getType()])
                || $expr instanceof Expr\BinaryOp\Plus
                || $expr instanceof Expr\BinaryOp\Minus
                || $expr instanceof Expr\BinaryOp\Mul) {
                // In ordinary PHP mode BinaryOpTrait routes native Int
                // addition/subtraction/multiplication through php::Var's
                // checked operators, preserving overflow-to-float semantics.
                // Narrowing the loop counter therefore does not make these
                // read-only expression uses native C++ arithmetic.
                return $this->loopExprHasIntHazard($expr->left, $varName, $allowedNodes)
                    || $this->loopExprHasIntHazard($expr->right, $varName, $allowedNodes);
            }
            return $this->exprUsesVar($expr, $varName);
        }

        if ($expr instanceof Expr\Assign) {
            if ($this->isVarNamed($expr->var, $varName)) {
                return true;
            }
            return $this->loopExprHasIntHazard($expr->expr, $varName, $allowedNodes);
        }

        if ($expr instanceof Expr\AssignRef) {
            return $this->isVarNamed($expr->var, $varName)
                || $this->exprUsesVar($expr->expr, $varName);
        }

        if ($expr instanceof Expr\AssignOp) {
            if ($this->isVarNamed($expr->var, $varName)) {
                return true;
            }
            return $this->loopExprHasIntHazard($expr->expr, $varName, $allowedNodes);
        }

        if ($expr instanceof Expr\PreInc || $expr instanceof Expr\PreDec
            || $expr instanceof Expr\PostInc || $expr instanceof Expr\PostDec) {
            return $this->isVarNamed($expr->var, $varName);
        }

        if ($expr instanceof Expr\UnaryMinus) {
            return $this->exprUsesVar($expr, $varName);
        }

        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall || $expr instanceof Expr\NullsafeMethodCall) {
            foreach ($expr->args as $arg) {
                if ($arg instanceof Node\Arg
                    && $arg->byRef
                    && $this->exprUsesVar($arg->value, $varName)) {
                    return true;
                }
            }
            if ($this->isStdRefCall($expr)) {
                foreach ($expr->args as $arg) {
                    if ($arg instanceof Node\Arg && $this->exprUsesVar($arg->value, $varName)) {
                        return true;
                    }
                }
            }
        }

        if ($expr instanceof Stmt\Unset_) {
            foreach ($expr->vars as $var) {
                if ($this->isVarNamed($var, $varName)) {
                    return true;
                }
            }
        }

        if ($expr instanceof Stmt\Foreach_) {
            // Foreach key/value variables are assigned implicitly on every
            // iteration. A name that is also used by a range-proven `for`
            // counter cannot share a function-scoped php::Int slot with an
            // arbitrary key or value (including list destructuring targets).
            if (($expr->keyVar instanceof Node && $this->exprUsesVar($expr->keyVar, $varName))
                || $this->exprUsesVar($expr->valueVar, $varName)) {
                return true;
            }
        }

        foreach ($expr->getSubNodeNames() as $name) {
            $value = $expr->$name;
            if ($value instanceof Node) {
                if ($this->loopExprHasIntHazard($value, $varName, $allowedNodes)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->loopExprHasIntHazard($item, $varName, $allowedNodes)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
