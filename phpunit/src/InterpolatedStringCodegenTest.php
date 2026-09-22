<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
final class InterpolatedStringCodegenTest extends BaseTest
{
    public function testLiteralPartsUseTheBinarySafeLiteralStringPool(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $compiler->setTargetName('interpolated_null_string');
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/interpolated-null-string.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        self::assertStringContainsString('php::concat({get_str(', $code);

        $literalStrings = (new ReflectionProperty($compiler, 'literalStrings'))->getValue($compiler);
        self::assertArrayHasKey("before\0", $literalStrings);
        self::assertArrayHasKey("after\0", $literalStrings);

        $extension = file_get_contents($compiler->genExtension());
        self::assertIsString($extension);
        self::assertStringContainsString('ZEND_STRL("before\000")', $extension);
        self::assertStringContainsString('ZEND_STRL("after\000")', $extension);
    }

    public function testNoLiteralStringsFallsBackToBinarySafeInlineStrings(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        (new ReflectionProperty($compiler, 'noLiteralStrings'))->setValue($compiler, true);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/interpolated-null-string.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        self::assertStringNotContainsString('get_str(', $code);
        self::assertStringContainsString('php::Str{ZEND_STRL("before\000")}', $code);
        self::assertStringContainsString('php::Str{ZEND_STRL("after\000")}', $code);
        self::assertSame([], (new ReflectionProperty($compiler, 'literalStrings'))->getValue($compiler));
    }
}
