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
final class ArrayKeyExistsOptimizationTest extends BaseTest
{
    public function testOnlyIntegerAndStringKeysUseDirectLookup(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/array-key-exists-optimization.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        self::assertSame(2, substr_count($code, '.offsetExists('));
        self::assertSame(8, substr_count($code, 'php::call('));
        self::assertStringNotContainsString('php::fn::array_key_exists(', $code);
    }
}
