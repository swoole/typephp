<?php

use TypePhp\CompilerTest;

final class NamespacedBareCallableArgumentsTest extends BaseTest
{
    public function testOnlyTheKnownGlobalCallableParameterConvertsBareFunctionNames(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/namespaced-bare-callable-arguments.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        $literals = (new ReflectionProperty($compiler, 'literalStrings'))->getValue($compiler);
        $callback = 'get_str(' . $literals['BareFunctionFallback\\local_callback'] . ')';

        foreach (['positional', 'named'] as $name) {
            self::assertSame(1, preg_match('/php::Var php_barefunctionfallback__' . $name . '\([^\n]*\) \{(.*?)\n\}/s', $code, $body));
            self::assertStringContainsString('if (get_function_resolution_cache(', $body[1]);
            self::assertStringContainsString('[&]() -> php::Var', $body[1]);
            self::assertStringContainsString('php::constant(', $body[1]);
            self::assertStringContainsString($callback, $body[1]);
            if ($name === 'positional') {
                self::assertStringContainsString('php::VarList{' . $callback . ',', $body[1]);
            } else {
                $parameter = 'get_str(' . $literals['callback'] . ')';
                self::assertStringContainsString('.set(' . $parameter . ', ' . $callback . ');', $body[1]);
            }
        }
        self::assertSame(1, preg_match('/php::Var php_barefunctionfallback__ordinary\([^\n]*\) \{(.*?)\n\}/s', $code, $body));
        self::assertStringContainsString('php::constant(', $body[1]);
        self::assertStringNotContainsString('get_function_resolution_cache(', $body[1]);
    }
}
