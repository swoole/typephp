<?php

use TypePhp\CompilerTest;

/**
 * Dynamic source does not capture AOT caller state and must not force
 * methods off their ordinary direct-call path.
 */
final class IndependentDynamicCodeTest extends BaseTest
{
    public function testNewReceiverKeepsDirectDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runnew');

        self::assertStringNotContainsString('typephp_call_method_cached', $body);
        self::assertStringContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testVariableReceiverKeepsDirectDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runvariable');

        self::assertStringNotContainsString('typephp_call_method_cached', $body);
        self::assertStringContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testThisReceiverKeepsDirectDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runthis');

        self::assertStringNotContainsString('typephp_call_method_cached', $body);
        self::assertStringContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
    }

    public function testIncludeMethodKeepsDirectDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evaltarget__runinclude');

        self::assertStringNotContainsString('typephp_call_method_cached', $body);
        self::assertStringContainsString('php_evalobjectcontext__evaltarget__includemutate(', $body);
    }

    public function testInheritedMethodKeepsDirectDispatch(): void
    {
        $body = $this->functionBody('php_evalobjectcontext__evalchild__runinherited');

        self::assertStringNotContainsString('typephp_call_method_cached', $body);
        self::assertStringContainsString('php_evalobjectcontext__evaltarget__mutate(', $body);
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

    public function testIncludeDoesNotExportCallerVariables(): void
    {
        foreach (['includemutate', 'requirefile', 'includeonce', 'requireonce'] as $method) {
            $body = $this->functionBody('php_evalobjectcontext__evaltarget__' . $method);
            self::assertStringContainsString('php::include(', $body);
            self::assertStringNotContainsString('php::Array{', $body);
        }
    }

    public function testIndependentDynamicCodeKeepsPropertyHoisting(): void
    {
        foreach (['stableeval', 'stableinclude'] as $method) {
            $body = $this->functionBody('php_evalobjectcontext__evaltarget__' . $method);
            self::assertStringContainsString('php::Int &_object_prop_this___value = this_.attrInt(', $body);
        }
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
