<?php

namespace TypePhp\Tests\NativeClass;

use TypePhp\Exception\TestError;

final class NativeClassValidationTest extends \BaseTest
{
    /**
     * @dataProvider nativeMemberNameConflictProvider
     */
    public function testRejectsNativePropertyAndMethodNameConflicts(string $fixture): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('conflicts with');
        $this->compile($fixture);
    }

    public static function nativeMemberNameConflictProvider(): array
    {
        return [
            ['native-class-member-name-conflict.php'],
            ['native-class-inherited-member-name-conflict.php'],
            ['native-class-inherited-property-name-conflict.php'],
        ];
    }

    public function testDiscoversNativeTypesBeforeCrossFileSignaturePreprocessing(): void
    {
        global $translator;

        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $directory = dirname(__DIR__, 2) . '/code/native-class-forward';
        $files = [$directory . '/a.php', $directory . '/b.php'];
        $compiler->discoverNativeClassDeclarations($files);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        foreach ($files as $file) {
            $compiler->convertFile($file);
        }

        $this->addToAssertionCount(1);
    }

    public function testDiscoversNativeGlobalSlotBeforeEarlierReaderIsConverted(): void
    {
        global $translator;

        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $directory = dirname(__DIR__, 2) . '/code/native-class-global-forward';
        $files = [$directory . '/a.php', $directory . '/b.php'];
        $compiler->discoverNativeClassDeclarations($files);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        $compiler->discoverNativeGlobalObjects($files);
        $reader = $compiler->convertFile($files[0]);
        $compiler->convertFile($files[1]);

        $code = file_get_contents($reader);
        self::assertIsString($code);
        self::assertStringContainsString(
            'php::nativeRequireObject(nativeForwardGlobal, "NativeForwardGlobalValue")->value',
            $code,
        );
        self::assertStringContainsString(
            'php::nativeRequireObject(nativeForwardPolymorphic, "NativeForwardBase")->value',
            $code,
        );
        self::assertStringContainsString(
            'php::nativeRequireObject(nativeForwardCoalesced, "NativeForwardGlobalValue")->value',
            $code,
        );
        self::assertStringContainsString(
            'auto &nativeForwardGlobalsArray = _global_var_nativeForwardGlobalsArray;',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/php::nativeRequireObject\\(tmp_var_\\d+, "NativeForwardGlobalValue"\\)->value/',
            $code,
        );
        self::assertStringContainsString(
            'php::nativeRequireObject(nativeForwardClosureGlobal, "NativeForwardGlobalValue")->value',
            $code,
        );
        self::assertStringNotContainsString('nativeForwardGlobal.attr(', $code);
    }

    public function testRejectsNativeAttributeOnInterface(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Native can only be applied to named classes');
        $this->compile('native-class-attribute-interface.php');
    }

    public function testRejectsNativeAttributeOnTrait(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Native can only be applied to named classes');
        $this->compile('native-class-attribute-trait.php');
    }

    public function testRejectsNativeAttributeOnEnum(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Native can only be applied to named classes');
        $this->compile('native-class-attribute-enum.php');
    }

    public function testRejectsNativeAttributeOnAnonymousClass(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Native can only be applied to named classes');
        $this->compile('native-class-anonymous.php');
    }

    public function testRejectsNativeClassDeclaredInStubFile(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            '#[Native] cannot be used in .stub.php; Native class layout must be owned by the TypePHP compiler',
        );
        $this->compile('native-class-stub.stub.php');
    }

    public function testRejectsUntypedProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class properties must declare a type');
        $this->compile('native-class-untyped-property.php');
    }

    public function testRejectsStaticProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static properties are not supported');
        $this->compile('native-class-static-property.php');
    }

    public function testRejectsInheritanceAcrossObjectModels(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native and ZendVM-backed classes cannot inherit from each other');
        $this->compile('native-class-zend-inheritance.php');
    }

    public function testRejectsStaticMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static methods are not supported');
        $this->compile('native-class-static-method.php');
    }

    public function testRejectsReadonlyPropertyUntilNativeWriteStateIsImplemented(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class readonly properties are not supported');
        $this->compile('native-class-readonly-property.php');
    }

    public function testRejectsStdContainerProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class properties cannot use Std Container types');
        $this->compile('native-class-std-container-property.php');
    }

    public function testRejectsBoxProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class properties cannot use Box types');
        $this->compile('native-class-box-property.php');
    }

    public function testRejectsNativeStdContainerConversionToPhpArray(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-escape.php');
    }

    public function testRejectsNativeStdContainerPassedAsPhpValue(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-argument.php');
    }

    public function testRejectsReturningStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-return.php');
    }

    public function testRejectsConvertingStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-conversion.php');
    }

    public function testRejectsCapturingStdContainerHoldingNativeObjectsInClosure(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot be captured by Zend closures');
        $this->compile('native-class-std-container-closure-capture.php');
    }

    public function testRejectsCapturingStdContainerHoldingNativeObjectsInArrowFunction(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot be captured by Zend closures');
        $this->compile('native-class-std-container-arrow-capture.php');
    }

    /**
     * @dataProvider nativeStdContainerStorageBoundaryProvider
     */
    public function testRejectsStoringStdContainerHoldingNativeObjects(string $fixture): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile($fixture);
    }

    public static function nativeStdContainerStorageBoundaryProvider(): array
    {
        return [
            ['native-class-std-container-php-property.php'],
            ['native-class-std-container-static-property.php'],
            ['native-class-std-container-php-array.php'],
            ['native-class-std-container-native-any-property.php'],
        ];
    }

    public function testRejectsReferencingStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-reference.php');
    }

    public function testRejectsDestructuringStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects cannot cross a PHP/ZendVM value boundary');
        $this->compile('native-class-std-container-destructure.php');
    }

    public function testRejectsStaticStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects must be function-local');
        $this->compile('native-class-static-std-container.php');
    }

    public function testRejectsGlobalStdContainerHoldingNativeObjects(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Std containers holding Native objects must be function-local');
        $this->compile('native-class-global-std-container.php');
    }

    public function testRejectsCompoundWritesToNativePropertyHooks(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native property hooks only support direct reads and assignments');
        $this->compile('native-class-property-hook-compound.php');
    }

    public function testRejectsIssetOnNativePropertyHooks(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('isset()/empty() are not supported for Native property hooks');
        $this->compile('native-class-property-hook-isset.php');
    }

    public function testRejectsIndirectWritesToNativePropertyHooks(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native property hooks only support direct reads and assignments');
        $this->compile('native-class-property-hook-indirect-write.php');
    }

    public function testRejectsIndirectUnsetOnNativePropertyHooks(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native property hooks only support direct reads and assignments');
        $this->compile('native-class-property-hook-indirect-unset.php');
    }

    public function testRejectsIndirectReferencesToNativePropertyHooks(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native property hooks only support direct reads and assignments');
        $this->compile('native-class-property-hook-indirect-reference.php');
    }

    public function testRejectsDynamicInstanceofBecauseNativeClassesHaveNoRuntimeTypeLookup(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Dynamic instanceof is not supported for native objects');
        $this->compile('native-class-instanceof.php');
    }

    public function testRejectsNativeObjectAsDynamicNewTarget(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as dynamic class targets');
        $this->compile('native-class-dynamic-new.php');
    }

    public function testLeavesDynamicClassExpressionsToTheOrdinaryPhpPath(): void
    {
        $this->compile('native-class-dynamic-class-expression.php');
    }

    public function testRejectsNativeObjectAsDynamicStaticCallTarget(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as dynamic class targets');
        $this->compile('native-class-dynamic-static-call.php');
    }

    public function testRejectsNativeObjectAsDynamicClassConstantTarget(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as dynamic class targets');
        $this->compile('native-class-dynamic-class-constant.php');
    }

    public function testRejectsNativeObjectStoredInPhpArray(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be stored in PHP arrays');
        $this->compile('native-class-php-array.php');
    }

    public function testRejectsNativeObjectStoredThroughDynamicGlobalsKey(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Native objects cannot be stored in PHP arrays, PHP object properties, static properties, or mixed variables',
        );
        $this->compile('native-class-dynamic-globals-write.php');
    }

    public function testRejectsNativeObjectStoredInPhpObjectProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be stored in PHP arrays, PHP object properties');
        $this->compile('native-class-php-property.php');
    }

    public function testRejectsNativeTypedPropertyOnZendObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object types can only be used as properties of native classes');
        $this->compile('native-class-zend-native-property.php');
    }

    public function testRejectsNativeObjectCapturedByClosure(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be captured by Zend closures');
        $this->compile('native-class-closure-capture.php');
    }

    public function testRejectsNativeMethodFirstClassCallable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object methods cannot be converted to Zend closures');
        $this->compile('native-class-first-class-callable.php');
    }

    public function testRejectsNativeAbiFunctionFirstClassCallable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native ABI functions cannot be converted to Zend closures');
        $this->compile('native-class-function-first-class-callable.php');
    }

    public function testRejectsNativeObjectClosureParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend closures cannot declare native object parameters or return types');
        $this->compile('native-class-closure-parameter.php');
    }

    public function testRejectsNativeObjectReturnedByUntypedClosure(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend closures cannot return native objects');
        $this->compile('native-class-closure-return.php');
    }

    public function testRejectsNativeObjectParameterOnZendConstructor(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend-backed constructors cannot accept or return native objects');
        $this->compile('native-class-zend-constructor.php');
    }

    public function testRejectsUnsupportedNativeObjectUnion(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object types do not support union or intersection declarations; use nullable ?Class syntax');
        $this->compile('native-class-union-signature.php');
    }

    public function testRejectsNativeObjectReferenceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object parameters cannot be passed by reference');
        $this->compile('native-class-reference-parameter.php');
    }

    public function testRejectsNativeObjectReferenceReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be returned by reference');
        $this->compile('native-class-reference-return.php');
    }

    public function testRejectsNativeObjectReferenceAssignment(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be referenced; object assignment already shares identity');
        $this->compile('native-class-reference-assignment.php');
    }

    public function testRejectsReferencesToNativeObjectProperties(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Only Native object properties declared as any can be referenced');
        $this->compile('native-class-property-reference.php');
    }

    public function testRejectsReferencesToMixedNativeObjectProperties(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Only Native object properties declared as any can be referenced');
        $this->compile('native-class-mixed-property-reference.php');
    }

    public function testAllowsReferencesToExplicitAnyNativeObjectProperties(): void
    {
        $this->compile('native-class-any-property-reference.php');
    }

    public function testRejectsUnsetOnNativeObjectProperties(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object properties cannot be unset');
        $this->compile('native-class-property-unset.php');
    }

    public function testRejectsExplicitNativeDestructorCall(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Explicit calls to native object destructors are not supported');
        $this->compile('native-class-explicit-destructor-call.php');
    }

    public function testRejectsNativeObjectReferenceKeywordMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be referenced; object assignment already shares identity');
        $this->compile('native-class-reference-method.php');
    }

    public function testNativeObjectToObjectKeywordUsesDeclaredNativeMethod(): void
    {
        $this->compile('native-class-to-object.php');
    }

    public function testRejectsNativeObjectToObjectKeywordWithParameters(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native conversion method `InvalidNativeToObjectParameters::toObject()` must not accept arguments');
        $this->compile('native-class-to-object-parameters.php');
    }

    public function testRejectsNativeObjectToObjectKeywordWithWrongReturnType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native conversion method `InvalidNativeToObjectReturn::toObject()` must return exactly `object`');
        $this->compile('native-class-to-object-return-type.php');
    }

    public function testRejectsUndefinedNativeObjectToAnyKeyword(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class `NativeWithoutToAny` must define `toAny()` for this conversion');
        $this->compile('native-class-to-any-undefined.php');
    }

    public function testRejectsUntypedNativeObjectToAnyReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Native conversion method `NativeUntypedToAny::toAny()` must return exactly `mixed` or `any`',
        );
        $this->compile('native-class-to-any-untyped-return.php');
    }

    public function testRejectsWrongNativeObjectToAnyReturnType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Native conversion method `NativeWrongToAnyReturn::toAny()` must return exactly `mixed` or `any`',
        );
        $this->compile('native-class-to-any-wrong-return.php');
    }

    public function testRejectsNativeObjectReferenceFunction(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be referenced; object assignment already shares identity');
        $this->compile('native-class-reference-function.php');
    }

    public function testRejectsNativeObjectVariadicParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object parameters cannot be variadic');
        $this->compile('native-class-variadic-parameter.php');
    }

    public function testRejectsNativeObjectPassedToUntypedParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot cross a PHP/ZendVM argument boundary');
        $this->compile('native-class-untyped-parameter.php');
    }

    public function testRejectsNativeObjectNullUnionInFavorOfNullableSyntax(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object types do not support union or intersection declarations; use nullable ?Class syntax');
        $this->compile('native-class-null-union-parameter.php');
    }

    public function testRejectsImplicitNullableNativeParameterDefault(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('A Native object parameter with a null default must use explicit nullable ?Class syntax');
        $this->compile('native-class-implicit-nullable-parameter.php');
    }

    public function testRejectsIncorrectNativeKeywordReturnType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must return exactly `array`');
        $this->compile('native-class-keyword-return-type.php');
    }

    public function testRejectsNativeToArrayParametersAtDeclaration(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Native conversion method `NativeToArrayWithParameters::toArray()` must not accept arguments',
        );
        $this->compile('native-class-to-array-parameters.php');
    }

    public function testRejectsMissingNativeKeywordMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must define `toInt()`');
        $this->compile('native-class-keyword-missing.php');
    }

    public function testRejectsNativeObjectPassedToJsonEncode(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot cross a dynamic PHP/ZendVM call boundary');
        $this->compile('native-class-json-encode.php');
    }

    /**
     * @dataProvider nativeZendObjectFacilityProvider
     */
    public function testRejectsNativeObjectPassedToZendObjectFacilities(string $fixture): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot cross a dynamic PHP/ZendVM call boundary');
        $this->compile($fixture);
    }

    public static function nativeZendObjectFacilityProvider(): array
    {
        return [
            ['native-class-serialize.php'],
            ['native-class-weak-reference.php'],
        ];
    }

    public function testRejectsNativeObjectFromUntypedReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object return values require an explicit native class return type');
        $this->compile('native-class-untyped-return.php');
    }

    public function testRejectsNativeObjectFromMixedReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object return values require an explicit native class return type');
        $this->compile('native-class-mixed-return.php');
    }

    public function testRejectsNativeObjectPassedToInterfaceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be converted to interface `NativeInterfaceArgumentContract`');
        $this->compile('native-class-interface-argument.php');
    }

    public function testRejectsNativeObjectAssignedToInterfaceVariable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be assigned to interface-typed variables');
        $this->compile('native-class-interface-assignment.php');
    }

    public function testRejectsNativeObjectAssignedToInterfaceProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be assigned to interface-typed properties');
        $this->compile('native-class-interface-property.php');
    }

    public function testRejectsNativeObjectReturnedAsInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be returned as interface `NativeInterfaceReturnContract`');
        $this->compile('native-class-interface-return.php');
    }

    public function testRejectsDynamicMagicMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__get()`');
        $this->compile('native-class-dynamic-magic-method.php');
    }

    public function testRejectsVariableNativeMethodCalls(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Dynamic native object method calls are not supported');
        $this->compile('native-class-variable-method.php');
    }

    public function testRejectsVariableNativePropertyAccess(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Dynamic native object property access is not supported');
        $this->compile('native-class-variable-property.php');
    }

    public function testRejectsDynamicStaticMagicMethodBeforeGenericStaticDiagnostic(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__callStatic()`');
        $this->compile('native-class-dynamic-static-magic-method.php');
    }

    public function testRejectsDynamicMagicMethodInjectedByTrait(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__serialize()`');
        $this->compile('native-class-trait-dynamic-magic-method.php');
    }

    public function testDynamicMagicMethodDenyListIsComplete(): void
    {
        $trait = new \ReflectionClass(\TypePhp\NativeClass\NativeClassSupportTrait::class);
        $constant = $trait->getReflectionConstant('UNSUPPORTED_NATIVE_MAGIC_METHODS');
        $this->assertNotFalse($constant);
        $this->assertSame([
            '__call',
            '__callstatic',
            '__get',
            '__set',
            '__isset',
            '__unset',
            '__sleep',
            '__wakeup',
            '__serialize',
            '__unserialize',
            '__set_state',
            '__debuginfo',
        ], array_keys($constant->getValue()));
    }

    public function testRejectsStaticMethodInjectedByTrait(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static methods are not supported');
        $this->compile('native-class-trait-static-method.php');
    }

    public function testRejectsMissingInternalInterfaceMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement method `Countable::count()`');
        $this->compile('native-class-internal-interface-missing-method.php');
    }

    public function testRejectsNonPublicInternalInterfaceMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must be compatible with `Countable::count()`');
        $this->compile('native-class-internal-interface-visibility.php');
    }

    public function testRejectsNarrowedInternalInterfaceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must be compatible with `ArrayAccess::offsetExists()`');
        $this->compile('native-class-internal-interface-parameter.php');
    }

    public function testRejectsExplicitNativeConstructorCall(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Explicit calls to native object constructors are not supported');
        $this->compile('native-class-explicit-constructor-call.php');
    }

    public function testRejectsInternalInterfaceThatPhpClassesCannotImplement(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('cannot implement internal interface `Throwable`');
        $this->compile('native-class-non-implementable-interface.php');
    }

    public function testCountRequiresCountableContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('count() requires a native class implementing Countable');
        $this->compile('native-class-count-without-countable.php');
    }

    public function testRejectsNativeLooseEquality(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects do not support the `==` operator; use `===` or `!==` for identity comparison');
        $this->compile('native-class-loose-equality.php');
    }

    public function testRejectsNativeArithmeticOperators(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects do not support the `+` operator');
        $this->compile('native-class-arithmetic-operator.php');
    }

    public function testRejectsNativeUnaryOperators(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects do not support the unary `-` operator');
        $this->compile('native-class-unary-operator.php');
    }

    public function testRejectsIncrementingNativePointerSlots(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects do not support the `++` operator');
        $this->compile('native-class-increment.php');
    }

    public function testRejectsNativeCompoundAssignments(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects do not support the `+=` operator');
        $this->compile('native-class-compound-assignment.php');
    }

    public function testRejectsIncompatibleNativeCoalesceAssignment(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot assign native object `NativeCoalesceWrong` to `NativeCoalesceExpected`');
        $this->compile('native-class-coalesce-assign-type.php');
    }

    public function testRejectsIncompatibleNativePropertyCoalesceAssignment(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot assign object of class `NativeCoalescePropertyWrong` to object property `value` of class `NativeCoalescePropertyExpected`');
        $this->compile('native-class-coalesce-property-type.php');
    }

    public function testRejectsNativeSwitchConditions(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as switch values');
        $this->compile('native-class-switch.php');
    }

    public function testRejectsNativeSwitchCaseValues(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as switch values');
        $this->compile('native-class-switch-case.php');
    }

    public function testRejectsNativeArrayLiteralKeys(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as PHP array keys');
        $this->compile('native-class-array-key.php');
    }

    public function testRejectsNativeArrayDimensionKeys(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be used as PHP array keys');
        $this->compile('native-class-array-dim-key.php');
    }

    public function testRejectsArrayAccessWithoutArrayAccessInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement `ArrayAccess` to use array access syntax');
        $this->compile('native-class-array-access.php');
    }

    public function testRejectsArrayWritesWithoutArrayAccessInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement `ArrayAccess` to use array access syntax');
        $this->compile('native-class-array-access-write.php');
    }

    public function testRejectsArrayIssetWithoutArrayAccessInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement `ArrayAccess` to use array access syntax');
        $this->compile('native-class-array-access-isset.php');
    }

    public function testRejectsArrayUnsetWithoutArrayAccessInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement `ArrayAccess` to use array access syntax');
        $this->compile('native-class-array-access-unset.php');
    }

    public function testRejectsNativeArrayAccessCompoundModification(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Indirect modification of Native ArrayAccess elements is not supported');
        $this->compile('native-class-array-access-compound.php');
    }

    public function testRejectsNativeArrayAccessIncrement(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Indirect modification of Native ArrayAccess elements is not supported');
        $this->compile('native-class-array-access-increment.php');
    }

    public function testRejectsNativeArrayAccessNestedWrite(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Indirect modification of Native ArrayAccess elements is not supported');
        $this->compile('native-class-array-access-nested-write.php');
    }

    public function testRejectsNativeArrayAccessPropertyWrite(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Indirect modification of Native ArrayAccess elements is not supported');
        $this->compile('native-class-array-access-property-write.php');
    }

    public function testRejectsNativeArrayAccessReferences(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('References to Native ArrayAccess elements are not supported');
        $this->compile('native-class-array-access-reference.php');
    }

    public function testRejectsNativeArrayAccessCoalesceAssignment(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Indirect modification of Native ArrayAccess elements is not supported');
        $this->compile('native-class-array-access-coalesce-assign.php');
    }

    public function testRejectsInaccessibleNativeCloneMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Call to private NativePrivateClone::__clone()');
        $this->compile('native-class-private-clone.php');
    }

    public function testRejectsNativeObjectReferenceParameterBeforeVirtualAbiGeneration(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object parameters cannot be passed by reference');
        $this->compile('native-class-virtual-byref-variance.php');
    }

    public function testRejectsNamedArgumentHoleInNativeVirtualCall(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Named calls to Native virtual methods cannot skip an earlier optional parameter');
        $this->compile('native-class-virtual-named-gap.php');
    }

    public function testRejectsLateStaticConstructionInNativeClass(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support `new static()`');
        $this->compile('native-class-new-static.php');
    }

    public function testRejectsGetCalledClassInNativeClass(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding');
        $this->compile('native-class-get-called-class.php');
    }

    public function testRejectsQualifiedGetCalledClassInNativeClass(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding');
        $this->compile('native-class-get-called-class-qualified.php');
    }

    public function testRejectsNamespacedGetCalledClassInNativeClass(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding');
        $this->compile('native-class-get-called-class-namespaced.php');
    }

    public function testAllowsCompiledNamespacedGetCalledClassShadowInNativeClass(): void
    {
        $this->compile('native-class-get-called-class-shadow.php');
        $this->addToAssertionCount(1);
    }

    public function testRejectsGetClassForNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support runtime class introspection');
        $this->compile('native-class-get-class.php');
    }

    public function testRejectsNamespacedGetClassForNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support runtime class introspection');
        $this->compile('native-class-get-class-namespaced.php');
    }

    public function testRejectsImplicitGetClassInNativeMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support runtime class introspection');
        $this->compile('native-class-get-class-implicit.php');
    }

    public function testRejectsGetParentClassForNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support runtime class introspection');
        $this->compile('native-class-get-parent-class.php');
    }

    public function testRejectsNamespacedGetParentClassForNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support runtime class introspection');
        $this->compile('native-class-get-parent-class-namespaced.php');
    }

    public function testAllowsCompiledNamespacedGetClassShadowsInNativeClass(): void
    {
        $this->compile('native-class-get-class-shadow.php');
        $this->addToAssertionCount(1);
    }

    public function testRejectsChangingAnInferredNativeGlobalType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native global/static slot cannot change from `NativeGlobalFirst` to `NativeGlobalSecond`');
        $this->compile('native-class-global-type-change.php');
    }

    public function testRejectsLateStaticConstantResolutionInNativeClass(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding; use `self::` or a concrete class name');
        $this->compile('native-class-late-static-constant.php');
    }

    public function testRejectsLateStaticNativeMethodSignature(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding in return types');
        $this->compile('native-class-static-signature.php');
    }

    public function testRejectsLateStaticSignatureInjectedIntoNativeClassByTrait(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support late static binding in return types');
        $this->compile('native-class-trait-static-signature.php');
    }

    public function testRejectsInaccessibleNativeClassConstant(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Constant `NativePrivateConstantOwner::VALUE` is not accessible');
        $this->compile('native-class-private-constant-access.php');
    }

    /**
     * @dataProvider inaccessibleNativeMethodProvider
     */
    public function testRejectsInaccessibleNativeMethods(string $fixture): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Method');
        $this->expectExceptionMessage('is not accessible');
        $this->compile($fixture);
    }

    public static function inaccessibleNativeMethodProvider(): array
    {
        return [
            ['native-class-private-method-access.php'],
            ['native-class-protected-method-access.php'],
        ];
    }

    public function testRejectsNativeObjectCastToZendObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be converted to Zend objects');
        $this->compile('native-class-object-cast.php');
    }

    public function testRejectsErasingNativeObjectTypeWithAny(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be converted to mixed with std::any()');
        $this->compile('native-class-any-escape.php');
    }

    public function testRejectsBareReturnForNullableNativeObjectType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('A function with a Native object return type must return a value');
        $this->compile('native-class-bare-return.php');
    }

    public function testRejectsNullForNonNullableNativeObjectReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('The return type is non-nullable native object `NativeNullReturnValue`');
        $this->compile('native-class-null-return.php');
    }

    public function testRejectsNativeObjectGeneratorParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Generator functions cannot accept, capture, or return Native objects');
        $this->compile('native-class-generator-parameter.php');
    }

    public function testRejectsNativeObjectGeneratorMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Generator functions cannot accept, capture, or return Native objects');
        $this->compile('native-class-generator-method.php');
    }

    public function testRejectsNativeObjectConstructionInsideGenerator(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be created inside Generator functions');
        $this->compile('native-class-generator-local.php');
    }

    public function testRejectsNativeObjectRetainedByGeneratorFromFactory(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Generator functions cannot retain Native objects across suspension');
        $this->compile('native-class-generator-factory.php');
    }

    public function testRejectsYieldingNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be yielded through a Zend Generator');
        $this->compile('native-class-generator-yield.php');
    }

    public function testRejectsNativeClassReflection(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class `NativeReflectionClassValue` cannot be used with ReflectionClass');
        $this->compile('native-class-reflection-class.php');
    }

    public function testRejectsNativeMemberReflection(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class `NativeReflectionMemberValue` cannot be used with ReflectionMethod');
        $this->compile('native-class-reflection-member.php');
    }

    public function testRejectsThrowingNativeObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be thrown as Zend exceptions');
        $this->compile('native-class-throw.php');
    }

    public function testRejectsIteratingNativeObjectWithoutIteratorContract(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class `NativeForeachValue` must implement `Iterator` or `IteratorAggregate` to use foreach');
        $this->compile('native-class-foreach.php');
    }

    public function testRejectsNativeIteratorForeachByReference(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native Iterator foreach does not support references');
        $this->compile('native-class-foreach-by-reference.php');
    }

}
