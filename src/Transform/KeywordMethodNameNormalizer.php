<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeVisitorAbstract;
use TypePhp\CompilerBase;

/**
 * PHP method names are case-insensitive, so `$x->TOARRAY()` is the keyword
 * method `toArray()`. Rewriting the spelling once here lets every later
 * keyword-method lookup and code generator compare exact canonical names.
 */
final class KeywordMethodNameNormalizer extends NodeVisitorAbstract
{
    /** @var array<string, string>|null */
    private static ?array $canonicalNames = null;

    public static function canonicalName(string $name): ?string
    {
        if (self::$canonicalNames === null) {
            self::$canonicalNames = ['instanceof' => 'instanceOf'];
            foreach (array_keys(CompilerBase::KEYWORD_METHOD_MAP) as $keyword) {
                self::$canonicalNames[strtolower($keyword)] = $keyword;
            }
        }
        return self::$canonicalNames[strtolower($name)] ?? null;
    }

    public function enterNode(Node $node): null
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
        ) {
            $canonical = self::canonicalName($node->name->name);
            if ($canonical !== null && $canonical !== $node->name->name) {
                $node->name = new Node\Identifier($canonical, $node->name->getAttributes());
            }
        }
        return null;
    }
}
