<?php

use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;

final class ExpressionMaterializationContextTest extends \BaseTest
{
    private function compiler(): CompilerTest
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $phase = (new ReflectionClass(CompilerBase::class))->getConstant('PHASE_CONVERT');
        (new ReflectionMethod($compiler, 'enterCompilerPhase'))->invoke($compiler, $phase);
        (new ReflectionMethod($compiler, 'resetFunction'))->invoke($compiler);
        return $compiler;
    }

    private function expression(): Expr
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php return null ?? 'fallback';");
        return $ast[0]->expr;
    }

    private function context(CompilerTest $compiler): FunctionContext
    {
        return (new ReflectionProperty($compiler, 'context'))->getValue($compiler);
    }

    public function testRepeatedParsingMaterializesOnlyOnceWithoutMutatingAst(): void
    {
        $compiler = $this->compiler();
        $expr = $this->expression();
        $result = $compiler->parseExpr($expr);
        $context = $this->context($compiler);
        $statements = $context->beforeStmtLines;
        self::assertNotEmpty($statements);

        self::assertSame($result, $compiler->parseExpr($expr));
        self::assertSame($result, $compiler->parseExpr($expr));
        self::assertSame($statements, $context->beforeStmtLines);
        self::assertFalse($expr->hasAttribute('replace'));

        // Equal source text does not make two AST objects interchangeable.
        self::assertNotSame($result, $compiler->parseExpr($this->expression()));
    }

    public function testNestedContextMaterializesItsOwnValueAndRestoresOuterCache(): void
    {
        $compiler = $this->compiler();
        $expr = $this->expression();
        $outerValue = $compiler->parseExpr($expr);
        $outer = $this->context($compiler);
        $outerStatements = $outer->beforeStmtLines;

        $inner = new FunctionContext();
        $inner->tmpVarIndex = 10;
        $contextProperty = new ReflectionProperty($compiler, 'context');
        $contextProperty->setValue($compiler, $inner);
        try {
            self::assertNotSame($outerValue, $compiler->parseExpr($expr));
            self::assertNotEmpty($inner->beforeStmtLines);
        } finally {
            $contextProperty->setValue($compiler, $outer);
        }

        self::assertSame($outerValue, $compiler->parseExpr($expr));
        self::assertSame($outerStatements, $outer->beforeStmtLines);
    }

    public function testAnalysisResetDiscardsMaterializedResultsWithTheirStatements(): void
    {
        $compiler = $this->compiler();
        $expr = $this->expression();
        $compiler->parseExpr($expr);
        $context = $this->context($compiler);
        self::assertNotEmpty($context->beforeStmtLines);

        $context->resetAnalysisTemporaries([], 0, []);
        self::assertEmpty($context->beforeStmtLines);
        $compiler->parseExpr($expr);
        self::assertNotEmpty($context->beforeStmtLines);
    }

    public function testRepeatedDefaultLoweringProducesIndependentCompleteHelpers(): void
    {
        $compiler = $this->compiler();
        $expr = $this->expression();
        $method = new ReflectionMethod($compiler, 'parseParamDefaultValue');
        $first = $method->invoke($compiler, $expr);
        $second = $method->invoke($compiler, $expr);

        self::assertStringContainsString('auto default_value = ', $first);
        self::assertSame($first, $second);
        self::assertFalse($expr->hasAttribute('replace'));
    }
}
