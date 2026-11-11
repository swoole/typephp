<?php

namespace TypePhp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Proves local array bindings which retain their literal keys and values. */
final class LiteralArrayAnalyzer
{
    /** @var array<string, Expr\Assign> */
    private array $candidates = [];
    private array $defined = [];
    private array $invalid = [];

    /** @return array<string, Expr\Assign> */
    public function analyze(array $statements): array
    {
        $this->candidates = [];
        $this->defined = [];
        $this->invalid = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign
                && $statement->expr->var instanceof Expr\Variable
                && is_string($statement->expr->var->name) && $statement->expr->expr instanceof Expr\Array_) {
                $name = $statement->expr->var->name;
                if (isset($this->candidates[$name])) {
                    $this->invalid[$name] = true;
                }
                $this->candidates[$name] = $statement->expr;
            }
        }
        foreach ($statements as $statement) {
            $this->scan($statement);
        }
        return array_diff_key($this->candidates, $this->invalid);
    }

    private function scan(mixed $value, bool $read = true, ?Node $parent = null): void
    {
        foreach (is_array($value) ? $value : [$value] as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            if ($node instanceof Stmt\Goto_ || $node instanceof Stmt\Label) {
                $this->invalid += array_fill_keys(array_keys($this->candidates), true);
                continue;
            }
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    $this->scan($use->var, false);
                }
                continue;
            }
            if ($node instanceof Expr\ArrowFunction) {
                // Conservatively reject implicit captures. Its parameter names
                // belong to another scope and can only reduce this optimization.
                $this->scan($node->expr, false);
                continue;
            }
            if ($node instanceof Expr\Variable) {
                if (!is_string($node->name)) {
                    $this->invalid += array_fill_keys(array_keys($this->candidates), true);
                } elseif (isset($this->candidates[$node->name])
                    && !($read && isset($this->defined[$node->name])
                        && $parent instanceof Expr\ArrayDimFetch && $parent->var === $node)) {
                    $this->invalid[$node->name] = true;
                }
                continue;
            }
            if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable
                && is_string($node->var->name) && ($this->candidates[$node->var->name] ?? null) === $node) {
                $this->scan($node->expr, $read, $node);
                $this->defined[$node->var->name] = true;
                continue;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $childRead = $read;
                if ($node instanceof Expr\AssignRef || $node instanceof Stmt\Unset_
                    || $node instanceof Node\Arg || ($node instanceof Node\ArrayItem && $node->byRef)
                    || (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp
                        || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
                        || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) && $field === 'var')
                    || ($node instanceof Stmt\Foreach_ && ($field !== 'stmts' || $node->byRef))) {
                    $childRead = false;
                }
                $this->scan($node->{$field}, $childRead, $node);
            }
        }
    }
}
