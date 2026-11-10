<?php

namespace TypePhp\Tests\Context;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;

final class StdIterationLoweringTest extends TestCase
{
    private CompilerTest $compiler;

    protected function setUp(): void
    {
        global $translator;
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $this->compiler;
        $r = new \ReflectionClass($this->compiler);
        $r->getProperty('file')->setValue($this->compiler, __FILE__);
        $r->getProperty('indentLevel')->setValue($this->compiler, 3);
        $this->invoke('enterCompilerPhase', $r->getConstant('PHASE_CONVERT'));
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

    private function statement(string $source): mixed
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source)[0];
    }

    private function register(string $method, string $arguments): void
    {
        $this->invoke($method, 'values', $this->statement('std::unused(' . $arguments . ');')->expr);
    }

    public static function failures(): iterable
    {
        $containers = [
            'array' => ['parseStdArray', 'Type::Int, 2'],
            'vector' => ['parseStdVector', 'Type::Int, 2'],
            'map' => ['parseStdMap', 'Type::Int, Type::Int'],
            'ordered map' => ['parseStdOrderedMap', 'Type::Int, Type::Int'],
        ];
        $failures = [
            'reference target' => ['foreach ($values as &$item) {}', 'Cannot use &'],
            'key target' => ['foreach ($values as $$key => $item) {}', 'The `$$` syntax'],
            'value target' => ['foreach ($values as [$item]) {}', 'Cannot assign value'],
            'loop body' => ['foreach ($values as $item) { $$bad; }', 'The `$$` syntax'],
        ];
        foreach ($containers as $kind => [$method, $arguments]) {
            foreach ($failures as $name => [$source, $message]) {
                yield $kind . ': ' . $name => [$method, $arguments, $source, $message, $kind !== 'array'];
            }
        }
    }

    #[DataProvider('failures')]
    public function testEveryLoweringFailureRestoresIndentationAndIterationState(
        string $method,
        string $arguments,
        string $source,
        string $message,
        bool $mutable,
    ): void {
        $this->register($method, $arguments);
        $context = $this->context();
        $info = $context->getStdStorageInfo('values');
        $node = $this->statement($source);
        try {
            $this->invoke('parseForeachStdContainer', $node);
            self::fail('Expected foreach lowering to fail');
        } catch (TestError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertFalse($context->isStdContainerIterating('values'));
        self::assertSame(3, (new \ReflectionProperty($this->compiler, 'indentLevel'))->getValue($this->compiler));
        self::assertSame($info, $context->getStdStorageInfo('values'));
        self::assertSame(0, $context->scopeLevel);
        $this->invoke('assertStdContainerStructureMutable', $node, 'values');
        $valid = $this->statement('foreach ($values as $item) {}');
        $code = $this->invoke('parseForeachStdContainer', $valid);
        self::assertSame($mutable ? 1 : 0, substr_count($code, '.iterationGuard()'));
        self::assertFalse($context->isStdContainerIterating('values'));
    }

    public function testFailedNestedLoweringCannotDropTheOuterIterationRestriction(): void
    {
        $this->register('parseStdVector', 'Type::Int, 2');
        $context = $this->context();
        $context->withStdContainerIteration('values', function () use ($context): void {
            $node = $this->statement('foreach ($values as &$item) {}');
            try {
                $this->invoke('parseForeachStdContainer', $node);
                self::fail('Expected the invalid reference target');
            } catch (TestError) {
            }
            self::assertTrue($context->isStdContainerIterating('values'));
            try {
                $this->invoke('assertStdContainerStructureMutable', $node, 'values');
                self::fail('The outer iteration must remain protected');
            } catch (TestError $error) {
                self::assertStringContainsString('during foreach', $error->getMessage());
            }
        });
        self::assertFalse($context->isStdContainerIterating('values'));
    }
}
