<?php

class ClassTest extends \BaseTest
{
    public function testImportAliasesUseIndependentPhpSymbolDomains(): void
    {
        $this->compile('namespace/import-alias-domains.php');
    }

    public function testZendToArrayDeclarationCannotAcceptParameters(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'Conversion method `ZendToArrayWithParameters::toArray()` must not accept arguments',
        );
        $this->compile('zend-class-to-array-parameters.php');
    }

    public function testZendToArrayDeclarationMustReturnArray(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'Conversion method `ZendToArrayWrongReturn::toArray()` must return exactly `array`',
        );
        $this->compile('zend-class-to-array-return-type.php');
    }

    public function testKnownZendClassWithoutToArrayUsesPropertyFallback(): void
    {
        $this->compile('zend-class-to-array-missing.php');
    }

    public function testKnownZendClassWithMagicCallStillUsesArrayConversion(): void
    {
        $this->compile('zend-class-to-array-magic.php');
    }

    public function testOrdinaryClassCannotDeclareToAnyKeywordMethod(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'Method name `toAny()` is reserved for a TypePHP keyword method and cannot be declared here',
        );
        $this->compile('reserved-to-any-method.php');
    }

    public function testOrdinaryClassCannotDeclareToRefKeywordMethodCaseInsensitively(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'Method name `TOREF()` is reserved for a TypePHP keyword method and cannot be declared here',
        );
        $this->compile('reserved-to-ref-method.php');
    }

    public function testInterfaceCannotDeclareToAnyKeywordMethod(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Method name `toAny()` is reserved for a TypePHP keyword method');
        $this->compile('reserved-keyword-interface-method.php');
    }

    public function testTraitCannotDeclareToRefKeywordMethod(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Method name `toRef()` is reserved for a TypePHP keyword method');
        $this->compile('reserved-keyword-trait-method.php');
    }

    public function testRuntimeAttributesSupportLiteralAndConstantArrays(): void
    {
        $this->compile('preprocessor/attribute_array_argument.php');
    }

    public function testRuntimeAttributesSupportNewExpressionArguments(): void
    {
        $this->compile('preprocessor/attribute_new_expression_argument.php');
    }

    public function testGlobalConstantAttributesAreForbidden(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Attributes on global constants are not supported by TypePHP');
        $this->compile('global-constant-attribute.php');
    }

    public function testGetterGeneratesPublicMethodsForInstanceProperties(): void
    {
        $this->compile('getter.php');
    }

    public function testGetterRejectsStaticProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter can only be applied to instance properties');
        $this->compile('getter-static-property.php');
    }

    public function testGetterRejectsNonPropertyTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter can only be applied to instance properties');
        $this->compile('getter-function.php');
    }

    public function testCompileTimeAttributeRejectsAliasesOfTheSameAttributeRepeated(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter cannot be repeated on the same declaration');
        $this->compile('compile-time-attribute-duplicate.php');
    }

    public function testGetterSupportsReadonlyProperties(): void
    {
        $this->compile('getter-readonly-property.php');
    }

    public function testGetterRejectsHookProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter cannot be applied to properties with hooks');
        $this->compile('getter-hook-property.php');
    }

    public function testSetterRejectsReadonlyProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Setter cannot be applied to readonly properties');
        $this->compile('setter-readonly-property.php');
    }

    public function testSetterRejectsHookProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Setter cannot be applied to properties with hooks');
        $this->compile('setter-hook-property.php');
    }

    public function testWithRejectsReadonlyPropertiesIncludingReadonlyClasses(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('With cannot be applied to readonly properties');
        $this->compile('with-readonly-property.php');
    }

    public function testWithRejectsHookProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('With cannot be applied to properties with hooks');
        $this->compile('with-hook-property.php');
    }

    public function testGeneratedMethodRejectsDeclaredMethodConflictCaseInsensitively(): void
    {
        $this->exec('Duplicate method `getName`', 'generated-method-declared-conflict.php');
    }

    public function testGeneratedMethodConflictDiagnosticPointsToAttributeAndDeclaration(): void
    {
        try {
            $this->compile('generated-method-declared-conflict.php');
            $this->fail('Expected generated Getter conflict');
        } catch (\TypePhp\Exception\TestError $error) {
            $file = realpath(__DIR__ . '/../code/generated-method-declared-conflict.php');
            $this->assertNotFalse($file);
            $message = $error->getMessage();
            $this->assertStringContainsString('compile-time attribute: #[Getter]', $message);
            $this->assertStringContainsString('target: property $name', $message);
            $this->assertStringContainsString('source: ' . $file . ':5', $message);
            $this->assertStringContainsString('conflict source: declaration at ' . $file . ':8', $message);
        }
    }

    public function testGeneratedMethodsRejectEachOtherCaseInsensitively(): void
    {
        $this->exec('Duplicate method `getName`', 'generated-method-generated-conflict.php');
    }

    public function testPrinterGeneratedMethodUsesNormalDuplicateMethodValidation(): void
    {
        $this->exec('Duplicate method `__toString`', 'printer-generated-method-conflict.php');
    }

    public function testArrayableGeneratedMethodUsesNormalDuplicateMethodValidation(): void
    {
        $this->exec('Duplicate method `toArray`', 'arrayable-generated-method-conflict.php');
    }

    public function testGeneratedMethodMayOverrideCompatibleParentMethod(): void
    {
        $this->compile('generated-method-parent-conflict.php');
    }

    public function testGeneratedMethodObeysFinalParentMethodRule(): void
    {
        $this->exec(
            'Cannot override final method `GeneratedMethodFinalConflictParent::withName()`',
            'generated-method-final-parent-conflict.php'
        );
    }

    public function testCompileTimeGeneratedPropertyMethodsPrinterAndNotNull(): void
    {
        $this->compile('compile_time_attributes.php');
    }

    public function testArrayableAndPrinterFieldSelection(): void
    {
        $this->compile('arrayable.php');
    }

    public function testArrayableRejectsNonClassTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Arrayable can only be applied to named classes');
        $this->compile('arrayable-invalid-target.php');
    }

    public function testArrayableAcceptsExplicitFieldsWithoutVisibilityFiltering(): void
    {
        $this->compile('arrayable-explicit-fields.php');
    }

    public function testArrayableRejectsDynamicFields(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Arrayable field `missing` must be a declared instance property accessible from the class'
        );
        $this->compile('arrayable-dynamic-field.php');
    }

    public function testArrayableAcceptsPublicAndProtectedParentFields(): void
    {
        $this->compile('arrayable-parent-visible-fields.php');
    }

    public function testPrinterAcceptsPrivateSelectedFields(): void
    {
        $this->compile('printer-private-fields.php');
    }

    public function testPrinterRejectsPrivateParentFields(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Printer field `secret` must be a declared instance property accessible from the class'
        );
        $this->compile('printer-parent-private-field.php');
    }

    public function testPrinterRejectsStaticFields(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Printer field `shared` must be a declared instance property accessible from the class'
        );
        $this->compile('printer-static-field.php');
    }

    public function testPrinterConvertsNonStringFieldsAndArrayablePreservesValues(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/printer-arrayable-field-types.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $code = file_get_contents($cppFile);

        $printerStart = strpos($code, 'php_printerarrayablefieldtypes____tostring');
        $arrayableStart = strpos($code, 'php_printerarrayablefieldtypes__toarray');
        $this->assertNotFalse($printerStart);
        $this->assertNotFalse($arrayableStart);
        $printerCode = substr($code, $printerStart, $arrayableStart - $printerStart);
        $arrayableCode = substr($code, $arrayableStart);
        $this->assertSame(3, substr_count($printerCode, 'php::toString('));
        // The mixed field dispatches to a user-declared toString() at runtime.
        $this->assertSame(1, substr_count($printerCode, 'typephp_keyword_to_string('));
        $this->assertStringNotContainsString('php::toString(', $arrayableCode);
    }

    public function testArrayableRejectsNonArrayFields(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Arrayable $fields must be an array literal');
        $this->compile('arrayable-invalid-argument.php');
    }

    public function testNotNullRejectsNonParameterTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('NotNull can only be applied to function or method parameters');
        $this->compile('not-null-invalid-target.php');
    }

    public function testNotNullRejectsArrowFunctionParameters(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('NotNull is not supported on arrow function parameters');
        $this->compile('not-null-arrow-function.php');
    }

    public function testNotNullWarnsForExplicitlyNullableParameters(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $reporter = new class implements \TypePhp\Diagnostics\DiagnosticReporter {
            /** @var list<string> */
            public array $warnings = [];

            public function fatal(string $message): never
            {
                throw new \TypePhp\Exception\TestError($message);
            }

            public function warning(\PhpParser\Node $node, string $file, string $message): void
            {
                $this->warnings[] = $message;
            }
        };
        $compiler->setDiagnosticReporter($reporter);
        $testFile = __DIR__ . '/../code/not-null-nullable-warning.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);

        $this->assertSame([
            'NotNull is applied to nullable parameter `$value`',
            'NotNull is applied to nullable parameter `$value`',
            'NotNull is applied to nullable parameter `$value`',
        ], $reporter->warnings);
    }

    public function testParameterValidationUsesFixedSemanticOrder(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        $ast = $parser->parse(file_get_contents(__DIR__ . '/../code/parameter-validation-order.php'));
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver(null, ['replaceNodes' => false]));
        $traverser->addVisitor(new \TypePhp\Transform\Visitor());
        $stmts = $traverser->traverse($ast);
        $function = $stmts[0];

        $this->assertInstanceOf(\PhpParser\Node\Stmt\Function_::class, $function);
        $this->assertInstanceOf(\PhpParser\Node\Expr\BinaryOp\Identical::class, $function->stmts[0]->cond);
        $this->assertInstanceOf(\PhpParser\Node\Expr\Empty_::class, $function->stmts[1]->cond);
        $this->assertInstanceOf(\PhpParser\Node\Expr\BinaryOp\Identical::class, $function->stmts[2]->cond);
        $this->assertInstanceOf(\PhpParser\Node\Expr\FuncCall::class, $function->stmts[2]->cond->left);
        $this->assertSame('filter_var', strtolower($function->stmts[2]->cond->left->name->toString()));
    }

    public function testNotEmptyRejectsArrowFunctionParameters(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('NotEmpty is not supported on arrow function parameters');
        $this->compile('not-empty-arrow-function.php');
    }

    public function testValidateRejectsArrowFunctionParameters(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Validate is not supported on arrow function parameters');
        $this->compile('validate-arrow-function.php');
    }

    public function testValidateRejectsSanitizeFilters(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Validate only accepts FILTER_VALIDATE_* filters');
        $this->compile('validate-sanitize.php');
    }

    public function testValidateRejectsProvablyIncompatibleScalarType(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Validate filter FILTER_VALIDATE_EMAIL is incompatible with parameter `$email` declared as `int`'
        );
        $this->compile('validate-incompatible-email-int.php');
    }

    public function testValidateRejectsArrayWithoutArrayMode(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Validate filter FILTER_VALIDATE_INT is incompatible with parameter `$values` declared as `array`'
        );
        $this->compile('validate-incompatible-array.php');
    }

    public function testValidateAllowsCompatibleUnionAndExplicitArrayMode(): void
    {
        $this->compile('validate-compatible-types.php');
    }

    public function testValidateRejectsNonParameterTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Validate can only be applied to function or method parameters');
        $this->compile('validate-invalid-target.php');
    }

    public function testValidateUsesCentralDuplicateAttributeValidation(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Validate cannot be repeated on the same declaration');
        $this->compile('validate-duplicate.php');
    }

    public function testCompileTimeAttributeDiagnosticsContainTargetAndBothConflictSources(): void
    {
        try {
            $this->compile('validate-duplicate.php');
            $this->fail('Expected duplicate Validate diagnostic');
        } catch (\TypePhp\Exception\SyntaxError $error) {
            $message = $error->getMessage();
            $file = realpath(__DIR__ . '/../code/validate-duplicate.php');
            $this->assertNotFalse($file);
            $this->assertStringContainsString('target: parameter $value', $message);
            $this->assertStringContainsString('source: ' . $file . ':4', $message);
            $this->assertStringContainsString(
                'conflict source: #[Validate] at ' . $file . ':5',
                $message,
            );
        }
    }

    public function testLanguageCompileTimeAttributes(): void
    {
        $this->compile('language_attributes.php');
    }

    public function testMethodsForSupportsAliasesAndObjectTargets(): void
    {
        $this->compile('methods-for.php');
    }

    public function testMethodsForUsesKeywordClassHierarchyAndObjectFallbackPriority(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/methods-for-inheritance.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        $this->assertStringContainsString('php_hierarchykeywordmethods__keywordwins(', $cpp);
        $this->assertStringContainsString('php::echo(php_hierarchybase__realwins(child));', $cpp);
        $this->assertStringNotContainsString(
            'php::echo(php::toString(php_hierarchyobjectmethods__realwins(',
            $cpp,
        );
        $this->assertStringNotContainsString(
            'php::echo(php::toString(php_hierarchyobjectmethods__declaredmethod(',
            $cpp,
        );
        $this->assertStringContainsString('php_hierarchybasemethods__inheritedextension(', $cpp);
        $this->assertMatchesRegularExpression(
            '/php_hierarchychildmethods__nearestextension\([^,\n]+, child\)/',
            $cpp,
        );
        $this->assertMatchesRegularExpression(
            '/php_hierarchybasemethods__nearestextension\([^,\n]+, base\)/',
            $cpp,
        );
        $this->assertSame(
            3,
            substr_count($cpp, 'php::echo(php::toString(php_hierarchyobjectmethods__objectfallback('),
        );
    }

    public function testMethodsForRejectsKeywordAndTargetSpecificNameConflict(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'conflicts with keyword extension method *::inspect()'
        );
        $this->compile('methods-for-keyword-conflict.php');
    }

    public function testMethodsForKeywordConflictDoesNotDependOnDeclarationOrder(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'Keyword extension method *::inspect() conflicts with extension method php::str::inspect()'
        );
        $this->compile('methods-for-keyword-conflict-reversed.php');
    }

    public function testMethodsForRejectsInterfaceTargets(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage(
            'MethodsFor target InvalidMethodsForContract must be a class; interfaces are not supported'
        );
        $this->compile('methods-for-interface-target.php');
    }

    public function testHotAndColdFunctionAttributes(): void
    {
        $this->compile('hot-cold.php');
    }

    public function testHotAndColdCannotBeCombined(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Hot and Cold cannot be applied to the same function or method');
        $this->compile('hot-cold-conflict.php');
    }

    public function testHotRejectsNonFunctionTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Hot can only be applied to functions or methods');
        $this->compile('hot-invalid-target.php');
    }

    public function testMustUseRejectsDiscardedReturnValue(): void
    {
        $this->exec('must be used', 'must-use-discard.php');
    }

    public function testMustUseRejectsDiscardedMethodReturnValue(): void
    {
        $this->exec('must be used', 'must-use-method-discard.php');
    }

    public function testOverrideAcceptsParentInterfaceTraitAndNamespaceAliasMatches(): void
    {
        $this->compile('override-valid.php');
    }

    public function testOverrideAcceptsParentPropertiesIncludingPromotedAndHookedProperties(): void
    {
        $this->compile('override-property-valid.php');
    }

    public function testPropertyOverrideRequiresMatchingParentProperty(): void
    {
        $this->exec(
            'OverridePropertyMissing::$value has #[\\Override] attribute, but no matching parent class property exists',
            'override-property-missing.php',
        );
    }

    public function testPropertyOverrideCannotHidePrivateParentProperty(): void
    {
        $this->exec(
            'Declaration of `OverridePropertyPrivateChild::$value` conflicts with private property '
                . '`OverridePropertyPrivateParent::$value`; property shadowing across inheritance is not allowed',
            'override-property-private-parent.php',
        );
    }

    public function testPropertyOverrideIsRejectedOnInterfaceProperty(): void
    {
        $this->exec(
            'OverridePropertyInterface::$value has #[\\Override] attribute, '
                . 'but no matching parent class property exists',
            'override-property-interface.php',
        );
    }

    public function testPropertyOverrideOnTraitIsValidatedAtUseSite(): void
    {
        $this->exec(
            'OverridePropertyTraitConsumer::$value has #[\\Override] attribute, '
                . 'but no matching parent class property exists',
            'override-property-trait-missing.php',
        );
    }

    public function testOverrideRequiresMatchingParentMethod(): void
    {
        $this->exec(
            'OverrideMissing::missing() has #[\\Override] attribute, but no matching parent method exists',
            'override-missing.php',
        );
    }

    public function testOverrideNeverMatchesConstructor(): void
    {
        $this->exec(
            'OverrideConstructorChild::__construct() has #[\\Override] attribute, but no matching parent method exists',
            'override-constructor.php',
        );
    }

    public function testOverrideDoesNotMatchPrivateParentMethod(): void
    {
        $this->exec(
            'OverridePrivateChild::value() has #[\\Override] attribute, but no matching parent method exists',
            'override-attribute-private-parent.php',
        );
    }

    public function testOverrideOnTraitMethodIsValidatedAtUseSite(): void
    {
        $this->exec(
            'OverrideTraitConsumer::missing() has #[\\Override] attribute, but no matching parent method exists',
            'override-trait-missing.php',
        );
    }

    public function testOverrideOnRootInterfaceRequiresParentMethod(): void
    {
        $this->exec(
            'OverrideInterfaceMissing::missing() has #[\\Override] attribute, but no matching parent method exists',
            'override-interface-missing.php',
        );
    }

    public function testOverrideRejectsNonMethodOrPropertyTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Override can only be applied to methods or properties');
        $this->compile('override-invalid-target.php');
    }

    public function testOverrideRejectsArguments(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Override does not accept arguments');
        $this->compile('override-arguments.php');
    }

    public function testOverrideCannotBeRepeated(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Override cannot be repeated on the same declaration');
        $this->compile('override-duplicate.php');
    }

    public function testConstructorRejectsExistingConstructor(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Constructor cannot generate InvalidConstructor::__construct(): method is already declared',
        );
        $this->compile('constructor-existing.php');
    }

    public function testConstructorRejectsExistingConstructorRegardlessOfParameterOrder(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage(
            'Constructor cannot generate ReorderedConstructor::__construct(): method is already declared',
        );
        $this->compile('constructor-existing-reordered.php');
    }

    public function testConstructorRejectsStaticProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Constructor can only be applied to instance properties');
        $this->compile('constructor-static.php');
    }

    public function testConstructorCallsParentConstructorWithoutRequiredArguments(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/constructor-parent-optional.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);

        $this->assertStringContainsString(
            'this_.call(get_persistent_method(',
            file_get_contents($cppFile),
        );
    }

    public function testConstructorAllowsParentWithoutConstructor(): void
    {
        $this->compile('constructor-parent-none.php');
    }

    public function testConstructorRejectsParentConstructorWithRequiredArguments(): void
    {
        $this->exec(
            'parent constructor `ConstructorRequiredParent::__construct()` requires 1 argument(s)',
            'constructor-parent-required.php'
        );
    }

    public function testConstructorDoesNotCallPrivateParentConstructor(): void
    {
        $this->compile('constructor-parent-private.php');
    }

    public function testConstructorRejectsFinalParentConstructor(): void
    {
        $this->exec(
            'Cannot override final method `ConstructorFinalParent::__construct()`',
            'constructor-parent-final.php'
        );
    }

    public function testReAssignThis()
    {
        $this->exec('Cannot re-assign $this', 're-assign-this.php');
    }

    public function testAccessProtectedProperty()
    {
        $this->exec('Cannot access protected property `settings` of class `DevConfig`', 'protected-property.php');
    }

    public function testCannotWritePrivateSetPropertyOutsideDeclaringClass()
    {
        $this->exec('Cannot modify private(set) property', 'private-set-property.php');
    }

    public function testCannotWriteProtectedSetPropertyOutsideClassFamily()
    {
        $this->exec('Cannot modify protected(set) property', 'protected-set-property.php');
    }

    public function testCallAbstractParentMethod()
    {
        $this->exec('Cannot call abstract method `AbsBase::show()`', 'parent-abstract-method.php');
    }

    public function testNewAbstractClass()
    {
        $this->exec('abstract class `AbstractBase` cannot be instantiated', 'abstract-class-new.php');
    }

    public function testPrivateMethodMayBeRedeclared()
    {
        // Private methods are not inherited: Zend lets a child redeclare one
        // with any signature. Private calls bind to the declaring class's
        // copy, so each class keeps its own implementation.
        $this->compile('override-private-method.php');
    }

    public function testPromotedAsymmetricPropertyRequiresType(): void
    {
        $this->exec(
            'Property with asymmetric visibility PromotedAsymmetricUntyped::$value must have type',
            'promoted-asymmetric-untyped.php',
        );
    }

    public function testPromotedAsymmetricPropertyRejectsWiderSetVisibility(): void
    {
        $this->exec(
            'Visibility of property PromotedAsymmetricWiderSet::$value must not be weaker than set visibility',
            'promoted-asymmetric-wider-set.php',
        );
    }

    public function testFinalPromotedPropertyRequiresExplicitVisibility(): void
    {
        $this->exec(
            'Final promoted property must explicitly declare public, protected, or private visibility',
            'final-promoted-property-without-visibility.php',
        );
    }

    public function testTraitMayCallProtectedParentMethod()
    {
        // A protected parent method is reachable via parent:: from a trait,
        // matching PHP runtime behaviour.
        $this->compile('trait-parent-method-protected.php');
    }

    public function testCannotAccessPrivateParentMethodFromRegularClass()
    {
        $this->exec('Cannot access private method `Base::secret()`', 'parent-method-private.php');
    }

    public function testCannotAccessPrivateParentMethodFromTrait()
    {
        $this->exec('Cannot access private method `BaseSecret::secret()`', 'trait-parent-method-private.php');
    }

    public function testComposedTraitMethodMayShadowPrivateParentMethod()
    {
        // A trait-composed method redeclaring a parent's PRIVATE method is
        // valid in Zend, like any other private redeclaration.
        $this->compile('trait-method-shadows-private.php');
    }

    public function testSelfCanBePartOfUnionType()
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/union_type_self_allowed.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testParentCanBePartOfUnionType()
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/union_type_parent_allowed.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testSelfCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'self' cannot be part of an intersection type", 'intersection_type_self_not_allowed.php');
    }

    public function testParentCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'parent' cannot be part of an intersection type", 'intersection_type_parent_not_allowed.php');
    }

    public function testStaticCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'static' cannot be part of an intersection type", 'intersection_type_static_not_allowed.php');
    }

    public function testConstructorCannotDeclareReturnType()
    {
        $this->exec('Method `ConstructorReturnType::__construct()` cannot declare a return type', 'constructor-return-type.php');
    }

    public function testConstructorCannotReturnValue()
    {
        $this->exec('Method `ConstructorReturnValue::__construct()` cannot return a value', 'constructor-return-value.php');
    }

    public function testParentConstructorCannotBeUsedAsValue()
    {
        $this->compile('parent-constructor-used-as-value.php');
    }

    public function testParentConstructorCannotBeUsedAsArgument()
    {
        $this->compile('parent-constructor-used-as-argument.php');
    }

    public function testVoidExpressionCannotBeUsedAsBinaryOperand()
    {
        $this->compile('void-expression-binary-operand.php');
    }

    public function testVoidExpressionCannotBeUsedAsCondition()
    {
        $this->compile('void-expression-condition.php');
    }

    public function testVoidExpressionCannotBeUsedAsTernaryBranch()
    {
        $this->compile('void-expression-ternary-branch.php');
    }

    public function testVoidExpressionCannotBeUsedAsArrayValue()
    {
        $this->compile('void-expression-array-value.php');
    }

    public function testVoidExpressionCannotBeUsedAsMatchArm()
    {
        $this->compile('void-expression-match-arm.php');
    }

    public function testDestructorCannotDeclareReturnType()
    {
        $this->exec('Method `DestructorReturnType::__destruct()` cannot declare a return type', 'destructor-return-type.php');
    }

    public function testCloneReturnTypeMustBeVoid()
    {
        $this->exec('Method `CloneInvalidReturnType::__clone()` return type must be void when declared', 'clone-invalid-return-type.php');
    }

    public function testCallMagicMethodCannotBeStatic()
    {
        $this->exec('Method MagicCallStaticInvalid::__call() cannot be static', 'magic-call-static.php');
    }

    public function testCallStaticMagicMethodMustBeStatic()
    {
        $this->exec('Method MagicCallStaticNonStaticInvalid::__callStatic() must be static', 'magic-callstatic-nonstatic.php');
    }

    public function testToStringMagicMethodCannotTakeArguments()
    {
        $this->exec('Method MagicToStringArgsInvalid::__toString() must take exactly 0 arguments', 'magic-tostring-args.php');
    }

    public function testSetStateMagicMethodMustBeStatic()
    {
        $this->exec('Method MagicSetStateNonStaticInvalid::__set_state() must be static', 'magic-set-state-nonstatic.php');
    }

    public function testDestructMagicMethodCannotTakeArguments()
    {
        $this->exec('Method MagicDestructArgsInvalid::__destruct() must take exactly 0 arguments', 'magic-destruct-args.php');
    }

    public function testGetMagicMethodParameterMustBeString()
    {
        $this->exec('Method MagicGetParamTypeInvalid::__get() must take string as argument', 'magic-get-param-type.php');
    }

    public function testMagicMethodMustBePublic()
    {
        $this->exec('Method MagicGetProtectedInvalid::__get() must have public visibility', 'magic-get-protected.php');
    }

    public function testClassConstDefaultValue()
    {
        // 类常量（self:: / 类名:: / 完全限定名::，含继承自内部父类的常量）
        // 作为函数/方法默认参数值应当能够在编译期正确解析。
        $this->compile('class-const-default-value.php');
    }

    public function testClassConstantRejectsNonConstantExpression(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $this->compile('class-constant-invalid-expression.php');
    }

    public function testPropertyDefaultRejectsNonConstantExpression(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $this->compile('property-default-invalid-expression.php');
    }

    public function testClassConstantRejectsObjectCast(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Object casts are not supported in this context');
        $this->compile('class-constant-object-cast.php');
    }

    public function testPropertyDefaultRejectsObjectCast(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Object casts are not supported in this context');
        $this->compile('property-default-object-cast.php');
    }

    public function testPropertyDefaultArrayForIntTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use array as default value for property PropertyDefaultArrayForInt::$a of type int',
            'property-default-array-for-int.php'
        );
    }

    public function testPropertyDefaultStringForIntTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use string as default value for property PropertyDefaultStringForInt::$a of type int',
            'property-default-string-for-int.php'
        );
    }

    public function testPropertyDefaultNullForNonNullableIntFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use null as default value for property PropertyDefaultNullForInt::$a of type int',
            'property-default-null-for-int.php'
        );
    }

    public function testPropertyDefaultArrayForObjectTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use array as default value for property PropertyDefaultArrayForObject::$dep of type PropertyDefaultArrayForObjectDep',
            'property-default-array-for-object.php'
        );
    }

    public function testValidPropertyDefaultsCompile()
    {
        // 合法的默认值（含 int→float 协变、nullable、联合类型、mixed、常量）应通过检查。
        $this->compile('property-default-valid.php');
    }

    public function testTrueDefaultForFalsePropertyFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use true as default value for property PropertyDefaultTrueForFalse::$value of type false',
            'property-default-true-for-false.php'
        );
    }

    public function testFalseDefaultForTruePropertyFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use false as default value for property PropertyDefaultFalseForTrue::$value of type true',
            'property-default-false-for-true.php'
        );
    }

    public function testClassConstantPropertyDefaultTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use string as default value for property PropertyDefaultClassConstType::$value of type int',
            'property-default-class-const-type.php'
        );
    }

    public function testExpressionPropertyDefaultTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use float as default value for property PropertyDefaultExpressionType::$value of type int',
            'property-default-expression-type.php'
        );
    }
}
