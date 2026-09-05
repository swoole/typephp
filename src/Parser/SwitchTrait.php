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
        $var_def = '';
        $var_def .= $this->formatCapturedStmtLines($condBeforeStmts);
        $var_def .= $type . ' ' . $tmp_var . ' = ' . $condExpr . ';' . PHP_EOL;
        $var_def .= $this->formatCapturedStmtLines($condAfterStmts);

        // Save the scope; switch parsing may fail partway and add variables in the process, so it must be reset
        $localVars = $this->context->localVars;
        $code      = $this->parseBeforeStmtLines() . PHP_EOL;

        if ($type === Type::INT or $type === Type::BOOL) {
            $code .= 'do {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . 'switch (' . $tmp_var . ') {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($v->cases as $case) {
                if (empty($case->cond)) {
                    $code .= $this->getIndent() . 'default: {' . PHP_EOL;
                } else {
                    $condType = $case->cond->getType();
                    if ($condType !== 'Scalar_Int' and $condType !== 'Scalar_Float') {
                        $this->context->localVars = $localVars;
                        $this->indentLevel -= 2;
                        goto _fail;
                    }
                    $code .= $this->getIndent() . 'case ' . $this->parseScalar($case->cond) . ': {' . PHP_EOL;
                }
                $code .= $this->parseBlockStmts($case->stmts);
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '} while(0);' . PHP_EOL;

            return $var_def . $code;
        }

        _fail:

        $code = 'do {' . PHP_EOL;
        $this->indentLevel++;
        $switchTarget = $this->genTmpVarName();
        $switchMatched = $this->genTmpVarName();
        $code .= $this->getIndent() . 'int ' . $switchTarget . ' = -1;' . PHP_EOL;
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
            $count = count($caseGroups);
            if ($hasDefault) {
                $defaultTarget = $count;
            }
            $caseGroups[] = [$caseConds, $hasDefault, $stmts];
            $caseConds = [];
            $hasDefault = false;
        }

        foreach ($caseGroups as $target => [$conds]) {
            if (!empty($conds)) {
                $groupMatched = $this->genTmpVarName();
                $code .= $this->getIndent() . 'bool ' . $groupMatched . ' = false;' . PHP_EOL;
                foreach ($conds as $caseCond) {
                    $this->assertExprCanBeUsedAsValue($caseCond, 'switch case condition');
                    $caseBeforeStmtCount = count($this->context->beforeStmtLines);
                    $caseAfterStmtCount = count($this->context->afterStmtLines);
                    $caseCondExpr = $this->parseIdentifier($caseCond);
                    $caseBeforeStmts = array_slice($this->context->beforeStmtLines, $caseBeforeStmtCount);
                    $caseAfterStmts = array_slice($this->context->afterStmtLines, $caseAfterStmtCount);
                    $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $caseBeforeStmtCount);
                    $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $caseAfterStmtCount);

                    $code .= $this->getIndent() . 'if (!' . $switchMatched . ' && !' . $groupMatched . ') {' . PHP_EOL;
                    $code .= $this->formatCapturedStmtLines($caseBeforeStmts);
                    if ($caseAfterStmts) {
                        $caseTmpVar = $this->addTmpVar(Type::VAR);
                        $code .= $this->getIndent() . $caseTmpVar . ' = ' . $caseCondExpr . ';' . PHP_EOL;
                        $code .= $this->formatCapturedStmtLines($caseAfterStmts);
                        $caseCondExpr = $caseTmpVar;
                    }
                    $code .= $this->getIndent() . $groupMatched . ' = php::equals(' . $tmp_var . ', ' . $caseCondExpr . ');' . PHP_EOL;
                    $code .= $this->getIndent() . '}' . PHP_EOL;
                }
                $code .= $this->getIndent() . 'if (' . $groupMatched . ') {' . PHP_EOL;
                $code .= $this->getIndent() . $switchMatched . ' = true;' . PHP_EOL;
                $code .= $this->getIndent() . $switchTarget . ' = ' . $target . ';' . PHP_EOL;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
        }
        if ($defaultTarget !== null) {
            $code .= $this->getIndent() . 'if (!' . $switchMatched . ') {' . PHP_EOL;
            $code .= $this->getIndent() . $switchTarget . ' = ' . $defaultTarget . ';' . PHP_EOL;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }

        foreach ($caseGroups as $target => [, , $stmts]) {
            $code .= $this->getIndent() . 'if (' . $switchTarget . ' == ' . $target . ') {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->parseStmts($stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        if (!empty($caseConds) || $hasDefault) {
            // PHP allows a trailing label without statements; it has no code to execute.
            if ($hasDefault && $defaultTarget === null) {
                $code .= $this->getIndent() . 'if (!' . $switchMatched . ') {' . PHP_EOL;
                $code .= $this->getIndent() . $switchTarget . ' = -1;' . PHP_EOL;
                $code .= $this->getIndent() . '}' . PHP_EOL;
            }
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '} while (0);';

        return $var_def . $code;
    }

}
