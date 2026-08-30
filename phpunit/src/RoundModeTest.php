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
        $cpp = $this->compileToCpp('round-with-mode.php');

        // php::fn::round() models the mode as an Int, so an enum must not be
        // lowered to it: the conversion turns HalfEven into half away from
        // zero. The call goes to the runtime with the enum intact instead.
        self::assertStringNotContainsString('php::fn::round(', $cpp);
        self::assertStringContainsString('php::constant(', $cpp);
    }

    public function testLegacyIntegerModeAlsoReachesTheRuntime(): void
    {
        $cpp = $this->compileToCpp('round-with-legacy-mode.php');

        // The Native wrapper calls _php_math_round() directly and never
        // validates the mode, so an out-of-range integer aborts the process
        // instead of raising ValueError. A statically typed int proves
        // nothing about the runtime value, so every explicit mode is dynamic.
        self::assertStringNotContainsString('php::fn::round(', $cpp);
    }

    public function testUnpackedArgumentsStayOnTheDynamicPath(): void
    {
        $cpp = $this->compileToCpp('round-unpacked-mode.php');

        // Both the full and the partial unpack have fewer Node\Arg entries
        // than runtime arguments, so the syntactic count must not be read.
        self::assertStringNotContainsString('php::fn::round(', $cpp);
        self::assertSame(2, substr_count($cpp, 'appendUnpacked('));
    }

    public function testCallsWithoutAModeKeepTheNativeWrapper(): void
    {
        $cpp = $this->compileToCpp('round-native-path.php');

        self::assertSame(2, substr_count($cpp, 'php::fn::round('));
    }

    private function compileToCpp(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        return file_get_contents($compiler->convertFile($source));
    }
}
