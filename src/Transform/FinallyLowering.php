<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Route non-local exits through a single finally outside its protected region.
 * The result uses ordinary statements, so it can also be executed by PHP to
 * check cleanup order, exception boundaries and pending return values.
 */
final class FinallyLowering
{
    private \Closure $allocateName;
    /** @var array<string, true> */
    private array $labels = [];

    /** @param callable(): string $allocateName */
    public function __construct(callable $allocateName, private bool $returnsByRef = false)
    {
        $this->allocateName = \Closure::fromCallable($allocateName);
    }

    /** @return list<Stmt> */
    public function lower(Stmt\TryCatch $statement, int $depth = 0): array
    {
        $this->labels = $this->findLabels([$statement]);
        return $this->lowerStatements([$statement], [], $depth);
    }

    /**
     * @param list<Stmt> $statements
     * @param list<FinallyLoweringScope> $scopes
     * @return list<Stmt>
     */
    private function lowerStatements(array $statements, array $scopes, int $depth): array
    {
        $result = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\TryCatch && $statement->finally !== null) {
                array_push($result, ...$this->lowerFinally($statement, $scopes, $depth));
                continue;
            }
            if ($statement instanceof Stmt\Return_
                || $statement instanceof Stmt\Break_
                || $statement instanceof Stmt\Continue_
                || $statement instanceof Stmt\Goto_
            ) {
                array_push($result, ...$this->lowerExit($statement, $scopes, $depth));
                continue;
            }
            // A closure or nested declaration owns its own exits and labels.
            if ($statement instanceof Node\FunctionLike || $statement instanceof Stmt\ClassLike) {
                $result[] = $statement;
                continue;
            }
            $statement = clone $statement;
            $childDepth = $depth + (int) ($statement instanceof Stmt\For_
                || $statement instanceof Stmt\Foreach_ || $statement instanceof Stmt\While_
                || $statement instanceof Stmt\Do_ || $statement instanceof Stmt\Switch_);
            foreach ($statement->getSubNodeNames() as $field) {
                $value = $statement->{$field};
                if ($value instanceof Stmt) {
                    [$statement->{$field}] = $this->lowerStatements([$value], $scopes, $childDepth);
                } elseif (is_array($value) && $value !== [] && $value[array_key_first($value)] instanceof Stmt) {
                    $statement->{$field} = $this->lowerStatements($value, $scopes, $childDepth);
                }
            }
            $result[] = $statement;
        }
        return $result;
    }

    /**
     * @param list<FinallyLoweringScope> $parents
     * @return list<Stmt>
     */
    private function lowerFinally(Stmt\TryCatch $statement, array $parents, int $depth): array
    {
        $scope = new FinallyLoweringScope(
            ($this->allocateName)(),
            ($this->allocateName)(),
            $this->cleanupLabel(),
            $depth,
            $this->findLabels([...$statement->stmts, ...$statement->catches]),
        );
        $active = [...$parents, $scope];
        $body = $this->lowerStatements($statement->stmts, $active, $depth);
        if ($statement->catches !== []) {
            $catches = [];
            foreach ($statement->catches as $catch) {
                $catch = clone $catch;
                $catch->stmts = $this->lowerStatements($catch->stmts, $active, $depth);
                $catches[] = $catch;
            }
            $body = [new Stmt\TryCatch($body, $catches, null, $statement->getAttributes())];
        }
        $caught = ($this->allocateName)();
        $result = [
            $this->assign($scope->action, new Node\Scalar\Int_(0)),
            $this->assign($scope->exception, new Expr\ConstFetch(new Node\Name('null'))),
            new Stmt\TryCatch($body, [new Stmt\Catch_(
                [new Node\Name\FullyQualified('Throwable')],
                new Expr\Variable($caught),
                [$this->assign($scope->exception, new Expr\Variable($caught))],
            )], null, $statement->getAttributes()),
            new Stmt\Label($scope->cleanup),
        ];
        // This finally is no longer active while its body runs. A return or
        // throw here replaces the pending action and can still leave parents.
        array_push($result, ...$this->lowerStatements($statement->finally->stmts, $parents, $depth));
        $result[] = new Stmt\If_(new Expr\BinaryOp\NotIdentical(
            new Expr\Variable($scope->exception),
            new Expr\ConstFetch(new Node\Name('null')),
        ), ['stmts' => [new Stmt\Expression(new Expr\Throw_(new Expr\Variable($scope->exception)))]]);
        foreach ($scope->exits as $index => $exit) {
            $result[] = new Stmt\If_(new Expr\BinaryOp\Identical(
                new Expr\Variable($scope->action), new Node\Scalar\Int_($index + 1),
            ), ['stmts' => $this->lowerExit($exit, $parents, $depth)]);
        }
        return $result;
    }

    /**
     * @param list<FinallyLoweringScope> $scopes
     * @return list<Stmt>
     */
    private function lowerExit(Stmt $exit, array $scopes, int $depth): array
    {
        $targetDepth = null;
        if ($exit instanceof Stmt\Break_ || $exit instanceof Stmt\Continue_) {
            if ($exit->num !== null && (!$exit->num instanceof Node\Scalar\Int_ || $exit->num->value < 1)) {
                return [$exit]; // Preserve the compiler's invalid-level diagnostic.
            }
            $level = $exit->num->value ?? 1;
            if ($level > $depth) {
                return [$exit];
            }
            $targetDepth = $depth - $level;
        }
        foreach (array_reverse($scopes) as $scope) {
            if ($targetDepth !== null && $targetDepth >= $scope->depth) {
                continue;
            }
            if ($exit instanceof Stmt\Goto_ && isset($scope->labels[$exit->name->name])) {
                continue;
            }
            $saved = clone $exit;
            $result = [];
            if ($exit instanceof Stmt\Return_ && $exit->expr !== null) {
                $value = ($this->allocateName)();
                $capture = $this->returnsByRef
                    ? new Expr\AssignRef(new Expr\Variable($value), $exit->expr)
                    : new Expr\Assign(new Expr\Variable($value), $exit->expr);
                $result[] = new Stmt\Expression($capture, $exit->getAttributes());
                $saved = new Stmt\Return_(
                    new Expr\Variable($value, $exit->expr->getAttributes()), $exit->getAttributes(),
                );
            } elseif ($targetDepth !== null) {
                // The dispatcher has already left the loops inside this try.
                $level = new Node\Scalar\Int_($scope->depth - $targetDepth);
                $saved = $exit instanceof Stmt\Break_
                    ? new Stmt\Break_($level, $exit->getAttributes())
                    : new Stmt\Continue_($level, $exit->getAttributes());
            }
            $scope->exits[] = $saved;
            $result[] = $this->assign($scope->action, new Node\Scalar\Int_(count($scope->exits)));
            $result[] = new Stmt\Goto_($scope->cleanup, $exit->getAttributes());
            return $result;
        }
        return [$exit];
    }

    private function assign(string $variable, Expr $value): Stmt\Expression
    {
        return new Stmt\Expression(new Expr\Assign(new Expr\Variable($variable), $value));
    }

    private function cleanupLabel(): string
    {
        do {
            $label = 'typephp_finally_' . ($this->allocateName)();
        } while (isset($this->labels[$label]));
        $this->labels[$label] = true;
        return $label;
    }

    /**
     * @param array<mixed> $nodes
     * @return array<string, true>
     */
    private function findLabels(array $nodes): array
    {
        $labels = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                continue;
            }
            if ($node instanceof Stmt\Label) {
                $labels[$node->name->name] = true;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $value = $node->{$field};
                $labels += $this->findLabels(is_array($value) ? $value : [$value]);
            }
        }
        return $labels;
    }
}
