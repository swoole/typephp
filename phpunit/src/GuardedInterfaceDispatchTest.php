<?php

final class GuardedInterfaceDispatchTest extends BaseTest
{
    public function testExactClassGuardKeepsTheCachedFallback(): void
    {
        $code = $this->generatedCode();
        foreach (['guardedinterfacecall', 'guardednullablecall', 'guardedpropertycall'] as $function) {
            $body = $this->body($code, $function);
            self::assertMatchesRegularExpression('/\.isObject\(\) && \w+\.ce\(\) == /', $body);
            self::assertStringContainsString('php_guardedcounterimpl__countup(', $body);
            self::assertStringContainsString('typephp_call_method_cached(', $body);
            self::assertStringNotContainsString('instanceOf(', $body);
        }
    }

    public function testMultipleImplementationsAndDefaultArgumentsKeepCachedDispatch(): void
    {
        $code = $this->generatedCode();
        foreach (['guardedmulticall', 'guardeddefaultcall'] as $function) {
            $body = $this->body($code, $function);
            self::assertStringContainsString('typephp_call_method_cached(', $body);
            self::assertStringNotContainsString('.ce() ==', $body);
        }
    }

    private function generatedCode(): string
    {
        global $translator;
        $this->compile('guarded-interface-dispatch.php');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/guarded-interface-dispatch.php';
        return file_get_contents($translator->getCppFile($file));
    }

    private function body(string $code, string $function): string
    {
        self::assertSame(1, preg_match('/php::Int php_' . $function . '\(.*?\) \{(.*?)\n\}/s', $code, $matches));
        return $matches[1];
    }
}
