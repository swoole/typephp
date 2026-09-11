<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;

/**
 * Proves the deliberately small set of local Closures which can stay entirely
 * in the generated C++ function. An unknown use is an escape: this analysis
 * must reject it and leave the ordinary Zend Closure lowering untouched.
 */
final class LocalClosureAnalyzer
{
    /** @var array<string, array{assignment: Expr\Assign, closure: Expr\Closure|Expr\ArrowFunction, calls: int, callSites: list<Expr\FuncCall>}> */
    private array $candidates = [];

    /** @var array<string, true> */
    private array $invalid = [];

    /** @var array<string, true> */
    private array $defined = [];

    /**
     * @param list<Stmt> $statements
     * @return array<string, array{assignment: Expr\Assign, closure: Expr\Closure|Expr\ArrowFunction, calls: int, callSites: list<Expr\FuncCall>}>
     */
    public function analyze(array $statements): array
    {
        $this->candidates = [];
        $this->invalid = [];
        $this->defined = [];

        $duplicateNames = [];
        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt\Expression
                || !$statement->expr instanceof Expr\Assign
                || !$statement->expr->var instanceof Expr\Variable
                || !is_string($statement->expr->var->name)
                || (!$statement->expr->expr instanceof Expr\Closure
                    && !$statement->expr->expr instanceof Expr\ArrowFunction)
            ) {
                continue;
            }

            $name = $statement->expr->var->name;
            if (isset($this->candidates[$name])) {
                $duplicateNames[$name] = true;
                continue;
            }
            if (!$this->isSupportedClosure($statement->expr->expr)) {
                continue;
            }
            $this->candidates[$name] = [
                'assignment' => $statement->expr,
                'closure' => $statement->expr->expr,
                'calls' => 0,
                'callSites' => [],
            ];
        }

        foreach ($duplicateNames as $name => $_) {
            unset($this->candidates[$name]);
        }
        if ($this->candidates === []) {
            return [];
        }

        foreach ($statements as $statement) {
            $this->scanNode($statement);
        }

        foreach ($this->candidates as $name => $candidate) {
            if (isset($this->invalid[$name]) || $candidate['calls'] === 0) {
                unset($this->candidates[$name]);
            }
        }
        return $this->candidates;
    }

    private function isSupportedClosure(Expr\Closure|Expr\ArrowFunction $closure): bool
    {
        if ($closure->byRef) {
            return false;
        }
        if ($closure->returnType instanceof Node\Identifier
            && strtolower($closure->returnType->name) === 'never'
        ) {
            return false;
        }
        foreach ($closure->params as $parameter) {
            if ($parameter->byRef || $parameter->variadic || $parameter->default !== null) {
                return false;
            }
        }
        $body = $closure instanceof Expr\ArrowFunction ? $closure->expr : $closure->stmts;
        return !$this->containsUnsupportedClosureNode($body, false);
    }

    private function containsUnsupportedClosureNode(mixed $value, bool $root): bool
    {
        foreach (is_array($value) ? $value : [$value] as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            if (!$root && $node instanceof FunctionLike) {
                return true;
            }
            if ($node instanceof Expr\Yield_
                || $node instanceof Expr\YieldFrom
                || $node instanceof Stmt\Static_
                || $node instanceof Stmt\Global_
            ) {
                return true;
            }
            if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
                $name = strtolower(ltrim($node->name->toString(), '\\'));
                if (in_array($name, [
                    'func_get_arg',
                    'func_get_args',
                    'func_num_args',
                    'debug_backtrace',
                    'debug_print_backtrace',
                ], true)) {
                    return true;
                }
            }
            foreach ($node->getSubNodeNames() as $field) {
                if ($this->containsUnsupportedClosureNode($node->{$field}, false)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function scanNode(
        mixed $value,
        ?Node $parent = null,
        string $parentField = '',
        int $functionDepth = 0,
    ): void {
        foreach (is_array($value) ? $value : [$value] as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // All candidates invalidated — nothing left to scan
            if ($this->candidates === []) {
                return;
            }

            // Textual order is not a dominance proof in the presence of goto:
            // a jump may bypass the lambda initialization or re-enter its
            // scope. Keep all such functions on the Zend Closure path.
            if ($node instanceof Stmt\Goto_ || $node instanceof Stmt\Label) {
                foreach ($this->candidates as $name => $_candidate) {
                    $this->invalid[$name] = true;
                }
                return;
            }

            if ($node instanceof Expr\Variable && is_string($node->name)) {
                $this->classifyVariableUse($node->name, $parent, $parentField, $functionDepth);
            }

            $childFunctionDepth = $functionDepth + ($node instanceof FunctionLike ? 1 : 0);
            foreach ($node->getSubNodeNames() as $field) {
                $this->scanNode($node->{$field}, $node, $field, $childFunctionDepth);
            }
        }
    }

    private function classifyVariableUse(
        string $name,
        ?Node $parent,
        string $parentField,
        int $functionDepth,
    ): void {
        if (!isset($this->candidates[$name]) || isset($this->invalid[$name])) {
            return;
        }

        $candidate = $this->candidates[$name];
        if ($parent instanceof Expr\Assign && $parentField === 'var') {
            if ($parent === $candidate['assignment'] && !isset($this->defined[$name])) {
                $this->defined[$name] = true;
                return;
            }
            $this->invalid[$name] = true;
            return;
        }

        if ($functionDepth !== 0
            || !$parent instanceof Expr\FuncCall
            || $parentField !== 'name'
            || $parent->isFirstClassCallable()
            || !isset($this->defined[$name])
            || !$this->isSupportedDirectCall($parent, count($candidate['closure']->params))
        ) {
            $this->invalid[$name] = true;
            return;
        }

        $this->candidates[$name]['calls']++;
        $this->candidates[$name]['callSites'][] = $parent;
    }

    private function isSupportedDirectCall(Expr\FuncCall $call, int $parameterCount): bool
    {
        if (count($call->args) !== $parameterCount) {
            return false;
        }
        foreach ($call->args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack || $argument->name !== null) {
                return false;
            }
        }
        return true;
    }

}
