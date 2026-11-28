<?php

use TypePhp\CompilerTest;

final class LateStaticMethodCallTest extends BaseTest
{
    private string $code;

    protected function setUp(): void
    {
        global $translator;
        $translator = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/late-static-resolved-call.php';
        $translator->addFiles([$file]);
        $translator->prepareFile($file);
        $this->code = file_get_contents($translator->convertFile($file));
    }

    private function body(string $method): string
    {
        self::assertSame(1, preg_match(
            '/php_lateresolvedbase__' . $method . '\([^\n]*\) \{\n(.*?)\n\}/s', $this->code, $match,
        ));
        return $match[1];
    }

    public function testAllLateStaticInvocationFormsUseResolvedTargets(): void
    {
        foreach (['zero', 'withargs', 'dynamic', 'expression', 'fromstatic', 'traitcall', 'named', 'spread', 'reference', 'closure', 'staticclosure'] as $method) {
            self::assertStringContainsString('typephp_resolve_late_static_method(', $this->body($method));
        }
        self::assertStringNotContainsString('php::callScoped(this_, php::concat(', $this->code);
        self::assertStringNotContainsString('php::getMethod(_typephp_called_ce', $this->code);
    }

    public function testLookupPrecedesArgumentEvaluationAndNameIsEvaluatedOnce(): void
    {
        foreach (['withargs', 'dynamic', 'expression'] as $method) {
            $body = $this->body($method);
            self::assertLessThan(
                strpos($body, 'php_latecallargument('), strpos($body, 'typephp_resolve_late_static_method('),
            );
        }
        self::assertSame(1, preg_match_all('/names\.(?:get|item)\(/', $this->body('expression')));
    }

    public function testExactClassFastPathAndCallArgumentBridgesRemainAvailable(): void
    {
        self::assertStringContainsString('EXPECTED(', $this->body('zero'));
        self::assertStringContainsString('php_lateresolvedbase__value(this_)', $this->body('zero'));
        self::assertStringContainsString('.appendUnpacked(', $this->body('spread'));
        self::assertStringContainsString('.array()', $this->body('named'));
        self::assertStringContainsString('php::RefWrap', $this->body('reference'));
        self::assertStringContainsString('execute_data->func', $this->body('closure'));
        self::assertStringContainsString('execute_data->func, php::Object{}', $this->body('staticclosure'));
        self::assertDoesNotMatchRegularExpression('/\bEXPECTED\(/', $this->body('staticclosure'));
    }
}
