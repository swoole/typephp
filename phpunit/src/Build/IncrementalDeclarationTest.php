<?php

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TypePhp\CompilerTest;

final class IncrementalDeclarationTest extends TestCase
{
    private string $directory;
    private string $buildDirectory;
    private string $provider;
    private string $consumer;
    private string $independent;

    protected function setUp(): void
    {
        $this->directory = dirname(rtrim(TYPEPHP_ROOT_PATH, '/')) . '/tmp/typephp_incremental_' . bin2hex(random_bytes(8));
        $this->buildDirectory = $this->directory . '/build';
        mkdir($this->directory, 0777, true);
        $this->provider = $this->directory . '/provider.php';
        $this->consumer = $this->directory . '/consumer.php';
        $this->independent = $this->directory . '/independent.php';
        $this->copyFixture('default/provider.php', $this->provider);
        $this->copyFixture('default/independent.php', $this->independent);
        $this->copyFixture('default/consumer.php', $this->consumer);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testDeclarationsAreSplitBySourceAndDependenciesAreIncluded(): void
    {
        $compiler = $this->convertProject();
        $providerHeader = $compiler->getDeclarationHeaderFile($this->provider);
        $consumerHeader = $compiler->getDeclarationHeaderFile($this->consumer);
        $consumerCpp = $this->invoke($compiler, 'getCppFile', $this->consumer);
        $providerCpp = $this->invoke($compiler, 'getCppFile', $this->provider);

        self::assertFileExists($providerHeader);
        self::assertFileExists($consumerHeader);
        self::assertFileExists($this->buildDirectory . '/include/php_incremental_runtime_decl.h');
        self::assertFileExists($this->buildDirectory . '/include/php_incremental_all_decl.h');
        self::assertFileDoesNotExist($this->buildDirectory . '/include/php_incremental_func_decl.h');
        self::assertFileDoesNotExist($this->buildDirectory . '/include/php_incremental_data_decl.h');

        $providerDeclarations = (string) file_get_contents($providerHeader);
        $consumerDeclarations = (string) file_get_contents($consumerHeader);
        $runtimeDeclarations = (string) file_get_contents(
            $this->buildDirectory . '/include/php_incremental_runtime_decl.h',
        );
        $consumerCode = (string) file_get_contents($consumerCpp);
        $extensionCode = (string) file_get_contents(
            $this->buildDirectory . '/extension-incremental.cc',
        );
        self::assertStringContainsString('php_incremental__answer(', $providerDeclarations);
        self::assertStringContainsString('_const_var_Incremental__LIMIT', $providerDeclarations);
        self::assertStringNotContainsString('_global_var_shared', $providerDeclarations);
        self::assertStringContainsString('extern THREAD_LOCAL php::Var _global_var_shared;', file_get_contents($providerCpp));
        self::assertStringNotContainsString('_global_var_shared', $consumerDeclarations);
        self::assertStringNotContainsString('_global_var_shared', $runtimeDeclarations);
        self::assertStringContainsString('extern THREAD_LOCAL php::Var _global_var_shared;', $consumerCode);
        self::assertStringNotContainsString('php_main(', $providerDeclarations);
        self::assertStringContainsString('php_main(', $consumerDeclarations);
        self::assertStringContainsString(
            '#include <' . basename($providerHeader) . '>',
            $consumerDeclarations,
        );
        self::assertStringContainsString(
            '#include <' . basename($consumerHeader) . '>',
            $consumerCode,
        );
        self::assertStringContainsString(
            '#include <php_incremental_runtime_decl.h>',
            $extensionCode,
        );
        self::assertStringContainsString(
            '#include <' . basename($compiler->getArgInfoHeaderFile($this->provider)) . '>',
            $extensionCode,
        );
        self::assertStringNotContainsString(
            '#include <' . basename($providerHeader) . '>',
            $extensionCode,
        );
        self::assertStringNotContainsString(
            '#include <' . basename($consumerHeader) . '>',
            $extensionCode,
        );

        $symbols = $this->property($compiler, 'symbolDeclInFile');
        self::assertSame($this->provider, $symbols['function:incremental\\answer']);
        self::assertSame($this->provider, $symbols['constant:Incremental\\LIMIT']);
    }

    public function testGroupedDeclarationsPreserveHelpersAndFilesWithoutFunctions(): void
    {
        $this->copyFixture('grouped/provider.php', $this->provider);
        $this->copyFixture('grouped/consumer.php', $this->consumer);
        $this->copyFixture('grouped/independent.php', $this->independent);

        $compiler = $this->convertProject();
        foreach ([$this->provider, $this->consumer, $this->independent] as $file) {
            $expected = $this->invoke($compiler, 'renderFunctionDeclarations', $file)
                . $this->invoke($compiler, 'renderDataDeclarations', $file, false, false);
            self::assertSame($expected, file_get_contents($compiler->getDeclarationHeaderFile($file)));
        }
        $provider = file_get_contents($compiler->getDeclarationHeaderFile($this->provider));
        self::assertStringContainsString('php_incremental__answer_arg_0_default_value();', $provider);
        self::assertStringContainsString('php_incremental__answer_arg_1_default_value();', $provider);
        self::assertStringContainsString('php_incremental__base__amount_arg_0_default_value();', $provider);
        self::assertStringNotContainsString('php_main(', $provider);
        $independent = file_get_contents($compiler->getDeclarationHeaderFile($this->independent));
        self::assertStringNotContainsString('php_incremental__answer', $independent);
        self::assertStringNotContainsString('php_main(', $independent);
        self::assertStringNotContainsString('_default_value', $independent);

        $aggregate = $this->invoke($compiler, 'renderFunctionDeclarations');
        self::assertStringContainsString('php_incremental__answer(', $aggregate);
        self::assertStringContainsString('php_main(', $aggregate);
        self::assertStringContainsString('php_incremental__base__amount_arg_0_default_value();', $aggregate);
    }

    public function testSuperglobalsAreDeclaredInEachUsingSource(): void
    {
        $this->copyFixture('superglobals/provider.php', $this->provider);
        $this->copyFixture('superglobals/consumer.php', $this->consumer);

        foreach ([1, 2] as $_) {
            $compiler = $this->convertProject();
            $runtimeHeader = $this->buildDirectory . '/include/php_incremental_runtime_decl.h';
            $providerCpp = $this->invoke($compiler, 'getCppFile', $this->provider);
            $consumerCpp = $this->invoke($compiler, 'getCppFile', $this->consumer);

            $declarations = (string) file_get_contents($providerCpp);
            foreach (['_GET', '_POST', '_COOKIE', '_SERVER', '_FILES', '_SESSION', '_REQUEST', '_ENV', 'GLOBALS'] as $name) {
                self::assertStringContainsString(
                    'extern THREAD_LOCAL php::Var _global_var_' . $name . ';',
                    $declarations,
                );
            }
            self::assertStringNotContainsString('_global_var_', (string) file_get_contents($runtimeHeader));
            self::assertStringContainsString(
                'extern THREAD_LOCAL php::Var _global_var__SERVER;',
                (string) file_get_contents($consumerCpp),
            );
            self::assertStringContainsString(
                'php::Var &_SERVER = _global_var__SERVER;',
                (string) file_get_contents($consumerCpp),
            );
        }
    }

    public function testStdContainerParameterContractsSurviveWarmConversionAndInvalidateCallers(): void
    {
        $this->copyFixture('std-container/provider.php', $this->provider);
        $this->copyFixture('std-container/consumer.php', $this->consumer);
        $first = $this->convertProject();
        $providerCpp = $this->invoke($first, 'getCppFile', $this->provider);
        $coldCode = file_get_contents($providerCpp);
        self::assertStringContainsString('auto &vec_ref = php::toStdContainer<php::StdVector<php::Int>>', $coldCode);
        $second = $this->convertProject();
        self::assertFalse($this->invoke($second, 'shouldRegeneratePhpFile', $this->provider));
        self::assertSame($coldCode, file_get_contents($providerCpp));
        $function = $this->invoke($second, 'getFunction', 'incremental__answer');
        self::assertSame('vector', $function->argInfoList[0]->stdContainer['kind']);
        $this->copyFixture('std-container/provider-float.php', $this->provider);
        $third = $this->convertProject();
        self::assertTrue($this->invoke($third, 'shouldRegeneratePhpFile', $this->consumer));
        self::assertStringContainsString('php::StdVector<php::Float>', file_get_contents($providerCpp));
    }

    public function testUnchangedGeneratedCppKeepsItsTimestamp(): void
    {
        $first = $this->convertProject();
        $consumerCpp = $this->invoke($first, 'getCppFile', $this->consumer);
        $oldTimestamp = 1_600_000_000;
        touch($consumerCpp, $oldTimestamp);
        clearstatcache(true, $consumerCpp);

        $this->convertProject();

        clearstatcache(true, $consumerCpp);
        self::assertSame($oldTimestamp, filemtime($consumerCpp));
        self::assertStringContainsString(
            'get_str(uint32_t index)',
            (string) file_get_contents($this->buildDirectory . '/include/php_incremental_runtime_decl.h'),
        );
    }

    public function testGlobalArrayConstantInitializersSurviveColdAndWarmConversion(): void
    {
        $this->copyFixture('array-constants/provider.php', $this->provider);
        $first = $this->convertProject();
        $extension = $this->buildDirectory . '/extension-incremental.cc';
        $coldCode = file_get_contents($extension);
        self::assertStringContainsString('php::Array tmp_var_', $coldCode);
        $coldConstants = $this->property($first, 'constants');
        self::assertSame(\TypePhp\Type::BOOL, $coldConstants['_const_var_Incremental__ENABLED']->type);
        $second = $this->convertProject();
        self::assertFalse($this->invoke($second, 'shouldRegeneratePhpFile', $this->provider));
        self::assertSame($coldCode, file_get_contents($extension));
        $warmConstants = $this->property($second, 'constants');
        self::assertSame($coldConstants['_const_var_Incremental__LIMIT']->type, $warmConstants['_const_var_Incremental__LIMIT']->type);
        self::assertSame(\TypePhp\Type::BOOL, $warmConstants['_const_var_Incremental__ENABLED']->type);
    }

    public function testGeneratorFingerprintChangeKeepsIdenticalCppTimestamps(): void
    {
        $first = $this->convertProject();
        $generatedFiles = [
            $this->invoke($first, 'getCppFile', $this->provider),
            $this->invoke($first, 'getCppFile', $this->consumer),
            $this->invoke($first, 'getCppFile', $this->independent),
        ];
        $oldTimestamp = 1_600_000_000;
        foreach ($generatedFiles as $generatedFile) {
            touch($generatedFile, $oldTimestamp);
        }

        $stateFile = $this->buildDirectory . '/cache/incremental/incremental/build-state.json';
        $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
        $state['generatorFingerprint'] = str_repeat('0', 64);
        file_put_contents(
            $stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
        clearstatcache();

        $this->convertProject();

        clearstatcache();
        foreach ($generatedFiles as $generatedFile) {
            self::assertSame($oldTimestamp, filemtime($generatedFile));
        }
    }

    public function testRegenerationKeepsTimestampsForIdenticalGeneratedContents(): void
    {
        $first = $this->convertProject();
        $providerCpp = $this->invoke($first, 'getCppFile', $this->provider);
        $providerHeader = $first->getDeclarationHeaderFile($this->provider);
        $consumerCpp = $this->invoke($first, 'getCppFile', $this->consumer);
        $independentCpp = $this->invoke($first, 'getCppFile', $this->independent);
        $oldTimestamp = 1_600_000_000;
        foreach ([$providerCpp, $providerHeader, $consumerCpp, $independentCpp] as $generatedFile) {
            touch($generatedFile, $oldTimestamp);
        }
        $this->copyFixture('default/provider-updated.php', $this->provider);
        clearstatcache();

        $this->convertProject();

        clearstatcache();
        self::assertGreaterThan($oldTimestamp, filemtime($providerHeader));
        self::assertSame($oldTimestamp, filemtime($providerCpp));
        // The dependency graph still regenerates the consumer, but its own C++
        // bytes only include the provider header by its stable name. Preserve
        // the timestamp; the changed header content invalidates its object key.
        self::assertSame($oldTimestamp, filemtime($consumerCpp));
        self::assertSame($oldTimestamp, filemtime($independentCpp));
    }

    public function testCachedClassKeepsRuntimeArrayPropertyDefaultAllocator(): void
    {
        $this->copyFixture('array-defaults/independent.php', $this->independent);

        $this->convertProject();
        $this->convertProject();

        $extensionCode = (string) file_get_contents(
            $this->buildDirectory . '/extension-incremental.cc',
        );
        $initializer = 'typephp_ensure_request_array_defaults_IncrementalDefaults()';
        self::assertSame(2, substr_count($extensionCode, $initializer));
        self::assertStringContainsString(
            'php_class_entry_IncrementalDefaults->create_object =',
            $extensionCode,
        );
    }

    public function testGeneratedObjectCacheUsesContentRatherThanSourceTimestamp(): void
    {
        $compiler = $this->convertProject();
        $consumerCpp = $this->invoke($compiler, 'getCppFile', $this->consumer);
        $consumerObject = $compiler->getObjectFile($consumerCpp);
        $sourceTimestamp = 1_600_000_000;
        $objectTimestamp = $sourceTimestamp + 10;

        file_put_contents($consumerObject, 'object');
        touch($consumerCpp, $sourceTimestamp);
        touch($consumerObject, $objectTimestamp);
        clearstatcache();
        $this->invoke($compiler, 'writeGeneratedObjectCacheMetadata', $consumerCpp, $consumerObject);

        self::assertTrue(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        touch($consumerCpp, $objectTimestamp);
        clearstatcache();
        self::assertTrue(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        touch($consumerCpp, $objectTimestamp + 1);
        clearstatcache();
        self::assertTrue(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        file_put_contents($consumerCpp, "\n// changed generated input\n", FILE_APPEND);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        unlink($consumerObject);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        file_put_contents($consumerObject, 'object');
        touch($consumerObject, $objectTimestamp);
        unlink($consumerCpp);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );
    }

    public function testLinkCacheRequiresEveryObjectAndAnUpToDateTarget(): void
    {
        $compiler = $this->newCompiler();
        $target = $this->directory . '/incremental-bin';
        $firstObject = $this->directory . '/first.o';
        $secondObject = $this->directory . '/second.o';
        $timestamp = 1_600_000_000;
        foreach ([$target, $firstObject, $secondObject] as $file) {
            file_put_contents($file, 'artifact');
            touch($file, $timestamp);
        }
        clearstatcache();

        $objects = [$firstObject, $secondObject];
        $this->invoke($compiler, 'writeLinkCache', $objects, $target);
        self::assertFileExists(
            $this->buildDirectory . '/cache/link/incremental-bin.typephp-link-cache',
        );
        self::assertFileDoesNotExist($target . '.typephp-link-cache');
        self::assertTrue($this->invoke($compiler, 'hasLinkCache', $objects, $target));

        touch($secondObject, $timestamp + 1);
        clearstatcache();
        self::assertFalse($this->invoke($compiler, 'hasLinkCache', $objects, $target));

        unlink($secondObject);
        clearstatcache();
        self::assertFalse($this->invoke($compiler, 'hasLinkCache', $objects, $target));
    }

    public function testForceCacheClearRemovesAstAndTargetIncrementalState(): void
    {
        $compiler = $this->newCompiler();
        $astDirectory = $this->buildDirectory . '/cache/ast';
        $incrementalDirectory = $this->buildDirectory . '/cache/incremental/incremental';
        mkdir($astDirectory, 0777, true);
        mkdir($incrementalDirectory, 0777, true);
        file_put_contents($astDirectory . '/entry.ast', 'ast');
        file_put_contents($incrementalDirectory . '/stable-ids.json', '{}');

        $this->invoke($compiler, 'clearIncrementalBuildCache');

        self::assertDirectoryDoesNotExist($astDirectory);
        self::assertDirectoryDoesNotExist($incrementalDirectory);
    }

    private function prepareNativeStub(): void
    {
        $stub = $this->directory . '/provider.stub.php';
        rename($this->provider, $stub);
        $this->provider = $stub;
        $this->copyFixture('native-stub/provider.stub.php', $stub);
        $this->copyFixture('native-stub/consumer.php', $this->consumer);
    }

    public function testStubDeclarationsAreGeneratedWithoutConvertingStubBodies(): void
    {
        $this->prepareNativeStub();
        $first = $this->convertProject();
        $header = $first->getDeclarationHeaderFile($this->provider);
        self::assertFileExists($header);
        self::assertStringContainsString('php_incremental__answer(', file_get_contents($header));
        self::assertStringContainsString('#include <' . basename($header) . '>',
            file_get_contents($first->getDeclarationHeaderFile($this->consumer)));
        self::assertFileDoesNotExist($this->invoke($first, 'getCppFile', $this->provider));
        self::assertFileExists($first->getArgInfoHeaderFile($this->provider));
        $extension = $this->buildDirectory . '/extension-incremental.cc';
        $extensionCode = file_get_contents($extension);
        self::assertStringContainsString('ZEND_FUNCTION(incremental_answer)', $extensionCode);
        self::assertStringContainsString('#include <' . basename($first->getArgInfoHeaderFile($this->provider)) . '>', $extensionCode);
        $state = json_decode(file_get_contents($this->buildDirectory . '/cache/incremental/incremental/build-state.json'), true);
        self::assertFalse($state['files'][$this->provider]['emitsTranslationUnit']);
        self::assertContains($this->provider, $state['files'][$this->consumer]['dependencies']);
        $second = $this->convertProject();
        self::assertFalse($this->invoke($second, 'shouldRegeneratePhpFile', $this->provider));
        self::assertFalse($this->invoke($second, 'shouldRegeneratePhpFile', $this->consumer));
        self::assertSame($extensionCode, file_get_contents($extension));
    }

    public function testNamespacedGlobalStubCallIncludesProviderAndTracksInvalidation(): void
    {
        $this->prepareNativeStub();
        file_put_contents($this->provider, '<?php function native_global_answer(): int {}');
        file_put_contents($this->consumer, <<<'PHP'
<?php
namespace Consumer {
    final class Reader {
        public static function read(): int { return native_global_answer(); }
    }
}
namespace {
    function main(): int { return \Consumer\Reader::read(); }
}
PHP);
        $compiler = $this->convertProject();
        $providerHeader = $compiler->getDeclarationHeaderFile($this->provider);
        $consumerCode = file_get_contents($this->invoke($compiler, 'getCppFile', $this->consumer));
        self::assertStringContainsString('#include <' . basename($providerHeader) . '>', $consumerCode);
        self::assertStringContainsString('php_native_global_answer()', $consumerCode);
        $state = json_decode(file_get_contents($this->buildDirectory . '/cache/incremental/incremental/build-state.json'), true);
        self::assertContains($this->provider, $state['files'][$this->consumer]['dependencies']);
        file_put_contents($this->provider, '<?php function native_global_answer(int $value = 42): int {}');
        $updated = $this->convertProject();
        self::assertTrue($this->invoke($updated, 'shouldRegeneratePhpFile', $this->consumer));
    }

    public function testStubChangePreservingMtimeInvalidatesTheConsumer(): void
    {
        $this->prepareNativeStub();
        $this->convertProject();
        $extension = $this->buildDirectory . '/extension-incremental.cc';
        $oldCode = file_get_contents($extension);
        $mtime = filemtime($this->provider);
        $this->copyFixture('native-stub/provider-updated.stub.php', $this->provider);
        touch($this->provider, $mtime);
        clearstatcache();
        $second = $this->convertProject();
        self::assertTrue($this->invoke($second, 'shouldRegeneratePhpFile', $this->provider));
        self::assertTrue($this->invoke($second, 'shouldRegeneratePhpFile', $this->consumer));
        self::assertNotSame($oldCode, file_get_contents($extension));
        self::assertStringContainsString('43', file_get_contents($extension));
    }

    public function testMissingStubHeaderIsRegeneratedAndInvalidatesConsumers(): void
    {
        $this->prepareNativeStub();
        $first = $this->convertProject();
        unlink($first->getDeclarationHeaderFile($this->provider));
        $second = $this->convertProject();
        self::assertFileExists($second->getDeclarationHeaderFile($this->provider));
        self::assertTrue($this->invoke($second, 'shouldRegeneratePhpFile', $this->consumer));
    }

    public function testMissingStubArginfoIsRegeneratedAndInvalidatesConsumers(): void
    {
        $this->prepareNativeStub();
        $first = $this->convertProject();
        unlink($first->getArgInfoHeaderFile($this->provider));
        $second = $this->convertProject();
        self::assertFileExists($second->getArgInfoHeaderFile($this->provider));
        self::assertTrue($this->invoke($second, 'shouldRegeneratePhpFile', $this->consumer));
    }

    public function testImportedClassMetadataAndCallbacksStayInTheExtension(): void
    {
        $this->prepareNativeStub();
        $this->copyFixture('imported-class/provider.stub.php', $this->provider);
        $this->copyFixture('imported-class/consumer.php', $this->consumer);
        $first = $this->convertProject();
        $arginfo = $first->getArgInfoHeaderFile($this->provider);
        $extension = $this->buildDirectory . '/extension-incremental.cc';
        $extensionCode = file_get_contents($extension);
        self::assertStringContainsString('php_register_class_Incremental_Counter', file_get_contents($arginfo));
        self::assertStringContainsString('ZEND_METHOD(Incremental_Counter, add)', $extensionCode);
        self::assertStringContainsString('php_incremental__counter__add(this_, arg_delta)', $extensionCode);
        self::assertStringContainsString('#include <' . basename($arginfo) . '>', $extensionCode);
        self::assertStringNotContainsString(basename($arginfo), file_get_contents($this->invoke($first, 'getCppFile', $this->consumer)));
        self::assertFileDoesNotExist($this->invoke($first, 'getCppFile', $this->provider));
        $this->convertProject();
        self::assertSame($extensionCode, file_get_contents($extension));
    }

    public function testSwitchingTargetsDoesNotReuseAnotherTargetsGeneratedFiles(): void
    {
        $first = $this->convertProject();
        $cpp = $this->invoke($first, 'getCppFile', $this->independent);
        $original = file_get_contents($cpp);

        $this->convertProject('alternate');
        self::assertNotSame($original, file_get_contents($cpp));

        $restored = $this->convertProject();
        self::assertTrue($this->invoke($restored, 'shouldRegeneratePhpFile', $this->independent));
        self::assertSame($original, file_get_contents($cpp));

        $warm = $this->convertProject();
        self::assertFalse($this->invoke($warm, 'shouldRegeneratePhpFile', $this->independent));
    }

    public function testChangedGeneratedFilesInvalidateUnchangedPhpSources(): void
    {
        $first = $this->convertProject();
        $paths = [
            $this->invoke($first, 'getCppFile', $this->independent),
            $first->getDeclarationHeaderFile($this->provider),
            $first->getArgInfoHeaderFile($this->consumer),
        ];
        $contents = [];
        foreach ($paths as $path) {
            $contents[$path] = file_get_contents($path);
            file_put_contents($path, "\n// Changed outside the generator\n", FILE_APPEND);
        }

        $restored = $this->convertProject();
        foreach ([$this->provider, $this->consumer, $this->independent] as $source) {
            self::assertTrue($this->invoke($restored, 'shouldRegeneratePhpFile', $source));
        }
        foreach ($contents as $path => $original) {
            self::assertSame($original, file_get_contents($path));
        }
    }

    public function testAddingAndRemovingChildOverrideInvalidatesBaseDispatch(): void
    {
        $this->copyFixture('child-override/provider.php', $this->provider);
        $this->copyFixture('child-override/consumer.php', $this->consumer);
        $first = $this->convertProject();
        $baseCpp = $this->invoke($first, 'getCppFile', $this->provider);
        self::assertStringContainsString('php::toInt(php_incremental__actor__face(this_))', file_get_contents($baseCpp));
        $unchanged = $this->convertProject();
        self::assertFalse($this->invoke($unchanged, 'shouldRegeneratePhpFile', $this->provider));

        $this->copyFixture('child-override/consumer-overridden.php', $this->consumer);
        $overridden = $this->convertProject();
        self::assertTrue($this->invoke($overridden, 'shouldRegeneratePhpFile', $this->provider));
        self::assertFalse($this->invoke($overridden, 'shouldRegeneratePhpFile', $this->independent));
        self::assertStringNotContainsString('php::toInt(php_incremental__actor__face(this_))', file_get_contents($baseCpp));
        self::assertStringContainsString('typephp_call_method_cached', file_get_contents($baseCpp));

        $this->copyFixture('child-override/consumer.php', $this->consumer);
        $removed = $this->convertProject();
        self::assertTrue($this->invoke($removed, 'shouldRegeneratePhpFile', $this->provider));
        self::assertStringContainsString('php::toInt(php_incremental__actor__face(this_))', file_get_contents($baseCpp));
    }

    public function testAddingAndRemovingDescendantInvalidatesTypedCallers(): void
    {
        $this->copyFixture('descendant/provider.php', $this->provider);
        $this->copyFixture('descendant/consumer.php', $this->consumer);
        $first = $this->convertProject();
        $callerCpp = $this->invoke($first, 'getCppFile', $this->consumer);
        self::assertStringContainsString('php::toInt(php_actor__face(actor))', file_get_contents($callerCpp));

        $this->copyFixture('descendant/independent.php', $this->independent);
        $added = $this->convertProject();
        self::assertTrue($this->invoke($added, 'shouldRegeneratePhpFile', $this->provider));
        self::assertTrue($this->invoke($added, 'shouldRegeneratePhpFile', $this->consumer));
        self::assertStringNotContainsString('php::toInt(php_actor__face(actor))', file_get_contents($callerCpp));
        self::assertStringContainsString('typephp_call_method_cached', file_get_contents($callerCpp));

        $this->copyFixture('default/independent.php', $this->independent);
        $removed = $this->convertProject();
        self::assertTrue($this->invoke($removed, 'shouldRegeneratePhpFile', $this->consumer));
        self::assertStringContainsString('php::toInt(php_actor__face(actor))', file_get_contents($callerCpp));
    }

    private function copyFixture(string $fixture, string $destination): void
    {
        copy(TYPEPHP_ROOT_PATH . '/phpunit/code/incremental-declarations/' . $fixture, $destination);
    }

    private function convertProject(string $target = 'incremental'): CompilerTest
    {
        global $translator;

        $compiler = $this->newCompiler($target);
        $translator = $compiler;
        $files = [$this->provider, $this->consumer, $this->independent];
        $compiler->addFiles([$this->directory]);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        $files = $compiler->getSortedFiles($files);
        $this->invoke($compiler, 'initializeIncrementalCompilation', $files);
        $compiler->convert($files);
        return $compiler;
    }

    private function newCompiler(string $target = 'incremental'): CompilerTest
    {
        $compiler = CompilerTest::create($this->directory);
        // Exercise the same on-disk stable-ID registry used by real builds.
        $reflection = new ReflectionClass($compiler);
        $forTest = $reflection->getProperty('forTest');
        $forTest->setAccessible(true);
        $forTest->setValue($compiler, false);
        $compiler->setTargetName($target);
        $this->invoke($compiler, 'setBuildDir', $this->buildDirectory);
        return $compiler;
    }

    private function invoke(CompilerTest $compiler, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionClass($compiler);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);
        return $methodReflection->invoke($compiler, ...$arguments);
    }

    private function property(CompilerTest $compiler, string $name): mixed
    {
        $reflection = new ReflectionClass($compiler);
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue($compiler);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
