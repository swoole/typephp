<?php

use TypePhp\CompilerTest;
use TypePhp\Type;

final class ConstantTypePropagationTest extends BaseTest
{
    public function testGlobalLiteralSlotsUseTheirCppValueTypes(): void
    {
        [$compiler, $code] = $this->compileFixture();
        $constants = (new ReflectionProperty($compiler, 'constants'))->getValue($compiler);
        foreach (['LIMIT' => Type::INT, 'MASK' => Type::INT, 'RATIO' => Type::FLOAT,
            'LABEL' => Type::STR, 'ENABLED' => Type::BOOL, 'ITEMS' => Type::VAR] as $name => $type) {
            self::assertSame($type, $constants['_const_var_ConstantInference__' . $name]->type, $name);
        }
        self::assertStringNotContainsString('php::Var', $this->body($code, 'compare'));
        self::assertStringNotContainsString('php::Var', $this->body($code, 'pack'));
    }

    public function testClassAndInheritedInterfaceLiteralsUseNativeTemporaries(): void
    {
        [, $code] = $this->compileFixture();
        foreach (['values__pack', 'values__scale', 'values__enabled', 'values__label'] as $name) {
            $body = $this->body($code, $name);
            self::assertStringNotContainsString('php::Var', $body, $name);
            self::assertStringNotContainsString('throwReturnTypeError', $body, $name);
        }
    }

    public function testLoweredConstantTypesSurviveMissingInitializerAst(): void
    {
        [$compiler] = $this->compileFixture();
        $constant = $compiler->getClassDef('ConstantInference\\Values')->getConstant('LIMIT');
        $constant->valueExpr = null;
        $constant = unserialize(serialize($constant));
        $compiler->getClassDef('ConstantInference\\Values')->constants['LIMIT'] = $constant;
        $fetch = new \PhpParser\Node\Expr\ClassConstFetch(
            new \PhpParser\Node\Name\FullyQualified('ConstantInference\\Values'),
            'LIMIT',
        );
        $method = new ReflectionMethod($compiler, 'parseClassConstFetch');
        self::assertMatchesRegularExpression('/^4L+$/', $method->invoke($compiler, $fetch));
        self::assertSame(Type::INT, $fetch->getAttribute('aotLoweredType'));
        self::assertNull($constant->valueExpr);
    }

    public function testRuntimeLookupAndLateBindingKeepDynamicStorage(): void
    {
        [, $code] = $this->compileFixture();
        self::assertStringContainsString('php::constant(', $this->body($code, 'values__combined'));
        self::assertStringContainsString('php::constant(', $this->body($code, 'values__latebound'));
    }

    public function testBigintConstantKeepsVariantStorage(): void
    {
        $this->assertPayloadStorage('bigint_types', 'INTEGER', Type::BIGINT);
    }

    public function testDecimalConstantKeepsVariantStorage(): void
    {
        $this->assertPayloadStorage('decimal_types', 'FRACTION', Type::DECIMAL);
    }

    private function assertPayloadStorage(string $option, string $name, string $payloadType): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/build/phpunit/constant-payload-' . $option . '.php';
        if (!is_dir(dirname($source))) {
            mkdir(dirname($source), 0777, true);
        }
        file_put_contents($source, str_replace(
            'namespace ConstantPayload;',
            'namespace ConstantPayload;' . "\nuse {$option};",
            file_get_contents(TYPEPHP_ROOT_PATH . '/phpunit/code/constant-payload-storage.php'),
        ));
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $constants = (new ReflectionProperty($compiler, 'constants'))->getValue($compiler);
        self::assertSame(Type::VAR, $constants['_const_var_ConstantPayload__' . $name]->type);
        $constant = $compiler->getClassDef('ConstantPayload\\Values')->getConstant($name);
        self::assertSame(Type::VAR, $constant->type);
        self::assertSame($payloadType, $constant->loweredType);
    }

    private function body(string $code, string $name): string
    {
        self::assertSame(1, preg_match('/php::\w+ php_constantinference__' . preg_quote($name, '/')
            . '\(.*?\) \{(?<body>.*?)\n\}/s', $code, $matches));
        return $matches['body'];
    }

    private function compileFixture(): array
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/constant-type-propagation.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        return [$compiler, file_get_contents($compiler->convertFile($source))];
    }
}
