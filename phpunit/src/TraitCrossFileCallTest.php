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
final class TraitCrossFileCallTest extends BaseTest
{
    public function testCrossFileTraitMethodCallEmitsDirectNativeCallRegardlessOfConversionOrder(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $directory = TYPEPHP_ROOT_PATH . '/phpunit/code/trait-cross-file-call/';
        $traitFile = $directory . 'trait.php';
        $classFile = $directory . 'class.php';
        $callerFile = $directory . 'caller.php';

        $compiler->addFiles([$traitFile, $classFile, $callerFile]);
        $compiler->prepareFile($traitFile);
        $compiler->prepareFile($classFile);
        $compiler->prepareFile($callerFile);

        // Convert caller.php BEFORE class.php to verify order independence
        $callerGenerated = $compiler->convertFile($callerFile);
        $callerCode = file_get_contents($callerGenerated);

        self::assertIsString($callerCode);
        self::assertStringContainsString('php_lib__classb__getattribute(', $callerCode);
        self::assertStringNotContainsString('php_lib__classb____call(', $callerCode);
        self::assertStringNotContainsString('.call(', $callerCode);

        // Now convert class.php and verify it generates the native method implementation
        $classGenerated = $compiler->convertFile($classFile);
        $classCode = file_get_contents($classGenerated);

        self::assertIsString($classCode);
        self::assertStringContainsString('php_lib__classb__getattribute(', $classCode);
    }
}
