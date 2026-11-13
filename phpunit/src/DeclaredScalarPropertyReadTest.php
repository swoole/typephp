<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

/**
 * @internal
 * @coversNothing
 */
class DeclaredScalarPropertyReadTest extends BaseTest
{
    private function compileCode(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        return TYPEPHP_ROOT_PATH . '/build/phpunit/code/' . basename($file, '.php') . '.cc';
    }

    public function testFinalClassReceiverUsesTypedOffsetRead(): void
    {
        try {
            $outputFile = $this->compileCode('declared-scalar-property-read-final.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('attrInt(get_persistent_prop(', $code);
        $this->assertStringContainsString('attrFloat(get_persistent_prop(', $code);
        $this->assertStringContainsString('this_.attrInt(', $code);
        $this->assertStringContainsString('this_.attrFloat(', $code);
        $this->assertStringNotContainsString('attr(get_persistent_prop(', $code);
    }

    public function testNonFinalReceiverKeepsVariantRead(): void
    {
        try {
            $outputFile = $this->compileCode('declared-scalar-property-read-open.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('attr(get_persistent_prop(', $code);
        $this->assertStringNotContainsString('attrInt(get_persistent_prop(', $code);
        $this->assertStringNotContainsString('attrFloat(get_persistent_prop(', $code);
    }

    public function testNullablePropertyKeepsVariantRead(): void
    {
        try {
            $outputFile = $this->compileCode('declared-scalar-property-read-nullable.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('attr(get_persistent_prop(', $code);
        $this->assertStringNotContainsString('attrInt(get_persistent_prop(', $code);
    }

    public function testReadonlyPropertyKeepsCheckedRead(): void
    {
        try {
            $outputFile = $this->compileCode('declared-scalar-property-read-readonly.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('typephp_read_property_scoped(', $code);
        $this->assertStringNotContainsString('attrInt(get_persistent_prop(', $code);
    }
}
