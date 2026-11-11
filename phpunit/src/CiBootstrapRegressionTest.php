<?php
final class CiBootstrapRegressionTest extends BaseTest
{
    public function testClosureRelativeTypesNeverBecomeNamespacedSelfClasses(): void
    {
        global $translator;
        $this->compile('closure-relative-types.php');
        $extension = file_get_contents($translator->genExtension());
        $code = file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/closure-relative-types.php'));
        self::assertStringContainsString('php::Object left = php::Object(', $code);
        self::assertStringNotContainsString('ClosureRelativeTypes\\\\self', $extension);
        self::assertStringNotContainsString('ClosureRelativeTypes\\\\parent', $extension);
    }

    public function testImmutableClosureCanReturnAScalarFromItsParameter(): void
    {
        global $translator;
        $this->compile('immutable-closure-scalar-return.php');
        $code = file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/immutable-closure-scalar-return.php'));
        self::assertStringContainsString('php::Object value = php::Object(', $code);
    }

    public function testImmutableClosureStillRejectsObjectResultsEscaping(): void
    {
        $this->expectException(\TypePhp\Exception\TestError::class);
        $this->expectExceptionMessage('Immutable object `$value` cannot escape through a return value');
        $this->compile('immutable-closure-object-return.php');
    }

    public function testNativeArrayPropertyIsTheRootOfPresenceOperations(): void
    {
        global $translator;
        $this->compile('native-property-array-presence.php');
        $code = file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/native-property-array-presence.php'));
        self::assertStringNotContainsString('php::exists(this_,', $code);
        self::assertStringNotContainsString('php::notEmpty(this_,', $code);
        self::assertStringContainsString('php::exists(this_.values,', $code);
    }
}
