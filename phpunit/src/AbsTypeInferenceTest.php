<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
final class AbsTypeInferenceTest extends BaseTest
{
    public function testResultStorageFollowsTheStaticallySelectedOverload(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/abs-type-inference.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        self::assertSame(3, substr_count($code, 'php::Int result = 0;'));
        self::assertSame(1, substr_count($code, 'php::Float result = 0;'));
        self::assertSame(2, substr_count($code, 'php::Var result;'));
        self::assertSame(4, substr_count($code, 'php::fn::abs('));
        self::assertStringContainsString('get_function_resolution_cache(', $code);
    }
}
