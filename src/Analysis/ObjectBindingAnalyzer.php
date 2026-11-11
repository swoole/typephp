<?php

namespace TypePhp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeAbstract;

/** Finds bindings whose identity cannot be assumed stable across a guarded scope. */
final class ObjectBindingAnalyzer
{
    private array $unsafe = [];

    /** @return array<string, int> Unsafe-use counts. A '*' entry invalidates every binding. */
    public function analyze(NodeAbstract|array|null $body, bool $includeWrites = false): array
    {
        $this->unsafe = [];
        $this->scan($body, false, $includeWrites);
        return $this->unsafe;
    }

    private function scan(mixed $value, bool $unsafe, bool $includeWrites): void
    {
        foreach (is_array($value) ? $value : [$value] as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            if ($node instanceof Stmt\Goto_ || $node instanceof Stmt\Label) {
                $this->unsafe['*'] = 1;
                continue;
            }
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_ || $node instanceof Expr\ArrowFunction) {
                continue;
            }
            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    if ($use->byRef) {
                        $this->scan($use->var, true, $includeWrites);
                    }
                }
                continue;
            }
            if ($node instanceof Expr\Variable) {
                if (!is_string($node->name)) {
                    $this->unsafe['*'] = 1;
                } elseif ($unsafe) {
                    $this->unsafe[$node->name] = ($this->unsafe[$node->name] ?? 0) + 1;
                }
                continue;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $childUnsafe = $unsafe || $node instanceof Node\Arg || $node instanceof Expr\AssignRef
                    || $node instanceof Stmt\Global_ || $node instanceof Stmt\Static_
                    || ($node instanceof Node\ArrayItem && $node->byRef)
                    || ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier
                        && $node->name->toLowerString() === 'toref');
                if ($includeWrites) {
                    $childUnsafe = $childUnsafe || $node instanceof Stmt\Unset_
                        || (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp
                            || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
                            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) && $field === 'var')
                        || ($node instanceof Stmt\Foreach_ && in_array($field, ['keyVar', 'valueVar'], true))
                        || ($node instanceof Stmt\Catch_ && $field === 'var');
                }
                if ($node instanceof Stmt\Foreach_ && $node->byRef && $field === 'valueVar') {
                    $childUnsafe = true;
                }
                $this->scan($node->{$field}, $childUnsafe, $includeWrites);
            }
        }
    }
}
