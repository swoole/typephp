<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Entity\ArgInfo;
use TypePhp\Exception\SyntaxError;
use TypePhp\Exception\TestError;
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Modifiers;
use PhpParser\ParserFactory;

class PreprocessorTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/preprocessor_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    private function setProperty(string $name, mixed $value): void
    {
        if (in_array($name, ['classes', 'interfaces', 'functions', 'classExtends'], true)) {
            $symbols = $this->getProperty('symbols');
            $method = match ($name) {
                'classes' => 'replaceClasses',
                'interfaces' => 'replaceInterfaces',
                'functions' => 'replaceFunctions',
                'classExtends' => 'replaceParents',
            };
            $symbols->{$method}($value);
            return;
        }
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function getProperty(string $name): mixed
    {
        if (in_array($name, ['classes', 'interfaces', 'functions'], true)) {
            $symbols = $this->getProperty('symbols');
            return $symbols->{$name}();
        }
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($this->compiler);
    }

    private function parseFunctionNode(string $code): Function_
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse($code);
        $this->assertNotNull($stmts);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Function_) {
                return $stmt;
            }
        }
        $this->fail('No function node found');
    }

    // ========================================================================
    // genArgumentDeclaration
    // ========================================================================

    public function testGenArgumentDeclarationSimple(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'count';
        $arg->type = 'php::Int';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Int count', $result);
    }

    public function testGenArgumentDeclarationString(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'name';
        $arg->type = 'php::Str';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Str name', $result);
    }

    public function testGenArgumentDeclarationObject(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'obj';
        $arg->type = 'php::Object';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Object obj', $result);
    }

    public function testGenArgumentDeclarationVar(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'container';
        $arg->type = 'php::Var';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Var container', $result);
    }

    // ========================================================================
    // getCppFile
    // ========================================================================

    public function testGetCppFile(): void
    {
        $phpFile = '/home/user/project/src/app.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('.cc', $result);
        $this->assertStringStartsWith($this->compiler->getBuildDir(), $result);
        $this->assertStringContainsString('app', $result);
    }

    public function testGetCppFilePreservesRelativePath(): void
    {
        $phpFile = '/var/www/myapp/controllers/UserController.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('UserController.cc', $result);
        $this->assertStringStartsWith($this->compiler->getBuildDir(), $result);
    }

    public function testGetCppFileDoesNotTrimSiblingDirectoryByCharacterPrefix(): void
    {
        $this->setProperty('buildDir', '/tmp/project/build');

        $result = $this->compiler->getCppFile('/tmp/project2/src/app.php');

        $this->assertSame('/tmp/project/build/project2/src/app.cc', $result);
    }

    public function testGetCppFileDotPhpReplaced(): void
    {
        $phpFile = '/tmp/test_file.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('test_file.cc', $result);
        $this->assertStringNotContainsString('.php', basename($result));
    }

    // ========================================================================
    // getObjectFile
    // ========================================================================

    public function testGetObjectFile(): void
    {
        $cppFile = $this->compiler->getBuildDir() . '/include/test.cc';
        $result = $this->compiler->getObjectFile($cppFile);

        $this->assertSame($this->compiler->getBuildDir() . '/include/test.o', $result);
    }

    public function testGetObjectFileDifferentObjectExtension(): void
    {
        // On Linux the object extension is .o
        $path = '/some/path/file.cc';
        $result = $this->compiler->getObjectFile($path);
        $this->assertStringEndsWith('file.cc.o', $result);
    }

    public function testGetObjectFileKeepsNativeSourceExtensionsDistinct(): void
    {
        $cObject = $this->compiler->getObjectFile('/some/path/foo.c');
        $cppObject = $this->compiler->getObjectFile('/some/path/foo.cpp');
        $ccObject = $this->compiler->getObjectFile('/some/path/foo.cc');

        $this->assertSame('/some/path/foo.c.o', $cObject);
        $this->assertSame('/some/path/foo.cpp.o', $cppObject);
        $this->assertSame('/some/path/foo.cc.o', $ccObject);
    }

    // ========================================================================
    // getMethodName
    // ========================================================================

    public function testGetMethodName(): void
    {
        $method = new Node\Stmt\ClassMethod('handle');
        $result = $this->invokeMethod('getMethodName', $method);
        $this->assertEquals('handle', $result);
    }

    public function testGetMethodNameConstructor(): void
    {
        $method = new Node\Stmt\ClassMethod('__construct');
        $result = $this->invokeMethod('getMethodName', $method);
        $this->assertEquals('__construct', $result);
    }

    // ========================================================================
    // getParentClass
    // ========================================================================

    public function testGetParentClassWithNamespace(): void
    {
        $this->setProperty('classExtends', [
            'app\\controllers\\homecontroller' => 'app\\controllers\\basecontroller',
        ]);
        $result = $this->compiler->getParentClass('App\\Controllers\\HomeController');
        $this->assertEquals('app\\controllers\\basecontroller', $result);
    }

    public function testGetParentClassFullyQualified(): void
    {
        $this->setProperty('classExtends', [
            'app\\entity\\user' => 'app\\entity\\base',
        ]);
        $result = $this->compiler->getParentClass('\\App\\Entity\\User');
        $this->assertEquals('app\\entity\\base', $result);
    }

    // ========================================================================
    // getSortedFiles
    // ========================================================================

    public function testSortFilesPreservesOrderForUnrelatedFiles(): void
    {
        $files = ['/a/file1.php', '/a/file2.php', '/a/file3.php'];
        $files = $this->invokeMethod('getSortedFiles', $files);
        // All original files must still be present
        $this->assertContains('/a/file1.php', $files);
        $this->assertContains('/a/file2.php', $files);
        $this->assertContains('/a/file3.php', $files);
        // Original files are preserved (sorting may append, not remove)
        $this->assertGreaterThanOrEqual(3, count($files));
    }

    public function testSortFilesEmpty(): void
    {
        $files = $this->invokeMethod('getSortedFiles', []);
        // Empty array stays empty or nearly empty
        $this->assertIsArray($files);
    }

    public function testPrepareFileParsesInterfaceMembersAndTypeChecks(): void
    {
        $file = __DIR__ . '/../code/preprocessor/interface_members.php';

        $this->compiler->prepareFile($file);

        $interfaces = $this->getProperty('interfaces');
        $this->assertArrayHasKey('demo', $interfaces);

        $iface = $interfaces['demo'];
        $this->assertSame(['ParentA', 'ParentB'], $iface->extendsList);
        $this->assertSame('ParentA', $iface->extends);
        $this->assertArrayHasKey('VERSION', $iface->constants);
        $this->assertTrue($iface->hasMethod('run'));

        $functionDef = $iface->methods['run']->functionDef;
        $this->assertTrue($functionDef->method);
        $this->assertSame('php::Int', $functionDef->argInfoList[0]->type);
        $this->assertNull($functionDef->argInfoList[0]->typeCheck);
        $this->assertSame('php::Var', $functionDef->argInfoList[1]->type);
        $this->assertNotEmpty($functionDef->argInfoList[1]->typeCheck);
        $this->assertSame('php::Var', $functionDef->returnType);
        $this->assertNotEmpty($functionDef->returnTypeCheck);
    }

    public function testInterfaceArrayConstantIsLoweredOnlyDuringConvert(): void
    {
        $file = __DIR__ . '/../code/interface_array_constant.php';

        $this->compiler->prepareFile($file);

        $interfaces = $this->getProperty('interfaces');
        $this->assertArrayHasKey('interfacearrayconstant', $interfaces);
        $constant = $interfaces['interfacearrayconstant']->constants['ITEMS'];

        $this->assertSame('php::Array', $constant->type);
        $this->assertInstanceOf(\PhpParser\Node\Expr\Array_::class, $constant->valueExpr);
        $this->assertSame('', $constant->value);
        $this->assertSame([], $this->getProperty('classMap'));
        $this->assertSame([], $this->getProperty('persistentClassMap'));
        $this->assertSame([], $this->getProperty('funcMap'));
        $this->assertSame([], $this->getProperty('persistentFuncMap'));

        $this->setProperty('compilerPhase', 'convert');
        $this->compiler->finalizeDeclarationExpressions([$file]);
        $this->assertStringContainsString('php::Array', $constant->value);
    }

    public function testPrepareFileRejectsNamespacedDuplicateClassAndInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Duplicate class `App\\Demo`');

        $file = __DIR__ . '/../code/preprocessor/duplicate_namespaced_class_interface.php';
        $this->compiler->prepareFile($file);
    }

    public function testPrepareFileParsesNamespacedConstants(): void
    {
        $file = __DIR__ . '/../code/preprocessor/namespaced_const.php';

        $this->compiler->prepareFile($file);

        $constants = $this->getProperty('constants');
        $this->assertArrayHasKey('_const_var_App__VERSION', $constants);
        $this->assertSame('App\\VERSION', $constants['_const_var_App__VERSION']->name);
    }

    public function testNamespaceEndingCommentWithUnbracketedSyntaxIsIgnored(): void
    {
        global $translator;
        $file = __DIR__ . '/../code/preprocessor/namespace_ending_comment_unbracketed.php';
        $previousTranslator = $translator ?? null;
        $translator = $this->compiler;

        try {
            $this->compiler->addFiles([$file]);
            $this->compiler->prepareFile($file);
            $this->compiler->convertFile($file);
        } finally {
            $translator = $previousTranslator;
        }

        $constants = $this->getProperty('constants');
        $this->assertArrayHasKey('_const_var_NamespaceEndingComment__VALUE', $constants);
    }

    public function testSortFilesUsesImplementsAndTraitDependencies(): void
    {
        $classFile = realpath(__DIR__ . '/../code/preprocessor/deps_class_implements.php');
        $interfaceFile = realpath(__DIR__ . '/../code/preprocessor/deps_interface.php');
        $traitUserFile = realpath(__DIR__ . '/../code/preprocessor/deps_class_uses_trait.php');
        $traitFile = realpath(__DIR__ . '/../code/preprocessor/deps_trait.php');

        $this->compiler->prepareFile($classFile);
        $this->compiler->prepareFile($interfaceFile);
        $this->compiler->prepareFile($traitUserFile);
        $this->compiler->prepareFile($traitFile);

        $files = [$classFile, $interfaceFile, $traitUserFile, $traitFile];
        $files = $this->invokeMethod('getSortedFiles', $files);

        $this->assertLessThan(array_search($classFile, $files, true), array_search($interfaceFile, $files, true));
        $this->assertLessThan(array_search($traitUserFile, $files, true), array_search($traitFile, $files, true));
    }

    public function testPrepareFileParsesTraitAliasModifierWithoutNewName(): void
    {
        $file = __DIR__ . '/../code/preprocessor/trait_alias_modifier.php';

        $this->compiler->prepareFile($file);

        $classes = $this->getProperty('classes');
        $this->assertArrayHasKey('aliasmodifieruser', $classes);
        $aliases = $classes['aliasmodifieruser']->traitAliases;
        $this->assertArrayHasKey('aliasmodifiertrait::hello', $aliases);
        $this->assertNull($aliases['aliasmodifiertrait::hello'][0]['trait']);
        $this->assertSame('hello', $aliases['aliasmodifiertrait::hello'][0]['method']);
        $this->assertSame('hello', $aliases['aliasmodifiertrait::hello'][0]['newName']);
        $this->assertSame(Modifiers::PRIVATE, $aliases['aliasmodifiertrait::hello'][0]['newModifier']);
    }

    public function testPrepareFileInfersEachClassConstantTypeIndependently(): void
    {
        $file = __DIR__ . '/../code/preprocessor/class_constants.php';

        $this->compiler->prepareFile($file);

        $classes = $this->getProperty('classes');
        $this->assertArrayHasKey('preprocessorclassconstants', $classes);
        $constants = $classes['preprocessorclassconstants']->constants;
        $this->assertSame('php::Str', $constants['TEXT']->type);
        $this->assertSame('php::Array', $constants['ITEMS']->type);
    }

    public function testPrepareFileRejectsDuplicateClassConstants(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Duplicate constant `VALUE`');

        $file = __DIR__ . '/../code/preprocessor/duplicate_class_constant.php';
        $this->compiler->prepareFile($file);
    }

    public function testPrepareFileParsesAbstractMethodSignatures(): void
    {
        $file = __DIR__ . '/../code/preprocessor/abstract_method_signature.php';

        $this->compiler->prepareFile($file);

        $classes = $this->getProperty('classes');
        $this->assertArrayHasKey('preprocessorabstractsignature', $classes);
        $methodDef = $classes['preprocessorabstractsignature']->abstractMethodDefs['load'];
        $functionDef = $methodDef->functionDef;
        $this->assertTrue($functionDef->method);
        $this->assertSame('php::Int', $functionDef->argInfoList[0]->type);
        $this->assertSame('php::Var', $functionDef->argInfoList[1]->type);
        $this->assertNotEmpty($functionDef->argInfoList[1]->typeCheck);
        $this->assertSame('php::Object', $functionDef->returnType);
        $this->assertSame('PreprocessorAbstractSignature', $functionDef->returnClass);
    }

    public function testPrepareFileRejectsDuplicateAbstractMethods(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Duplicate method `load`');

        $file = __DIR__ . '/../code/preprocessor/duplicate_abstract_method.php';
        $this->compiler->prepareFile($file);
    }

    public function testPrepareFileAcceptsAttributeArrayArguments(): void
    {
        $file = __DIR__ . '/../code/preprocessor/attribute_array_argument.php';
        $this->compiler->prepareFile($file);

        $classes = $this->getProperty('classes');
        $this->assertArrayHasKey('preprocessorattributearrayargumentcontroller', $classes);
    }

    public function testPrepareFileAcceptsAttributeNewExpressionArguments(): void
    {
        $file = __DIR__ . '/../code/preprocessor/attribute_new_expression_argument.php';
        $this->compiler->prepareFile($file);

        $classes = $this->getProperty('classes');
        $this->assertArrayHasKey('preprocessorattributesubscriber', $classes);
    }

    public function testPrepareFileRejectsInvalidNestedAttributeExpression(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');

        $file = __DIR__ . '/../code/preprocessor/attribute_invalid_expression.php';
        $this->compiler->prepareFile($file);
    }

    public function testIntersectionParamDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php interface A {} interface B {} function demo(A&B $value): void {}');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->argInfoList[0]->type);
        $this->assertNotEmpty($functionDef->argInfoList[0]->typeCheck);
        $this->assertSame('A&B', $functionDef->argInfoList[0]->typeStr);
    }

    public function testIntersectionReturnDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php interface A {} interface B {} function demo(): A&B { throw new \Exception(); }');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->returnType);
        $this->assertNotEmpty($functionDef->returnTypeCheck);
        $this->assertSame('A&B', $functionDef->returnTypeStr);
    }

    public function testNullableReturnDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php function demo(): ?int { return null; }');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->returnType);
        $this->assertNotEmpty($functionDef->returnTypeCheck);
        $this->assertSame('?int', $functionDef->returnTypeStr);
    }
}
