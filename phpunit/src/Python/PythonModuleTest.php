<?php

namespace TypePhp\Tests\Python;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class PythonModuleTest extends TestCase
{
    public function testStaticallyResolvedPythonCallsUseNativeBridge(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/module-access.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        self::assertStringContainsString('php::python::callMember(', $cpp);
        self::assertStringContainsString('php::python::getAttr(', $cpp);
        self::assertStringContainsString('#include <phpx_python.h>', $cpp);
        self::assertStringContainsString('php::python::importModule(', $extension);
        self::assertStringNotContainsString('php::call(', $extension);
    }

    public function testDynamicPythonMethodNameStillUsesZendDispatch(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/dynamic-method.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        self::assertStringContainsString('object.call(method, php::VarList{1L})', $cpp);
        self::assertStringNotContainsString('typephp_call_method_cached(', $cpp);
        self::assertStringNotContainsString('php::python::callMember(', $cpp);
    }

    public function testUsedModuleGeneratesLazyNativeBindingWithoutPhpyLinkage(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/module-access.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cppFile = $compiler->convertFile($source);
        $cpp = file_get_contents($cppFile);
        $extensionFile = $compiler->genExtension();
        $extension = file_get_contents($extensionFile);

        $this->assertStringContainsString('php_get_python_module(', $cpp);
        $this->assertStringContainsString('php::python::getAttr(', $cpp);
        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('THREAD_LOCAL zval php_python_module_map[1]', $extension);
        $this->assertStringContainsString('php::Object php_get_python_module(', $extension);
        $this->assertStringContainsString('zval_ptr_dtor(', $extension);
        $this->assertStringContainsString('php::python::importModule(', $extension);
        $this->assertStringNotContainsString('#include <phpy', $extension);
        $this->assertStringNotContainsString('phpy::', $extension);
        $this->assertStringNotContainsString('module_that_does_not_exist', $extension);
    }

    public function testUnusedPythonUseDoesNotGenerateModuleRuntimeState(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/unused-module.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $extensionFile = $compiler->genExtension();
        $extension = file_get_contents($extensionFile);

        $this->assertStringNotContainsString('php_python_module_map', $extension);
        $this->assertStringNotContainsString('php_get_python_module', $extension);
        $this->assertStringNotContainsString('module_that_does_not_exist', $extension);
    }

    public function testModuleValueCannotUsePhpClassConstantSyntax(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Python module member `math::pi` must use `math\pi`');

        $this->compileFixture('module-constant.php');
    }

    public function testFullyQualifiedModuleAttributeUsesNamespaceConstantSyntax(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/fully-qualified-module-constant.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        $this->assertStringContainsString('php_get_python_module(', $cpp);
        $this->assertStringContainsString('php::python::getAttr(', $cpp);
    }

    public function testModuleStaticCallSyntaxIsRejected(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Python module callable `math::sqrt()` must use `math\sqrt()`');

        $this->compileFixture('obsolete-module-static-call.php');
    }

    public function testModuleStaticPropertySyntaxIsRejected(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Python module attribute `math::$pi` must use `math\pi`');

        $this->compileFixture('obsolete-module-static-property.php');
    }

    public function testModuleAliasUsesPhpCaseInsensitiveConflictRules(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('the name is already in use');

        $this->compileFixture('alias-conflict.php');
    }

    public function testNestedModuleUsesPythonDottedImportName(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/nested-module.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('numpy.linalg', $extension);
        $this->assertStringNotContainsString('numpy\\\\linalg', $extension);
    }

    public function testPhpFunctionAndConstantImportsResolvePythonSymbols(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/imported-symbols.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertSame(4, substr_count($cpp, 'php_get_python_module('));
        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('php::python::getAttr(', $cpp);
        $this->assertStringContainsString('builtins', $extension);
        $this->assertStringContainsString('math', $extension);
        $this->assertStringContainsString('THREAD_LOCAL zval php_python_module_map[2]', $extension);
    }

    public function testFullyQualifiedModuleAccessDoesNotRequireUseDeclaration(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/fully-qualified-module.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('php::python::getAttr(', $cpp);
        $this->assertStringContainsString('math', $extension);
        $this->assertStringContainsString('os.path', $extension);
        $this->assertStringContainsString('builtins', $extension);
        $this->assertStringContainsString('__str__', $extension);
        $this->assertStringContainsString('THREAD_LOCAL zval php_python_module_map[3]', $extension);
        $this->assertStringNotContainsString('App.python.math', $extension);
    }

    public function testRelativePythonNameInsideNamespaceRemainsPhpName(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/relative-module-name.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringNotContainsString('php_get_python_module(', $cpp);
        $this->assertStringNotContainsString('php_python_module_map', $extension);
        $this->assertStringContainsString('php_app__python__math__sqrt', $cpp);
        $this->assertStringContainsString('php_app__python__len', $cpp);
    }

