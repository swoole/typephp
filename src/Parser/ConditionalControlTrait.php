<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers if, elseif, and else statement chains.
 */

namespace TypePhp\Parser;

use PhpParser\Node;

trait ConditionalControlTrait
{
    protected function parseIf(Node\Stmt\If_ $v): string
    {
        $arms = [[$v->cond, $v->stmts]];
        foreach ($v->elseifs as $elseif) {
            $arms[] = [$elseif->cond, $elseif->stmts];
        }

        return $this->parseBeforeStmtLines()
            . $this->parseIfChain($arms, $v->else, 0) . PHP_EOL;
    }

    protected function parseIfChain(array $arms, ?Node\Stmt\Else_ $else, int $index): string
    {
        if (!isset($arms[$index])) {
            if (!$else || $this->isEmptyStmtList($else->stmts)) {
                return '';
            }
            return $this->parseStmts($else->stmts);
        }

        [$cond, $stmts] = $arms[$index];
        $code = $this->genConditionWithCapturedStmts($cond, 'if ');
        $code .= $this->parseBlockStmts($stmts);
        $hasTail = isset($arms[$index + 1]) || ($else && !$this->isEmptyStmtList($else->stmts));
        if ($hasTail) {
            $code .= $this->getIndent() . '} else {' . PHP_EOL;
            $tail = $this->withIndentedBlock(fn() => $this->parseIfChain($arms, $else, $index + 1));
            $code .= $tail;
            if (!str_ends_with($tail, PHP_EOL)) {
                $code .= PHP_EOL;
            }
        }
        $code .= $this->getIndent() . '}';
        return $code;
    }

    protected function isEmptyStmtList(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Nop) {
                return false;
            }
        }
        return true;
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
}
