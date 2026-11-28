<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node\Stmt;

/** Pending exits from one protected try/catch region. */
final class FinallyLoweringScope
{
    /** @var list<Stmt> */
    public array $exits = [];

    /** @param array<string, true> $labels */
    public function __construct(
        public string $action,
        public string $exception,
        public string $cleanup,
        public int $depth,
        public array $labels,
    ) {
    }
}
