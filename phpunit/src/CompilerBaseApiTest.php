<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\CompilerBase;
use TypePhp\Type;
use TypePhp\Exception\TestError;
use TypePhp\Platform\Macos;
use TypePhp\Platform\Windows;

class CompilerBaseApiTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;
    private \ReflectionClass $ref;
    private array $originalArgv;
    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        global $argv;
        $this->originalArgv = $argv ?? [];
        $this->originalPath = getenv('PATH');
        $this->testDir = sys_get_temp_dir() . '/compiler_api_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        global $argv;
        $argv = $this->originalArgv;
        if ($this->originalPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH=' . $this->originalPath);
        }
        // Recursively remove the test directory (compiler creates build/ subdir)
        $this->removeDirectory($this->testDir);
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

    private function getPropertyValue(string $name): mixed
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($this->compiler);
    }

    private function setPropertyValue(string $name, mixed $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    public function testMethodCacheKeepsPreviouslyAssignedClassLifetime(): void
    {
        $this->setPropertyValue('compilerPhase', 'convert');
        // The two maps have independent ID spaces. This reproduces the tpc
        // bootstrap ordering where both classes occupied slot zero.
        $this->setPropertyValue('classMap', ['LateKnownClass' => 0]);
        $this->setPropertyValue('classIndex', 1);
        $this->setPropertyValue('persistentClassMap', ['StableResolver' => 0]);
        $this->setPropertyValue('persistentClassIndex', 1);

        $methodPtr = $this->invokeMethod('getMethodPtr', 'LateKnownClass', 'run');

        $this->assertStringStartsWith('get_method(RequestFuncId{0}, ', $methodPtr);
        $this->assertStringContainsString(', RequestClassId{0}, ', $methodPtr);
        $this->assertSame(
            ['LateKnownClass::run' => 0],
            $this->getPropertyValue('funcMap'),
        );
        $this->assertSame([], $this->getPropertyValue('persistentFuncMap'));
    }

    public function testMethodCacheRejectsMismatchedLifetimeDomains(): void
    {
        $this->setPropertyValue('compilerPhase', 'convert');
        $this->setPropertyValue('classMap', ['LateKnownClass' => 0]);
        $this->setPropertyValue('classIndex', 1);
        $this->setPropertyValue('persistentFuncMap', ['LateKnownClass::run' => 0]);
        $this->setPropertyValue('persistentFuncIndex', 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Cache lifetime mismatch for LateKnownClass::run: method ID 0 is persistent, class ID 0 is request-local',
        );
        $this->invokeMethod('getMethodPtr', 'LateKnownClass', 'run');
    }

    public function testPrepareStoresDeclarationAstsWithoutAllocatingCacheIds(): void
    {
        $consumer = $this->testDir . '/consumer.php';
        $declaration = $this->testDir . '/late.php';
        file_put_contents($consumer, <<<'PHP'
<?php
namespace CachePhase;

final class Defaults
{
    public const string VALUE = LateClass::VALUE;
    public string $property = LateClass::VALUE;

    public function read(string $value = LateClass::VALUE): string
    {
        return $value;
    }
}
PHP);
        file_put_contents($declaration, <<<'PHP'
<?php
namespace CachePhase;

final class LateClass
{
    public const string VALUE = 'ready';
}
PHP);

        // Deliberately prepare the consumer first. This was the bootstrap
        // ordering that used to assign a request ID before LateClass became
        // visible, then assign persistent IDs to later method lookups.
        $this->compiler->prepareFile($consumer);
        $this->compiler->prepareFile($declaration);

        foreach (['classMap', 'persistentClassMap', 'funcMap', 'persistentFuncMap', 'persistentPropMap'] as $map) {
            $this->assertSame([], $this->getPropertyValue($map), $map);
        }
        $defaults = $this->compiler->getClassDef('CachePhase\\Defaults');
        $this->assertNotNull($defaults);
        $this->assertInstanceOf(
            \PhpParser\Node\Expr\ClassConstFetch::class,
            $defaults->getConstant('VALUE')->valueExpr,
        );
        $this->assertSame('', $defaults->getConstant('VALUE')->value);
        $this->assertNotNull($defaults->getProperty('property')->defaultExpr);
        $this->assertTrue($defaults->getMethod('read')->functionDef->argInfoList[0]->hasDefaultValue());
        $this->assertSame('', $defaults->getMethod('read')->functionDef->argInfoList[0]->default);

        $this->setPropertyValue('compilerPhase', 'convert');
        $this->compiler->finalizeDeclarationExpressions([$consumer, $declaration]);

        $this->assertSame([], $this->getPropertyValue('classMap'));
        $this->assertArrayHasKey(
            'CachePhase\\LateClass',
            $this->getPropertyValue('persistentClassMap'),
        );
        $this->assertNotSame('', $defaults->getConstant('VALUE')->value);
        $this->assertNotNull($defaults->getProperty('property')->default);
        $this->assertNotSame('', $defaults->getMethod('read')->functionDef->argInfoList[0]->default);
    }

    public function testCacheIdAllocationIsRejectedOutsideConvertPhase(): void
    {
        $this->setPropertyValue('compilerPhase', 'prepare');
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'class cache ID allocation can only be used during convert phase, current phase is prepare',
        );
        $this->invokeMethod('getClassId', 'ForbiddenDuringPrepare');
    }

    private function fixturePath(string $file): string
    {
        return __DIR__ . '/../code/compiler_api/' . $file;
    }

    private function createProjectFile(string $yaml, string $filename = 'project.yml', string $baseDir = ''): string
    {
        $projectDir = $baseDir === '' ? $this->testDir : $this->testDir . '/' . trim($baseDir, '/');
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0777, true);
        }

        $sourceFile = $projectDir . '/main.php';
        copy($this->fixturePath('main.php'), $sourceFile);

        $projectFile = $projectDir . '/' . $filename;
        file_put_contents($projectFile, $yaml);

        return $projectFile;
    }

    public function testWasiTargetDetection(): void
    {
        foreach (['wasm32-wasip2', 'wasm32-unknown-wasip2'] as $target) {
            $this->setPropertyValue('targetPlatform', $target);
            $this->assertTrue($this->compiler->isWasiTarget(), $target);
        }

        foreach (['', 'wasm32-wasi', 'wasm32-wasip1', 'wasm32-wasip1-threads', 'wasm32-unknown-unknown', 'aarch64-linux-gnu'] as $target) {
            $this->setPropertyValue('targetPlatform', $target);
            $this->assertFalse($this->compiler->isWasiTarget(), $target);
        }
    }

    private function createFakeClangFormat(string $binDir, string $logFile): void
    {
        mkdir($binDir, 0777, true);
        file_put_contents($binDir . '/clang-format', "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then\n  echo 'clang-format version test'\n  exit 0\nfi\npwd > " . escapeshellarg($logFile) . "\nprintf '%s\\n' \"$@\" >> " . escapeshellarg($logFile) . "\n");
        chmod($binDir . '/clang-format', 0755);
    }

    public function testBuildModeAliasesAreNormalized(): void
    {
        $this->compiler->setBuildMode('library');
        $this->assertSame(CompilerBase::BUILD_MODE_LIB, $this->compiler->getBuildMode());

        $this->compiler->setBuildMode('extension');
        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $this->compiler->getBuildMode());

        $this->compiler->setBuildMode('cli');
        $this->assertSame(CompilerBase::BUILD_MODE_BIN, $this->compiler->getBuildMode());
    }

    public function testPhpLanguageVersionControlsParser(): void
    {
        $this->assertSame('8.5.0', $this->compiler->getPhpVersion());

        $this->compiler->setPhpVersion('8.4');
        $this->assertSame('8.4.0', $this->compiler->getPhpVersion());
        $parser = $this->getPropertyValue('parser');
        $this->expectException(\PhpParser\Error::class);
        $parser->parse('<?php $value = "hello" |> trim(...);');
    }

    public function testPhpLanguageVersionAcceptsPipeAt85AndRejectsPre84Target(): void
    {
        $this->compiler->setPhpVersion('8.5');
        $parser = $this->getPropertyValue('parser');
        $this->assertNotEmpty($parser->parse('<?php $value = "hello" |> trim(...);'));

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Supported versions: 8.4, 8.5');
        $this->compiler->setPhpVersion('8.3');
    }

    public function testLinkedPhpRuntimeMustBeAtLeast84WithoutMatchingLanguageMinor(): void
    {
        $php83 = $this->testDir . '/php83';
        mkdir($php83 . '/include/php/main', 0777, true);
        file_put_contents($php83 . '/include/php/main/php_version.h', "#define PHP_VERSION_ID 80399\n");

        try {
            $this->invokeMethod('validatePhpRuntimeMinimum', $php83);
            self::fail('Expected PHP 8.3 target runtime to be rejected');
        } catch (TestError $error) {
            self::assertStringContainsString('requires libphp 8.4 or later', $error->getMessage());
        }

        $php84 = $this->testDir . '/php84';
        mkdir($php84 . '/include/php/main', 0777, true);
        file_put_contents($php84 . '/include/php/main/php_version.h', "#define PHP_VERSION_ID 80400\n");
        $this->compiler->setPhpVersion('8.5');
        $this->invokeMethod('validatePhpRuntimeMinimum', $php84);
        $this->addToAssertionCount(1);
    }

    public function testMiscObjectCacheIsInvalidatedWhenCompileOptionsChange(): void
    {
        $source = $this->testDir . '/typephp_runtime.cc';
        $object = $this->testDir . '/typephp_runtime.o';
        file_put_contents($source, "int typephp_runtime_test = 1;\n");
        file_put_contents($object, 'object');
        touch($source, time() - 10);
        touch($object, time() + 10);

        $this->invokeMethod('writeMiscObjectCacheMetadata', $source, $object);
        $this->assertTrue($this->compiler->hasMiscObjectFileCache($source));

        $this->setPropertyValue('cxxFlags', '-fno-rtti');
        $this->assertFalse($this->compiler->hasMiscObjectFileCache($source));
    }

    // ========================================================================
    // getTypeFromZendType
    // ========================================================================

    public function testGetTypeFromZendTypeKnown(): void
    {
        $this->assertEquals(Type::INT, $this->compiler->getTypeFromZendType('int'));
        $this->assertEquals(Type::FLOAT, $this->compiler->getTypeFromZendType('float'));
        $this->assertEquals(Type::BOOL, $this->compiler->getTypeFromZendType('bool'));
        $this->assertEquals(Type::BOOL, $this->compiler->getTypeFromZendType('true'));
        $this->assertEquals(Type::BOOL, $this->compiler->getTypeFromZendType('false'));
        $this->assertEquals(Type::VOID, $this->compiler->getTypeFromZendType('void'));
        $this->assertEquals(Type::VOID, $this->compiler->getTypeFromZendType('never'));
        $this->assertEquals(Type::STR, $this->compiler->getTypeFromZendType('string'));
        $this->assertEquals(Type::ARRAY, $this->compiler->getTypeFromZendType('array'));
        $this->assertEquals(Type::OBJECT, $this->compiler->getTypeFromZendType('object'));
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('mixed'));
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('null'));
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('callable'));
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('iterable'));
    }

    public function testGetTypeFromZendTypeUnknown(): void
    {
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('unknown_type'));
        $this->assertEquals(Type::VAR, $this->compiler->getTypeFromZendType('SomeClass'));
    }

    // ========================================================================
    // genTmpVarName
    // ========================================================================

    public function testGenTmpVarName(): void
    {
        // context must be initialized before genTmpVarName can be used
        $this->invokeMethod('resetFunction');

        $name1 = $this->compiler->genTmpVarName();
        $name2 = $this->compiler->genTmpVarName();
        $name3 = $this->compiler->genTmpVarName();

        $this->assertStringStartsWith('tmp_var_', $name1);
        $this->assertStringStartsWith('tmp_var_', $name2);
        $this->assertStringStartsWith('tmp_var_', $name3);

        // Must be sequential and unique
        $this->assertNotEquals($name1, $name2);
        $this->assertNotEquals($name2, $name3);
        $this->assertNotEquals($name1, $name3);
    }

    public function testWindowsIntegerLiteralSuffixForGeneratedCValues(): void
    {
        $this->setPropertyValue('platform', new Windows());

        $this->assertSame('42LL', $this->invokeMethod('genCValue', 42));
        $this->assertSame('-42LL', $this->invokeMethod('genCValue', -42));
        $this->assertSame('ZEND_LONG_MAX', $this->invokeMethod('genCValue', PHP_INT_MAX));
        $this->assertSame('ZEND_LONG_MIN', $this->invokeMethod('genCValue', PHP_INT_MIN));
    }

    public function testGeneratedCValuesAreAlwaysSourceCodeStrings(): void
    {
        // A string cast here would follow the precision ini, so the assertion
        // is that the emitted literal reads back as the same double.
        $this->assertSame(M_E, (float) $this->invokeMethod('genCValue', M_E));
        $this->assertSame('1', $this->invokeMethod('genCValue', true));
        $this->assertSame('0', $this->invokeMethod('genCValue', false));

        $code = $this->invokeMethod(
            'parseConstFetch',
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('M_E'))
        );
        $this->assertIsString($code);
        $this->assertSame(M_E, (float) $code);
    }

    public function testNumericStringIdentifiersGenerateSourceCodeStrings(): void
    {
        $this->assertSame(
            (string) floatval('0.2'),
            $this->invokeMethod('parseNumericIdentifier', new \PhpParser\Node\Scalar\String_('0.2'))
        );
        $this->assertSame(
            '42',
            $this->invokeMethod('parseNumericIdentifier', new \PhpParser\Node\Scalar\String_('42'))
        );
        $this->assertSame(
            '0',
            $this->invokeMethod('parseNumericIdentifier', new \PhpParser\Node\Scalar\String_('0'))
        );
    }

    public function testWindowsIntegerLiteralSuffixForInternalConstants(): void
    {
        $this->setPropertyValue('platform', new Windows());

        $this->assertSame(PHP_INT_SIZE . 'LL', $this->compiler->getConstValue('PHP_INT_SIZE'));
        $this->assertSame('ZEND_LONG_MAX', $this->compiler->getConstValue('PHP_INT_MAX'));
        $this->assertSame('ZEND_LONG_MIN', $this->compiler->getConstValue('PHP_INT_MIN'));
    }

    public function testConstDeclarationsAreStaticallyExpanded(): void
    {
        $this->compiler->prepareFile($this->fixturePath('const_decl.php'));

        $code = $this->invokeMethod(
            'parseConstFetch',
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('AOT_COMPILE_TIME_CONST'))
        );

        $this->assertSame(CompilerBase::CONST_VAR . 'AOT_COMPILE_TIME_CONST', $code);
    }

    public function testDefineConstantsAreNotStaticallyExpanded(): void
    {
        $internalConstants = $this->getPropertyValue('internalConstants');
        $this->assertArrayHasKey('PHP_VERSION', $internalConstants);
        $this->assertArrayNotHasKey('ROOT_PATH', $internalConstants);
        $this->assertArrayNotHasKey('TYPEPHP_ROOT_PATH', $internalConstants);

        foreach (['ROOT_PATH', 'TYPEPHP_ROOT_PATH'] as $constantName) {
            $code = $this->invokeMethod(
                'parseConstFetch',
                new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name($constantName))
            );

            $this->assertStringStartsWith('php::constant(', $code);
            $this->assertStringNotContainsString(TYPEPHP_ROOT_PATH, $code);
        }
    }

    public function testUnqualifiedRuntimeConstantUsesNamespaceFallback(): void
    {
        $this->setPropertyValue('namespace', 'App\\Worker');
        $this->setPropertyValue('noLiteralStrings', true);

        $code = $this->invokeMethod(
            'parseConstFetch',
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('COMPOSER_PATH'))
        );

        $this->assertStringNotContainsString('php::fn::defined(', $code);
        $this->assertStringContainsString('App\\\\Worker\\\\COMPOSER_PATH', $code);
        $this->assertStringEndsWith(', php::ConstantLookup::UnqualifiedInNamespace)', $code);
        $this->assertStringNotContainsString('php::constant(nullptr,', $code);
    }

    public function testQualifiedRuntimeConstantDoesNotUseGlobalFallback(): void
    {
        $this->setPropertyValue('namespace', 'App\\Worker');
        $this->setPropertyValue('noLiteralStrings', true);

        $code = $this->invokeMethod(
            'parseConstFetch',
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('Config\\PATH'))
        );

        $this->assertStringNotContainsString('php::fn::defined(', $code);
        $this->assertStringContainsString('App\\\\Worker\\\\Config\\\\PATH', $code);
    }

    public function testDynamicallyDefinedConstantsAreNotInternalConstants(): void
    {
        $name = 'AOT_USER_DEFINE_' . str_replace('.', '_', uniqid('', true));
        define($name, 'runtime-value');

        $compiler = CompilerTest::create($this->testDir);
        $ref = new \ReflectionClass($compiler);
        $prop = $ref->getProperty('internalConstants');
        $prop->setAccessible(true);

        $this->assertArrayNotHasKey($name, $prop->getValue($compiler));
    }

    // ========================================================================
    // genAnonClassName
    // ========================================================================

    public function testGenAnonClassName(): void
    {
        $name1 = $this->compiler->genAnonClassName();
        $name2 = $this->compiler->genAnonClassName();

        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name1);
        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name2);
        $this->assertNotEquals($name1, $name2);
    }

    // ========================================================================
    // getIncludeDir / getBuildDir
    // ========================================================================

    public function testGetBuildDir(): void
    {
        $buildDir = $this->compiler->getBuildDir();
        $this->assertStringEndsWith('/build', $buildDir);
        $this->assertStringStartsWith($this->testDir, $buildDir);
    }

    public function testFormatCodeDisabledByDefault(): void
    {
        $this->assertFalse($this->getPropertyValue('formatCode'));
    }

    public function testGetIncludeDir(): void
    {
        $includeDir = $this->compiler->getIncludeDir();
        $buildDir = $this->compiler->getBuildDir();
        $this->assertEquals($buildDir . '/include', $includeDir);
    }

    public function testParseProjectYamlLoadsDocumentedCompilerOptions(): void
    {
        $binDir = $this->testDir . '/bin';
        $formatLog = $this->testDir . '/format.log';
        $this->createFakeClangFormat($binDir, $formatLog);
        putenv('PATH=' . $binDir . ':' . ($this->originalPath ?: ''));

        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
optimize: 2
job: 8
debug: true
profile: true
no-progress: true
no-console: true
no-literal-strings: true
sanitize: address
target-platform: aarch64-linux-gnu
build-dir: /tmp/project-build
include-paths:
  - /opt/mylib/include
  - ../shared/headers
defines:
  - ENABLE_LOGGING=1
  - DEBUG_LEVEL=3
lto: true
format: true
link-libs:
  - curl
  - ssl
link-paths:
  - /usr/local/lib
  - /opt/custom/lib
extension-dependencies:
  - pdo_mysql
  - curl
  - curl
YAML);

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(2, $this->getPropertyValue('optimizeLevel'));
        $this->assertSame(8, $this->getPropertyValue('maxJob'));
        $this->assertTrue($this->getPropertyValue('debug'));
        $this->assertTrue($this->getPropertyValue('enableProfiler'));
        $this->assertTrue($this->getPropertyValue('noProgress'));
        $this->assertTrue($this->getPropertyValue('noConsole'));
        $this->assertTrue($this->getPropertyValue('noLiteralStrings'));
        $this->assertSame('address', $this->getPropertyValue('sanitize'));
        $this->assertSame('aarch64-linux-gnu', $this->getPropertyValue('targetPlatform'));
        $this->assertSame(['/opt/mylib/include', dirname($projectFile) . '/../shared/headers'], $this->compiler->getUserIncludePaths());
        $this->assertSame(['ENABLE_LOGGING=1', 'DEBUG_LEVEL=3'], $this->compiler->getUserDefines());
        $this->assertTrue($this->compiler->isLtoEnabled());
        $this->assertSame(['curl', 'ssl'], $this->compiler->getLinkLibs());
        $this->assertSame(['/usr/local/lib', '/opt/custom/lib'], $this->compiler->getLinkPaths());
        $this->assertSame(['pdo_mysql', 'curl'], $this->compiler->getExtensionDependencies());
        $this->assertSame('/tmp/project-build', $this->compiler->getBuildDir());
        $this->assertTrue($this->getPropertyValue('formatCode'));
    }

    public function testParseProjectYamlRejectsInvalidExtensionDependencies(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
extension-dependencies: curl
YAML);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('`extension-dependencies` must be array');
        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testParseProjectYamlSupportsExtDepsAlias(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
ext-deps:
  - pdo_mysql
  - curl
  - curl
YAML);

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(['pdo_mysql', 'curl'], $this->compiler->getExtensionDependencies());
    }

    public function testParseProjectYamlRejectsBothExtensionDependencyNames(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
extension-dependencies:
  - pdo_mysql
ext-deps:
  - curl
YAML);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            '`extension-dependencies` and `ext-deps` cannot be used together',
        );
        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testExtensionDependenciesAreWrittenToZendModuleEntry(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
ext-deps:
  - pdo_mysql
  - curl
YAML);
        $files = $this->invokeMethod('parseProjectYaml', $projectFile);
        $this->compiler->addFiles($files);
        foreach ($files as $file) {
            $this->compiler->prepareFile($file);
            $this->compiler->convertFile($file);
        }

        $extension = file_get_contents($this->compiler->genExtension());

        $this->assertStringContainsString(
            "static const zend_module_dep typephp_app_module_deps[] = {\n"
            . "    ZEND_MOD_REQUIRED(\"pdo_mysql\")\n"
            . "    ZEND_MOD_REQUIRED(\"curl\")\n"
            . "    ZEND_MOD_END\n"
            . '};',
            $extension,
        );
        $this->assertStringContainsString(
            "zend_module_entry typephp_app_module_entry = {\n"
            . "    STANDARD_MODULE_HEADER_EX,\n"
            . "    nullptr,\n"
            . "    typephp_app_module_deps,",
            $extension,
        );
    }

    public function testParseProjectYamlSupportsCustomFilenameAndRelativeBuildDir(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
build-dir: build/output
YAML, 'myproject.yml', 'nested/config');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(
            realpath($this->testDir . '/nested/config/build/output'),
            $this->compiler->getBuildDir()
        );
    }

    public function testGetFilesAcceptsYamlExtension(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
YAML, 'project.yaml');

        $files = $this->compiler->getFiles($projectFile);

        $this->assertSame([realpath(dirname($projectFile) . '/main.php')], $files);
    }

    public function testParseProjectYamlSupportsCliStyleModeAndOutputAliases(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
mode: ext
output: out/custom-ext
dry: true
YAML, 'custom-name.yml', 'yaml-alias');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $this->getPropertyValue('buildMode'));
        $this->assertTrue($this->getPropertyValue('dryRun'));
        $this->assertSame('custom_ext', $this->getPropertyValue('targetName'));
        $this->assertSame(dirname($projectFile) . '/out', $this->getPropertyValue('outputDir'));
    }

    public function testParseProjectYamlNameDoesNotSetOutputDirectory(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
name: tetris
sources:
  - main.php
YAML, 'myproject.yml', 'examples/tetris-sdl');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame('tetris', $this->getPropertyValue('targetName'));
        $this->assertSame('', $this->getPropertyValue('outputDir'));
        $this->assertSame('tetris', $this->invokeMethod('getTargetFileName'));
    }

    public function testLibModeUsesUnixLibraryPrefixUnlessOutputIsExplicit(): void
    {
        $this->compiler->setBuildMode(CompilerBase::BUILD_MODE_LIB);
        $this->compiler->setTargetName('demo');
        $this->assertSame('libdemo.so', $this->invokeMethod('getTargetFileName'));

        $this->compiler->setOutputPath('demo.so');
        $this->assertSame('demo.so', $this->invokeMethod('getTargetFileName'));
    }

    public function testExtensionModeDoesNotUseUnixLibraryPrefix(): void
    {
        $this->compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $this->compiler->setTargetName('demo');

        $this->assertSame('demo.so', $this->invokeMethod('getTargetFileName'));
    }

    public function testGeneratedZendModuleAlwaysUsesTypePhpPrefix(): void
    {
        $this->compiler->setTargetName('demo');
        $this->assertSame('typephp_demo', $this->compiler->getModuleName());

        $this->compiler->setTargetName('123');
        $this->assertSame('typephp_123', $this->compiler->getModuleName());
    }

    public function testParseProjectYamlResolvesRelativePathOptionsAgainstYamlDirectory(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
include-paths:
  - includes
link-paths:
  - libs
output: bin/my-app
YAML, 'myproject.yml', 'nested/config');

        $this->invokeMethod('parseProjectYaml', $projectFile);

        $projectDir = dirname($projectFile);
        $this->assertSame([$projectDir . '/includes'], $this->compiler->getUserIncludePaths());
        $this->assertSame([$projectDir . '/libs'], $this->compiler->getLinkPaths());
        $this->assertSame($projectDir . '/bin', $this->getPropertyValue('outputDir'));
        $this->assertSame('my_app', $this->getPropertyValue('targetName'));
    }

    public function testCliOutputOverridesYamlOutputOnlyWhenCommandLineArgumentsAreApplied(): void
    {
        global $argv;
        $argv = ['compiler.php', '--output', 'cli/out-file'];
        $compiler = CompilerTest::create($this->testDir);
        $ref = new \ReflectionClass($compiler);
        $parseMethod = $ref->getMethod('parseProjectYaml');
        $parseMethod->setAccessible(true);
        $applyMethod = $ref->getMethod('applyCommandLineArguments');
        $applyMethod->setAccessible(true);
        $targetProp = $ref->getProperty('targetName');
        $targetProp->setAccessible(true);
        $outputProp = $ref->getProperty('outputDir');
        $outputProp->setAccessible(true);

        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
output: yaml/out-file
YAML, 'myproject.yml', 'cli-output');

        $parseMethod->invoke($compiler, $projectFile);
        $this->assertSame(dirname($projectFile) . '/yaml', $outputProp->getValue($compiler));
        $this->assertSame('out_file', $targetProp->getValue($compiler));

        $applyMethod->invoke($compiler);
        $this->assertSame('cli', $outputProp->getValue($compiler));
        $this->assertSame('out_file', $targetProp->getValue($compiler));
    }

    public function testApplyCommandLineArgumentsDoesNotClearYamlRepeatableOptionsWhenCliAbsent(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
include-paths:
  - /yaml/include
defines:
  - YAML_DEFINE=1
lto: true
link-libs:
  - yamlssl
link-paths:
  - /yaml/lib
YAML);

        $this->invokeMethod('parseProjectYaml', $projectFile);
        $this->invokeMethod('applyCommandLineArguments');

        $this->assertSame(['/yaml/include'], $this->compiler->getUserIncludePaths());
        $this->assertSame(['YAML_DEFINE=1'], $this->compiler->getUserDefines());
        $this->assertTrue($this->compiler->isLtoEnabled());
        $this->assertSame(['yamlssl'], $this->compiler->getLinkLibs());
        $this->assertSame(['/yaml/lib'], $this->compiler->getLinkPaths());
    }

    public function testParseProjectYamlFiltersIgnoredFilesFromReturnedSources(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - .
ignore:
  - ignored.php
  - skipped
YAML);
        $projectDir = dirname($projectFile);
        mkdir($projectDir . '/skipped', 0777, true);
        copy($this->fixturePath('ignored.php'), $projectDir . '/ignored.php');
        copy($this->fixturePath('skipped_nested.php'), $projectDir . '/skipped/nested.php');
        copy($this->fixturePath('kept.php'), $projectDir . '/kept.php');

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertContains(realpath($projectDir . '/main.php'), $files);
        $this->assertContains(realpath($projectDir . '/kept.php'), $files);
        $this->assertNotContains(realpath($projectDir . '/ignored.php'), $files);
        $this->assertNotContains(realpath($projectDir . '/skipped/nested.php'), $files);
    }

    public function testParseProjectYamlAllowsMissingIgnorePaths(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - main.php
ignore:
  - optional-missing.php
  - optional-missing-directory
YAML);

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame([realpath(dirname($projectFile) . '/main.php')], $files);
        $this->assertSame([], $this->getPropertyValue('ignorePaths'));
    }

    public function testParseProjectYamlStillRejectsMissingSources(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - required-missing.php
YAML);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Source file not exists: `required-missing.php`');

        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testParseProjectYamlSupportsConditionalSourcesByPhpVersion(): void
    {
        $futureVersion = PHP_VERSION_ID + 10000;
        $projectFile = $this->createProjectFile(<<<YAML
sources:
  - main.php
  - path: php-current.php
    if: PHP_VERSION_ID >= 80000
  - path: php-id-reversed.php
    if: 80000 <= PHP_VERSION_ID
  - path: missing-future.php
    if: PHP_VERSION_ID >= {$futureVersion}
YAML);
        $projectDir = dirname($projectFile);
        file_put_contents($projectDir . '/php-current.php', "<?php\nfunction php_current_source(): void {}\n");
        file_put_contents($projectDir . '/php-id-reversed.php', "<?php\nfunction php_id_reversed_source(): void {}\n");

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertContains(realpath($projectDir . '/main.php'), $files);
        $this->assertContains(realpath($projectDir . '/php-current.php'), $files);
        $this->assertContains(realpath($projectDir . '/php-id-reversed.php'), $files);
        $this->assertNotContains($projectDir . '/missing-future.php', $files);
    }

    public function testParseProjectYamlConditionalSourceAllowsIfBeforePath(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - if: PHP_VERSION_ID >= 80000
    path: if-before-path.php
YAML);
        $projectDir = dirname($projectFile);
        file_put_contents($projectDir . '/if-before-path.php', "<?php\nfunction if_before_path_source(): void {}\n");

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame([realpath($projectDir . '/if-before-path.php')], $files);
    }

    public function testParseProjectYamlSupportsCompositeConditionalSources(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - path: composite.php
    if: PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 90000
YAML);
        $projectDir = dirname($projectFile);
        file_put_contents($projectDir . '/composite.php', "<?php\nfunction composite_source(): void {}\n");

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame([realpath($projectDir . '/composite.php')], $files);
    }

    public function testParseProjectYamlSupportsPhpVersionStringConditionalSources(): void
    {
        $major = PHP_MAJOR_VERSION;
        $nextMajor = PHP_MAJOR_VERSION + 1;
        $projectFile = $this->createProjectFile(<<<YAML
sources:
  - path: php-version-current.php
    if: PHP_VERSION >= "{$major}.0.0"
  - path: php-version-reversed.php
    if: '"{$major}.0.0" <= PHP_VERSION'
  - path: missing-next-major.php
    if: PHP_VERSION >= "{$nextMajor}.0.0"
YAML);
        $projectDir = dirname($projectFile);
        file_put_contents($projectDir . '/php-version-current.php', "<?php\nfunction php_version_current_source(): void {}\n");
        file_put_contents($projectDir . '/php-version-reversed.php', "<?php\nfunction php_version_reversed_source(): void {}\n");

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame(
            [
                realpath($projectDir . '/php-version-current.php'),
                realpath($projectDir . '/php-version-reversed.php'),
            ],
            $files
        );
    }

    public function testProjectPhpVersionControlsConditionalSources(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
php-version: '8.4'
sources:
  - path: php84.php
    if: PHP_VERSION_ID == 80400
  - path: php85.php
    if: PHP_VERSION >= '8.5'
YAML);
        $projectDir = dirname($projectFile);
        file_put_contents($projectDir . '/php84.php', "<?php\nfunction php84_source(): void {}\n");
        file_put_contents($projectDir . '/php85.php', "<?php\nfunction php85_source(): void {}\n");

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        $this->assertSame('8.4.0', $this->compiler->getPhpVersion());
        $this->assertSame([realpath($projectDir . '/php84.php')], $files);
    }

    public function testParseProjectYamlSupportsAllVersionCompareOperators(): void
    {
        $current = $this->compiler->getPhpVersion();
        $projectFile = $this->createProjectFile(<<<YAML
sources:
  - path: op-lt.php
    if: PHP_VERSION lt "{$current}.1"
  - path: op-le.php
    if: PHP_VERSION le "{$current}"
  - path: op-gt.php
    if: PHP_VERSION gt "0.0.0"
  - path: op-ge.php
    if: PHP_VERSION ge "{$current}"
  - path: op-eq.php
    if: PHP_VERSION eq "{$current}"
  - path: op-ne.php
    if: PHP_VERSION ne "0.0.0"
  - path: op-symbol-eq.php
    if: PHP_VERSION = "{$current}"
  - path: op-symbol-ne.php
    if: PHP_VERSION <> "0.0.0"
  - path: op-id-alias.php
    if: PHP_VERSION_ID GE 80000
YAML);
        $projectDir = dirname($projectFile);
        foreach (['lt', 'le', 'gt', 'ge', 'eq', 'ne', 'symbol-eq', 'symbol-ne', 'id-alias'] as $name) {
            file_put_contents($projectDir . '/op-' . $name . '.php', "<?php\nfunction op_" . str_replace('-', '_', $name) . "(): void {}\n");
        }

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        foreach (['lt', 'le', 'gt', 'ge', 'eq', 'ne', 'symbol-eq', 'symbol-ne', 'id-alias'] as $name) {
            $this->assertContains(realpath($projectDir . '/op-' . $name . '.php'), $files);
        }
    }

    public function testParseProjectYamlSupportsPhpOsFamilyConditionalSources(): void
    {
        $osFamily = PHP_OS_FAMILY;
        $otherFamily = $osFamily === 'Windows' ? 'Linux' : 'Windows';
        $projectFile = $this->createProjectFile(<<<YAML
sources:
  - path: os-current.php
    if: PHP_OS_FAMILY == "{$osFamily}"
  - path: os-not-windows.php
    if: PHP_OS_FAMILY != "{$otherFamily}"
  - path: os-reversed.php
    if: '"{$osFamily}" == PHP_OS_FAMILY'
  - path: os-composite.php
    if: PHP_OS_FAMILY == "{$osFamily}" && PHP_VERSION_ID >= 80000
  - path: os-or.php
    if: PHP_OS_FAMILY == "{$otherFamily}" || PHP_OS_FAMILY == "{$osFamily}"
  - path: missing-os.php
    if: PHP_OS_FAMILY == "{$otherFamily}" && PHP_OS_FAMILY != "{$osFamily}"
YAML);
        $projectDir = dirname($projectFile);
        foreach (['current', 'not-windows', 'reversed', 'composite', 'or'] as $name) {
            file_put_contents($projectDir . '/os-' . $name . '.php', "<?php\nfunction os_" . str_replace('-', '_', $name) . "(): void {}\n");
        }

        $files = $this->invokeMethod('parseProjectYaml', $projectFile);

        foreach (['current', 'not-windows', 'reversed', 'composite', 'or'] as $name) {
            $this->assertContains(realpath($projectDir . '/os-' . $name . '.php'), $files);
        }
        $this->assertNotContains($projectDir . '/missing-os.php', $files);
    }

    public function testParseProjectYamlRejectsUnsupportedPhpOsFamilyOperator(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - path: main.php
    if: PHP_OS_FAMILY >= "Linux"
YAML);

        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Unsupported source condition');

        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testParseProjectYamlRejectsBarePhpVersionCondition(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - path: main.php
    if: PHP_VERSION
YAML);

        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Unsupported source condition');

        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testParseProjectYamlRejectsUnsafeConditionalSourceExpression(): void
    {
        $projectFile = $this->createProjectFile(<<<'YAML'
sources:
  - path: main.php
    if: PHP_VERSION_ID >= getenv("MIN_PHP")
YAML);

        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Unsupported source condition');

        $this->invokeMethod('parseProjectYaml', $projectFile);
    }

    public function testCCompileCommandOptionsKeepCommonUserConfiguration(): void
    {
        $this->setPropertyValue('userIncludePaths', ['/user/include']);
        $this->setPropertyValue('userDefines', ['FEATURE_X=1']);
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_EXT);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('enableLto', true);
        $this->setPropertyValue('sanitize', 'address');
        $this->setPropertyValue('targetPlatform', 'aarch64-linux-gnu');
        $this->setPropertyValue('march', 'native');

        $options = $this->invokeMethod('getCCompileCommandOptions');

        $this->assertContains('/user/include', $options['include_paths']);
        $this->assertSame(['FEATURE_X=1'], $options['user_defines']);
        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $options['build_mode']);
        $this->assertTrue($options['enable_profiler']);
        $this->assertTrue($options['lto']);
        $this->assertSame('address', $options['sanitize']);
        $this->assertSame('aarch64-linux-gnu', $options['target_platform']);
        $this->assertSame('native', $options['march']);
    }

    public function testNativeCompileCommandOptionsKeepCommonUserConfiguration(): void
    {
        $this->setPropertyValue('userIncludePaths', ['/native/include']);
        $this->setPropertyValue('userDefines', ['NATIVE_FEATURE=1']);
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_EXT);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('enableLto', true);

        $options = $this->invokeMethod('getNativeCompileCommandOptions', 'objective-c');

        $this->assertContains('/native/include', $options['include_paths']);
        $this->assertSame(['NATIVE_FEATURE=1'], $options['user_defines']);
        $this->assertSame(CompilerBase::BUILD_MODE_EXT, $options['build_mode']);
        $this->assertTrue($options['enable_profiler']);
        $this->assertTrue($options['lto']);
        $this->assertArrayNotHasKey('cpp_std', $options);
        $this->assertArrayNotHasKey('cxxflags', $options);
    }

    public function testEmbeddedCompileOptionsKeepProjectNameOutOfCommonPchOptions(): void
    {
        $this->compiler->setTargetName('module_accessor');

        foreach ([CompilerBase::BUILD_MODE_BIN, CompilerBase::BUILD_MODE_LIB] as $mode) {
            $this->setPropertyValue('buildMode', $mode);
            $options = $this->invokeMethod('getCommonCompileCommandOptions');

            $this->assertNotContains('TYPEPHP_PROJECT_NAME=module_accessor', $options['user_defines'], $mode);
            $this->assertNotContains('TYPEPHP_RUNTIME_EXPORTS=1', $options['user_defines'], $mode);
            $this->assertSame(
                [],
                array_values(array_filter(
                    $options['user_defines'],
                    static fn (string $define): bool => str_starts_with($define, 'TYPEPHP_EMBED_GET_MODULE='),
                )),
                $mode,
            );
        }
    }

    public function testProjectRuntimeEntryHasTargetDefineWithoutPchOrObjectCache(): void
    {
        $this->compiler->setTargetName('module_accessor');
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_BIN);
        $this->setPropertyValue('precompiledHeader', [
            'header' => '/tmp/typephp_pch.hpp',
            'artifact' => '/tmp/typephp_pch.hpp.gch',
        ]);

        $phpxDir = $this->invokeMethod('getPhpxDir');
        $entry = $phpxDir . '/src/misc/typephp_main.cc';
        $options = $this->invokeMethod('getSourceCompileCommandOptions', $entry, null);

        $this->assertContains('TYPEPHP_PROJECT_NAME=module_accessor', $options['user_defines']);
        $this->assertContains('TYPEPHP_RUNTIME_EXPORTS=1', $options['user_defines']);
        $this->assertArrayNotHasKey('forced_include', $options->toArray());
        $this->assertArrayNotHasKey('precompiled_header', $options->toArray());
        $this->assertFalse($this->compiler->hasMiscObjectFileCache($entry));
        $this->assertStringContainsString(
            DIRECTORY_SEPARATOR . 'phpx-misc' . DIRECTORY_SEPARATOR . 'module_accessor' . DIRECTORY_SEPARATOR,
            $this->compiler->getObjectFile($entry),
        );
    }

    public function testProjectIndependentMiscObjectsUseSharedCacheScope(): void
    {
        $phpxDir = $this->invokeMethod('getPhpxDir');
        foreach (['typephp_runtime.cc', 'php_cli_process_title.c', 'ps_title.c'] as $sourceName) {
            $object = $this->compiler->getObjectFile($phpxDir . '/src/misc/' . $sourceName);
            $this->assertStringContainsString(
                DIRECTORY_SEPARATOR . 'phpx-misc' . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR,
                $object,
                $sourceName,
            );
        }
    }

    public function testProjectIndependentMiscObjectCacheSurvivesTargetNameChange(): void
    {
        $phpxDir = $this->invokeMethod('getPhpxDir');
        $source = $phpxDir . '/src/misc/ps_title.c';

        $this->compiler->setTargetName('first_project');
        $object = $this->compiler->getObjectFile($source);
        file_put_contents($object, 'object');
        touch($object, time() + 10);
        $this->invokeMethod('writeMiscObjectCacheMetadata', $source, $object);

        $this->compiler->setTargetName('second_project');
        $this->assertSame($object, $this->compiler->getObjectFile($source));
        $this->assertTrue($this->compiler->hasMiscObjectFileCache($source));
    }

    public function testMacosNativeBuildOptionsIncludeHomebrewSearchPaths(): void
    {
        $this->setPropertyValue('platform', new Macos());

        $includePaths = $this->invokeMethod('getIncludePaths');
        $this->assertContains('/opt/homebrew/include', $includePaths);
        $this->assertContains('/usr/local/include', $includePaths);

        $libraryPaths = $this->invokeMethod('getLibraryPaths');
        $this->assertContains('/opt/homebrew/lib', $libraryPaths);
        $this->assertContains('/usr/local/lib', $libraryPaths);
    }

    public function testRequestShutdownClearsRuntimeMapsInEveryBuildMode(): void
    {
        global $translator;
        foreach ([CompilerBase::BUILD_MODE_BIN, CompilerBase::BUILD_MODE_LIB, CompilerBase::BUILD_MODE_EXT] as $mode) {
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $compiler->setBuildMode($mode);

            $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/extension_clean_maps.php';
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
            $code = file_get_contents($compiler->genExtension());

            $this->assertStringContainsString('#include <cstring>', $code, $mode);
            $this->assertStringContainsString(
                'std::memset(php_func_map, 0, sizeof(php_func_map));',
                $code,
                $mode,
            );
            $this->assertStringContainsString(
                'std::memset(php_class_map, 0, sizeof(php_class_map));',
                $code,
                $mode,
            );
            $this->assertStringNotContainsString('php_property_map', $code, $mode);
            $this->assertStringNotContainsString('func_map = {}', $code, $mode);
            $this->assertStringNotContainsString('class_map = {}', $code, $mode);
            $this->assertStringNotContainsString('property_map = {}', $code, $mode);
        }
    }

    public function testGeneratedRuntimeSymbolsUseProjectNamespace(): void
    {
        global $translator;
        $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/extension_clean_maps.php';

        foreach ([
            CompilerBase::BUILD_MODE_EXT => 'isolated_ext',
            CompilerBase::BUILD_MODE_BIN => 'isolated_bin',
            CompilerBase::BUILD_MODE_LIB => 'isolated_lib',
        ] as $mode => $target) {
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $compiler->setBuildMode($mode);
            $compiler->setTargetName($target);
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);

            $dataFile = $this->testDir . '/' . $target . '_data_decl.h';
            $compiler->genDataDeclarations($dataFile);
            $data = file_get_contents($dataFile);
            $extension = file_get_contents($compiler->genExtension());
            $namespace = 'typephp_' . $target;

            $this->assertStringContainsString('namespace ' . $namespace . ' {', $data, $mode);
            $this->assertStringContainsString('using namespace ' . $namespace . ';', $data, $mode);
            $this->assertStringContainsString('zend_class_entry *get_class(', $data, $mode);
            $this->assertStringContainsString('namespace ' . $namespace . ' {', $extension, $mode);
            $this->assertStringContainsString('zend_class_entry *get_class(', $extension, $mode);
            $this->assertStringContainsString('static void module_init()', $extension, $mode);
            $this->assertStringContainsString('static void module_clean()', $extension, $mode);
            $this->assertStringContainsString('typephp_register_fiber_generator_class();', $extension, $mode);
            $this->assertStringContainsString('typephp_unregister_fiber_generator_class();', $extension, $mode);
            $this->assertStringNotContainsString('php_app_init', $extension, $mode);
            $this->assertStringNotContainsString('php_app_clean', $extension, $mode);

            if ($mode === CompilerBase::BUILD_MODE_BIN || $mode === CompilerBase::BUILD_MODE_LIB) {
                $this->assertStringNotContainsString('zend_module_entry *php_embed_get_module()', $extension);
                $this->assertStringContainsString('#include <typephp_runtime.h>', $extension);
                $this->assertStringContainsString('TYPEPHP_EMBED_GET_MODULE_FUNCTION(' . $target . ')', $extension);
                $this->assertStringContainsString(
                    'return &' . $namespace . '::' . $namespace . '_module_entry;',
                    $extension,
                );
            } else {
                $this->assertStringNotContainsString('php_embed_get_module', $extension, $mode);
            }
        }
    }

    public function testFunctionPointerCacheRejectsClassMethodNames(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Class methods must be resolved through getMethodPtr()');

        $this->invokeMethod('getFuncPtr', 'RuntimeClass::method');
    }

    public function testPersistentSymbolCachesAreLazyAndZtsSafe(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);

        $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/persistent_symbol_cache.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        $dataFile = $this->testDir . '/persistent_symbol_cache_data_decl.h';
        $compiler->genDataDeclarations($dataFile);
        $data = file_get_contents($dataFile);
        $extension = file_get_contents($compiler->genExtension());

        $this->assertStringNotContainsString('php_persistent_class_map', $data);
        $this->assertStringNotContainsString('php_persistent_func_map', $data);
        $this->assertStringNotContainsString('php_persistent_property_map', $data);
        $this->assertStringContainsString(
            'static php::PersistentCacheSlot<zend_class_entry *> php_persistent_class_map',
            $extension,
        );
        $this->assertStringContainsString(
            'static php::PersistentCacheSlot<zend_function *> php_persistent_func_map',
            $extension,
        );
        $this->assertStringContainsString(
            'static php::PersistentCacheSlot<uint32_t> php_persistent_property_map',
            $extension,
        );
        $this->assertStringContainsString('enum class RequestClassId : uint32_t {};', $data);
        $this->assertStringContainsString('enum class PersistentClassId : uint32_t {};', $data);
        $this->assertStringContainsString('enum class RequestFuncId : uint32_t {};', $data);
        $this->assertStringContainsString('enum class PersistentFuncId : uint32_t {};', $data);
        $this->assertStringContainsString('enum class PersistentPropertyId : uint32_t {};', $data);
        $this->assertStringContainsString('get_persistent_class', $extension);
        $this->assertStringContainsString('php::getPersistentCache(php_persistent_class_map[index]', $extension);
        $this->assertStringContainsString('for (auto &slot : php_persistent_class_map)', $extension);
        $this->assertStringContainsString('php::resetPersistentCache(slot);', $extension);
        $this->assertStringNotContainsString('#ifdef ZTS', $data);
        $this->assertStringNotContainsString('compare_exchange_strong', $extension);
        $this->assertStringNotContainsString('// internal symbol caches', $extension);
        $this->assertStringNotContainsString('php_find_internal_function', $extension);
        $this->assertStringNotContainsString('php_find_internal_method', $extension);
        $this->assertStringNotContainsString('php_internal_', $extension);
    }

    public function testArrayableKeywordConversionCallsGeneratedMethodAtRuntime(): void
    {
        global $translator;
        $translator = $this->compiler;

        $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/arrayable.php';
        $this->compiler->addFiles([$testFile]);
        $this->compiler->prepareFile($testFile);
        $cppFile = $this->compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        $this->assertStringContainsString('data = php_arrayableuser__toarray(user);', $cpp);
        $this->assertStringNotContainsString('php::toArray(', $cpp);
        $this->assertStringContainsString('php::Array php_arrayableuser__toarray(', $cpp);
        $this->assertStringContainsString('php::Str php_arrayableuser____tostring(', $cpp);
    }

    public function testLibraryFunctionHeaderExportsDefaultValueHelpersWithoutLiteralStorage(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->compiler->setTargetName('prime2');

        $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/default_argument_abi.stub.php';
        $this->compiler->addFiles([$testFile]);
        $this->compiler->prepareFile($testFile);
        $this->compiler->convertFile($testFile);

        $headerFile = $this->testDir . '/php_abi_defaults_func_decl.h';
        $this->compiler->genFunctionDeclarations($headerFile);
        $header = file_get_contents($headerFile);

        $this->assertStringContainsString('#pragma once', $header);
        $this->assertStringContainsString('#include <typephp_helper.h>', $header);
        $this->assertStringContainsString('# define TYPEPHP_PRIME2_API TYPEPHP_SYMBOL_EXPORT', $header);
        $this->assertStringContainsString('# define TYPEPHP_PRIME2_API TYPEPHP_SYMBOL_IMPORT', $header);
        $this->assertStringNotContainsString('__declspec(', $header);
        $this->assertStringNotContainsString('__attribute__(', $header);
        $this->assertStringNotContainsString('defined(_WIN32)', $header);
        $this->assertStringNotContainsString('defined(__GNUC__)', $header);
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API php::Str php_exported_defaults_arg_0_default_value();',
            $header
        );
        $this->assertStringContainsString(
            'php::Str text = php_exported_defaults_arg_0_default_value()',
            $header
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API php::Array php_exported_variadic_arg_0_default_value();',
            $header
        );
        $this->assertStringNotContainsString('_literal_strings', $header);
        $this->assertStringNotContainsString('_const_var_', $header);
        $this->assertStringNotContainsString('php_func_map', $header);
        $this->assertStringNotContainsString('php_class_map', $header);

        $dataHeaderFile = $this->testDir . '/php_abi_defaults_data_decl.h';
        $this->compiler->genDataDeclarations($dataHeaderFile);
        $dataHeader = file_get_contents($dataHeaderFile);
        $this->assertStringContainsString('extern php::Var _const_var_EXPORTED_ABI_INT;', $dataHeader);
        $this->assertStringContainsString('extern php::Var _const_var_EXPORTED_ABI_STRING;', $dataHeader);
        $this->assertStringContainsString('extern php::Var _const_var_EXPORTED_ABI_ARRAY;', $dataHeader);
        $this->assertStringContainsString(
            'ZEND_ATTRIBUTE_CONST php::Str &get_str(uint32_t index);',
            $dataHeader,
        );
        $this->assertStringNotContainsString('_literal_strings', $dataHeader);
        $this->assertStringNotContainsString('php_func_map', $dataHeader);
        $this->assertStringNotContainsString('php_class_map', $dataHeader);

        $extensionFile = $this->compiler->genExtension();
        $extension = file_get_contents($extensionFile);
        $this->assertStringContainsString('php::Str php_exported_defaults_arg_0_default_value() {', $extension);
        $this->assertStringContainsString('static php::Str _literal_strings[]', $extension);
        $this->assertStringContainsString('return get_str(', $extension);
        $this->assertStringContainsString('php::Array php_exported_variadic_arg_0_default_value() {', $extension);
    }

    public function testExternalImportStubFunctionsAreAlwaysImported(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->compiler->setTargetName('prime2');

        $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/prime2.stub.php';
        $this->compiler->addFiles([$testFile]);
        $this->compiler->prepareFile($testFile);
        $this->compiler->convertFile($testFile);

        $headerFile = $this->testDir . '/php_prime2_func_decl.h';
        $this->compiler->genFunctionDeclarations($headerFile);
        $header = file_get_contents($headerFile);

        $this->assertStringContainsString('#define TYPEPHP_PRIME2_IMPORT TYPEPHP_SYMBOL_IMPORT', $header);
        $this->assertStringContainsString('# define TYPEPHP_PRIME2_API TYPEPHP_SYMBOL_EXPORT', $header);
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Array php_exported_defaults(',
            $header
        );
        $this->assertSame(['prime2'], $this->getPropertyValue('linkLibs'));
        $this->assertSame('', $this->invokeMethod('genDefaultArgumentHelperDefinitions'));

        $options = $this->invokeMethod('getCompileCommandOptions');
        $this->assertContains('TYPEPHP_PRIME2_EXPORTS=1', $options['user_defines']);
    }

    public function testLibraryImportStubCombinesPhpFunctionsClassesAndNativeStubs(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->setPropertyValue('outputDir', $this->testDir);
        $this->compiler->setTargetName('prime2');

        $files = [
            TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/library_import_php.php',
            TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/library_import_native.stub.php',
            TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/library_import_global.php',
        ];
        $this->compiler->addFiles($files);
        $cppFiles = [];
        foreach ($files as $file) {
            $this->compiler->prepareFile($file);
            $cppFiles[$file] = $this->compiler->convertFile($file);
            $this->assertStringNotContainsString(
                'NoExport',
                file_get_contents($this->compiler->getArgInfoHeaderFile($file)),
            );
            $this->assertStringNotContainsString(
                'Getter',
                file_get_contents($this->compiler->getArgInfoHeaderFile($file)),
            );
            foreach (\TypePhp\Transform\CompileTimeAttributeRegistry::names() as $attribute) {
                $needle = $attribute === 'Native' ? '#[Native' : $attribute;
                $this->assertStringNotContainsString(
                    $needle,
                    file_get_contents($this->compiler->getArgInfoHeaderFile($file)),
                );
            }
        }
        $phpCpp = file_get_contents($cppFiles[$files[0]]);
        $this->assertStringContainsString(
            'TYPEPHP_HOT_ATTRIBUTE php::Int php_libraryapi__twice(',
            $phpCpp,
        );
        $this->assertStringContainsString(
            'TYPEPHP_COLD_ATTRIBUTE php::Str php_libraryapi__counter__label(',
            $phpCpp,
        );
        $provider = $this->invokeMethod('getClass', 'LibraryApi\\InternalStringExtension');
        $this->assertSame(Type::STR, $provider->methodsForTarget);

        $stubFile = $this->compiler->genLibraryImportStub($files);
        $stub = file_get_contents($stubFile);
        $this->assertSame($this->testDir . '/prime2.stub.php', $stubFile);
        $this->assertStringContainsString('/** @import-library */', $stub);
        $this->assertStringContainsString('namespace LibraryApi;', $stub);
        $this->assertStringContainsString('class Counter', $stub);
        $this->assertStringContainsString('public const int STEP = 2;', $stub);
        $this->assertStringContainsString('public int $value = 1;', $stub);
        $this->assertStringContainsString('#[\Constructor, \Getter, \Setter, \With]', $stub);
        $this->assertStringContainsString("#[\Printer(fields: ['value', 'doubled'])]", $stub);
        $this->assertStringContainsString("#[\Arrayable(['value'])]", $stub);
        $this->assertStringContainsString('#[\NotNull, \Validate(FILTER_VALIDATE_EMAIL)]', $stub);
        $this->assertStringContainsString('#[\MustUse, \Cold]', $stub);
        $this->assertStringContainsString('#[\MustUse, \Hot]', $stub);
        $this->assertStringContainsString('#[\Override]', $stub);
        $this->assertStringContainsString('#[\Immutable]', $stub);
        $this->assertMatchesRegularExpression(
            '/function inspect\(\s*#\[\\\\Immutable\]\s*\\\\LibraryApi\\\\Counter \$counter\s*\): int/s',
            $stub,
        );
        $this->assertMatchesRegularExpression(
            '/public int \$doubled\s*\{\s*get\s*\{\s*\}\s*set\(int \$value\)\s*\{\s*\}\s*\}/s',
            $stub,
        );
        $this->assertStringContainsString('function add(int $amount = self::STEP): int', $stub);
        $this->assertMatchesRegularExpression(
            '/function label\(\s*#\[\\\\NotNull, \\\\Validate\(FILTER_VALIDATE_EMAIL\)\]\s*string \$value\s*\): string/s',
            $stub,
        );
        $this->assertStringContainsString('function twice(int $value): int', $stub);
        $this->assertStringContainsString('function native_value(string $name = \'typephp\'): string', $stub);
        $this->assertStringContainsString('class NativeCounter', $stub);
        $this->assertStringContainsString('function bump(int $amount): int', $stub);
        $this->assertStringNotContainsString('function reset()', $stub);
        $this->assertStringNotContainsString('class InternalCounter', $stub);
        $this->assertStringNotContainsString('class InternalStringExtension', $stub);
        $this->assertStringNotContainsString('function internal_twice(', $stub);
        $this->assertStringNotContainsString('function native_hidden(', $stub);
        $this->assertStringNotContainsString('function global_hidden(', $stub);
        $this->assertStringNotContainsString('NoExport', $stub);
        $this->assertStringNotContainsString('return $this->value', $stub);
        $this->assertStringNotContainsString('intdiv($value, 2)', $stub);
        $this->assertStringNotContainsString('return $value * 2', $stub);

        $libraryHeaderFile = $this->testDir . '/php_prime2_func_decl.h';
        $this->compiler->genFunctionDeclarations($libraryHeaderFile);
        $libraryHeader = file_get_contents($libraryHeaderFile);
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API TYPEPHP_HOT_ATTRIBUTE php::Int php_libraryapi__twice(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API TYPEPHP_COLD_ATTRIBUTE php::Str php_libraryapi__counter__label(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API php::Int php_libraryapi__counter__getvalue(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API php::Array php_libraryapi__counter__toarray(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_API php::Str php_libraryapi__counter____tostring(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_libraryapi__internal_twice(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_libraryapi__internalcounter__value(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_libraryapi__internalstringextension__bytelength(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern void php_libraryapi__counter__reset(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_libraryapi__native_hidden(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_global_hidden(',
            $libraryHeader,
        );
        $this->assertStringContainsString(
            'extern php::Int php_libraryapi__internal_twice_arg_0_default_value();',
            $libraryHeader,
        );

        $consumerDir = $this->testDir . '/consumer';
        mkdir($consumerDir, 0777, true);
        $consumer = CompilerTest::create($consumerDir);
        $translator = $consumer;
        $consumerRef = new \ReflectionClass($consumer);
        $buildMode = $consumerRef->getProperty('buildMode');
        $buildMode->setAccessible(true);
        $buildMode->setValue($consumer, CompilerBase::BUILD_MODE_BIN);
        $consumer->setTargetName('consumer');
        $consumer->addFiles([$stubFile]);
        $consumer->prepareFile($stubFile);
        $stubCpp = $consumer->convertFile($stubFile);

        $headerFile = $consumerDir . '/php_consumer_func_decl.h';
        $consumer->genFunctionDeclarations($headerFile);
        $header = file_get_contents($headerFile);
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Int php_libraryapi__counter__add(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Int php_libraryapi__counter__getvalue(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Array php_libraryapi__counter__toarray(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Str php_libraryapi__counter____tostring(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT TYPEPHP_HOT_ATTRIBUTE php::Int php_libraryapi__twice(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Str php_libraryapi__native_value(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Int php_libraryapi__nativecounter__bump(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT php::Int php_libraryapi__counter____typephp_property_get_646f75626c6564(',
            $header,
        );
        $this->assertStringContainsString(
            'TYPEPHP_PRIME2_IMPORT void php_libraryapi__counter____typephp_property_set_646f75626c6564(',
            $header,
        );

        $stubCppCode = file_get_contents($stubCpp);
        $this->assertStringContainsString('ZEND_METHOD(LibraryApi_Counter, add)', $stubCppCode);
        $this->assertStringContainsString('ZEND_METHOD(LibraryApi_Counter, getValue)', $stubCppCode);
        $this->assertStringContainsString('php_libraryapi__counter__getvalue(this_)', $stubCppCode);
        $this->assertStringContainsString('php_libraryapi__counter__add(this_, arg_amount)', $stubCppCode);
        $this->assertStringNotContainsString(
            'php::Int php_libraryapi__counter__add(php::Object &this_',
            $stubCppCode,
        );

        $arginfoFile = $consumer->getArgInfoHeaderFile($stubFile);
        $arginfo = file_get_contents($arginfoFile);
        $this->assertStringNotContainsString('Getter', $arginfo);
        $this->assertStringContainsString('const_STEP_value', $arginfo);
        $this->assertStringContainsString('property_value_default_value', $arginfo);
        $this->assertSame(['prime2'], $consumer->getLinkLibs());
    }

    public function testLibraryBuildRejectsExportedNativeClass(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);

        $file = $this->fixturePath('library_exported_native.php');
        $this->compiler->addFiles([$file]);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Native class `LibraryExportedNative` cannot be exported through a library stub; mark it with #[NoExport]',
        );
        $this->compiler->prepareFile($file);
    }

    public function testNoExportNativeClassIsOmittedFromLibraryStub(): void
    {
        global $translator;
        $translator = $this->compiler;
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->setPropertyValue('outputDir', $this->testDir);
        $this->compiler->setTargetName('hidden_native');

        $file = $this->fixturePath('library_hidden_native.php');
        $this->compiler->addFiles([$file]);
        $this->compiler->prepareFile($file);
        $this->compiler->convertFile($file);

        $stub = file_get_contents($this->compiler->genLibraryImportStub([$file]));
        $this->assertStringContainsString('function library_visible_value(): int', $stub);
        $this->assertStringNotContainsString('LibraryHiddenNative', $stub);
        $this->assertStringNotContainsString('#[Native]', $stub);
    }

    public function testLibraryStubGeneratorRejectsExportedNativeClassWithoutPrepare(): void
    {
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->setPropertyValue('outputDir', $this->testDir);
        $this->compiler->setTargetName('exported_native');

        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Native class `LibraryExportedNative` cannot be exported through a library stub; mark it with #[NoExport]',
        );
        $this->compiler->genLibraryImportStub([
            $this->fixturePath('library_exported_native.php'),
        ]);
    }

    public function testNoExportFollowsPhpNamespaceResolution(): void
    {
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->setPropertyValue('outputDir', $this->testDir);
        $this->compiler->setTargetName('namespace_rules');

        $stubFile = $this->compiler->genLibraryImportStub([
            TYPEPHP_ROOT_PATH . '/phpunit/code/compiler_api/library_no_export_namespace.php',
        ]);
        $stub = file_get_contents($stubFile);

        $this->assertStringNotContainsString('function imported_attribute(): int', $stub);
        $this->assertStringNotContainsString('function aliased_attribute(): int', $stub);
        $this->assertStringNotContainsString('function fully_qualified_attribute(): int', $stub);
        $this->assertStringContainsString('function relative_attribute(): int', $stub);
        $this->assertStringContainsString('function qualified_attribute(): int', $stub);
    }

    public function testLibraryCompileOptionsExportOnlyPublicApiByDefault(): void
    {
        $this->setPropertyValue('buildMode', CompilerBase::BUILD_MODE_LIB);
        $this->compiler->setTargetName('abi_defaults');

        $options = $this->invokeMethod('getCompileCommandOptions');

        $this->assertContains('TYPEPHP_ABI_DEFAULTS_EXPORTS=1', $options['user_defines']);
        $this->assertStringEndsWith('/php_abi_defaults_func_decl.h', $options['forced_include']);
        if (!$this->compiler->isWindows()) {
            $flags = $this->getPropertyValue('compilerBackend')->buildCompileOptions($options->toArray());
            $this->assertStringContainsString('-fvisibility=hidden', $flags);
            $this->assertStringContainsString('-include', $flags);
        }
    }

    public function testObjectiveCppCompileCommandOptionsKeepCppOptions(): void
    {
        $this->setPropertyValue('cxxStd', 'c++20');
        $this->setPropertyValue('cxxFlags', '-fobjc-arc');

        $options = $this->invokeMethod('getNativeCompileCommandOptions', 'objective-c++');

        $this->assertSame('c++20', $options['cpp_std']);
        $this->assertSame('-fobjc-arc', $options['cxxflags']);
    }

    public function testLinkCommandOptionsPassUserLibrariesThroughBackendFields(): void
    {
        $this->setPropertyValue('linkLibs', ['curl', 'ssl']);
        $this->setPropertyValue('linkPaths', ['/user/lib']);
        $this->setPropertyValue('enableProfiler', true);
        $this->setPropertyValue('ldflags', '-Wl,--as-needed');

        $options = $this->invokeMethod('getLinkCommandOptions');

        $this->assertContains('/user/lib', $options['library_paths']);
        $this->assertContains('profiler', $options['libraries']);
        $this->assertContains('curl', $options['libraries']);
        $this->assertContains('ssl', $options['libraries']);
        $this->assertSame('-Wl,--as-needed', $options['ldflags']);
    }

    public function testWindowsDebugCompilerPdbFollowsBuildDirectory(): void
    {
        $this->compiler->setTargetName('pdb_app');

        $pdb = $this->invokeMethod('getMsvcCompilerPdbFile');
        $expectedDirectory = $this->compiler->getBuildDir()
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'msvc';

        $this->assertSame(
            $expectedDirectory . DIRECTORY_SEPARATOR . 'pdb_app.compile.pdb',
            $pdb
        );
        $this->assertDirectoryExists($expectedDirectory);
    }

    public function testFormatCppCodeEscapesPathsWithSpaces(): void
    {
        $spaceDir = sys_get_temp_dir() . '/compiler api format ' . uniqid();
        mkdir($spaceDir, 0777, true);
        $binDir = $spaceDir . '/bin';
        $logFile = $spaceDir . '/format.log';
        $sourceFile = $spaceDir . '/hello world.cc';

        copy($this->fixturePath('hello_world.cc'), $sourceFile);
        $this->createFakeClangFormat($binDir, $logFile);
        putenv('PATH=' . $binDir . ':' . ($this->originalPath ?: ''));

        $compiler = CompilerTest::create($spaceDir);
        $ref = new \ReflectionClass($compiler);
        $formatProp = $ref->getProperty('formatCode');
        $formatProp->setAccessible(true);
        $formatProp->setValue($compiler, true);

        $method = $ref->getMethod('formatCppCode');
        $method->setAccessible(true);
        $method->invoke($compiler, $sourceFile);

        $this->assertFileExists($logFile);
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $this->assertSame($spaceDir, $lines[0]);
        $this->assertSame('-i', $lines[1]);
        $this->assertSame($sourceFile, $lines[2]);

        $this->removeDirectory($spaceDir);
    }

    // ========================================================================
    // isWindows / isLinux / isMacos
    // ========================================================================

    public function testPlatformDetectionMethods(): void
    {
        $isWin = $this->compiler->isWindows();
        $isLin = $this->compiler->isLinux();
        $isMac = $this->compiler->isMacos();

        // Exactly one platform must be true
        $sum = ($isWin ? 1 : 0) + ($isLin ? 1 : 0) + ($isMac ? 1 : 0);
        $this->assertEquals(1, $sum, 'Exactly one platform must be detected');

        // All return bool
        $this->assertIsBool($isWin);
        $this->assertIsBool($isLin);
        $this->assertIsBool($isMac);
    }

    // ========================================================================
    // isScalarInt - public method
    // ========================================================================

    public function testIsScalarIntTrue(): void
    {
        $this->assertTrue($this->compiler->isScalarInt(new \PhpParser\Node\Scalar\LNumber(42)));
    }

    public function testIsScalarIntFalse(): void
    {
        $this->assertFalse($this->compiler->isScalarInt(new \PhpParser\Node\Expr\Variable('a')));
    }

    // ========================================================================
    // getNamespacedClassName - fully qualified
    // ========================================================================

    public function testGetNamespacedClassNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('\\App\\Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use alias
    // ========================================================================

    public function testGetNamespacedClassNameWithUseAlias(): void
    {
        $this->setPropertyValue('useAliases', ['user' => 'App\\Entity\\User']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    public function testGetNamespacedClassNameWithUseAliasSubNamespace(): void
    {
        $this->setPropertyValue('useAliases', ['entity' => 'App\\Entity']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    public function testExplicitClassAliasDoesNotImportTheOriginalShortName(): void
    {
        $use = new \PhpParser\Node\Stmt\Use_([
            new \PhpParser\Node\UseItem(
                new \PhpParser\Node\Name('Vendor\\Package\\Notes'),
                new \PhpParser\Node\Identifier('NotesFactory'),
            ),
        ]);

        $this->invokeMethod('parseUse', $use);
        $this->setPropertyValue('namespace', 'Application\\Api');

        $this->assertSame([], $this->getPropertyValue('useNamespaces'));
        $this->assertSame(
            ['notesfactory' => 'Vendor\\Package\\Notes'],
            $this->getPropertyValue('useAliases'),
        );
        $this->assertSame(
            'Vendor\\Package\\Notes',
            $this->compiler->getNamespacedClassName('NOTESFACTORY'),
        );
        $this->assertSame(
            'Application\\Api\\Notes',
            $this->compiler->getNamespacedClassName('Notes'),
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use namespace (partial match)
    // ========================================================================

    public function testGetNamespacedClassNameWithUseNamespace(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // The last segment of 'App\Entity' is 'Entity', matching input 'Entity'
        $this->assertEquals(
            'App\\Entity',
            $this->compiler->getNamespacedClassName('Entity')
        );
    }

    public function testGetNamespacedClassNameWithUseNamespaceSub(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // 'Entity\User' - first part 'Entity' matches the last part of 'App\Entity'
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with current namespace
    // ========================================================================

    public function testGetNamespacedClassNameWithCurrentNamespace(): void
    {
        $this->setPropertyValue('namespace', 'App\\Service');
        // No matching alias or use namespace
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'App\\Service\\MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    public function testGetNamespacedClassNameNoNamespace(): void
    {
        $this->setPropertyValue('namespace', '');
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    // ========================================================================
    // getNamespacedClassName - alias takes priority over use namespace
    // ========================================================================

    public function testGetNamespacedClassNameAliasPriority(): void
    {
        $this->setPropertyValue('useAliases', ['user' => 'App\\Models\\User']);
        $this->setPropertyValue('useNamespaces', ['App\\Controllers']);
        // Alias should be checked first
        $this->assertEquals(
            'App\\Models\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    // ========================================================================
    // getNamespacedFuncName
    // ========================================================================

    public function testGetNamespacedFuncNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('\\App\\Lib\\helper_func')
        );
    }

    public function testGetNamespacedFuncNameWithUseFunction(): void
    {
        $this->setPropertyValue('useFunctions', [
            'helper_func' => 'App\\Lib\\helper_func',
        ]);
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    public function testGetNamespacedFuncNameNoNamespace(): void
    {
        $this->setPropertyValue('useFunctions', []);
        $this->assertEquals(
            'helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    // ========================================================================
    // getNamespacedFuncName - not in useFunctions returns bare name
    // ========================================================================

    public function testGetNamespacedFuncNameNotInUseFunctions(): void
    {
        $this->setPropertyValue('useFunctions', ['other' => 'Some\\Ns\\other']);
        $this->assertEquals(
            'my_func',
            $this->compiler->getNamespacedFuncName('my_func')
        );
    }

    // ========================================================================
    // getPhpDir
    // ========================================================================

    public function testGetPhpDir(): void
    {
        $phpDir = $this->compiler->getPhpDir();
        $this->assertIsString($phpDir);
        $this->assertNotEmpty($phpDir);
    }
}
