<?php

use TypePhp\CompilerTest;

/**
 * A class constant valued by an enum case must register a persistent
 * IS_CONSTANT_AST (`Enum::Case`) instead of a folded scalar: the engine then
 * separates the constants table per request, evaluates the fetch there, and
 * cleans it up — preserving case identity for static access, constant(), and
 * reflection, safely under concurrent ZTS requests.
 */
final class EnumCaseClassConstantTest extends \BaseTest
{
    private string $arginfo;

    protected function setUp(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/enum-case-class-constant.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $this->arginfo = file_get_contents(
            TYPEPHP_ROOT_PATH . '/' . 'build/include/' . basename($compiler->getArgInfoHeaderFile($source))
        );
    }

    public function testDirectCaseRegistersConstantAst(): void
    {
        self::assertStringContainsString('Z_TYPE_INFO(const_CB_value) = IS_CONSTANT_AST;', $this->arginfo);
        self::assertStringNotContainsString('ZVAL_AST(', $this->arginfo);
        self::assertStringContainsString('const_CB_value_fetch_ast->kind = ZEND_AST_CLASS_CONST;', $this->arginfo);
        self::assertStringContainsString('zend_string_init_interned("CodegenEnum", sizeof("CodegenEnum") - 1, 1)', $this->arginfo);
        self::assertStringNotContainsString('ZVAL_LONG(&const_CB_value', $this->arginfo);
    }

    public function testConstantExpressionFoldsToCaseIdentity(): void
    {
        // true ? A : B folds to the A case identity, not to a scalar.
        self::assertMatchesRegularExpression(
            '/const_PICKED_value_case_name = zend_string_init_interned\("A"/',
            $this->arginfo,
        );
    }

    public function testTypedConstantKeepsDeclaredTypeAndAstValue(): void
    {
        self::assertStringContainsString('const_CASE_VALUE_value_fetch_ast->kind = ZEND_AST_CLASS_CONST;', $this->arginfo);
        self::assertStringContainsString('zend_declare_typed_class_constant(class_entry, const_CASE_VALUE_name', $this->arginfo);
    }

    public function testInternalEnumCaseRegistersConstantAst(): void
    {
        self::assertStringContainsString('zend_string_init_interned("RoundingMode", sizeof("RoundingMode") - 1, 1)', $this->arginfo);
    }

    public function testExpressionValuedBackedCaseRegistersComputedValue(): void
    {
        self::assertStringContainsString('ZVAL_LONG(&enum_case_A_value, 2);', $this->arginfo);
    }
}
