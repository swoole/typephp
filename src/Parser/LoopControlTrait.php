<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers for, while, do/while, break, and continue control flow.
 */

namespace TypePhp\Parser;

use PhpParser\Node;
use TypePhp\Type;

trait LoopControlTrait
{
    protected function parseFor(Node\Stmt\For_ $v): string
    {
        $init  = $v->init;
        $cond  = $v->cond;
        $loop  = $v->loop;
        $stmts = $v->stmts;
        $code  = '';

        $list_expr = [];
        foreach ($init as $expr) {
            [$initExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $initExpr = $this->stringifyParsedExpr($initExpr);
            $code .= $this->formatCapturedStmtLines($beforeStmts);
            $list_expr[] = $initExpr;
            if ($afterStmts) {
                $list_expr[] = implode(";\n" . $this->getIndent(), $afterStmts);
            }
        }
        $list_expr[] = '';
        $code .= implode(";\n" . $this->getIndent(), $list_expr);

        $list_cond = [];
        $list_cond_expr = [];
        $hasCondStmts = false;
        foreach ($cond as $expr) {
            $voidCast = $expr instanceof Node\Expr\Cast\Void_;
            if (!$voidCast) {
                $this->assertExprCanBeUsedAsCondition($expr, 'for condition');
            }
            [$condExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $condExpr = $this->stringifyParsedExpr($condExpr);
            $hasCondStmts = $hasCondStmts || $beforeStmts || $afterStmts;
            $list_cond[] = [$expr, $condExpr, $beforeStmts, $afterStmts, $voidCast];
            $list_cond_expr[] = $voidCast
                ? $condExpr
                : $this->convertConditionExpr($expr, $condExpr);
        }

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'for (;';
        if ($hasCondStmts) {
            $condCode = '[&]() -> bool {';
            if (empty($list_cond)) {
                $condCode .= $this->getIndent() . 'return true;';
            } else {
                $condResult = $this->genTmpVarName();
                $condCode .= $this->getIndent() . 'bool ' . $condResult . ' = true;' . PHP_EOL;
                foreach ($list_cond as [$condNode, $condExpr, $beforeStmts, $afterStmts, $voidCast]) {
                    $condCode .= $this->formatCapturedStmtLines($beforeStmts);
                    if ($voidCast) {
                        $condCode .= $this->getIndent() . $condExpr . ';' . PHP_EOL;
                        $condCode .= $this->formatCapturedStmtLines($afterStmts);
                        continue;
                    }
                    if ($afterStmts) {
                        $tmpVar = $this->addTmpVar(Type::VAR);
                        $condCode .= $this->getIndent() . $tmpVar . ' = ' . $condExpr . ';' . PHP_EOL;
                        $condCode .= $this->formatCapturedStmtLines($afterStmts);
                        $condExpr = $tmpVar;
                    }
                    $condCode .= $this->getIndent() . $condResult . ' = ' . $this->convertConditionExpr($condNode, $condExpr) . ';' . PHP_EOL;
                }
                $condCode .= $this->getIndent() . 'return ' . $condResult . ';';
            }
            $condCode .= $this->getIndent() . '}()';
            $code .= $condCode;
        } else {
            $code .= implode(', ', $list_cond_expr);
        }
        $code .= '; ';

        $list_loop = [];
        foreach ($loop as $expr) {
            // for-loop post-expressions discard return value, so $i++ ≡ ++$i.
            // Only rewrite simple variables; TypePHP lowers prefix and postfix
            // differently for compound lvalues (e.g. static-property write-back).
            if ($expr instanceof Node\Expr\PostInc && $this->isVarExpr($expr->var)) {
                $expr = new Node\Expr\PreInc($expr->var, $expr->getAttributes());
            } elseif ($expr instanceof Node\Expr\PostDec && $this->isVarExpr($expr->var)) {
                $expr = new Node\Expr\PreDec($expr->var, $expr->getAttributes());
            }
            [$loopExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $loopExpr = $this->stringifyParsedExpr($loopExpr);
            if ($beforeStmts || $afterStmts) {
                $loopCode = '[&]() {';
                $loopCode .= $this->formatCapturedStmtLines($beforeStmts);
                $loopCode .= $this->getIndent() . $loopExpr . ';' . PHP_EOL;
                $loopCode .= $this->formatCapturedStmtLines($afterStmts);
                $loopCode .= $this->getIndent() . '}()';
                $list_loop[] = $loopCode;
            } else {
                $list_loop[] = $loopExpr;
            }
        }
        $code .= implode(', ', $list_loop);
        $code .= ') {' . PHP_EOL;

        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    /**
     * Generate C++ code for dynamic property ++/-- operations.
     *
     * Returns null if $var is not a dynamic property fetch, so callers can
     * fall through to their normal codegen path.
     */

    protected function parseWhile(Node\Stmt\While_ $v): string
    {
        $stmts = $v->stmts;
        $this->assertExprCanBeUsedAsCondition($v->cond, 'while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        if ($beforeStmts || $afterStmts) {
            $code .= 'while (true) {' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(Type::VAR);
                $code .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $code .= $this->formatCapturedStmtLines($afterStmts);
                $cond = $tmpVar;
            }
            $cond = $this->convertConditionExpr($v->cond, $cond);
            $code .= $this->getIndent() . 'if (!(' . $cond . ')) { break; }' . PHP_EOL;
        } else {
            $cond = $this->convertConditionExpr($v->cond, $cond);
            $code .= 'while (' . $cond . ') {' . PHP_EOL;
        }
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }


    protected function parseDo(Node\Stmt\Do_ $v): string
    {
        $stmts = $v->stmts;
        // A do-while body always runs before its condition. Parse it first so
        // variables introduced by the body are available while lowering the
        // condition, matching PHP's execution order.
        $bodyCode = $this->parseBlockStmts($stmts);
        $this->assertExprCanBeUsedAsCondition($v->cond, 'do-while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);
        if ($beforeStmts || $afterStmts) {
            $condCode = '[&]() -> bool {';
            $condCode .= $this->formatCapturedStmtLines($beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(Type::VAR);
                $condCode .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $condCode .= $this->formatCapturedStmtLines($afterStmts);
                $cond = $tmpVar;
            }
            $condCode .= $this->getIndent() . 'return ' . $this->convertConditionExpr($v->cond, $cond) . ';';
            $condCode .= $this->getIndent() . '}()';
            $cond = $condCode;
        } else {
            $cond = $this->convertConditionExpr($v->cond, $cond);
        }
        $code  = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'do {' . PHP_EOL;
        $code .= $bodyCode;
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    /**
     * 值选择，如 ?: 或者 ??
     */

    protected function parseBreak(Node\Stmt\Break_ $v): string
    {
        if (!$this->context->inLoop) {
            $this->fatalError($v, 'Cannot break outside loop');
        }
        $num = $v->num;
        if ($num) {
            $this->checkLoopJumpLevel($v, $num, 'break');
            if ($num->value > 1) {
                $this->context->hasMultiLevelBreak = true;
                return '_brk_flag = ' . ($num->value - 1) . '; break;';
            }
        }

        return 'break;';
    }

    protected function parseContinue(Node\Stmt\Continue_ $v): string
    {
        if (!$this->context->inContinuableLoop) {
            $this->fatalError($v, 'Cannot continue outside loop');
        }
        $num = $v->num;
        if ($num) {
            $this->checkLoopJumpLevel($v, $num, 'continue');
            if ($num->value > 1) {
                $this->context->hasMultiLevelContinue = true;
                return '_cnt_flag = ' . ($num->value - 1) . '; break;';
            }
        }
        return 'continue;';
    }

    /**
     * PHP only accepts a positive integer literal that does not exceed the
     * number of enclosing loops/switches. The flag lowering relies on this:
     * it guarantees the countdown reaches zero at an enclosing construct.
     */
    protected function checkLoopJumpLevel(Node\Stmt $v, Node\Expr $num, string $operator): void
    {
        if (!$num instanceof Node\Scalar\Int_ || $num->value < 1) {
            $this->fatalError($v, "'{$operator}' operator accepts only positive integer literals");
        }
        if ($num->value > $this->context->breakableDepth) {
            $this->fatalError($v, "Cannot '{$operator}' {$num->value} levels");
        }
    }

    /**
     * Emit flag-propagation checks right after a nested breakable construct.
     *
     * A multi-level break / continue is lowered to a flag assignment plus a
     * plain break out of the innermost construct. Each enclosing loop or
     * switch places this check immediately after every nested loop / switch
     * statement, so the flag keeps breaking outward — before any trailing
     * statements of the enclosing body can run — until it reaches zero at
     * the targeted level. When the check sits inside a switch, a continue
     * that lands on the switch level behaves like break, matching PHP.
     */
    protected function genMultiLevelJumpCheck(bool $enclosingIsSwitch): string
    {
        $code = '';
        $indent = $this->getIndent();
        if ($this->context->hasMultiLevelBreak) {
            $code .= "{$indent}if (_brk_flag > 0) { _brk_flag--; break; }" . PHP_EOL;
        }
        if ($this->context->hasMultiLevelContinue) {
            if ($enclosingIsSwitch) {
                $code .= "{$indent}if (_cnt_flag > 0) { _cnt_flag--; break; }" . PHP_EOL;
            } else {
                $code .= "{$indent}if (_cnt_flag > 0) { _cnt_flag--; if (_cnt_flag == 0) continue; else break; }" . PHP_EOL;
            }
        }
        return $code;
    }

}
