<?php

final class LiteralArrayTypeTest extends BaseTest
{
    public function testUnchangedArrayReadsAndLiteralDestructuringKeepTypes(): void
    {
        $code = $this->generated();
        foreach (['literalarrayread', 'literalarraydestructure', 'literalarrayknownkeys'] as $function) {
            $body = $this->body($code, $function);
            self::assertStringContainsString('php::Object value;', $body);
            self::assertStringContainsString('php_literalarrayvalue__value(value)', $body);
            self::assertStringNotContainsString('typephp_call_method_cached(', $body);
        }
        $body = $this->body($code, 'literalarraydestructure');
        self::assertStringContainsString('php::Int count', $body);
        self::assertStringContainsString('php::Str text;', $body);
    }

    public function testUnknownKeysAndMutableOrEscapingArraysKeepDynamicElements(): void
    {
        $code = $this->generated();
        foreach (['literalarrayunknownkey', 'literalarraymodified', 'literalarrayelementescape',
            'literalarraycaptureescape', 'literalarraysnapshot'] as $function) {
            self::assertStringContainsString('php::Var value;', $this->body($code, $function));
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
        $this->compile('literal-array-types.php');
        return file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/literal-array-types.php'));
    }
}
