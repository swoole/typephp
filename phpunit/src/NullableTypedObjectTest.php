<?php

use TypePhp\CompilerTest;
use TypePhp\Type;

final class NullableTypedObjectTest extends BaseTest
{
    public function testNullableClassDeclarationsKeepTheirObjectAbiAndClass(): void
    {
        $code = $this->compileFixture();
        foreach (['nullabletypedidentity', 'nullabletypedunion'] as $name) {
            self::assertStringContainsString('php::Object php_' . $name . '(php::Object target)', $code);
        }
        self::assertStringContainsString('php::Object target;', $code);
        self::assertStringContainsString('php_nullabletypedtarget__value(target)', $code);
        self::assertStringNotContainsString('if (UNEXPECTED(!target.isObject()))', $code);
        self::assertStringNotContainsString('Call to a member function %s() on null', $code);
    }

    public function testScalarOtherUnionsAndReferencesKeepTheirExistingAbi(): void
    {
        $code = $this->compileFixture();
        self::assertStringContainsString('php::Var php_nullabletypedscalar(php::Var number)', $code);
        self::assertStringContainsString('php::Var php_nullabletypedotherunion(php::Var target)', $code);
        self::assertStringContainsString('void php_nullabletypedreference(php::Ref target)', $code);
    }

    public function testNullablePropertyDeclarationsKeepTheClassAndNullability(): void
    {
        $this->compileFixture();
        global $translator;
        $class = $translator->getClassDef('NullableTypedHolder');
        self::assertNotNull($class);
        foreach (['target', 'union', 'promoted'] as $name) {
            $property = $class->getProperty($name);
            self::assertSame(Type::OBJECT, $property->type);
            self::assertSame('NullableTypedTarget', $property->class);
            self::assertTrue($property->nullable);
        }
    }

    private function compileFixture(): string
    {
        global $translator;
        $translator = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/nullable-typed-object.php';
        $translator->addFiles([$source]);
        $translator->prepareFile($source);
        $generated = $translator->convertFile($source);
        return file_get_contents($generated);
    }
}
