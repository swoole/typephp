<?php

class AssignTest extends \BaseTest
{
    // === Existing tests ===

    public function testReAssign()
    {
        $this->exec('Cannot re-assign `$obj` from `php::Str` to `php::Object`', 're-assign.php');
    }

    public function testReAssignNumericValueWithoutUsingNativeTypes()
    {
        $this->exec('Cannot re-assign `$x` from `php::Array` to `php::Int', "re-assign-numeric-without-using-native-types.php");
    }

    public function testAssignClass()
    {
        $this->exec('Cannot re-assign typed object `$obj1` from `stdClass` to `ArrayObject`', 're-assign-2.php');
    }

    public function testCannotAssignParentObjectToChildTypedObject()
    {
        $this->exec(
            'Cannot re-assign typed object `$child` from `TypedObjectAssignChild` to `TypedObjectAssignBase`',
            're-assign-parent-to-child-object.php'
        );
    }

    public function testCanAssignNullToTypedObject()
    {
        $this->compile('typed-object-unset-assign-null.php');
    }

    public function testCannotAssignUnrelatedObjectToInterfaceDeclaredObject()
    {
        $this->exec(
            'Cannot re-assign typed object `$object` from `InterfaceDeclaredAssignContract` to `InterfaceDeclaredAssignOther`',
            'interface-declared-object-mismatch.php'
        );
    }

    public function testCannotPassUnrelatedObjectToInterfaceParameter()
    {
        $this->exec(
            'Argument `object` must be an instance of `InterfaceParamMismatchContract`, `InterfaceParamMismatchOther` given',
            'interface-param-mismatch.php'
        );
    }

    public function testCannotReturnUnrelatedObjectFromInterfaceReturn()
    {
        $this->exec(
            'The return type is `InterfaceReturnMismatchContract`, cannot return an instance of `InterfaceReturnMismatchOther`',
            'interface-return-mismatch.php'
        );
    }

    public function testStdContainerAcceptsSubclassValue()
    {
        $this->compile('std-container-static-class-mismatch.php');
    }

    // === Object value assigned to non-object variable (right side is New_ expr) ===

    public function testObjectToInt()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Object` to `php::Int`", 're-assign-obj-to-int.php');
    }

    public function testObjectToFloat()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Object` to `php::Float`", 're-assign-obj-to-float.php');
    }

    public function testObjectToBool()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Object` to `php::Bool`", 're-assign-obj-to-bool.php');
    }

    public function testObjectToStr()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Object` to `php::Str`", 're-assign-obj-to-str.php');
    }

    public function testObjectToArray()
    {
        $this->exec("Cannot re-assign `\$obj` from `php::Object` to `php::Array`", 're-assign-obj-to-array.php');
    }

    // === Non-object value assigned to Object variable ===

    public function testIntToObject()
    {
        $this->exec("Cannot re-assign `\$obj` from `php::Int` to `php::Object`", 're-assign-int-to-obj.php');
    }

    public function testFloatToObject()
    {
        $this->exec("Cannot re-assign `\$obj` from `php::Float` to `php::Object`", 're-assign-float-to-obj.php');
    }

    public function testBoolToObject()
    {
        $this->exec("Cannot re-assign `\$obj` from `php::Bool` to `php::Object`", 're-assign-bool-to-obj.php');
    }

    public function testArrayToObject()
    {
        $this->exec("Cannot re-assign `\$obj` from `php::Array` to `php::Object`", 're-assign-array-to-obj.php');
    }

    public function testCanAssignSubclassToTypedObjectProperty()
    {
        $this->compile('object-prop-subclass-mismatch.php');
    }

    public function testCanPassExternalLibrarySubclassToParentParameter()
    {
        $this->compile('external-library-subclass-param.php');
    }

    // === Str / Array value assigned to non-object scalar variable ===

    public function testStrToInt()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Str` to `php::Int`", 're-assign-str-to-int.php');
    }

    public function testArrayToInt()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Array` to `php::Int`", 're-assign-array-to-int.php');
    }

    public function testStrToFloat()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Str` to `php::Float`", 're-assign-str-to-float.php');
    }

    public function testArrayToFloat()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Array` to `php::Float`", 're-assign-array-to-float.php');
    }

    public function testStrToBool()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Str` to `php::Bool`", 're-assign-str-to-bool.php');
    }

    public function testArrayToBool()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Array` to `php::Bool`", 're-assign-array-to-bool.php');
    }

    // === Scalar value assigned to Array variable ===

    public function testIntToArray()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Int` to `php::Array`", 're-assign-int-to-array.php');
    }

    public function testFloatToArray()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Float` to `php::Array`", 're-assign-float-to-array.php');
    }

    public function testBoolToArray()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Bool` to `php::Array`", 're-assign-bool-to-array.php');
    }

    public function testStrToArray()
    {
        $this->exec("Cannot re-assign `\$x` from `php::Str` to `php::Array`", 're-assign-str-to-array.php');
    }

    public function testStrictTypesZeroNotAllowed()
    {
        $this->exec("declare(strict_types=0) is not allowed, only strict_types=1 is supported", 'declare-strict-types-zero.php');
    }

    /** @dataProvider nativeScalarArrayDimWriteProvider */
    public function testNativeScalarArrayDimWriteFailsInTypePhp(string $file): void
    {
        $this->exec('Cannot use [] for numbers', $file);
    }

    public static function nativeScalarArrayDimWriteProvider(): iterable
    {
        yield 'native int append' => ['native-int-array-dim-write.php'];
        yield 'native float keyed write' => ['native-float-array-dim-write.php'];
        yield 'native bool append' => ['native-bool-array-dim-write.php'];
        yield 'explicit std::int keyed write' => ['explicit-native-int-array-dim-write.php'];
    }
}
