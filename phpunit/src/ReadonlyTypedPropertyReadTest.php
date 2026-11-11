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
class ReadonlyTypedPropertyReadTest extends BaseTest
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

    public function testReadonlyScalarReadsRestoreDeclaredStorageType(): void
    {
        $this->compileFixture('readonly-typed-property-read.php');
        $code = file_get_contents(
            TYPEPHP_ROOT_PATH . '/build/phpunit/code/readonly-typed-property-read.cc',
        );

        // The generic read of a readonly property returns php::Variant. A
        // typed local (switch discriminant, return value) must not be
        // initialized from it directly.
        $this->assertStringContainsString('php::toInt(typephp_read_property_scoped(this_,', $code);
        $this->assertStringContainsString('php::toFloat(typephp_read_property_scoped(this_,', $code);
        $this->assertStringContainsString('switch (tmp_var_0)', $code);
        // Readonly properties stay out of the hoisted scalar reference path.
        $this->assertStringNotContainsString('_object_prop_this___status', $code);
        $this->assertStringNotContainsString('_object_prop_this___ratio', $code);
        $this->assertStringNotContainsString('AttrMode::Update', $code);
    }

    public function testReadonlyReadsRestoreStorageForOtherReceivers(): void
    {
        global $translator;
        $this->compile('readonly-typed-property-receivers.php');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/readonly-typed-property-receivers.php';
        $code = file_get_contents($translator->getCppFile($file));

        $this->assertStringContainsString('php::toInt(typephp_read_property_scoped(state,', $code);
        $this->assertStringContainsString('php::toInt(typephp_read_property_scoped(local,', $code);
        $this->assertStringContainsString('php::toInt(typephp_read_property_scoped(php::newObject(', $code);
        $this->assertStringContainsString('php::toFloat(typephp_read_property_scoped(state,', $code);
        $this->assertStringContainsString('php::toBool(typephp_read_property_scoped(state,', $code);
        $this->assertStringNotContainsString('_object_prop_', $code);
        $this->assertStringNotContainsString('AttrMode::Update', $code);
    }
}
