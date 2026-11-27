<?php

use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

final class ClassArrayRequestScopeTest extends BaseTest
{
    public function testArrayStorageAndZendDescriptorsAreRequestLocal(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_BIN);
        $compiler->setTargetName('class_array_request_scope');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/class-array-request-scope.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $compiler->convert([$file]);
        $extension = (string) file_get_contents($compiler->getBuildDir() . '/extension-class_array_request_scope.cc');
        self::assertStringContainsString('THREAD_LOCAL php::Var class_const__requestarrayparent__DATA;', $extension);
        self::assertStringContainsString('THREAD_LOCAL php::Var class_const__requestarraycontract__CONTRACT;', $extension);
        $headers = glob($compiler->getBuildDir() . '/include/*class_array_request_scope*decl.h');
        self::assertNotEmpty($headers);
        $declarations = implode("\n", array_map('file_get_contents', $headers));
        self::assertStringContainsString('extern THREAD_LOCAL php::Var class_const__requestarrayparent__DATA;', $declarations);
        $arginfo = (string) file_get_contents($compiler->getArgInfoHeaderFile($file));
        // An AST placeholder makes CE_CONSTANTS_TABLE return a per-request
        // descriptor. TLS storage alone would still mutate the shared CE.
        self::assertStringContainsString('Z_TYPE_INFO(const_DATA_value) = IS_CONSTANT_AST;', $arginfo);
        self::assertStringNotContainsString('ZVAL_AST(', $arginfo);
        self::assertStringContainsString('GC_PERSISTENT | GC_IMMUTABLE', $arginfo);
        self::assertStringContainsString('typephp_release_ast_constants_', $arginfo);
        self::assertStringContainsString('Z_TYPE_INFO(const_CONTRACT_value) = IS_CONSTANT_AST;', $arginfo);
        self::assertStringContainsString('Z_TYPE_INFO(const_OWN_value) = IS_CONSTANT_AST;', $arginfo);
        $leaf = substr($arginfo, strpos($arginfo, 'php_register_class_RequestArrayLeaf('));
        self::assertStringContainsString('!ZEND_MAP_PTR(class_entry->mutable_data)', $leaf);
        self::assertStringContainsString('ZEND_MAP_PTR_INIT(class_entry->mutable_data, (zend_class_mutable_data *) zend_map_ptr_new());', $leaf);
    }

    public function testArrayPlaceholderExtUsesTheAstShutdownContract(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $compiler->setTargetName('class_array_request_scope_ext');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/class-array-request-scope.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $compiler->convert([$file]);
        $extension = (string) file_get_contents($compiler->getBuildDir() . '/extension-class_array_request_scope_ext.cc');
        self::assertStringContainsString('if (type == MODULE_TEMPORARY)', $extension);
        self::assertStringContainsString('registers AST class constants', $extension);
        $start = strpos($extension, 'PHP_MSHUTDOWN_FUNCTION');
        self::assertIsInt($start);
        $shutdown = substr($extension, $start);
        self::assertMatchesRegularExpression('/PHP_MSHUTDOWN_FUNCTION[^\{]+\{\s*typephp_release_ast_constants_/', $shutdown);
    }
}
