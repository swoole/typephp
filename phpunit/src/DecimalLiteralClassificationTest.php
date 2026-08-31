<?php

use TypePhp\CompilerTest;

/**
 * The auto-Decimal promotion applies to decimal literals whose mantissa has
 * 16+ significant digits AND whose value the double cannot reproduce
 * exactly. Exponent digits, leading zeros and trailing mantissa zeros carry
 * no precision; hex/octal/binary literals fold to their exact numeric value
 * like Zend; and a Decimal-classified literal meeting a float-typed
 * expression demotes to its exact double instead of failing to compile.
 */
final class DecimalLiteralClassificationTest extends \BaseTest
{
    public function testOnlyGenuinePrecisionLossPromotesToDecimal(): void
    {
        $code = $this->compileFixture();

        // Exactly one literal (the 21-digit pi) is promoted...
        self::assertSame(1, substr_count($code, 'php::toDecimal('));
        // ...and the borderline literals stay native floats, so every
        // is_float() probe statically folds to true.
        self::assertGreaterThanOrEqual(3, substr_count($code, 'php::toBool(true)'));
    }

    public function testHexLiteralFoldsToExactDouble(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('2.0988295480315429e+19', $code);
        self::assertStringNotContainsString('0x123456789E1234567', $code);
    }

    public function testDecimalLiteralDemotesAgainstFloatTypedExpression(): void
    {
        $code = $this->compileFixture();

        // The comparison compiles (no "Cannot convert float expression to
        // Decimal" fatal) and compares doubles like Zend.
        self::assertStringContainsString('php::equals(f, 3.1415926535897931)', $code);
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/decimal-literal-classification.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}
