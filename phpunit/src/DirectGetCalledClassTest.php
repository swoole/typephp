<?php

use TypePhp\Build\NanoExtensionSelector;
use TypePhp\Analysis\CompilationStatistics;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

final class DirectGetCalledClassTest extends BaseTest
{
    public function testBuiltinUsesRuntimeCalledClassInInstanceAndStaticMethods(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/direct-get-called-class.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertSame(2, substr_count($code, 'const php::Str _typephp_called_class ='));
        self::assertStringNotContainsString('php::call(', $code);
        self::assertStringContainsString('php_directcalledbase__instancename(object)', $code);
    }

    public function testCaseInsensitiveUserFunctionAliasIsNotReplacedWithClassName(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/get-called-class-shadow-alias.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertStringNotContainsString('_typephp_called_class', $code);
        self::assertStringContainsString('php_externalfunctions__shadow(', $code);
    }

    public function testNamespacedFunctionShadowsAndFallsBackAtRuntime(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $compiler->setTargetName('get_called_class_fallback');
        (new \ReflectionProperty($compiler, 'noLiteralStrings'))->setValue($compiler, true);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/get-called-class-namespace-fallback.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertStringContainsString('php_calledclassknown__get_called_class(', $code);
        self::assertStringContainsString('php::fn::function_exists(', $code);
        self::assertStringContainsString('typephp_call_cached(', $code);
        self::assertStringContainsString('_typephp_called_class', $code);
        self::assertStringContainsString('CalledClassDynamic\\\\get_called_class', $code);
        self::assertStringNotContainsString('ZEND_STRL("get_called_class")', $code);
        self::assertSame(1, substr_count($code, 'typephp_get_function_resolution_cache('));
        self::assertSame(1, substr_count($code, 'typephp_get_function_call_cache('));
        self::assertStringContainsString('resolution == 0', $code);
        self::assertStringContainsString('resolution = php::fn::function_exists(', $code);
        self::assertStringContainsString(') ? 1 : 2;', $code);
        self::assertStringContainsString('resolution == 1', $code);

        $extension = file_get_contents($compiler->genExtension());
        self::assertIsString($extension);
        self::assertStringContainsString('uint8_t function_resolution_cache_map[1]', $extension);
        self::assertStringContainsString(
            'uint8_t &typephp_get_function_resolution_cache(FunctionResolutionCacheId cache_id)',
            $extension,
        );

        $statistics = $compiler->getCompilationStatistics();
        self::assertTrue($statistics->has(CompilationStatistics::DIRECT_FUNCTIONS, 'function_exists'));
        $selection = (new NanoExtensionSelector())->select($statistics, ['standard']);
        self::assertSame(['standard.core'], $selection->features);
    }

    public function testQualifiedRuntimeFunctionUsesResolvedTarget(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        (new \ReflectionProperty($compiler, 'noLiteralStrings'))->setValue($compiler, true);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/qualified-runtime-function.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertStringContainsString('Vendor\\\\Package\\\\runtime_function', $code);
        self::assertStringContainsString('Consumer\\\\Sub\\\\runtime_function', $code);
        self::assertStringNotContainsString('Lib\\\\runtime_function', $code);
    }
}
