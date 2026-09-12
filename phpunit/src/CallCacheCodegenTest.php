<?php

use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\Int_;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

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
        self::assertSame(1, substr_count($code, 'typephp_call_cached('));
        self::assertSame(4, substr_count($code, 'php::callStaticMethod('));
        self::assertStringNotContainsString('php::concat({', $code);
        self::assertSame(9, substr_count($code, 'php::VarList{'));
        self::assertStringNotContainsString('std::array<php::Variant', $code);
        self::assertStringNotContainsString('php::ArgList{', $code);
        self::assertSame(2, substr_count($code, 'typephp_call_method_cached('));
        self::assertSame(0, substr_count($code, 'typephp_call_method_scoped_cached('));
        self::assertSame(1, substr_count($code, 'php::callScoped('));
        self::assertSame(2, substr_count($code, '.call(method'));
        self::assertStringContainsString('.call(get_persistent_method(', $code);

        self::assertStringContainsString('php::FunctionCallCacheSlot function_call_cache_map[1]', $extension);
        self::assertStringContainsString('php::MethodCallCacheSlot method_call_cache_map[2]', $extension);
        self::assertStringContainsString('typephp_get_function_call_cache(FunctionCallCacheId cache_id)', $extension);
        self::assertStringContainsString('typephp_get_method_call_cache(MethodCallCacheId cache_id)', $extension);
    }

    public function testSlotAccessorsDeclareNoexceptWithoutChangingResolvingLookups(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $compiler->setTargetName('noexcept_cache_accessors');
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/call-cache-sites.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $headerFile = tempnam(sys_get_temp_dir(), 'typephp-noexcept-');
        try {
            $compiler->genDataDeclarations($headerFile);
            $header = file_get_contents($headerFile);
            $extension = file_get_contents($compiler->genExtension());
            foreach ([
                'get_property_cache(PropertyCacheId cache_id)',
                'typephp_get_method_call_cache(MethodCallCacheId cache_id)',
                'typephp_get_function_call_cache(FunctionCallCacheId cache_id)',
            ] as $signature) {
                self::assertStringContainsString($signature . ' noexcept;', $header);
                self::assertStringContainsString($signature . ' noexcept {', $extension);
            }
            // Resolving lookups may throw and must retain that contract.
            self::assertStringContainsString(
                'get_persistent_func(PersistentFuncId func_id, const php::Str &func_name);',
                $header,
            );
            self::assertStringContainsString(
                'get_persistent_func(PersistentFuncId func_id, const php::Str &func_name) {',
                $extension,
            );
        } finally {
            unlink($headerFile);
        }
    }

    public function testCallArgumentLimitRejectsBrokenUnboundedLowering(): void
    {
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $argument = new Arg(new Int_(0), false, false, ['startLine' => 1]);
        $method = new ReflectionMethod($compiler, 'assertCallArgumentLimit');
        (new ReflectionProperty($compiler, 'file'))->setValue($compiler, 'argument-limit.php');

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('A function call cannot contain more than 65536 arguments');
        $method->invoke($compiler, array_fill(0, 65_537, $argument));
    }
}
