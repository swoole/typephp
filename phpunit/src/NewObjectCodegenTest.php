<?php

use TypePhp\CompilerTest;

final class NewObjectCodegenTest extends \BaseTest
{
    public function testStableClassEntryLookupIsHoistedOutOfObjectCreationLoop(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php_createknownobjects\([^)]*\) \{[\s\S]*?zend_class_entry \*(tmp_var_\d+) = get_persistent_class\([^;]+;[\s\S]*?php::newObject\(\1\)/',
            $code,
        );
        self::assertStringNotContainsString(
            'php::newObject(get_persistent_class(',
            $code,
        );
    }

    public function testRuntimeProvidedClassStillResolvesAtNewExpression(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php_createruntimeobject\([^)]*\)[\s\S]*?php::newObject\(get_class\(/',
            $code,
        );
    }

    public function testStubRepresentablePropertyDefaultsUseZendDefaultTable(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringNotContainsString(
            'create_object_KnownNewObjectCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_EmptyArrayDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_ScalarExpressionDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_ScalarConstantDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringContainsString(
            'create_object_RuntimeArrayDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'zend_update_property(php_class_entry_RuntimeArrayDefaultCodegen, obj, ZEND_STRL("scalar")',
            $extension,
        );
        self::assertStringContainsString(
            'create_object_EnumPropertyDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_HookOnlyDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
        self::assertStringNotContainsString(
            'create_object_AsymmetricOnlyDefaultCodegen = php::getCreateObjectFn',
            $extension,
        );
    }

    public function testCreateObjectFunctionStorageIsOnlyDeclaredForClassesThatUseIt(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringNotContainsString(
            'static zend_object* (*create_object_KnownNewObjectCodegen)',
            $extension,
        );
        self::assertStringContainsString(
            'static zend_object* (*create_object_RuntimeArrayDefaultCodegen)',
            $extension,
        );
    }

    public function testRuntimeArrayDefaultsUseLazyRequestTemplates(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringContainsString(
            'THREAD_LOCAL bool typephp_request_array_defaults_initialized_RuntimeArrayDefaultCodegen = false;',
            $extension,
        );
        self::assertStringContainsString(
            'THREAD_LOCAL php::Var typephp_request_array_default_runtimearraydefaultcodegen__values;',
            $extension,
        );
        self::assertStringContainsString(
            'THREAD_LOCAL php::Var typephp_request_array_default_runtimearraydefaultcodegen__labels;',
            $extension,
        );
        self::assertMatchesRegularExpression(
            '/if \(UNEXPECTED\(!typephp_request_array_defaults_initialized_RuntimeArrayDefaultCodegen\)\) \{[\s\S]*prepared_default_0[\s\S]*prepared_default_1[\s\S]*typephp_request_array_defaults_initialized_RuntimeArrayDefaultCodegen = true;/',
            $extension,
        );
        $createObject = strpos(
            $extension,
            'php_class_entry_RuntimeArrayDefaultCodegen->create_object = [](zend_class_entry *class_type)',
        );
        self::assertIsInt($createObject);
        $ensureDefaults = strpos(
            $extension,
            'typephp_ensure_request_array_defaults_RuntimeArrayDefaultCodegen();',
            $createObject,
        );
        self::assertIsInt($ensureDefaults);
        $allocateObject = strpos($extension, 'typephp_create_object_with_defaults(', $ensureDefaults);
        self::assertIsInt($allocateObject);
        self::assertLessThan($ensureDefaults, $createObject);
        self::assertLessThan($allocateObject, $ensureDefaults);
        self::assertStringContainsString(
            '= typephp_request_array_default_runtimearraydefaultcodegen__values;',
            $extension,
        );
        self::assertStringContainsString(
            'typephp_request_array_default_runtimearraydefaultcodegen__values.unset();',
            $extension,
        );
        self::assertStringContainsString(
            'typephp_request_array_default_runtimearraydefaultcodegen__labels.unset();',
            $extension,
        );
    }

    public function testOrdinaryClassesKeepNativeReadAndWritePropertyHandlers(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringContainsString(
            'property_handlers_KnownNewObjectCodegen.read_property = '
            . 'base_property_handlers_KnownNewObjectCodegen->read_property;',
            $extension,
        );
        self::assertStringContainsString(
            'property_handlers_KnownNewObjectCodegen.write_property = '
            . 'base_property_handlers_KnownNewObjectCodegen->write_property;',
            $extension,
        );
    }

    public function testHookAndAsymmetricClassesKeepOnlyRequiredDispatchHandlers(): void
    {
        [, $extension] = $this->compileFixtureAndExtension();

        self::assertStringNotContainsString('base_property_handlers_HookOnlyDefaultCodegen', $extension);
        self::assertStringNotContainsString('base_property_handlers_GetterOnlyDefaultCodegen', $extension);
        self::assertStringContainsString(
            'property_handlers_AsymmetricOnlyDefaultCodegen.read_property = '
            . 'base_property_handlers_AsymmetricOnlyDefaultCodegen->read_property;',
            $extension,
        );
        self::assertStringNotContainsString(
            'property_handlers_AsymmetricOnlyDefaultCodegen.write_property = '
            . 'base_property_handlers_AsymmetricOnlyDefaultCodegen->write_property;',
            $extension,
        );
    }

    private function compileFixture(): string
    {
        return $this->compileFixtureAndExtension()[0];
    }

    /** @return array{string, string} */
    private function compileFixtureAndExtension(): array
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/new-object-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        $extension = file_get_contents($compiler->genExtension());

        self::assertIsString($code);
        self::assertIsString($extension);
        return [$code, $extension];
    }
}
