<?php

use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

final class CallCacheCodegenTest extends BaseTest
{
    public function testDynamicCallSitesUseRequestLocalTypePhpCaches(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $compiler->setTargetName('call_cache_sites');
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/call-cache-sites.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        $extension = file_get_contents($compiler->genExtension());

        self::assertIsString($code);
        self::assertIsString($extension);
        self::assertSame(5, substr_count($code, 'typephp_call_cached('));
        self::assertSame(3, substr_count($code, 'typephp_call_method_cached('));
        self::assertSame(1, substr_count($code, 'typephp_call_method_scoped_cached('));
        self::assertStringNotContainsString('php::callScoped(', $code);
        self::assertSame(3, substr_count($code, 'php::fn::get_class('));
        self::assertStringContainsString('.call(get_persistent_method(', $code);

        self::assertStringContainsString('php::FunctionCallCacheSlot function_call_cache_map[5]', $extension);
        self::assertStringContainsString('php::MethodCallCacheSlot method_call_cache_map[4]', $extension);
        self::assertStringContainsString('typephp_get_function_call_cache(FunctionCallCacheId cache_id)', $extension);
        self::assertStringContainsString('typephp_get_method_call_cache(MethodCallCacheId cache_id)', $extension);
    }
}
