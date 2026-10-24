<?php
/**
 * SSA-based type narrowing optimizer.
 *
 * Uses SSA/e-SSA analysis to narrow local variable types from php::Var
 * to C++ native types (php::Int, php::Float) when all definitions
 * provably produce the same type and no dangerous operations exist.
 */

namespace TypePhp\Optimizer;

use TypePhp\Type;

use TypePhp\Analysis\SsaBuilder;
use TypePhp\Analysis\SsaFlags;
use TypePhp\Analysis\SsaVar;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;

trait SsaTypeOptimizer
{
    /**
     * Binary ops that are safe while reading a narrowed int. Arithmetic is
     * excluded because it may overflow to float in PHP.
     */
    protected const array SAFE_INT_BINARY_OPS = [
        'Expr_BinaryOp_BitwiseAnd' => true,
        'Expr_BinaryOp_BitwiseOr' => true,
        'Expr_BinaryOp_BitwiseXor' => true,
        'Expr_BinaryOp_ShiftLeft' => true,
        'Expr_BinaryOp_ShiftRight' => true,
        'Expr_BinaryOp_Mod' => true,
        'Expr_BinaryOp_Equal' => true,
        'Expr_BinaryOp_NotEqual' => true,
        'Expr_BinaryOp_Identical' => true,
        'Expr_BinaryOp_NotIdentical' => true,
        'Expr_BinaryOp_Greater' => true,
        'Expr_BinaryOp_GreaterOrEqual' => true,
        'Expr_BinaryOp_Smaller' => true,
        'Expr_BinaryOp_SmallerOrEqual' => true,
        'Expr_BinaryOp_Spaceship' => true,
        'Expr_BinaryOp_BooleanAnd' => true,
        'Expr_BinaryOp_BooleanOr' => true,
        'Expr_BinaryOp_LogicalAnd' => true,
        'Expr_BinaryOp_LogicalOr' => true,
        'Expr_BinaryOp_Coalesce' => true,
    ];

    /**
     * Ops that force numeric operands through integer-only PHP semantics.
     * They are unsafe for variables narrowed to php::Float.
     */
    protected const array FLOAT_INT_ONLY_OPS = [
        'Expr_BinaryOp_BitwiseAnd' => true,
        'Expr_BinaryOp_BitwiseOr' => true,
        'Expr_BinaryOp_BitwiseXor' => true,
        'Expr_BinaryOp_ShiftLeft' => true,
        'Expr_BinaryOp_ShiftRight' => true,
        'Expr_BinaryOp_Mod' => true,
        'Expr_AssignOp_BitwiseAnd' => true,
        'Expr_AssignOp_BitwiseOr' => true,
        'Expr_AssignOp_BitwiseXor' => true,
        'Expr_AssignOp_ShiftLeft' => true,
        'Expr_AssignOp_ShiftRight' => true,
        'Expr_AssignOp_Mod' => true,
    ];

