<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class ArrayLoweringScopeTest extends TestCase
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

    private function indentation(): int
    {
        return (new \ReflectionProperty($this->compiler, 'indentLevel'))->getValue($this->compiler);
    }

    private function expression(string $source): Expr
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source . ';')[0]->expr;
    }

    #[DataProvider('failingArrays')]
    public function testArrayFailureRestoresIndentationForTheFollowingExpression(string $source): void
    {
        try {
            $this->invoke('parseArray', $this->expression($source));
            self::fail('Expected the unsupported Closure diagnostic');
        } catch (TestError $error) {
            self::assertStringContainsString('cannot return by reference', $error->getMessage());
        }

        self::assertSame(3, $this->indentation());
        self::assertSame("php::Array{\n\t\t\t\tphp::Var(1L)\n\t\t\t}", $this->invoke('parseArray', $this->expression('[1]')));
        self::assertSame(3, $this->indentation());
    }

    public static function failingArrays(): array
    {
        return [
            'list value' => ['[function &() { return 1; }]'],
            'integer keyed value' => ['[2 => function &() { return 1; }]'],
            'string keyed value' => ['["key" => function &() { return 1; }]'],
            'nested list' => ['[[function &() { return 1; }]]'],
            'nested map' => ['["key" => [function &() { return 1; }]]'],
        ];
    }

    #[DataProvider('failingDestructuring')]
    public function testDestructuringFailureRestoresEveryEnclosingIndentation(string $source, string $message): void
    {
        $assignment = $this->expression($source);
        try {
            $this->invoke('parseAssignToList', $assignment->var, $assignment->expr);
            self::fail('Expected the destructuring diagnostic');
        } catch (TestError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame(3, $this->indentation());
    }

    public static function failingDestructuring(): array
    {
        return [
            'target' => ['list($$bad) = [1]', 'The `$$` syntax is not supported'],
            'nested target' => ['list($first, list($$bad)) = [1, [2]]', 'The `$$` syntax is not supported'],
            'rhs array value' => ['list($first) = [function &() { return 1; }]', 'cannot return by reference'],
        ];
    }

    public function testSuccessfulNestedArraysAndDestructuringKeepTheirClosingIndentation(): void
    {
        $array = $this->invoke('parseArray', $this->expression('[[1], [2]]'));
        $context = (new \ReflectionProperty($this->compiler, 'context'))->getValue($this->compiler);
        self::assertStringContainsString(
            "\n\t\t\t\t\tphp::Var(1L)",
            implode("\n", $context->statements->peekBefore()),
        );
        self::assertStringEndsWith("\n\t\t\t}", $array);
        self::assertSame(3, $this->indentation());

        $assignment = $this->expression('list($first, list($second)) = [1, [2]]');
        $code = $this->invoke('parseAssignToList', $assignment->var, $assignment->expr);
        self::assertStringEndsWith("\n\t\t\t}", $code);
        self::assertSame(3, $this->indentation());
    }
}
