<?php

use TypePhp\CompilerTest;

final class SelectionTypeInferenceTest extends BaseTest
{
    public function testObjectCoalesceKeepsItsDispatchClass(): void
    {
        $body = $this->body('selectionproperty');
        self::assertStringContainsString('php::Object object;', $body);
        self::assertStringContainsString('php_selectiontypevalue__value(object)', $body);
        self::assertStringNotContainsString('typephp_call_method_cached(', $body);
    }

    public function testScalarCoalesceKeepsNativeStorage(): void
    {
        $body = $this->body('selectionscalar');
        self::assertStringContainsString('php::Int value', $body);
        self::assertStringNotContainsString('throwReturnTypeError', $body);
    }

    public function testPropertyPresenceReadIsSilentAndOccursOnce(): void
    {
        foreach (['selectionscalarproperty', 'selectionuninitialized', 'selectionnullsafeproperty'] as $name) {
            $body = $this->body($name);
            self::assertSame(1, substr_count($body, 'php::AttrMode::Isset'));
            self::assertStringNotContainsString('.attrInt(', $body);
            self::assertStringNotContainsString('php::AttrMode::Get', $body);
        }
    }

    public function testCoalesceProbesTrailingPropertiesButReadsMethodReceivers(): void
    {
        $body = $this->body('selectionnullsafenestedproperty');
        self::assertSame(2, substr_count($body, 'php::AttrMode::Isset'));
        self::assertStringNotContainsString('php::AttrMode::Get', $body);
        $body = $this->body('selectionnullsaferequiredmethod');
        self::assertStringContainsString('php::AttrMode::Get', $body);
        self::assertStringNotContainsString('php::AttrMode::Isset', $body);
    }

    public function testNullsafeScalarCoalesceUsesOneChainAndDirectCall(): void
    {
        $body = $this->body('selectionnullsafescalar');
        self::assertSame(1, substr_count($body, ' = [&]()'));
        self::assertStringContainsString('php_selectiontyperoot__count(', $body);
        self::assertStringNotContainsString('typephp_call_method_cached(', $body);
        self::assertStringNotContainsString('throwReturnTypeError', $body);
    }

    public function testNullsafeObjectResultsKeepTheirClass(): void
    {
        $body = $this->body('selectionnullsafeobject');
        self::assertStringContainsString('php::Object object;', $body);
        self::assertStringContainsString('php_selectiontyperoot__next(', $body);
        self::assertStringContainsString('php_selectiontypevalue__value(object)', $body);
        self::assertStringNotContainsString('typephp_call_method_cached(', $body);
        $body = $this->body('selectionnullsafelocal');
        self::assertStringContainsString('php::Object object;', $body);
        self::assertStringContainsString('php_selectiontypevalue__value(', $body);
    }

    public function testPolymorphismAndUnsafeNormalHopKeepRuntimeDispatch(): void
    {
        $body = $this->body('selectionpolymorphic');
        self::assertStringContainsString('typephp_call_method_cached(', $body);
        self::assertStringNotContainsString('php_selectiontypebase__count(', $body);
        $body = $this->body('selectionnormalhop');
        self::assertStringContainsString('typephp_call_method_cached(', $body);
        self::assertStringNotContainsString('php_selectiontypevalue__constantvalue(', $body);
    }

    public function testNullsafeDirectCallRetainsReferenceArguments(): void
    {
        $body = $this->body('selectionreferences');
        self::assertStringContainsString('php_selectiontyperoot__record(root, events)', $body);
        self::assertStringNotContainsString('php::VarList{events}', $body);
    }

    private function body(string $name): string
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/selection-type-inference.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertSame(1, preg_match('/php::\w+ php_' . $name . '\(.*?\) \{(?<body>.*?)\n\}/s', $code, $matches));
        return $matches['body'];
    }
}
