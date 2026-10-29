<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class SnippetParser
{
    private const CLASS_PREFIX = "<?php\nfinal class __TypePhpSnippet {\n";
    private const STMT_PREFIX = "<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): void {\n";
    private const EXPR_PREFIX = "<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): mixed { return ";
    private const PREFIX_LINES = 2;

    private static ?Parser $parser = null;

    public static function member(string $source, string $site): Stmt
    {
        $stmts = self::parse(self::CLASS_PREFIX, $source, "\n}", $site);
        if (count($stmts) !== 1) {
            throw new ProviderException(
                'member() takes exactly one class member, got ' . count($stmts) . ' ' . $site,
            );
        }
        $member = $stmts[0];
        if (!$member instanceof Stmt\ClassMethod
            && !$member instanceof Stmt\Property
            && !$member instanceof Stmt\ClassConst
            && !$member instanceof Stmt\TraitUse
        ) {
            throw new ProviderException(
                'member() accepts a method, a property, a class constant or `use TraitName;`, got '
                . $member->getType() . ' ' . $site,
            );
        }
        self::assertNoAttributes($member, $site);
        self::assertMemberNameAllowed($member, $site);
        if ($member instanceof Stmt\TraitUse) {
            $member->traits = array_map(
                static fn (Node\Name $name): Node\Name => new Node\Name\FullyQualified(
                    TypeString::ofName($name),
                ),
                $member->traits,
            );
        }

        return $member;
    }

    /** @return list<Stmt> */
    public static function statements(string $source, string $site): array
    {
        $stmts = self::parse(self::STMT_PREFIX, $source, "\n} }", $site);
        foreach ($stmts as $stmt) {
            self::assertNoAttributes($stmt, $site);
        }

        return $stmts;
    }

    public static function expression(string $source, string $site): Node\Expr
    {
        $stmts = self::parse(self::EXPR_PREFIX, $source, ";\n} }", $site);
        if (count($stmts) !== 1 || !$stmts[0] instanceof Stmt\Return_ || !$stmts[0]->expr instanceof Node\Expr) {
            throw new ProviderException('expected a single expression ' . $site);
        }
        self::assertNoAttributes($stmts[0], $site);

        return $stmts[0]->expr;
    }

    /** @return list<Stmt> */
    private static function parse(string $prefix, string $source, string $suffix, string $site): array
    {
        try {
            $stmts = self::parser()->parse($prefix . $source . $suffix) ?? [];
        } catch (Error $error) {
            throw new ProviderException(
                'generated code does not parse: ' . $error->getRawMessage()
                . ' at generated line ' . self::relativeLine($error->getStartLine() ?: 0) . ' ' . $site,
            );
        }
        $wrapper = $stmts[0] ?? null;
        if (!$wrapper instanceof Stmt\Class_) {
            throw new ProviderException('generated code does not parse ' . $site);
        }
        if ($prefix === self::CLASS_PREFIX) {
            return $wrapper->stmts;
        }
        $method = $wrapper->stmts[0] ?? null;
        if (!$method instanceof Stmt\ClassMethod) {
            throw new ProviderException('generated code does not parse ' . $site);
        }

        return $method->stmts ?? [];
    }

    private static function relativeLine(int $line): int
    {
        return max(1, $line - self::PREFIX_LINES);
    }

    private static function parser(): Parser
    {
        return self::$parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    private static function assertNoAttributes(Node $node, string $site): void
    {
        $attributes = (new NodeFinder())->findInstanceOf([$node], Node\AttributeGroup::class);
        if ($attributes === []) {
            return;
        }
        throw new ProviderException(
            'attribute groups are not allowed in generated code; generated members are not expanded again '
            . $site,
        );
    }

    private static function assertMemberNameAllowed(Stmt $member, string $site): void
    {
        $names = [];
        if ($member instanceof Stmt\ClassMethod) {
            $names[] = $member->name->toString();
        } elseif ($member instanceof Stmt\Property) {
            foreach ($member->props as $prop) {
                $names[] = $prop->name->toString();
            }
        } elseif ($member instanceof Stmt\ClassConst) {
            $names = array_map(static fn (Node\Const_ $const): string => $const->name->toString(), $member->consts);
        }
        foreach ($names as $name) {
            if (str_starts_with($name, '__typephp_')) {
                throw new ProviderException('generated member name `' . $name . '` is reserved ' . $site);
            }
            if (in_array(strtolower($name), ['__construct', '__destruct', '__clone'], true)) {
                throw new ProviderException('generated member name `' . $name . '` is not allowed ' . $site);
            }
        }
    }
}
