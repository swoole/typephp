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
class TernaryStorageTypeTest extends BaseTest
{
    private function compileFixture(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        $this->addToAssertionCount(1);
        return TYPEPHP_ROOT_PATH . '/build/phpunit/code/' . basename($file, '.php') . '.cc';
    }

    public function testTypedPropertyBranchesUnifyToDeclaredStorageType(): void
    {
        $this->compileFixture('ternary-storage-unification.php');
        $code = file_get_contents(
            TYPEPHP_ROOT_PATH . '/build/phpunit/code/ternary-storage-unification.cc',
        );

        // `$this->enabled ? $this->mode : ''` used to emit a raw C++ ternary
        // mixing php::Variant (attribute read) with const php::String, which
        // no C++ compiler accepts.
        $this->assertStringContainsString('php::toString(this_.attr(', $code);
        $this->assertStringContainsString('php::toString(php::empty_string)', $code);
        $this->assertStringNotContainsString('? (this_.attr(', $code);
        $this->assertStringNotContainsString('? (_typephp_static_property_', $code);
    }
}
