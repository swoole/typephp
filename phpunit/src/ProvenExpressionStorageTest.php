<?php

use TypePhp\CompilerTest;

final class ProvenExpressionStorageTest extends BaseTest
{
    public function testTypedStorageAvoidsStringConversionsButDynamicReadsKeepThem(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/proven-expression-storage.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        foreach (['storedstringchoice', 'storedcallchoice', 'storedmatchchoice', 'provenexpressionstorage__methodchoice'] as $name) {
            self::assertSame(1, preg_match('/php::Str php_' . $name . '\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
            self::assertStringNotContainsString('php::toString(', $match[1], $name);
        }
        self::assertSame(1, preg_match('/php::Str php_dynamicpropertychoice\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertSame(1, substr_count($match[1], 'php::toString('));
        self::assertSame(1, preg_match('/php::Str php_dynamicresultchoice\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertStringContainsString('throwReturnTypeError', $match[1]);
    }
}
