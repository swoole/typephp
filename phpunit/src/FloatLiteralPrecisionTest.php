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
class FloatLiteralPrecisionTest extends TestCase
{
    public function testFloatConstantsIgnoreThePrecisionIniOfTheHost(): void
    {
        // precision=14 is the PHP default, so this is what most hosts compile
        // with. The constant baked into the binary must not depend on it.
        $previous = ini_set('precision', '14');

        try {
            $cpp = $this->compileToCpp('float-constant-precision.php');
        } finally {
            if ($previous !== false) {
                ini_set('precision', $previous);
            }
        }

        self::assertStringContainsString('2.7182818284590451', $cpp);
        self::assertStringNotContainsString('2.718281828459)', $cpp);
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
