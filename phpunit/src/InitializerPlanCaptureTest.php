<?php

use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Entity\ArrayInitPlan;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class InitializerPlanCaptureTest extends BaseTest
{
    private CompilerTest $compiler;
    private FunctionContext $context;

    protected function setUp(): void
    {
        global $translator;
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $this->compiler;
        (new ReflectionProperty($this->compiler, 'file'))->setValue($this->compiler, __FILE__);
        $reflection = new ReflectionClass($this->compiler);
        $reflection->getMethod('enterCompilerPhase')->invoke($this->compiler, $reflection->getConstant('PHASE_CONVERT'));
        $reflection->getMethod('resetFunction')->invoke($this->compiler);
        $this->context = $reflection->getProperty('context')->getValue($this->compiler);
        $this->context->addLocalVar('outer', Type::INT);
    }

    private function expression(string $code = 'null ?? "fallback"'): Expr
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php return ' . $code . ';')[0]->expr;
    }

    private function plan(Expr $expression): ArrayInitPlan
    {
        return (new ReflectionMethod($this->compiler, 'buildExpressionInitPlan'))->invoke($this->compiler, $expression);
    }

    private function assertCompletePlan(ArrayInitPlan $plan): void
    {
        self::assertStringContainsString('php::Var ' . $plan->expr . ';', $plan->init);
        self::assertStringContainsString($plan->expr . ' = ', $plan->init);
        self::assertStringContainsString('php::exists(', $plan->init);
        self::assertTrue($plan->requiresRuntimeInit());
    }

    public function testRepeatedLoweringOfOneAstEmitsCompleteIndependentPlans(): void
    {
        $expression = $this->expression();
        $first = $this->plan($expression);
        $second = $this->plan($expression);

        $this->assertCompletePlan($first);
        $this->assertCompletePlan($second);
        self::assertNotSame($first->expr, $second->expr);
        self::assertSame(['outer' => Type::INT], $this->context->localVars);
        self::assertNull($this->context->getMaterializedExpression($expression));
        self::assertFalse($this->context->statements->hasPending());
    }

    public function testInitializerCannotReuseTheCallersMaterialization(): void
    {
        $expression = $this->expression();
        $outerValue = $this->compiler->parseExpr($expression);
        $locals = $this->context->localVars;
        $before = $this->context->statements->peekBefore();
        $plan = $this->plan($expression);

        $this->assertCompletePlan($plan);
        self::assertNotSame($outerValue, $plan->expr);
        self::assertSame($locals, $this->context->localVars);
        self::assertSame($outerValue, $this->context->getMaterializedExpression($expression));
        self::assertSame($before, $this->context->statements->peekBefore());
        self::assertSame($outerValue, $this->compiler->parseExpr($expression));
        self::assertSame($before, $this->context->statements->peekBefore());
    }

    public function testRealLoweringFailureDiscardsLocalsAndAllowsAnotherPlan(): void
    {
        $bad = $this->expression('[0 => null ?? "fallback", "bad" => function &() { return 1; }]');
        $left = $bad->items[0]->value;
        $outerValue = $this->compiler->parseExpr($left);
        $locals = $this->context->localVars;
        $before = $this->context->statements->peekBefore();
        $counter = $this->context->tmpVarIndex;
        $this->context->statements->addAfter('outer_cleanup();');
        try {
            $this->plan($bad);
            self::fail('Expected the unsupported Closure return diagnostic');
        } catch (TestError $error) {
            self::assertStringContainsString('cannot return by reference', $error->getMessage());
        }

        self::assertSame($locals, $this->context->localVars);
        self::assertGreaterThan($counter, $this->context->tmpVarIndex);
        self::assertSame($outerValue, $this->context->getMaterializedExpression($left));
        self::assertSame($before, $this->context->statements->peekBefore());
        $this->assertCompletePlan($this->plan($left));
        self::assertSame($locals, $this->context->localVars);
        self::assertSame(['outer_cleanup();'], $this->context->statements->takeAfter());
    }
}
