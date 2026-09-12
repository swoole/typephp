<?php

use TypePhp\CompilerTest;

final class VariadicFixedArgumentsTest extends BaseTest
{
    public function testFixedArgumentsInitializeTheArrayBeforeVariadicMerge(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/variadic-fixed-arguments.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertMatchesRegularExpression(
            '/(tmp_var_\d+) = php::Array\{\s*head\s*\};\s*\1\.merge\(tail\);/',
            $code,
        );
    }
}