    /**
     * Use SSA analysis to narrow local variable types to C++ native types.
     *
     * For each variable, if ALL definitions produce the same narrow type
     * (int, float), and the variable is never referenced, escaped,
     * killed, or defined by a φ function with mixed sources, pre-set the
     * type in localVars so genScopeVarDecl emits the narrow C++ type.
     */
    protected function optimizeVarTypes(SsaBuilder $ssa): void
    {
        if (empty($ssa->ssaVars)) {
            return;
        }

        $narrowableTypes = [
            Type::INT => true,
            Type::FLOAT => true,
        ];

        // Group SSA vars by original variable name
        $groups = [];
        foreach ($ssa->ssaVars as $ssaVar) {
            $name = $ssaVar->origName;
            if (!isset($groups[$name])) {
                $groups[$name] = [];
            }
            $groups[$name][] = $ssaVar;
        }

        // Type detection may inspect RHS expressions before the normal parse
        // pass has registered their inputs. This includes variables introduced
        // by destructuring foreach targets, which are deliberately not always
        // represented as standalone SSA definitions. Seed every statically
        // named variable read as Var for analysis only; resetAnalysisTemporaries()
        // restores the real symbol table before code generation, where genuine
        // undefined-variable diagnostics still run normally.
        $nodeFinder = new NodeFinder();
        foreach ($nodeFinder->findInstanceOf($ssa->getStmts(), Node\Expr\Variable::class) as $variable) {
            if (!is_string($variable->name)) {
                continue;
            }
            $varName = $this->escapeVarName($variable->name);
            if (!isset($this->context->arguments[$varName]) && !$this->hasVar($varName)) {
                $this->context->localVars[$varName] = Type::VAR;
            }
        }

        // A foreach key may be int or string and a foreach value is entirely
        // runtime-defined. Even when the same local has an earlier integer
        // assignment, neither target can be narrowed safely. Include list
        // destructuring targets as well.
        $foreachTargets = [];
        foreach ($nodeFinder->findInstanceOf($ssa->getStmts(), Node\Stmt\Foreach_::class) as $foreach) {
            foreach ([$foreach->keyVar, $foreach->valueVar] as $target) {
                if (!$target instanceof Node) {
                    continue;
                }
                if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
                    $foreachTargets[$target->name] = true;
                }
                foreach ($nodeFinder->findInstanceOf($target, Node\Expr\Variable::class) as $variable) {
                    if (is_string($variable->name)) {
                        $foreachTargets[$variable->name] = true;
                    }
                }
            }
        }

        foreach (array_keys($groups) as $name) {
            $varName = $this->escapeVarName($name);
            if (!isset($this->context->arguments[$varName]) && !$this->hasVar($varName)) {
                $this->context->localVars[$varName] = Type::VAR;
            }
        }

        foreach ($groups as $groupName => $varList) {
            $varName = $this->escapeVarName($groupName);
            // Skip parameters — they already have declared types
            if (isset($this->context->arguments[$varName]) || isset($foreachTargets[$groupName])) {
                continue;
            }

            // Skip if any SSA var has dangerous flags
            $hasPhi = false;
            $hasDanger = false;
            $narrowedType = null;

            $nonNarrowableType = null;

            foreach ($varList as $ssaVar) {
                if ($ssaVar->flags & SsaFlags::PHI) {
                    $hasPhi = true;
                    continue;
                }
                if ($ssaVar->flags & (SsaFlags::REFERENCE | SsaFlags::ESCAPED | SsaFlags::KILLED)) {
                    $hasDanger = true;
                    break;
                }

                $defType = $this->detectSsaDefType($ssaVar);
                if ($defType === null) {
                    $hasDanger = true;
                    break;
                }
                // Non-narrowable types (BigInt/BigFloat/Decimal/Stream/objects etc.)
                // are not dangerous — they just can't be narrowed. Record the type
                // so dependent SSA variables can resolve it later.
                if (!isset($narrowableTypes[$defType])) {
                    if ($nonNarrowableType === null) {
                        $nonNarrowableType = $defType;
                    } elseif ($nonNarrowableType !== $defType) {
                        // Mixed non-narrowable types — can't determine a single type
                        $nonNarrowableType = Type::VAR;
                    }
                    continue;
                }

                if ($narrowedType === null) {
                    $narrowedType = $defType;
                } elseif ($narrowedType !== $defType) {
                    $hasDanger = true;
                    break;
                }
            }

            if ($hasDanger) {
                continue;
            }

            // Mixed narrowable and non-narrowable types (e.g. $x = [1,2] then $x = 42)
            // — can't safely narrow.
            if ($narrowedType !== null && $nonNarrowableType !== null && $nonNarrowableType !== Type::VAR) {
                continue;
            }

            if ($narrowedType === null) {
                // No narrowable type found, but register Big* types so dependent
                // SSA variables can resolve them (these types have no extra metadata).
                if (
                    $nonNarrowableType !== null
                    && in_array($nonNarrowableType, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT, Type::STREAM], true)
                ) {
                    $this->context->localVars[$varName] = $nonNarrowableType;
                }
                continue;
            }

