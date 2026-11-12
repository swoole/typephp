<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers switch cases, fallthrough, and defaults.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;

trait SwitchTrait
{
    protected function parseSwitch(Node\Stmt\Switch_ $v): string
    {
        $cond    = $v->cond;
        if ($this->isNativeObjectClass($this->detectClassOfExpr($cond))) {
            $this->fatalError($cond, 'Native objects cannot be used as switch values');
        }
        foreach ($v->cases as $case) {
            if ($case->cond !== null
                && $this->isNativeObjectClass($this->detectClassOfExpr($case->cond))
            ) {
                $this->fatalError($case->cond, 'Native objects cannot be used as switch values');
            }
        }
        $tmp_var = $this->genTmpVarName();
        $type    = $this->detectTypeOfExpr($cond);
        $this->assertExprCanBeUsedAsValue($cond, 'switch condition');
        if ($this->isVarExpr($cond)) {
            $this->requireVar($v, $this->parseIdentifier($cond));
        }
        [$condExpr, $condBeforeStmts, $condAfterStmts] = $this->parseExprWithCapturedStmts($cond);
        if (($type === Type::INT || $type === Type::BOOL)
            && $this->getLoweredExpressionType($cond) !== $type) {
            // A declared scalar may still be returned through Zend as Var.
            // Do not coerce it merely to make a native switch compilable.
            $type = Type::VAR;
        }
        $var_def = '';
        $var_def .= $this->formatCapturedStmtLines($condBeforeStmts);
        $var_def .= $type . ' ' . $tmp_var . ' = ' . $condExpr . ';' . PHP_EOL;
        $var_def .= $this->formatCapturedStmtLines($condAfterStmts);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;

        if ($type === Type::INT or $type === Type::BOOL) {
            // Check all labels before lowering any bodies. C++ requires integer
            // labels; for bool subjects, only 0 and 1 preserve PHP comparison.
            $nativeCaseValues = [];
            $caseValues = [];
            foreach ($v->cases as $index => $case) {
                $caseCond = $case->cond;
                if ($caseCond === null) {
                    continue;
                }
                $value = $this->nativeSwitchCaseValue($caseCond);
                if ($value === null
                    || ($type === Type::BOOL && $value !== 0 && $value !== 1)
                ) {
                    goto _fail;
                }
                if (isset($nativeCaseValues[$value])) {
                    // PHP permits duplicate case values and selects the first
                    // matching label, while C++ rejects duplicate case labels.
                    goto _fail;
                }
                $nativeCaseValues[$value] = true;
                $caseValues[$index] = $value;
            }
            $code .= 'do {' . PHP_EOL;
            $code .= $this->withIndentedBlock(function () use ($tmp_var, $v, $caseValues): string {
                $code = $this->getIndent() . 'switch (' . $tmp_var . ') {' . PHP_EOL;
                $code .= $this->withIndentedBlock(function () use ($v, $caseValues): string {
                    $code = '';
                    foreach ($v->cases as $index => $case) {
                        if (empty($case->cond)) {
                            $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                        } else {
                            $code .= $this->getIndent() . 'case ' . $this->genIntegerLiteral($caseValues[$index]) . ': {' . PHP_EOL;
                        }
                        $code .= $this->parseBlockStmts($case->stmts);
                        $code .= $this->getIndent() . '}' . PHP_EOL;
                    }
                    return $code;
                });
                return $code . $this->getIndent() . '}' . PHP_EOL;
            });
            $code .= $this->getIndent() . '} while(0);' . PHP_EOL;

            return $var_def . $code;
        }

        _fail:

        $code = 'do {' . PHP_EOL;
        $code .= $this->withIndentedBlock(fn() => $this->parseDynamicSwitchCases($v, $tmp_var, $type));
        $code .= $this->getIndent() . '} while (0);';

        return $var_def . $code;
    }

    private function nativeSwitchCaseValue(Node\Expr $condition): ?int
    {
        if ($condition instanceof Node\Scalar\Int_) {
            return $this->bigintTypes ? null : $condition->value;
        }
        if (!$condition instanceof Node\Expr\ClassConstFetch || !$this->isHoistSafeClassConstFetch($condition)) {
            return null;
        }
        [, $before, $after] = $this->captureStmtLines(fn() => $this->parseClassConstFetch($condition));
        if ($before !== [] || $after !== [] || $this->getLoweredExpressionType($condition) !== Type::INT) {
            return null;
        }
        $constant = $condition->getAttribute('nativeConst');
        // Reuse the side-effect-free numeric evaluator. Constants requiring
        // class/namespace lookup or other runtime work remain on the fallback.
        return $constant?->valueExpr !== null ? $this->constantIntValue($constant->valueExpr) : null;
    }

