<?php

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;
use PHPUnit\Framework\TestCase;

class InheritanceErrorTest extends TestCase
{
    private function exec(string $expected, string $file): void
    {
        try {
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $testFile = __DIR__ . '/../code/' . $file;
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
        } catch (TestError $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            return;
        }
        $this->fail('Expected TestError exception was not thrown');
    }

    private function assertCompiles(string $file): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testParameterCountMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error.php');
    }

    public function testParameterTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_type.php');
    }

    public function testReturnTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_return.php');
    }

    public function testReturnTypeCannotBeContravariant()
    {
        $this->exec('must be compatible', 'inheritance_error_return_contravariant_class.php');
    }

    public function testUnionReturnTypeCannotBeWidenedToUnrelatedType(): void
    {
        $this->exec('must be compatible', 'inheritance_error_return_union_widened.php');
    }

    public function testIntersectionReturnTypeCannotDropAMember(): void
    {
        $this->exec('must be compatible', 'inheritance_error_return_intersection_missing.php');
    }

    public function testDnfReturnTypeCannotBeWidened(): void
    {
        $this->exec('must be compatible', 'inheritance_error_dnf_return_widened.php');
    }

    public function testStaticReturnTypeCannotBeWidenedToSelf(): void
    {
        $this->exec('must be compatible', 'inheritance_error_return_static_widened.php');
    }

    public function testNeverReturnTypeCannotBeWidenedToVoid(): void
    {
        $this->exec('must be compatible', 'inheritance_error_return_never_widened.php');
    }

    public function testGeneratorReturnTypeCannotBeWidenedToIterable(): void
    {
        $this->exec('must be compatible', 'inheritance_error_generator_return_widened.php');
    }

    public function testIntersectionReturnTypeCanNarrowToIntersectionOrConcreteSubtype(): void
    {
        $this->assertCompiles('return_type_covariance_intersection.php');
    }

    public function testParameterTypeCannotBeCovariant()
    {
        $this->exec('must be compatible', 'inheritance_error_param_covariant_class.php');
    }

    public function testDnfParameterTypeCannotBeNarrowed(): void
    {
        $this->exec('must be compatible', 'inheritance_error_dnf_param_narrowed.php');
    }

    public function testUnionParameterCannotNarrowUntypedParent()
    {
        $this->exec('must be compatible', 'interface_param_union_narrows_untyped.php');
    }

    public function testUnionParameterCannotNarrowMixedParent()
    {
        $this->exec('must be compatible', 'interface_param_union_narrows_mixed.php');
    }

    public function testByRefMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_byref.php');
    }

    public function testTrailingVariadicMayAbsorbParentParameter()
    {
        // Zend accepts a trailing child variadic standing in for the remaining
        // parent parameter positions (zend_do_perform_implementation_check).
        $this->assertCompiles('inheritance_error_variadic.php');
    }

    public function testMethodVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_visibility_narrow.php');
    }

    public function testMethodVisibilityWideningIsAllowed()
    {
        $this->assertCompiles('inheritance_error_visibility.php');
    }

    public function testParentScopeMayAccessChildProtectedMethod()
    {
        $this->assertCompiles('inheritance_protected_child_method_access.php');
    }

    public function testParentScopeMayAccessChildProtectedConstant()
    {
        $this->assertCompiles('inheritance_protected_child_const_access.php');
    }

    public function testMethodStaticMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_static.php');
    }

    public function testCannotOverrideFinalMethod()
    {
        $this->exec('Cannot override final method', 'inheritance_error_final_method.php');
    }

    public function testCannotOverrideFinalPropertyHook(): void
    {
        $this->exec(
            'Cannot override final property hook FinalPropertyHookParent::$value::get()',
            'inheritance_error_final_property_hook.php',
        );
    }

    public function testCannotOverrideFinalPropertySetHook(): void
    {
        $this->exec(
            'Cannot override final property hook FinalPropertySetHookParent::$value::set()',
            'inheritance_error_final_property_set_hook.php',
        );
    }

    public function testCannotOverrideFinalProperty(): void
    {
        $this->exec(
            'Cannot override final property FinalPropertyParent::$value',
            'inheritance_error_final_property.php',
        );
    }

    public function testCannotOverrideFinalHookedProperty(): void
    {
        $this->exec(
            'Cannot override final property FinalHookedPropertyParent::$value',
            'inheritance_error_final_hooked_property.php',
        );
    }

    public function testPrivateSetPropertyIsImplicitlyFinal(): void
    {
        $this->exec(
            'Cannot override final property PrivateSetPropertyParent::$value',
            'inheritance_error_private_set_property.php',
        );
    }

    public function testPromotedPrivateSetPropertyIsImplicitlyFinal(): void
    {
        $this->exec(
            'Cannot override final property PromotedPrivateSetParent::$value',
            'inheritance_error_promoted_private_set_property.php',
        );
    }

    public function testCannotOverrideFinalPromotedProperty(): void
    {
        $this->exec(
            'Cannot override final property FinalPromotedPropertyParent::$value',
            'inheritance_error_final_promoted_property.php',
        );
    }

    public function testInterfaceMethodStaticMismatch()
    {
        $this->exec('must be compatible', 'interface_method_static_mismatch.php');
    }

    public function testChildMayAddOptionalTrailingParameter()
    {
        $this->assertCompiles('inheritance_optional_param_allowed.php');
    }

    public function testChildMayDeclareReturnTypeWhenParentHasNone()
    {
        $this->assertCompiles('inheritance_return_type_covariant_from_none.php');
    }

    public function testChildReturnTypeMayBeCovariant()
    {
        $this->assertCompiles('inheritance_return_type_covariant_class.php');
    }

    public function testChildParameterTypeMayBeContravariant()
    {
        $this->assertCompiles('inheritance_parameter_type_contravariant_class.php');
    }

    public function testPropertyTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_type.php');
    }

    public function testPropertyVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_visibility.php');
    }

    public function testPropertyVisibilityMayBeWidened()
    {
        $this->assertCompiles('inheritance_prop_visibility_widen.php');
    }

    public function testPrivateParentPropertyCannotBeRedeclared()
    {
        $this->exec('property shadowing across inheritance is not allowed', 'inheritance_private_prop_redeclare.php');
    }

    public function testPrivateParentPropertyCannotBeShadowedByPublicProperty()
    {
        $this->exec('property shadowing across inheritance is not allowed', 'accessibility/private-prop-in-parent.php');
    }

    public function testPrivateTraitPropertyCannotBeShadowedByPublicProperty()
    {
        $this->exec('property shadowing across inheritance is not allowed', 'accessibility/private-prop-in-trait.php');
    }

    public function testConstantTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_const_type.php');
    }

    public function testTypedConstantCannotBeOverriddenWithoutDeclaredType()
    {
        $this->exec('must be compatible', 'inheritance_error_const_missing_type.php');
    }

    public function testFinalConstantCannotBeOverridden()
    {
        $this->exec('Cannot override final constant', 'inheritance_error_const_final.php');
    }

    public function testConstantVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_const_visibility.php');
    }

    public function testConstantVisibilityMayBeWidened()
    {
        $this->assertCompiles('inheritance_const_visibility_widen.php');
    }

    public function testPrivateParentConstantMayBeRedeclared()
    {
        $this->assertCompiles('inheritance_private_const_redeclare.php');
    }

    public function testPropertyReadonlyMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_readonly.php');
    }

    public function testInterfaceMethodMissing()
    {
        $this->exec('must implement method', 'interface_method_missing.php');
    }

    public function testInterfaceMethodSignatureMismatch()
    {
        $this->exec('must be compatible', 'interface_method_signature_mismatch.php');
    }

    public function testInterfaceMethodProvidedByTrait()
    {
        $this->assertCompiles('interface_method_from_trait.php');
    }

    public function testAbstractClassMayDeferInterfaceMethodImplementation()
    {
        $this->assertCompiles('interface_abstract_class_missing.php');
    }

    public function testAbstractMethodMayImplementInterfaceContract()
    {
        $this->assertCompiles('interface_abstract_method_signature.php');
    }

    public function testAbstractMethodWithReferenceParameter()
    {
        // 抽象方法的按引用参数签名应被正确识别，基类构造中向未定义变量按引用传参不报错
        $this->assertCompiles('abstract_method_byref.php');
    }

    public function testAbstractMethodWithReferenceParameterAcrossNamespace()
    {
        // 跨命名空间的抽象方法按引用参数签名应被正确识别（使用完全限定类名解析）
        $this->assertCompiles('abstract_method_byref_namespace.php');
    }

    public function testInterfaceMethodWithReferenceParameter()
    {
        // 接口的按引用方法签名应被正确识别
        $this->assertCompiles('abstract_method_byref_interface.php');
    }

    public function testInterfaceTypedReceiverWithReferenceParameter()
    {
        // 接口类型接收者必须从接口及其父接口解析按引用参数签名。
        $this->assertCompiles('abstract_method_byref_interface_typed.php');
    }

    public function testAbstractMethodWithReferenceParameterMultilevel()
    {
        // 多级继承下，沿父类链查找抽象方法的按引用参数签名
        $this->assertCompiles('abstract_method_byref_multilevel.php');
    }

    public function testAbstractInterfaceMethodSignatureMismatch()
    {
        $this->exec('must be compatible', 'interface_abstract_method_mismatch.php');
    }

    public function testInterfaceArrayConstantInitializesRuntimeValue()
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/interface_array_constant.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $extensionFile = $compiler->genExtension();

        $this->assertStringContainsString('php::updateConstant("InterfaceArrayConstant", "ITEMS"', file_get_contents($extensionFile));
    }

    public function testInterfaceArrayConstantPropagatesToImplementingClass()
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/interface_array_constant_implements.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $extensionFile = $compiler->genExtension();

        $this->assertStringContainsString('class_const__interfacearrayconstantcontract__ITEMS', file_get_contents($cppFile));
        $this->assertStringContainsString('php::updateConstant("InterfaceArrayConstantImpl", "ITEMS"', file_get_contents($extensionFile));
    }

    public function testConcreteClassMustImplementInheritedAbstractMethod()
    {
        $this->exec('must implement abstract method', 'abstract_parent_method_missing.php');
    }

    public function testConcreteClassMustImplementInheritedInterfaceMethod()
    {
        $this->exec('must implement method', 'interface_abstract_parent_missing.php');
    }

    public function testCompatibleTraitMemberDuplicatesCompile()
    {
        $this->assertCompiles('trait_member_compatible.php');
    }

    public function testTraitConstantConflict()
    {
        $this->exec('constant `VALUE` conflicts', 'trait_constant_conflict.php');
    }

    public function testTraitPropertyConflict()
    {
        $this->exec('property `count` conflicts', 'trait_property_conflict.php');
    }

    public function testAbstractMethodSignatureMismatch()
    {
        $this->exec('must be compatible', 'abstract_method_signature_mismatch.php');
    }

    public function testTraitMethodMustBeCompatibleWithParent()
    {
        // A trait method flattened into a class must remain signature-compatible
        // with any same-named parent method, just like a directly-declared
        // override. Without this check the incompatibility only surfaces as a
        // runtime fatal error that the compiled binary would otherwise ignore.
        $this->exec('must be compatible', 'trait-method-override-incompatible.php');
    }

    public function testTraitMethodCannotOverrideFinalParentMethod()
    {
        $this->exec('Cannot override final method', 'trait-method-override-final.php');
    }

    public function testTraitParentCallRequiresParentClass()
    {
        $this->exec('does not extend any class', 'trait-parent-without-parent.php');
    }
}
