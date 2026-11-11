<?php

final class InstanceofTypeNarrowingTest extends BaseTest
{
    public function testFinalClassesAndFinalMethodsUseScopedNativeCalls(): void
    {
        $code = $this->generated();
        foreach (['narrowingfinal', 'narrowingunion', 'narrowingand'] as $name) {
            $body = $this->body($code, $name);
            self::assertStringContainsString('php_narrowingvalue__value(', $body);
            self::assertStringNotContainsString('typephp_call_method_cached(', $body);
        }
        self::assertStringContainsString('php_narrowingother__value(value)', $this->body($code, 'narrowingunion'));
        self::assertStringContainsString('php_narrowingbase__fixed(', $this->body($code, 'narrowingfixed'));
        self::assertStringNotContainsString('php_narrowingbase__value(', $this->body($code, 'narrowingbase'));
        self::assertStringContainsString('typephp_call_method_cached(', $this->body($code, 'narrowingbase'));
    }

    public function testBranchFactsDoNotEscapeOrSurviveUnknownReplacements(): void
    {
        $code = $this->generated();
        $restored = $this->body($code, 'narrowingrestored');
        self::assertStringContainsString('php_narrowingvalue__value(', $restored);
        self::assertStringContainsString('typephp_call_method_cached(value,', $restored);
        foreach (['narrowingmodified', 'narrowingalias', 'narrowingreference', 'narrowingdynamic',
            'narrowingclosurecapture', 'narrowingchangedcondition', 'narrowinggoto'] as $name) {
            self::assertStringNotContainsString('php_narrowingvalue__value(', $this->body($code, $name));
        }
    }

    private function body(string $code, string $function): string
    {
        self::assertSame(1, preg_match('/php::\w+ php_' . $function . '\(.*?\) \{(.*?)\n\}/s', $code, $matches));
        return $matches[1];
    }

    private function generated(): string
    {
        global $translator;
        $this->compile('instanceof-type-narrowing.php');
        return file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/instanceof-type-narrowing.php'));
    }
}
