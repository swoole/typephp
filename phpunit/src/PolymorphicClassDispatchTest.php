<?php

use TypePhp\CompilerTest;

final class PolymorphicClassDispatchTest extends BaseTest
{
    public function testPolymorphicClassIntrospectionAndStaticDispatch(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/polymorphic-dispatch.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);

        // Exact new object definition get_class() folds to compile-time literal string
        self::assertMatchesRegularExpression(
            '/php_getexactclass\(\) \{.*?tmp_var_\d+ = \(get_str\(\d+\)\);/s',
            $code,
        );

        // Polymorphic get_class() must NOT fold to BaseAnimal; it must invoke runtime get_class
        self::assertMatchesRegularExpression(
            '/php_getpolymorphicclass\(\) \{.*?tmp_var_\d+ = \(php::fn::get_class\(animal\)\);/s',
            $code,
        );

        // Exact new object definition static call devirtualizes to cached call
        self::assertMatchesRegularExpression(
            '/php_callexactstatic\(\) \{.*?typephp_call_cached\(get_str\(\d+\)/s',
            $code,
        );

        // Polymorphic static call must NOT statically bind to BaseAnimal; it must dispatch dynamically
        self::assertMatchesRegularExpression(
            '/php_callpolymorphicstatic\(\) \{.*?php::callStaticMethod\([^)]+\)/s',
            $code,
        );
    }
}
