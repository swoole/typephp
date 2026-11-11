<?php

final class ObjectCompositeTypeTest extends BaseTest
{
    public function testObjectUnionsAndIntersectionsUseAnObjectHandleWithoutSelectingOneMember(): void
    {
        $code = $this->generated();
        foreach (['objectcompositeunion', 'objectcompositeintersection', 'objectcompositednf'] as $name) {
            self::assertStringContainsString('php::Object php_' . $name . '(php::Object value)', $code);
        }
        self::assertStringContainsString('php::Object copy;', $code);
        self::assertStringContainsString('php::Object result;', $code);
        self::assertStringNotContainsString('php_objectcompositeboth__left(value)', $code);
        self::assertStringContainsString('throwArgumentTypeError(', $code);
        self::assertStringContainsString('throwReturnTypeError(', $code);
        self::assertStringContainsString('php_objectcompositeunion_arg_0_default_value()', $code);
    }

    public function testMixedUnionsAndReferenceParametersKeepTheVariantAbi(): void
    {
        $code = $this->generated();
        self::assertStringContainsString('php::Var php_objectcompositemixed(php::Var value)', $code);
        self::assertStringContainsString('void php_objectcompositeref(php::Ref value)', $code);
        self::assertStringContainsString('php::Object value = php::Object(', $code);
    }

    private function generated(): string
    {
        global $translator;
        $this->compile('object-composite-types.php');
        return file_get_contents($translator->getCppFile(TYPEPHP_ROOT_PATH . '/phpunit/code/object-composite-types.php'));
    }
}
