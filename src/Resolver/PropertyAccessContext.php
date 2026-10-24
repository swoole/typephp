<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Resolver;

use TypePhp\Entity\ClassDef;
use PhpParser\Node;

interface PropertyAccessContext
{
    public function getClassDef(string $name): ?ClassDef;

    public function getParentClass(string $class): string;

    // Match CompilerDiagnosticTrait: MINIT cannot autoload PhpParser classes
    // to prove contravariance between NodeAbstract and Node.
    public function fatalError(Node $node, string $msg): never;
}
