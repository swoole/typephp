<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

/**
 * @internal
 * @coversNothing
 */
class NativePropertyTest extends BaseTest
{
    private function compileNativeProperty(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        $this->addToAssertionCount(1);
        return TYPEPHP_ROOT_PATH . '/build/phpunit/code/' . basename($file, '.php') . '.cc';
    }

    public function testFindNativePropertyUsesFullClassNameAcrossBranches(): void
    {
        try {
            $this->compileNativeProperty('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testStaticStaticPropertyUsesDynamicCalledClassPath(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString(
            'zend_class_entry *const _typephp_called_ce = typephp_get_called_ce(this_);',
            $code,
        );
        $this->assertStringContainsString(
            'typephp_get_static_property_slot(_typephp_called_ce, get_str(',
            $code,
        );
        $this->assertStringNotContainsString('typephp_get_called_class(this_)', $code);
        $this->assertStringContainsString('= php::toInt(value);', $code);
    }

    public function testNativeIntPropertyAssignOpUsesNativeReference(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-assign-op-int.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php::Int &_object_prop_this___value = this_.attrInt(', $code);
        $this->assertStringContainsString('php::Int &_object_prop_box__value = Z_LVAL_P(box.attr(', $code);
        $this->assertMatchesRegularExpression('/_object_prop_this___value \+= \(2L+\);/', $code);
        $this->assertMatchesRegularExpression('/_object_prop_box__value \+= \(2L+\);/', $code);
        $this->assertSame(1, substr_count($code, 'Z_LVAL_P('));
        $this->assertStringNotContainsString('typephp_write_property_scoped(', $code);
    }

    public function testReadonlyPropertiesDoNotUseNativeScalarReferences(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('readonly-property-no-native-ref.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringNotContainsString('typephp_static_int_ref(', $code);
        $this->assertStringNotContainsString('typephp_static_float_ref(', $code);
        $this->assertStringNotContainsString('_object_prop_', $code);
        $this->assertStringNotContainsString('AttrMode::Update', $code);
        $this->assertSame(2, substr_count($code, 'typephp_write_property_scoped('));
    }

    public function testNativeIntPropertyAssignOpConvertsBitwiseNotClassConst(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-assign-op-class-const.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php::Int &_object_prop_this___flags = this_.attrInt(', $code);
        // The constant folds to 1L, but its unknown static type selects Zend
        // bitwise not. Check the property write after the dynamic operation.
        $this->assertMatchesRegularExpression('/\(~php::Var\(1L+\)\)/', $code);
        $this->assertStringContainsString('typephp_write_property_scoped(this_', $code);
        $this->assertStringNotContainsString('_object_prop_this___flags &= ', $code);
    }

    public function testNativePropertyWriteConvertsOnlyWhenTypesDiffer(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-write-conversion.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString(' = nativeValue;', $code);
        $this->assertStringContainsString(' = php::toIntExact(dynamicValue, "NativePropertyWriteConversionBox::$value");', $code);
        $this->assertStringContainsString(' = php::toStringExact(dynamicName, "NativePropertyWriteConversionBox::$name");', $code);
        $this->assertStringContainsString(' = php::toArrayExact(dynamicItems, "NativePropertyWriteConversionBox::$items");', $code);
        $this->assertStringNotContainsString(' = php::toInt(nativeValue);', $code);
        $this->assertStringNotContainsString('php::toString(([&]() -> php::Var', $code);
        $this->assertStringNotContainsString('php::toArray(([&]() -> php::Var', $code);
    }

    public function testNativeThisPropertyWriteUsesExactHelperOnNativeReference(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-this-write-conversion.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php::Int &_object_prop_this___value = this_.attrInt(', $code);
        $this->assertStringContainsString('_object_prop_this___value = php::toIntExact(dynamicValue, "NativePropertyThisWriteConversionBox::$value");', $code);
    }

    public function testUnsetTypedPropertyDisablesSlotHoisting(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-unset-disables-hoist.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringNotContainsString('_object_prop_this___value', $code);
        $this->assertStringContainsString('this_.attr(', $code);
    }

    public function testUnsetObjectDisablesPropertySlotsButKeepsNativeMethodCall(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-method-unset-keeps-optimization.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringNotContainsString('_object_prop_object__value', $code);
        $this->assertStringContainsString('object.attr(', $code);
        $this->assertStringContainsString('php_nativemethodunsetkeepsoptimization__read(object)', $code);
    }

    public function testNativePropertyStaticScalarTypeMismatchFailsAtCompileTime(): void
    {
        $this->exec(
            'Cannot assign string to property NativePropertyStaticTypeMismatchBox::$value of type int',
            'native-property-static-type-mismatch.php'
        );
    }

    public function testCannotAccessPrivateNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access private property `value` of class `NativePrivateOwner`', 'native-property-private-other-class.php');
    }

    public function testNativeClassCannotHideParentPrivateProperty(): void
    {
        $this->exec(
            'Declaration of `NativePrivateShadowChild::$value` conflicts with private property '
                . '`NativePrivateShadowParent::$value`; property shadowing across inheritance is not allowed',
            'native-property-private-shadow.php',
        );
    }

    public function testCannotWritePromotedPrivateSetNativePropertyOutsideDeclaringClass(): void
    {
        $this->exec(
            'Cannot modify private(set) property `NativePromotedPrivateSetExternalWrite::$value`',
            'native-promoted-private-set-external-write.php',
        );
    }

    public function testCannotAccessProtectedNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access protected property `value` of class `NativeProtectedOwner`', 'native-property-protected-unrelated-class.php');
    }

    public function testCannotAccessPrivateNativePropertyThroughNullsafe(): void
    {
        $this->exec('Cannot access private property `value` of class `NullsafePrivateOwner`', 'nullsafe-private-property.php');
    }

    public function testCannotAccessNestedPrivateNativePropertyThroughNullsafe(): void
    {
        $this->exec('Cannot access private property `value` of class `NullsafeNestedChild`', 'nullsafe-nested-private-property.php');
    }

    public function testCannotAssignThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign.php');
    }

    public function testCannotUseCompoundAssignThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign-op.php');
    }

    public function testCannotIncrementThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-inc.php');
    }

    public function testCannotUnsetThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-unset.php');
    }

    public function testCannotAssignReferenceToNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign-ref-left.php');
    }

    public function testCannotTakeReferenceOfNullsafeProperty(): void
    {
        $this->exec('Cannot take reference of a nullsafe chain', 'nullsafe-write-assign-ref-right.php');
    }

    public function testNativePropertyWritesAndCompoundAssignsConvertLoweredVariantStorage(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-write-lowered-storage.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('_object_prop_this___number += (php::toInt(', $code);
        $this->assertStringContainsString('_object_prop_this___amount += (php::toFloat(', $code);
        $this->assertStringContainsString('typephp_static_int_ref(_typephp_static_property_0().direct_ptr()) += (php::toInt(', $code);
        $this->assertStringContainsString('typephp_static_float_ref(_typephp_static_property_1().direct_ptr()) += (php::toFloat(', $code);
        $this->assertMatchesRegularExpression('/_object_prop_this___number = php::toInt\(\(\(tmp_var_\d+\) \+ \(1L+\)\)\);/', $code);
        $this->assertMatchesRegularExpression('/_object_prop_this___amount = php::toFloat\(\(\(tmp_var_\d+\) \+ \(0\.5\)\)\);/', $code);
        $this->assertStringContainsString('_object_prop_this___number = php::toInt(-(source.attr(', $code);
        $this->assertMatchesRegularExpression('/_object_prop_this___number = php::toInt\(\(php::toBool\(cond\)\) \? \(source\.attr\(.+?\) : \(0L+\)\);/', $code);
        $this->assertMatchesRegularExpression('/_object_prop_this___number = php::toInt\(tmp_var_\d+\);/', $code);
    }

    public function testNativePropertyWriteLoweredStorageCompilesWithClang(): void
    {
        $cxx = $this->findCxxCompiler();
        if ($cxx === null) {
            $this->markTestSkipped('No C++ compiler found (clang++, g++, c++)');
        }

        $includeDirs = $this->resolveIncludeFlags();
        if ($includeDirs === null) {
            $this->markTestSkipped('php-config not found; cannot determine C++ include flags');
        }

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $compiler->setBuildMode(CompilerTest::BUILD_MODE_EXT);
        $compiler->setTargetName('test_native_prop_lowered');
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/native-property-write-lowered-storage.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convert([$source]);
        $ccFile = $compiler->convertFile($source);

        $cmd = escapeshellarg($cxx) . ' -fsyntax-only -std=c++20 ' . $includeDirs . ' ' . escapeshellarg($ccFile) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode, "C++ compilation failed:\n" . implode("\n", $output));
    }

    private function findCxxCompiler(): ?string
    {
        foreach (['clang++', 'g++', 'c++'] as $candidate) {
            $path = shell_exec('command -v ' . escapeshellarg($candidate));
            if ($path !== null && trim($path) !== '') {
                return trim($path);
            }
        }
        return null;
    }

    private function resolveIncludeFlags(): ?string
    {
        $phpConfig = shell_exec('command -v php-config');
        if ($phpConfig === null || trim($phpConfig) === '') {
            return null;
        }
        $phpIncludes = trim((string) shell_exec('php-config --includes'));
        $flags = '-I build/include -I vendor/swoole/phpx/include ' .
            '-I vendor/swoole/phpx/thirdparty/mpdecimal/libmpdec++ ' .
            '-I vendor/swoole/phpx/thirdparty/mpdecimal/libmpdec ' .
            $phpIncludes;

        foreach (['gmp', 'mpfr', 'mpdecimal'] as $pkg) {
            $prefix = trim((string) @shell_exec('brew --prefix ' . escapeshellarg($pkg) . ' 2>/dev/null'));
            if ($prefix !== '' && is_dir($prefix . '/include')) {
                $flags .= ' -I ' . escapeshellarg($prefix . '/include');
            }
        }

        return $flags;
    }
}
