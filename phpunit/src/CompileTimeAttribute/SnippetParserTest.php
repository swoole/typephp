<?php

namespace TypePhp\Tests\CompileTimeAttribute;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;
use TypePhp\CompileTimeAttribute\Literal;
use TypePhp\CompileTimeAttribute\ProviderException;
use TypePhp\CompileTimeAttribute\SnippetParser;

final class SnippetParserTest extends TestCase
{
    public function testMemberAcceptsOneMethod(): void
    {
        $member = SnippetParser::member('public function tag(): string { return "x"; }', 'test');

        $this->assertInstanceOf(Stmt\ClassMethod::class, $member);
        $this->assertSame('tag', $member->name->toString());
    }

    public function testMemberAcceptsAPropertyAndAConstant(): void
    {
        $this->assertInstanceOf(Stmt\Property::class, SnippetParser::member('private int $__count = 0;', 'test'));
        $this->assertInstanceOf(Stmt\ClassConst::class, SnippetParser::member('public const TAG = "x";', 'test'));
    }

    public function testMemberNormalisesTraitUseToFullyQualified(): void
    {
        $member = SnippetParser::member('use App\Support\Logs;', 'test');

        $this->assertInstanceOf(Stmt\TraitUse::class, $member);
        $this->assertSame('App\Support\Logs', $member->traits[0]->toString());
        $this->assertInstanceOf(Node\Name\FullyQualified::class, $member->traits[0]);
    }

    public function testMemberRejectsMultipleMembers(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('exactly one class member');

        SnippetParser::member('private int $a = 0; private int $b = 0;', 'test');
    }

    public function testMemberRejectsAttributes(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('attribute groups are not allowed');

        SnippetParser::member('#[Deprecated] public function tag(): void {}', 'test');
    }

    public function testMemberRejectsReservedNames(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('reserved');

        SnippetParser::member('public function __typephp_orig_tag(): void {}', 'test');
    }

    public function testMemberRejectsAConstructor(): void
    {
        $this->expectException(ProviderException::class);

        SnippetParser::member('public function __construct() {}', 'test');
    }

    public function testStatementsCanReturnAnEmptyList(): void
    {
        $this->assertSame([], SnippetParser::statements('', 'test'));
    }

    public function testStatementsParseACall(): void
    {
        $statements = SnippetParser::statements('$this->log[] = $value;', 'test');

        $this->assertCount(1, $statements);
        $this->assertInstanceOf(Stmt\Expression::class, $statements[0]);
    }

    public function testExpressionParsesAnArithmeticExpression(): void
    {
        $expression = SnippetParser::expression('$this->v * 2', 'test');

        $this->assertInstanceOf(Node\Expr\BinaryOp\Mul::class, $expression);
    }

    public function testParseErrorPointsAtTheSnippet(): void
    {
        try {
            SnippetParser::member('public function broken( {', 'site-marker');
            $this->fail('expected a ProviderException');
        } catch (ProviderException $error) {
            $this->assertStringContainsString('does not parse', $error->getMessage());
            $this->assertStringContainsString('site-marker', $error->getMessage());
        }
    }

    public function testLiteralEvaluatesConstantsAndArrayLiterals(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $expression = $parser->parse('<?php [1, "a" => 2.5, true, null, \App\Thing::class];')[0] ?? null;
        $this->assertInstanceOf(Stmt\Expression::class, $expression);

        $this->assertSame([1, 'a' => 2.5, true, null, 'App\Thing'], Literal::evaluate($expression->expr));
    }

    public function testLiteralRejectsNonConstantExpressions(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $expression = $parser->parse('<?php $value;')[0] ?? null;
        $this->assertInstanceOf(Stmt\Expression::class, $expression);

        $this->expectException(ProviderException::class);

        Literal::evaluate($expression->expr);
    }
}