    private function parseDynamicSwitchCases(Node\Stmt\Switch_ $v, string $tmp_var, string $type): string
    {
        $switchTarget = $this->genTmpVarName();
        $switchMatched = $this->genTmpVarName();
        $code = $this->getIndent() . 'int ' . $switchTarget . ' = -1;' . PHP_EOL;
        $code .= $this->getIndent() . 'bool ' . $switchMatched . ' = false;' . PHP_EOL;
        $caseConds = [];
        $caseGroups = [];
        $hasDefault = false;
        $defaultTarget = null;
        foreach ($v->cases as $case) {
            if (empty($case->cond)) {
                $hasDefault = true;
            } else {
                $caseConds[] = $case->cond;
            }
            $stmts = $case->stmts;
            if (empty($stmts)) {
                continue;
            }
            if (count($stmts) === 1 and $stmts[0] instanceof Node\Stmt\Block) {
                $stmts = $stmts[0]->stmts;
            }
            $lastExpr = end($stmts);
            if (!$this->isReturnExpr($lastExpr)
                and !$this->isExitExpr($lastExpr)
                and !$this->isBreakExpr($lastExpr)
                and !$this->isContinueExpr($lastExpr)
                and !$this->isThrowExpr($lastExpr)
            ) {
                $this->fatalError($case, 'switch case must end with return/break/continue/exit/throw, ' . $lastExpr->getType() . ' given');
            }
            $target = count($caseGroups);
            if ($hasDefault) {
                $defaultTarget = $target;
            }
            $caseGroups[] = [$caseConds, $hasDefault, $stmts];
            $caseConds = [];
            $hasDefault = false;
        }
        if (!empty($caseConds) || $hasDefault) {
            $target = count($caseGroups);
            if ($hasDefault) {
                $defaultTarget = $target;
            }
            $caseGroups[] = [$caseConds, $hasDefault, []];
        }

        foreach ($caseGroups as $groupIndex => [$conds]) {
            if (!empty($conds)) {
                $groupMatched = $this->genTmpVarName();
                $code .= $this->getIndent() . 'bool ' . $groupMatched . ' = false;' . PHP_EOL;
                foreach ($conds as $caseCond) {
                    $this->assertExprCanBeUsedAsValue($caseCond, 'switch case condition');
                    [$caseCondExpr, $caseBeforeStmts, $caseAfterStmts] = $this->captureStmtLines(
                        fn() => $this->parseIdentifier($caseCond),
                    );

                    $code .= $this->getIndent() . 'if (!' . $switchMatched . ' && !' . $groupMatched . ') {' . PHP_EOL;
                    $code .= $this->formatCapturedStmtLines($caseBeforeStmts);
                    if ($caseAfterStmts) {
                        $caseTmpVar = $this->addTmpVar(Type::VAR);
                        $code .= $this->getIndent() . $caseTmpVar . ' = ' . $caseCondExpr . ';' . PHP_EOL;
                        $code .= $this->formatCapturedStmtLines($caseAfterStmts);
                        $caseCondExpr = $caseTmpVar;
                    }
                    if ($type === Type::BOOL) {
                        $caseCondExpr = 'php::toBool(' . $caseCondExpr . ')';
                    }
                    $code .= $this->getIndent() . $groupMatched . ' = php::equals(' . $tmp_var . ', ' . $caseCondExpr . ');' . PHP_EOL;
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                }
                $code .= $this->getIndent() . 'if (' . $groupMatched . ') {' . PHP_EOL;
                $code .= $this->getIndent() . $switchMatched . ' = true;' . PHP_EOL;
                $code .= $this->getIndent() . $switchTarget . ' = ' . $groupIndex . ';' . PHP_EOL;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
        }
        if ($defaultTarget !== null) {
            $code .= $this->getIndent() . 'if (!' . $switchMatched . ') {' . PHP_EOL;
            $code .= $this->getIndent() . $switchTarget . ' = ' . $defaultTarget . ';' . PHP_EOL;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }

        foreach ($caseGroups as $groupIndex => [, , $stmts]) {
            $code .= $this->getIndent() . 'if (' . $switchTarget . ' == ' . $groupIndex . ') {' . PHP_EOL;
            $code .= $this->parseBlockStmts($stmts);
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        return $code;
    }

}
