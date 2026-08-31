<?php

use TypePhp\CompilerTest;

/**
 * Class-constant and property-default metadata must embed scalar values with
 * exact, well-formed C literals: PHP_INT_MIN cannot be spelled as one
 * negative literal ("-9223372036854775808" negates an out-of-range positive
 * literal and is ill-formed), -0.0 must keep its sign, and doubles must
 * round-trip at 17 significant digits.
 */
final class ConstantMetadataLiteralTest extends \BaseTest
{
    public function testIntMinAndFloatConstantsEmitExactLiterals(): void
    {
        $previous = ini_set('precision', '14');
        try {
            global $translator;
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/int-min-constant-metadata.php';
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
            $arginfoHeader = $compiler->getArgInfoHeaderFile($testFile);
            $arginfo = file_get_contents($arginfoHeader);
        } finally {
            if ($previous !== false) {
                ini_set('precision', $previous);
            }
        }

        self::assertIsString($arginfo);
        self::assertStringContainsString('ZVAL_LONG(&const_MIN_value, ZEND_LONG_MIN);', $arginfo);
        self::assertStringContainsString('ZVAL_LONG(&const_MAX_value, 9223372036854775807);', $arginfo);
        self::assertStringContainsString('ZVAL_LONG(&property_floor_default_value, ZEND_LONG_MIN);', $arginfo);
        self::assertStringContainsString('ZVAL_DOUBLE(&const_NEGZ_value, -0.0);', $arginfo);
        self::assertStringContainsString('ZVAL_DOUBLE(&const_PI_value, 3.1415926535897931);', $arginfo);
        self::assertStringNotContainsString('-9223372036854775808', $arginfo);
    }
}
