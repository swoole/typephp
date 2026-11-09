<?php

use TypePhp\CompilerTest;

/**
 * eval() and include/require see the caller's `$this`, which only exists in
 * the Zend object frame. Any call that is devirtualized into a direct C++
 * function would lose it, so those calls must keep runtime dispatch.
 */
final class EvalObjectContextTest extends BaseTest
{
    public function testNewReceiverKeepsRuntimeDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runnew');

        self::assertStringContainsString('typephp_call_method_cached', $body);
        self::assertStringNotContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testVariableReceiverKeepsRuntimeDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runvariable');

        self::assertStringContainsString('typephp_call_method_cached', $body);
        self::assertStringNotContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testThisReceiverKeepsRuntimeDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runthis');

        self::assertStringContainsString('typephp_call_method_cached', $body);
        self::assertStringNotContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testIncludeMethodKeepsRuntimeDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runinclude');

        self::assertStringContainsString('typephp_call_method_cached', $body);
        self::assertStringNotContainsString('php_evalobjectcontext__evaltarget__includemutate(', $body);
    }

    public function testInheritedMethodKeepsRuntimeDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evalchild__runinherited');

        self::assertStringContainsString('typephp_call_method_cached', $body);
        self::assertStringNotContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testMethodWithoutObjectContextStillUsesDirectCall(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runplain');

        self::assertStringContainsString('php_evalobjectcontext__plaintarget__poke(', $body);
        self::assertStringNotContainsString('typephp_call_method_cached', $body);
    }

    private function functionBody(string $function): string
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/(?:[\w:]+) ' . preg_quote($function, '/') . '\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        return $matches['body'];
    }

    private function compileFixture(): string
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/eval-object-context.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);
        return $code;
    }
}
