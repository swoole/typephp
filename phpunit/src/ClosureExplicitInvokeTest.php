<?php

final class ClosureExplicitInvokeTest extends BaseTest
{
    public function testKnownClosureInvokeUsesADedicatedEntryWithoutMethodCaches(): void
    {
        global $translator;
        $this->compile('closure-explicit-invoke.php');
        $code = file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/closure-explicit-invoke.php'));
        self::assertStringContainsString('php::Object callback', $code);
        self::assertSame(7, substr_count($code, 'typephp_call_closure('));
        self::assertStringNotContainsString('get_method_call_cache(', $code);
        self::assertStringNotContainsString('typephp_call_method_cached(', $code);
        self::assertStringNotContainsString('get_persistent_method(', $code);
    }
}
