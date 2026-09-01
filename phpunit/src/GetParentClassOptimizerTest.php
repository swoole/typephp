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
final class GetParentClassOptimizerTest extends TestCase
{
    public function testInternalClassLiteralFallsBackToRuntimeLookup(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/get-parent-class-internal-literal.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        self::assertIsString($cpp);
        self::assertSame(2, substr_count($cpp, 'php::fn::get_parent_class('));
    }
}
