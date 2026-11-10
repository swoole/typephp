<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Analysis\SsaBuilder;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class AnalysisCheckpointTest extends TestCase
{
    public static function exits(): array
    {
        return ['success' => [false], 'exception' => [true]];
    }

    private function analyze(FunctionContext $context, callable $callback, bool $fail): mixed
    {
        $failure = new \RuntimeException('analysis interrupted');
        try {
            return $context->withAnalysisTemporaries(function () use ($callback, $fail, $failure): mixed {
                $result = $callback();
                if ($fail) {
                    throw $failure;
                }
                return $result;
            });
        } catch (\RuntimeException $caught) {
            self::assertTrue($fail);
            self::assertSame($failure, $caught);
            return null;
        }
    }

    #[DataProvider('exits')]
    public function testEntryStorageIsRestoredBeforeFinalLoopTypesAreApplied(bool $fail): void
    {
        $context = new FunctionContext();
        $context->planVariantStorage('dynamic');
        $context->addLocalVar('dynamic', Type::OBJECT);
        $context->addLocalVar('native', 'NativeThing *');
        $context->declareObjectClass('native', 'NativeContract');
        $context->bindNativeObjectClass('native', 'NativeThing');
        $context->markNativeObjectNonNull('native');
        $context->tmpVarIndex = 9;

        $loopTypes = $this->analyze($context, function () use ($context): array {
            $context->setInferredLocalType('dynamic', Type::INT);
            $context->setInferredLocalType('counter', Type::FLOAT);
            $context->declareObjectClass('native', 'SpeculativeContract');
            $context->bindNativeObjectClass('temporary', 'TemporaryNative');
            $context->forgetNativeObjectNonNull('native');
            $context->markNativeObjectNonNull('temporary');
            $context->tmpVarIndex += 5;
            return ['counter' => Type::INT];
        }, $fail);

        self::assertSame(['dynamic' => Type::VAR, 'native' => 'NativeThing *'], $context->localVars);
        self::assertSame(['native' => 'NativeContract'], $context->declaredObjects);
        self::assertSame(['native' => 'NativeThing'], $context->nativeObjects);
        self::assertSame(['native' => true], $context->nonNullNativeObjects);
        self::assertSame(9, $context->tmpVarIndex);
        self::assertSame(['dynamic' => Type::VAR], $context->varTypeDegradations);
        if ($fail) {
            self::assertNull($loopTypes);
        } else {
            self::assertSame(['counter' => Type::INT], $loopTypes);
            foreach ($loopTypes as $name => $type) {
                $context->setInferredLocalType($name, $type);
            }
            self::assertSame(Type::INT, $context->localVars['counter']);
        }
        $context->addLocalVar('dynamic', Type::FLOAT);
        self::assertSame(Type::VAR, $context->localVars['dynamic']);
    }

    #[DataProvider('exits')]
    public function testSpeculativeStatementsReferencesAndCachesAreDiscardedTogether(bool $fail): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('root', Type::INT);
        $buffer = $context->statements;
        $assignment = new Expr\Assign(new Expr\Variable('candidate'), new Expr\Closure());
        $this->analyze($context, function () use ($context, $assignment): void {
            $context->registerTypedRefLocal('alias', 'root', Type::INT_REF);
            $context->withTypedRefBridgeScope(function () use ($context): void {
                $context->registerTypedRefBridge('alias', 'analysis_wrapper');
            });
            $context->setLocalClosureCandidates(['candidate' => [
                'assignment' => $assignment,
                'closure' => $assignment->expr,
                'calls' => 1,
            ]]);
            $context->registerNativeLocalClosure('candidate', $assignment);
            $context->nativeStackPromotions['temporary'] = [];
            $context->statements->addBefore('analysis_prepare();');
            $context->statements->addAfter('analysis_cleanup();');
            $context->materializedExpressions[new Expr\Variable('root')] = 'analysis_value';
            $context->objectProps['slot'] = [];
            $context->hoistedProps['object']['property'] = true;
            $context->registerStaticPropertySlot('Example::$value', 'analysis_slot()');
            $context->getOrCreateLocalClassEntry('Example', static fn() => 'analysis_ce');
            $context->needsCalledCe = true;
            $context->needsCalledClass = true;
            $context->enterScope();
            $context->inLoop = true;
            $context->inContinuableLoop = true;
        }, $fail);

        self::assertSame(['root' => Type::INT], $context->localVars);
        self::assertSame($buffer, $context->statements);
        self::assertFalse($buffer->hasPending());
        self::assertCount(0, $context->materializedExpressions);
        self::assertSame([], $context->localVarInitializers);
        self::assertNull($context->getLocalClosureCandidate($assignment));
        self::assertFalse($context->isNativeLocalClosure('candidate'));
        self::assertSame([], $context->typedRefBindings);
        self::assertSame([], $context->typedRefRoots);
        self::assertSame([], $context->typedRefAliases);
        self::assertSame([], $context->nativeStackPromotions);
        self::assertSame([], $context->objectProps);
        self::assertSame([], $context->hoistedProps);
        self::assertSame([], $context->getStaticPropertySlots());
        self::assertSame([], $context->getLocalClassEntries());
        self::assertFalse($context->needsCalledCe);
        self::assertFalse($context->needsCalledClass);
        self::assertSame([], $context->scopeLayouts);
        self::assertSame(0, $context->scopeLevel);
        self::assertFalse($context->inLoop);
        self::assertFalse($context->inContinuableLoop);
        $context->withTypedRefBridgeScope(function () use ($context): void {
            self::assertNull($context->findTypedRefBridge('root'));
        });
    }

    #[DataProvider('exits')]
    public function testAnalysisFactsAndFunctionContractsSurviveTemporaryCleanup(bool $fail): void
    {
        $context = new FunctionContext();
        $ssa = new SsaBuilder([], []);
        $ssa->build();
        $context->arguments['parameter'] = Type::OBJECT;
        $context->markImmutableBinding('parameter', true);
        $this->analyze($context, function () use ($context, $ssa): void {
            $context->ssaBuilder = $ssa;
            $context->recordStableObjectClass('exact', 'Concrete', true);
            $context->recordStableObjectClass('stable', 'Contract', false);
            $context->unsafeObjectProps['exact'] = ['property' => true];
            $context->planVariantStorage('captured');
            $context->markImmutableBinding('captured');
        }, $fail);

        self::assertSame($ssa, $context->ssaBuilder);
        self::assertSame(['exact' => 'Concrete', 'stable' => 'Contract'], $context->stableObjects);
        self::assertSame(['exact' => 'Concrete'], $context->exactObjects);
        self::assertSame(['exact' => ['property' => true]], $context->unsafeObjectProps);
        self::assertSame(['captured' => Type::VAR], $context->varTypeDegradations);
        self::assertSame(['parameter' => true, 'captured' => true], $context->immutableVars);
        self::assertSame(['parameter' => true], $context->immutableObjectVars);
        self::assertSame(['parameter' => Type::OBJECT], $context->arguments);
    }

    public function testEachAnalysisUsesTheCurrentEntryStorage(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('first', Type::INT);
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->setInferredLocalType('discarded', Type::FLOAT);
        });
        $context->addLocalVar('second', Type::STR);
        $context->tmpVarIndex = 12;
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->setInferredLocalType('first', Type::FLOAT);
            $context->tmpVarIndex = 100;
        });
        self::assertSame(['first' => Type::INT, 'second' => Type::STR], $context->localVars);
        self::assertSame(12, $context->tmpVarIndex);
    }

    public function testRealLoweringFailureDiscardsTheMaterializationBeforeRetry(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $reflection = new \ReflectionClass($compiler);
        $reflection->getProperty('file')->setValue($compiler, __FILE__);
        $reflection->getMethod('enterCompilerPhase')->invoke($compiler, $reflection->getConstant('PHASE_CONVERT'));
        $reflection->getMethod('resetFunction')->invoke($compiler);
        $context = $reflection->getProperty('context')->getValue($compiler);
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php null ?? 'value';");
        $expression = $ast[0]->expr;
        try {
            $context->withAnalysisTemporaries(function () use ($compiler, $context, $expression): void {
                $compiler->parseExpr($expression);
                self::assertTrue($context->statements->hasPending());
                $compiler->parseExpr(new Expr\Variable(new Expr\Variable('unsupported')));
            });
            self::fail('Expected the lowering failure');
        } catch (TestError $error) {
            self::assertStringContainsString('The `$$` syntax is not supported', $error->getMessage());
        }
        self::assertSame([], $context->localVars);
        self::assertSame(0, $context->tmpVarIndex);
        self::assertFalse($context->statements->hasPending());
        self::assertCount(0, $context->materializedExpressions);
        $compiler->parseExpr($expression);
        self::assertNotEmpty($context->statements->peekBefore());
    }
}
