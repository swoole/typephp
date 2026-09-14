<?php

use TypePhp\CompilerTest;

final class TypedPropertyMethodCallTest extends BaseTest
{
    public function testKnownPropertyReceiverUsesDirectCallWithReferenceArguments(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/typed-property-method-call.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        self::assertIsString($code);
        self::assertSame(1, preg_match(
            '/php::Array php_propertycallholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertSame(2, substr_count($matches['body'], 'php_propertycalltarget__record('));
        self::assertStringNotContainsString('php_propertycallbase__value(', $matches['body']);
    }

    public function testNonFinalPropertyReceiverUsesRuntimeDispatch(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/php::\w+ php_privatemethodpropertyholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringNotContainsString('php_privatemethodpropertybase__value(', $matches['body']);
        self::assertMatchesRegularExpression('/(?:typephp_call_method(?:_scoped)?_cached|\.call)\(/', $matches['body']);
    }

    public function testInterfacePropertyReceiverUsesInterfaceReferenceSignature(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_interfacepropertyholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertMatchesRegularExpression('/(?:RefWrap|toReference|\.ref\()/i', $matches['body']);
        self::assertMatchesRegularExpression('/(?:typephp_call_method_cached|\.call)\(/', $matches['body']);
    }

    public function testStaticPropertyReceiverUsesDirectCallWithReferenceArguments(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_staticpropertycallholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringContainsString('php_staticpropertycalltarget__record(', $matches['body']);
    }

    public function testUnknownReceiverDoesNotUseSameNamedGlobalFunctionSignature(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_dynamicreceiverrecordcall\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringNotContainsString('RefWrap', $matches['body']);
        self::assertStringNotContainsString('.ref()', $matches['body']);
    }

    public function testMissingTypedPropertySignatureDoesNotUseSameNamedGlobalFunction(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_missingsignaturepropertyholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringNotContainsString('RefWrap', $matches['body']);
        self::assertStringNotContainsString('.ref()', $matches['body']);
    }

    public function testLexicalPrivateMethodWinsOverPropertyClassMethod(): void
    {
        $code = $this->compileFixture();
        foreach (['runfinal', 'runopen'] as $method) {
            self::assertSame(1, preg_match(
                '/void php_lexicalprivatepropertybase__' . $method . '\\(.*?\\) \\{(?<body>.*?)\\n\\}/s',
                $code,
                $matches,
            ));
            self::assertStringNotContainsString('php_lexicalprivatepropertybase__record(', $matches['body']);
            self::assertStringNotContainsString('php_lexicalprivatefinalpropertychild__record(', $matches['body']);
            self::assertStringNotContainsString('php_lexicalprivateopenpropertychild__record(', $matches['body']);
            self::assertStringContainsString('typephp_call_method_scoped_cached(', $matches['body']);
            self::assertMatchesRegularExpression('/(?:RefWrap|toReference|\.ref\()/i', $matches['body']);
        }
    }

    public function testFinalPropertyPrivateMethodUsesMagicRuntimeDispatch(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_magicprivatepropertyholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringNotContainsString('php_magicprivatepropertytarget__hidden(', $matches['body']);
        self::assertMatchesRegularExpression('/(?:typephp_call_method(?:_scoped)?_cached|\.call)\(/', $matches['body']);
        self::assertStringNotContainsString('RefWrap', $matches['body']);
        self::assertStringNotContainsString('.ref()', $matches['body']);
    }

    public function testFinalPropertyMissingMethodRetainsDirectMagicOptimization(): void
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/void php_magicmissingpropertyholder__run\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        self::assertStringContainsString('php_magicmissingpropertytarget____call(', $matches['body']);
        self::assertStringNotContainsString('typephp_call_method', $matches['body']);
        self::assertStringNotContainsString('RefWrap', $matches['body']);
    }

    private function compileFixture(): string
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/typed-property-method-call.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        self::assertIsString($code);
        return $code;
    }
}
