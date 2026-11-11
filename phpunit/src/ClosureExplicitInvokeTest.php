<?php

final class ClosureExplicitInvokeTest extends BaseTest
{
    public function testClosureInvokeUsesTheObjectHandlerInsteadOfAClassMethodPointer(): void
    {
        global $translator;
        $this->compile('closure-explicit-invoke.php');
        $code = file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/closure-explicit-invoke.php'));
        self::assertStringContainsString('php::Object callback', $code);
        self::assertStringContainsString('typephp_call_method_cached(', $code);
        self::assertStringNotContainsString('get_persistent_method(', $code);
    }
}
