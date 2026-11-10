<?php

use TypePhp\CompilerTest;

final class TypedPropertyTypeInferenceTest extends BaseTest
{
    public function testPropertyLocalsRetainObjectClassForDirectDispatch(): void
    {
        $code = $this->compileFixture();
        foreach ([
            'holder__local', 'holder__returned', 'holder__cloned', 'holder__maybe',
            'childholder__inherited', 'fromparameter', 'nested',
        ] as $function) {
            $body = $this->functionBody($code, $function);
            self::assertStringContainsString('php::Object style;', $body, $function);
            self::assertStringContainsString('php_propertyinference__style__sum(style)', $body, $function);
            self::assertStringNotContainsString('typephp_call_method_cached', $body, $function);
            self::assertStringNotContainsString('throwReturnTypeError', $body, $function);
        }
    }

    public function testInterfaceReturnResumesDirectDispatchWithoutBindingInterfaceMethod(): void
    {
        $body = $this->functionBody($this->compileFixture(), 'holder__viacontract');
        self::assertStringContainsString('php::Object style;', $body);
        self::assertSame(1, substr_count($body, 'typephp_call_method_cached('));
        self::assertStringContainsString('php_propertyinference__style__sum(', $body);
        self::assertStringNotContainsString('php_propertyinference__style__withsum(', $body);
    }

    public function testPolymorphicPropertyStillUsesRuntimeDispatch(): void
    {
        $body = $this->functionBody($this->compileFixture(), 'holder__virtual');
        self::assertStringContainsString('typephp_call_method_cached(', $body);
        self::assertStringNotContainsString('php_propertyinference__basestyle__sum(', $body);
        self::assertStringNotContainsString('php_propertyinference__childstyle__sum(', $body);
    }

    public function testLocalReceiverPreservesReferenceArguments(): void
    {
        $body = $this->functionBody($this->compileFixture(), 'byreference');
        self::assertStringContainsString('php_propertyinference__style__record(style, events)', $body);
        self::assertStringNotContainsString('typephp_call_method_cached(', $body);
    }

    public function testInaccessibleDeclarationDoesNotTypeMagicPropertyResult(): void
    {
        $code = $this->compileFixture();
        $body = $this->functionBody($code, 'magicproperty');
        self::assertStringContainsString('typephp_call_method_cached(', $body);
        self::assertStringNotContainsString('php_propertyinference__style__sum(', $body);
        $body = $this->functionBody($code, 'magicnumber');
        self::assertStringContainsString('php::Var value;', $body);
        self::assertStringNotContainsString('php::Object value;', $body);
    }

    public function testInterfacePropertyScalarBranchKeepsCompatibleStorage(): void
    {
        $body = $this->functionBody($this->compileFixture(), 'holder__choose');
        self::assertStringContainsString('typephp_call_method_cached(', $body);
        self::assertStringContainsString('php::Var(0L)', $body);
    }

    private function functionBody(string $code, string $function): string
    {
        self::assertSame(1, preg_match(
            '/(?:php::\w+|void) php_propertyinference__' . preg_quote($function, '/')
                . '\(.*?\) \{(?<body>.*?)\n\}/s',
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
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/typed-property-type-inference.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        return file_get_contents($compiler->convertFile($source));
    }
}