            // Scan for operations that SSA definition types alone can't detect
            $functionStmts = $ssa->getStmts();
            if ($functionStmts) {
                if ($narrowedType === Type::INT && $this->hasDangerousIntOps($varName, $functionStmts)) {
                    continue;
                }
                if ($narrowedType === Type::FLOAT && $this->hasDangerousFloatOps($varName, $functionStmts)) {
                    continue;
                }
            }

            // Check φ-function sources for mixed types
            if ($hasPhi && $this->phiSourcesHaveMixedTypes($ssa, $varList, $narrowedType)) {
                continue;
            }

            // Apply narrowing — bypass getNativeType(): SSA has PROVEN the type.
            $this->context->localVars[$varName] = $narrowedType;
        }
    }

    /**
     * Detect the type produced by a single SSA variable definition.
     * Returns null if the type cannot be determined from the AST.
     */
    protected function detectSsaDefType(SsaVar $ssaVar): ?string
    {
        $def = $ssaVar->definition;
        if (!$def) {
            return null;
        }

        if ($def instanceof Node\Stmt\Expression && $def->expr instanceof Node\Expr\Assign) {
            $expr = $def->expr->expr;
            $type = $this->detectTypeOfExpr($expr);
            if ($type === Type::INT && !$this->isSafeSsaIntExpr($expr)) {
                return null;
            }
            return $type;
        }

        if ($def instanceof Node\Stmt\Expression && $def->expr instanceof Node\Expr\AssignOp) {
            return $this->detectAssignOpDefType($def->expr);
        }

        if ($def instanceof Node\Stmt\Expression
            && ($def->expr instanceof Node\Expr\PreInc || $def->expr instanceof Node\Expr\PostInc
                || $def->expr instanceof Node\Expr\PreDec || $def->expr instanceof Node\Expr\PostDec)) {
            return null;
        }

        if ($def instanceof Node\Stmt\Foreach_) {
            return null;
        }

        if ($def instanceof Node\Stmt\Catch_) {
            return Type::OBJECT;
        }

        if ($def instanceof Node\Stmt\Static_) {
            foreach ($def->vars as $staticVar) {
                if ($staticVar->var->name === $ssaVar->origName && $staticVar->default) {
                    return $this->detectTypeOfExpr($staticVar->default);
                }
            }
            return null;
        }

        return null;
    }

    protected function detectAssignOpDefType(Node\Expr\AssignOp $expr): ?string
    {
        if ($expr instanceof Node\Expr\AssignOp\Div) {
            return Type::FLOAT;
        }

        if ($expr instanceof Node\Expr\AssignOp\Concat) {
            return Type::STR;
        }

        if ($expr instanceof Node\Expr\AssignOp\Pow) {
            return null;
        }

        if ($expr instanceof Node\Expr\AssignOp\Plus
            || $expr instanceof Node\Expr\AssignOp\Minus
            || $expr instanceof Node\Expr\AssignOp\Mul) {
            $rhsType = $this->detectTypeOfExpr($expr->expr);
            if ($rhsType === Type::FLOAT) {
                return Type::FLOAT;
            }
            if ($rhsType === Type::INT) {
                return null;
            }
            return $rhsType;
        }

        return null;
    }

    /**
     * Return true only for int-producing expressions whose C++ native-int
     * evaluation matches PHP without relying on range analysis.
     */
    protected function isSafeSsaIntExpr(NodeAbstract $expr): bool
    {
        if ($expr instanceof Node\Scalar\LNumber) {
            return true;
        }

        if ($expr instanceof Node\Expr\Cast\Int_) {
            return true;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return $this->detectConstType($expr) === Type::INT;
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $target = $this->resolveStaticFunctionCallTarget($expr->name);
            return $target['definitelyGlobal']
                && $this->detectMathCallReturnType($target['lower'], $expr) === Type::INT;
        }

        if ($expr instanceof Node\Expr\BitwiseNot) {
            return $this->isSafeSsaIntExpr($expr->expr);
        }

        if ($expr instanceof Node\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Node\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Node\Expr\BinaryOp\BitwiseXor
            || $expr instanceof Node\Expr\BinaryOp\Mod) {
            return $this->isSafeSsaIntExpr($expr->left)
                && $this->isSafeSsaIntExpr($expr->right);
        }

        return false;
    }

    /**
     * Check whether a TYPE_INT expression could overflow int64 at runtime
     * and produce a float in PHP.
     */
    protected function exprCanOverflowInt(NodeAbstract $expr): bool
    {
        // Runtime division may produce float even when both operands are int.
        if ($expr instanceof Node\Expr\BinaryOp\Div) {
            return true;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Plus
            || $expr instanceof Node\Expr\BinaryOp\Minus
            || $expr instanceof Node\Expr\BinaryOp\Mul) {
            return true;
        }

        // ** can easily overflow (e.g. 2**63 already exceeds INT64_MAX)
        if ($expr instanceof Node\Expr\BinaryOp\Pow) {
            return true;
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && strtolower($expr->name->toString()) === 'pow') {
            return true;
        }

        if ($expr instanceof Node\Expr\BinaryOp) {
            return $this->exprCanOverflowInt($expr->left)
                || $this->exprCanOverflowInt($expr->right);
        }

        // Follow expressions that forward a value. A wrapper must not hide
        // overflow from a match arm or a declared int return boundary.
        if ($expr instanceof Node\Expr\Ternary) {
            return $this->exprCanOverflowInt($expr->if ?? $expr->cond)
                || $this->exprCanOverflowInt($expr->else);
        }
        if ($expr instanceof Node\Expr\Match_) {
            foreach ($expr->arms as $arm) {
                if ($this->exprCanOverflowInt($arm->body)) {
                    return true;
                }
            }
        }
        if ($expr instanceof Node\Expr\ErrorSuppress
            || $expr instanceof Node\Expr\UnaryPlus
            || $expr instanceof Node\Expr\UnaryMinus) {
            return $this->exprCanOverflowInt($expr->expr);
        }

        return false;
    }

    protected function isBoundaryConstant(NodeAbstract $node): bool
    {
        if ($node instanceof Node\Expr\ConstFetch
            && $node->name instanceof Node\Name) {
            $name = $node->name->toLowerString();
            return $name === 'php_int_max' || $name === 'php_int_min';
        }
        return false;
    }

    protected function phiSourcesHaveMixedTypes(SsaBuilder $ssa, array $varList, string $narrowedType): bool
    {
        foreach ($varList as $ssaVar) {
            if (($ssaVar->flags & SsaFlags::PHI) && !empty($ssaVar->phiSources)) {
                foreach ($ssaVar->phiSources as $srcSsaId) {
                    if (isset($ssa->ssaVars[$srcSsaId])) {
                        $srcVar = $ssa->ssaVars[$srcSsaId];
                        $srcType = $this->detectSsaDefType($srcVar);
                        if ($srcType !== null && $srcType !== $narrowedType) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check whether $varName is used in any operation that would produce
     * incorrect results with a narrowed C++ int64_t. Only bitwise ops
     * (& | ^ << >> ~) and modulo (%) are safe — arithmetic can overflow
     * to float, which int64_t would wrap instead.
     */
    protected function hasDangerousIntOps(string $varName, array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->scanStmtForDangerousIntOps($stmt, $varName)) {
                return true;
            }
        }
        return false;
    }

    protected function scanStmtForDangerousIntOps($stmt, string $varName): bool
    {
        if (!$stmt instanceof Node) {
            return false;
        }

        // Check conditions in control-flow statements
        if (($stmt instanceof Node\Stmt\If_
            || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_)
            && $stmt->cond instanceof Node
            && $this->exprHasIntHazard($stmt->cond, $varName)) {
            return true;
        }

        if ($stmt instanceof Node\Stmt\For_) {
            foreach ($stmt->cond as $cond) {
                if ($cond instanceof Node && $this->exprHasIntHazard($cond, $varName)) {
                    return true;
                }
            }
            foreach ($stmt->init as $init) {
                if ($init instanceof Node && $this->exprHasIntHazard($init, $varName)) {
                    return true;
                }
            }
            foreach ($stmt->loop as $loop) {
                if ($loop instanceof Node && $this->exprHasIntHazard($loop, $varName)) {
                    return true;
                }
            }
        }

        if ($stmt instanceof Node\Stmt\Foreach_) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            if ($this->exprHasIntHazard($stmt->cond, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Expression) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                if ($expr instanceof Node && $this->exprHasIntHazard($expr, $varName)) {
                    return true;
                }
            }
        }

        return $this->recurseForDangerousOps($stmt, $varName, 'Int');
    }

    /**
     * Recursively check an expression for hazardous int usage.
     *
     * Only bitwise ops (& | ^ << >> ~) and modulo (%) are safe for a
     * narrowed int64_t. Any other BinaryOp, AssignOp, UnaryMinus, or
     * Inc/Dec that involves $varName is a hazard.
     */
    protected function exprHasIntHazard($expr, string $varName): bool
    {
        if (!$expr instanceof Node) {
            return false;
        }

        $type = $expr->getType();

        // Safe binary ops: bitwise + modulo — recurse into children
        if (isset(self::SAFE_INT_BINARY_OPS[$type])) {
            return $this->exprHasIntHazard($expr->left, $varName)
                || $this->exprHasIntHazard($expr->right, $varName);
        }

        // Unsafe binary op: flag if $varName appears anywhere inside
        if ($expr instanceof Node\Expr\BinaryOp) {
            if ($this->exprUsesVar($expr, $varName)) {
                return true;
            }
            return $this->exprHasIntHazard($expr->left, $varName)
                || $this->exprHasIntHazard($expr->right, $varName);
        }

        // Compound assignment can change the variable type or depends on
        // PHP's integer conversion edge cases. Treat all of them as hazards
        // for int narrowing.
        if ($expr instanceof Node\Expr\AssignOp) {
            if ($this->isVarNamed($expr->var, $varName)) {
                return true;
            }
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // Assignment: $var = rhs — LHS is a definition, safe; check RHS
        if ($expr instanceof Node\Expr\Assign) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // ~$varName is always int, safe
        if ($expr instanceof Node\Expr\BitwiseNot) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // -$varName can overflow INT_MIN → float
        if ($expr instanceof Node\Expr\UnaryMinus) {
            if ($this->exprUsesVar($expr, $varName)) {
                return true;
            }
            return false;
        }

        // $varName++, ++$varName, $varName--, --$varName can overflow
        if ($expr instanceof Node\Expr\PreInc || $expr instanceof Node\Expr\PreDec
            || $expr instanceof Node\Expr\PostInc || $expr instanceof Node\Expr\PostDec) {
            return $this->isVarNamed($expr->var, $varName);
        }

        // (int)$varName — safe type read
        if ($expr instanceof Node\Expr\Cast\Int_) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // Generic recursion over common expression sub-nodes
        foreach (['left', 'right', 'expr', 'var', 'cond', 'if', 'else', 'dim', 'value'] as $prop) {
            if (isset($expr->$prop) && $expr->$prop instanceof Node) {
                if ($this->exprHasIntHazard($expr->$prop, $varName)) {
                    return true;
                }
            }
        }

        foreach (['args', 'exprs', 'items', 'stmts'] as $prop) {
            if (isset($expr->$prop) && is_array($expr->$prop)) {
                foreach ($expr->$prop as $item) {
                    if ($item instanceof Node) {
                        if ($this->exprHasIntHazard($item, $varName)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check whether $varName appears anywhere recursively in an AST node.
     */
    protected function exprUsesVar($node, string $varName): bool
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($this->isVarNamed($node, $varName)) {
            return true;
        }

        foreach (['left', 'right', 'expr', 'var', 'cond', 'if', 'else', 'dim', 'value'] as $prop) {
            if (isset($node->$prop) && $node->$prop instanceof Node) {
                if ($this->exprUsesVar($node->$prop, $varName)) {
                    return true;
                }
            }
        }

        foreach (['args', 'exprs', 'items', 'stmts'] as $prop) {
            if (isset($node->$prop) && is_array($node->$prop)) {
                foreach ($node->$prop as $item) {
                    if ($item instanceof Node) {
                        if ($this->exprUsesVar($item, $varName)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    protected function isVarNamed($node, string $varName): bool
    {
        return $node instanceof Node\Expr\Variable
            && is_string($node->name)
            && ($node->name === $varName || $this->escapeVarName($node->name) === $varName);
    }

    protected function hasDangerousFloatOps(string $varName, array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->scanStmtForDangerousFloatOps($stmt, $varName)) {
                return true;
            }
        }
        return false;
    }

    protected function scanStmtForDangerousFloatOps($stmt, string $varName): bool
    {
        if (!$stmt instanceof Node) {
            return false;
        }

        if ($stmt instanceof Node\Stmt\Expression && $this->exprHasFloatHazard($stmt->expr, $varName)) {
            return true;
        }

        if (($stmt instanceof Node\Stmt\If_
            || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_)
            && $stmt->cond instanceof Node
            && $this->exprHasFloatHazard($stmt->cond, $varName)) {
            return true;
        }

        if ($stmt instanceof Node\Stmt\For_) {
            foreach ([$stmt->init, $stmt->cond, $stmt->loop] as $exprList) {
                foreach ($exprList as $expr) {
                    if ($expr instanceof Node && $this->exprHasFloatHazard($expr, $varName)) {
                        return true;
                    }
                }
            }
        }

        if ($stmt instanceof Node\Stmt\Foreach_) {
            if ($this->exprHasFloatHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            if ($this->exprHasFloatHazard($stmt->cond, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node) {
            if ($this->exprHasFloatHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                if ($expr instanceof Node && $this->exprHasFloatHazard($expr, $varName)) {
                    return true;
                }
            }
        }

        return $this->recurseForDangerousOps($stmt, $varName, 'Float');
    }

    protected function exprHasFloatHazard($expr, string $varName): bool
    {
        if (!$expr instanceof Node) {
            return false;
        }

        if (isset(self::FLOAT_INT_ONLY_OPS[$expr->getType()]) && $this->exprUsesVar($expr, $varName)) {
            return true;
        }

        foreach (['left', 'right', 'expr', 'var', 'cond', 'if', 'else', 'dim', 'value'] as $prop) {
            if (isset($expr->$prop) && $expr->$prop instanceof Node) {
                if ($this->exprHasFloatHazard($expr->$prop, $varName)) {
                    return true;
                }
            }
        }

        foreach (['args', 'exprs', 'items', 'stmts'] as $prop) {
            if (isset($expr->$prop) && is_array($expr->$prop)) {
                foreach ($expr->$prop as $item) {
                    if ($item instanceof Node && $this->exprHasFloatHazard($item, $varName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Recurse into compound statements (if/else, foreach, while, for, try/catch, switch).
     */
    protected function recurseForDangerousOps($stmt, string $varName, string $mode): bool
    {
        $method = 'hasDangerous' . $mode . 'Ops';

        if ($stmt instanceof Node\Stmt\If_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
            if (!empty($stmt->elseifs)) {
                foreach ($stmt->elseifs as $elseif) {
                    if ($this->$method($varName, $elseif->stmts)) return true;
                }
            }
            if ($stmt->else && $this->$method($varName, $stmt->else->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\Foreach_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\For_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\TryCatch) {
            if ($this->$method($varName, $stmt->stmts)) return true;
            foreach ($stmt->catches as $catch) {
                if ($this->$method($varName, $catch->stmts)) return true;
            }
            if ($stmt->finally && $this->$method($varName, $stmt->finally->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\Case_ || $stmt instanceof Node\Stmt\Switch_) {
            if (isset($stmt->stmts) && $this->$method($varName, $stmt->stmts)) return true;
        }

        return false;
    }
}