    public function testSameModuleAcrossFilesUsesOneRuntimeSlot(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $sources = [
            TYPEPHP_ROOT_PATH . '/phpunit/code/python/module-access.php',
            TYPEPHP_ROOT_PATH . '/phpunit/code/python/duplicate-module.php',
        ];
        $compiler->addFiles($sources);
        foreach ($sources as $source) {
            $compiler->prepareFile($source);
            $compiler->convertFile($source);
        }
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('THREAD_LOCAL zval php_python_module_map[1]', $extension);
    }

    public function testPythonBuiltinsUseBuiltinsModuleAndPreserveKnownObjectTypes(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/builtins.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php_get_python_module(', $cpp);
        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('php::Object list;', $cpp);
        $this->assertStringContainsString('php::Object dict;', $cpp);
        $this->assertStringContainsString('php::Object tuple;', $cpp);
        $this->assertStringContainsString('php::Object set;', $cpp);
        $this->assertStringContainsString('php::Object str;', $cpp);
        $this->assertStringContainsString('php::Object _php__var__int;', $cpp);
        $this->assertStringContainsString('php::Object object;', $cpp);
        $this->assertStringContainsString('php::Object bytes;', $cpp);
        $this->assertStringContainsString('scalar = php::toInt(', $cpp);
        $this->assertSame(8, substr_count($cpp, 'php::python::construct('));
        $this->assertStringNotContainsString('php::newObject(', $cpp);
        $this->assertStringNotContainsString('php::call(get_persistent_class(get_str(', $cpp);
        $this->assertStringNotContainsString('PyList', $extension);
        $this->assertStringNotContainsString('PyDict', $extension);
        $this->assertStringContainsString('THREAD_LOCAL zval php_python_module_map[1]', $extension);
        $this->assertStringContainsString('builtins', $extension);
        $this->assertStringNotContainsString('PyCore::int', $extension);
        $this->assertStringContainsString('php::python::configureRuntime(true)', $extension);
        $this->assertStringContainsString('php_python_runtime_configured = false;', $extension);
        $this->assertStringNotContainsString('python\\\\list', $extension);
    }

    public function testNestedPythonNameUsesModuleCallableSyntax(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/invalid-builtin-path.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('collections', $extension);
        $this->assertStringContainsString('deque', $extension);
    }

    public function testConstructorOnlyProgramConfiguresObjectPreservingRuntimeLazily(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/constructor-only.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php_configure_python_runtime()', $cpp);
        $this->assertStringContainsString('void php_configure_python_runtime()', $extension);
        $this->assertStringContainsString('php::python::configureRuntime(true)', $extension);
    }

    public function testPythonOperatorsLowerToTheOperatorModule(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/operators.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('operator', $extension);
        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('add', $extension);
        $this->assertStringContainsString('iadd', $extension);
        $this->assertStringContainsString('is_', $extension);
        $this->assertStringContainsString('is_not', $extension);
    }

    public function testPythonObjectProtocolUsesNativeCallsAndZendHandlersWhereAppropriate(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/object-protocol.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php::python::callMember(', $cpp);
        $this->assertStringContainsString('php::python::call(', $cpp);
        $this->assertStringContainsString('php::python::getAttr(', $cpp);
        $this->assertStringNotContainsString('.attr(', $cpp);
        $this->assertStringContainsString('.item(', $cpp);
        $this->assertStringContainsString('php::ForeachIterator', $cpp);
        $this->assertStringContainsString('integer = php::toInt(object);', $cpp);
        $this->assertStringContainsString('iadd', $extension);
        $this->assertStringNotContainsString('phpy::', $cpp);
    }

    public function testPyObjectConversionMethodsUseTheNativeBridge(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/object-conversion-methods.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringContainsString('php::Array array;', $cpp);
        $this->assertStringContainsString('php::Var value;', $cpp);
        $this->assertStringNotContainsString('php::toArray(', $cpp);
        $this->assertStringContainsString('php::python::toArray(', $cpp);
        $this->assertStringContainsString('php::python::toValue(', $cpp);
        $this->assertStringNotContainsString('php::toPlainValue(', $cpp);
        $this->assertStringNotContainsString('phpy::', $cpp);
    }

    public function testPythonFacadeMethodsKeepTheirPhpReturnTypes(): void
    {
        global $translator;
        $translator = $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/facade-methods.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        self::assertStringContainsString('php::Int count = 0;', $cpp);
        self::assertStringContainsString('php::Bool contains = 0;', $cpp);
        self::assertStringContainsString('php::Bool member = 0;', $cpp);
        self::assertStringContainsString('php::Array slice;', $cpp);
        self::assertStringContainsString('php::python::toArray(', $cpp);
        self::assertStringNotContainsString('php::python::callMember(', $cpp);
    }

    public function testProjectClassesWithFacadeNamesDoNotUseThePythonBridge(): void
    {
        global $translator;
        $translator = $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/facade-class-name-collision.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $cpp = file_get_contents($compiler->convertFile($source));

        self::assertStringNotContainsString('php::python::', $cpp);
    }

    private function compileFixture(string $file): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
    }
}
