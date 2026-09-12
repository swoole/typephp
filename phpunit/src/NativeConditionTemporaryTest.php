<?php

use TypePhp\CompilerTest;

final class NativeConditionTemporaryTest extends BaseTest
{
    public function testBooleanConditionsWithOperandCleanupRemainUnboxed(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/native-condition-temporaries.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        preg_match_all('/(tmp_var_\d+) = php::toBool\(php::same\(/', $code, $matches);
        self::assertCount(2, $matches[1]);
        foreach ($matches[1] as $temporary) {
            self::assertStringContainsString('php::Bool ' . $temporary . ' = 0;', $code);
        }

        $dynamicBody = explode('php::Bool php_dynamiccondition(', $code, 2)[1];
        $dynamicBody = explode("\n}", $dynamicBody, 2)[0];
        self::assertStringContainsString('php::Var tmp_var_1;', $dynamicBody);
        self::assertStringContainsString('tmp_var_1 = php_dynamicconditionvalue(', $dynamicBody);
    }
}
