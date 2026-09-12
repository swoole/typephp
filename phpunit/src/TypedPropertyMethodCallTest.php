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
}
