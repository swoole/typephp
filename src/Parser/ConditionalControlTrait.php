<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers if, elseif, and else statement chains.
 */

namespace TypePhp\Parser;

use PhpParser\Node;
use PhpParser\NodeAbstract;
use TypePhp\Analysis\ObjectBindingAnalyzer;
use TypePhp\Type;

trait ConditionalControlTrait
{
    protected function prepareObjectBindingFacts(NodeAbstract|array|null $body): void
    {
        $unsafe = [];
        foreach ((new ObjectBindingAnalyzer())->analyze($body) as $name => $_) {
            $unsafe[$name === '*' ? '*' : $this->escapeVarName($name)] = true;
        }
        $this->context->setUnsafeObjectBindings($unsafe);
        $writes = [];
        foreach ((new ObjectBindingAnalyzer())->analyze($body, true) as $name => $count) {
            $writes[$name === '*' ? '*' : $this->escapeVarName($name)] = $count;
        }
        $this->context->setObjectBindingWrites($writes);
        if (!$this->context->inClosure) {
            foreach ($this->functionDef?->argInfoList ?? [] as $argument) {
                if ($argument->type === Type::OBJECT && $argument->declaredClass === '' && $argument->class === ''
                    && $argument->typeCheck && isset($this->context->arguments[$argument->name])
                    && $this->context->getObjectBindingWriteCount($argument->name) > 0) {
                    $this->context->degradeArgumentStorageToVariant($argument->name);
                }
            }
        }
    }

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
        $bindings = $this->getInstanceofBranchBindings($cond);
        $changed = (new ObjectBindingAnalyzer())->analyze([$cond, ...$stmts], true);
        foreach ($bindings as $name => $_) {
            if (isset($changed['*']) || isset($changed[$this->unescapeVarName($name)])) {
                unset($bindings[$name]);
            }
        }
        $code .= $this->context->withRefinedObjectClasses($bindings, fn() => $this->parseBlockStmts($stmts));
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

    private function getInstanceofBranchBindings(Node\Expr $condition): array
    {
        if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd || $condition instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return $this->getInstanceofBranchBindings($condition->left)
                + $this->getInstanceofBranchBindings($condition->right);
        }
        if (!$condition instanceof Node\Expr\Instanceof_ || !$condition->expr instanceof Node\Expr\Variable
            || !is_string($condition->expr->name) || !$condition->class instanceof Node\Name
            || strtolower($condition->class->toString()) === 'static') {
            return [];
        }
        $name = $this->parseIdentifier($condition->expr);
        if (!$this->hasLocalVar($name) || $this->hasScopeGlobalVar($name) || $this->hasStaticVar($name)
            || !$this->context->canRefineObjectBinding($name)
            || !in_array($this->getRawVarType($name), [Type::VAR, Type::OBJECT], true)) {
            return [];
        }
        [, $class] = $this->resolveTypeDecl($condition->class, self::DECL_TYPE_OF_PARAM);
        if ($class === '' || $this->isNativeObjectClass($class)
            || (!$this->hasClass($class) && !$this->hasInterface($class))) {
            return [];
        }
        $known = $this->detectClassOfExpr($condition->expr);
        if ($known !== '' && $this->isObjectClassStaticallyAssignableTo($known, $class)) {
            return [];
        }
        return [$name => ['class' => $class, 'exact' => $this->isFinalClass($class)]];
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
