<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class SelectionLoweringScopeTest extends TestCase
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
        $this->context()->addLocalVar('receiver', Type::OBJECT);
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

    private function expression(string $source): Expr
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source . ';')[0]->expr;
    }

    #[DataProvider('failingExpressions')]
    public function testFailureRestoresAllEnclosingExpressionIndentation(string $method, string $source, string $message): void
    {
        try {
            $this->invoke($method, $this->expression($source));
            self::fail('Expected the expression diagnostic');
        } catch (TestError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame(3, $this->indentation());
        $following = $this->invoke('parseMatch', $this->expression('match (1) { 1 => 2, default => 3 }'));
        self::assertStringEndsWith("\n\t\t\t}()", $following);
        self::assertSame(3, $this->indentation());
    }

    public static function failingExpressions(): array
    {
        $closureError = 'cannot return by reference';
        return [
            'match condition' => ['parseMatch', 'match (1) { (function &() { return 1; }) => 2 }', $closureError],
            'match arm' => ['parseMatch', 'match (1) { 1 => function &() { return 1; } }', $closureError],
            'match default' => ['parseMatch', 'match (1) { default => function &() { return 1; } }', $closureError],
            'nested match' => ['parseMatch', 'match (1) { 1 => match (2) { default => function &() { return 1; } } }', $closureError],
            'forbidden match condition' => ['parseMatch', 'match (1) { (match (2) { default => 2 }) => 3 }', 'Match expression cannot be used as a condition'],
            'nullsafe argument' => ['parseNullsafeExpr', '$receiver?->run(function &() { return 1; })', $closureError],
            'nullsafe chain argument' => ['parseNullsafeExpr', '$receiver?->child?->run(function &() { return 1; })', $closureError],
            'nullsafe in match' => ['parseMatch', 'match (1) { default => $receiver?->run(function &() { return 1; }) }', $closureError],
            'match in nullsafe' => ['parseNullsafeExpr', '$receiver?->run(match (1) { default => function &() { return 1; } })', $closureError],
        ];
    }

    #[DataProvider('receiverStorage')]
    public function testSuccessfulNullsafeChainKeepsGuardsAndCleanupOutsideItsBody(string $type, string $guardReceiver, int $cleanupCount): void
    {
        $this->context()->addLocalVar('receiver', $type);
        $result = $this->invoke('parseNullsafeExpr', $this->expression('$receiver?->child?->value'));
        $context = $this->context();
        $code = implode("\n", $context->statements->takeBefore());
        self::assertStringContainsString("\n\t\t\t\tif ({$guardReceiver}.isNull()) {", $code);
        self::assertStringContainsString("\n\t\t\t\t\treturn php::null;", $code);
        self::assertStringEndsWith("\n\t\t\t};", $code);
        self::assertMatchesRegularExpression('/^tmp_var_\d+\(\)$/', $result);
        self::assertCount($cleanupCount, $context->statements->takeAfter());
        self::assertSame(3, $this->indentation());
    }

    public static function receiverStorage(): array
    {
        return [
            'object' => [Type::OBJECT, 'receiver', 2],
            'variant needs an owned receiver' => [Type::VAR, 'tmp_var_0', 3],
        ];
    }
}
