<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
class RoundModeTest extends TestCase
{
    public function testRoundingModeReachesTheRuntimeUnconverted(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/round-with-mode.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        // php::fn::round() models the mode as an Int, so an enum must not be
        // lowered to it: the conversion turns HalfEven into half away from
        // zero. The call goes to the runtime with the enum intact instead.
        self::assertStringNotContainsString('php::fn::round(', $cpp);
        self::assertStringContainsString('php::constant(', $cpp);
    }

    public function testLegacyIntegerModeKeepsTheNativeCall(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/round-with-legacy-mode.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        self::assertStringContainsString('php::fn::round(', $cpp);
    }
}
