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
class DevirtualizeOrderTest extends TestCase
{
    public function testSandwichDeclarationOrderKeepsOverrideDynamic(): void
    {
        // Ancestor first, leaf second, intermediate class last: the ancestor
        // method's override flag used to be missed, and the late-bound call
        // in Base::delete() was wrongly devirtualized to a direct native call.
        $cpp = $this->compileBaseInOrder(['base.php', 'leaf.php', 'mid.php']);
        $this->assertDeleteDispatchesDynamically($cpp);
    }

    public function testNormalDeclarationOrderKeepsOverrideDynamic(): void
    {
        // Control: ancestor, intermediate, leaf.
        $cpp = $this->compileBaseInOrder(['base.php', 'mid.php', 'leaf.php']);
        $this->assertDeleteDispatchesDynamically($cpp);
    }

    /** @param list<string> $order */
    private function compileBaseInOrder(array $order): string
    {
        global $translator;

        $dir = TYPEPHP_ROOT_PATH . '/phpunit/code/devirtualize-order';
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        foreach ($order as $file) {
            $compiler->addFiles([$dir . '/' . $file]);
            $compiler->prepareFile($dir . '/' . $file);
        }

        return file_get_contents($compiler->convertFile($dir . '/base.php'));
    }

    private function assertDeleteDispatchesDynamically(string $cpp): void
    {
        $matched = preg_match(
            '/php::\w+ php_ordertest__base__delete\(php::Object &this_\) \{(?<body>.*?)\n\}/s',
            $cpp,
            $m,
        );
        self::assertSame(1, $matched, 'generated body of OrderTest\\Base::delete() not found');
        // A direct native call to the base implementation means the override
        // was wrongly devirtualized; the call must go through dynamic dispatch.
        self::assertStringNotContainsString('php_ordertest__base__perform', $m['body']);
        self::assertStringContainsString('callScoped', $m['body']);
    }
}
