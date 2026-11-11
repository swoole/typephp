<?php

final class ClosureTypePropagationTest extends BaseTest
{
    public function testLocalClosureContractsTypeParametersAndResults(): void
    {
        $code = $this->generated();
        self::assertStringContainsString('php::Object input = php::Object(', $code);
        self::assertStringContainsString('php::Int number = php::toInt(', $code);
        self::assertStringContainsString('php::Object item;', $code);
        self::assertStringContainsString('php::Int answer', $code);
        self::assertStringContainsString('php_closuretypevalue__value(item)', $code);
    }

    public function testEscapingClosureRetainsCheckedParametersAndValueCaptures(): void
    {
        $code = $this->generated();
        self::assertStringContainsString('php::Object value = php::Object(vars_.get(', $code);
        self::assertStringContainsString('php::Int amount = php::toInt(vars_.get(', $code);
        self::assertStringContainsString('php_closuretypevalue__value(input)', $code);
        self::assertStringContainsString('throwArgumentTypeError(', $code);
        self::assertStringContainsString('throwReturnTypeError(', $code);
        self::assertStringContainsString('typephp_call_method_cached(value,', $code);
        self::assertStringNotContainsString('php_closuretypebase__value(value)', $code);
    }

    private function generated(): string
    {
        global $translator;
        $this->compile('closure-type-propagation.php');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/closure-type-propagation.php';
        return file_get_contents($translator->getCppFile($file));
    }
}
