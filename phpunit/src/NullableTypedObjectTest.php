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

    public function testKnownTypedPathsHaveTheSameGeneratedBodiesWithOrWithoutNullability(): void
    {
        $code = $this->compileFixture();
        self::assertSame($this->body($code, 'typedidentity'), $this->body($code, 'nullabletypedidentity'));
        self::assertSame($this->body($code, 'typedidentity'), $this->body($code, 'nullabletypedunion'));
        self::assertSame($this->body($code, 'typedcall'), $this->body($code, 'nullabletypedcall'));
        self::assertSame(
            str_replace('php_typedidentity(', 'callee(', $this->body($code, 'typedforward')),
            str_replace('php_nullabletypedidentity(', 'callee(', $this->body($code, 'nullabletypedforward')),
        );
    }

    public function testOnlyUnprovenNonNullConversionsRetainRuntimeChecks(): void
    {
        $code = $this->compileFixture();
        self::assertStringNotContainsString('php::toObject(', $this->body($code, 'typedforward'));
        foreach (['nullabletypedpasstononnull', 'nullabletypedreturnnonnull', 'typedrebound'] as $function) {
            self::assertSame(1, substr_count($this->body($code, $function), 'php::toObject('));
        }
    }

    public function testKnownPropertyWritesDoNotAddObjectTemporariesOrChecks(): void
    {
        $code = $this->compileFixture();
        $normalize = static fn (string $body): string => preg_replace(
            '/PersistentPropertyId\{\d+\}|get_str\(\d+\)/', 'id', $body,
        );
        self::assertSame(
            $normalize($this->body($code, 'typedpropertyassign')),
            $normalize($this->body($code, 'nullabletypedpropertyassign')),
        );
    }

    private function body(string $code, string $function): string
    {
        self::assertSame(1, preg_match('/(?:php::\w+|void) php_' . $function . '\([^\n]*\) \{\n(.*?)\n\}/s', $code, $matches));
        return $matches[1];
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
