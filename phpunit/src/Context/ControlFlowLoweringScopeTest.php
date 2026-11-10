<?php

namespace TypePhp\Tests\Context;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;

final class ControlFlowLoweringScopeTest extends TestCase
{
    private CompilerTest $compiler;

    protected function setUp(): void
    {
        global $translator;
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $this->compiler;
        (new \ReflectionProperty($this->compiler, 'file'))->setValue($this->compiler, __FILE__);
        (new \ReflectionProperty($this->compiler, 'indentLevel'))->setValue($this->compiler, 3);
        $this->invoke('enterCompilerPhase', 'convert');
        $this->invoke('resetFunction');
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($this->compiler, $method))->invoke($this->compiler, ...$arguments);
    }

    private function context(): FunctionContext
    {
        return (new \ReflectionProperty($this->compiler, 'context'))->getValue($this->compiler);
    }

    private function indentation(): int
    {
        return (new \ReflectionProperty($this->compiler, 'indentLevel'))->getValue($this->compiler);
    }

    private function parse(string $source): string
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source);
        return $this->invoke('parseStmts', $stmts);
    }

    public function testNestedIndentationRestoresEachCallerOnFailure(): void
    {
        $failure = new \RuntimeException('nested block failed');
        $result = $this->invoke('withIndentedBlock', function () use ($failure): int {
            self::assertSame(4, $this->indentation());
            try {
                $this->invoke('withIndentedBlock', function () use ($failure): void {
                    self::assertSame(5, $this->indentation());
                    throw $failure;
                });
                self::fail('Expected the nested failure');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(4, $this->indentation());
            return 42;
        });
        self::assertSame(42, $result);
        self::assertSame(3, $this->indentation());
    }

    #[DataProvider('failingStatements')]
    public function testLoweringFailureRestoresEnclosingScopeAndControlState(string $source): void
    {
        $context = $this->context();
        $context->withScope(function () use ($context, $source): void {
            $layouts = $context->scopeLayouts;
            $context->withBreakableContext(true, function () use ($context, $source, $layouts): void {
                try {
                    $this->parse($source);
                    self::fail('Expected unsupported variable-variable lowering to fail');
                } catch (TestError $error) {
                    self::assertStringContainsString('The `$$` syntax is not supported', $error->getMessage());
                }
                self::assertSame(3, $this->indentation());
                self::assertSame(1, $context->scopeLevel);
                self::assertSame($layouts, $context->scopeLayouts);
                self::assertTrue($context->inLoop);
                self::assertFalse($context->inContinuableLoop);
                self::assertTrue($context->breakableIsSwitch);
                self::assertSame(1, $context->breakableDepth);
            });
        });
        self::assertSame(0, $context->scopeLevel);
        self::assertSame([], $context->scopeLayouts);
        self::assertFalse($context->inLoop);
        self::assertFalse($context->inContinuableLoop);
        self::assertFalse($context->breakableIsSwitch);
        self::assertSame(0, $context->breakableDepth);
    }

    public static function failingStatements(): array
    {
        return [
            'for and while' => ['for ($i = 0; $i < 1; ++$i) { while (true) { $$bad; } }'],
            'do while' => ['do { $$bad; } while (false);'],
            'foreach body' => ['foreach ([1] as $value) { $$bad; }'],
            'foreach target' => ['foreach ([1] as $$bad) {}'],
            'native switch' => ['switch (1) { case 1: $$bad; break; }'],
            'dynamic switch' => ['switch ("a") { case "a": $$bad; break; }'],
            'elseif' => ['if (false) {} elseif (true) { $$bad; }'],
            'catch with finally' => ['try {} catch (\Exception $e) { $$bad; } finally { echo 1; }'],
            'finally' => ['try {} finally { $$bad; }'],
            'nested blocks' => ['{ { $$bad; } }'],
        ];
    }

    public function testFailedLoopDoesNotAllowFollowingBreakOrContinue(): void
    {
        try {
            $this->parse('while (true) { $$bad; }');
            self::fail('Expected the loop failure');
        } catch (TestError) {
        }
        foreach (['break', 'continue'] as $jump) {
            try {
                $this->parse($jump . ';');
                self::fail('Expected the outside-loop diagnostic');
            } catch (TestError $error) {
                self::assertStringContainsString('Cannot ' . $jump . ' outside loop', $error->getMessage());
            }
        }
        self::assertSame(0, $this->context()->scopeLevel);
        self::assertSame([], $this->context()->scopeLayouts);
        self::assertSame(3, $this->indentation());
    }
}
