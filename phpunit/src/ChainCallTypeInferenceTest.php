<?php

use TypePhp\CompilerTest;

/**
 * Chained calls keep the receiver's static type while the declared return type
 * names a class or interface contract. These tests pin the generated C++ shape
 * (direct call vs. runtime dispatch) and the linear cost of long chains.
 */
final class ChainCallTypeInferenceTest extends BaseTest
{
    public function testConcreteChainUsesDirectCalls(): void
    {
        $body = $this->functionBody('php_chaintype__column__run');

        self::assertStringContainsString('php_chaintype__column__make(', $body);
        self::assertStringContainsString('php_chaintype__column__gap(', $body);
        self::assertStringContainsString('php_chaintype__column__padding(', $body);
        self::assertStringContainsString('php_chaintype__column__describe(', $body);
        self::assertStringNotContainsString('typephp_call_method_cached', $body);
    }

    public function testLateStaticReturnTypeChainUsesDirectCalls(): void
    {
        $body = $this->functionBody('php_chaintype__staticcolumn__runstatic');

        self::assertStringContainsString('php_chaintype__staticcolumn__make(', $body);
        self::assertStringContainsString('php_chaintype__staticcolumn__add(', $body);
        self::assertStringContainsString('php_chaintype__staticcolumn__total(', $body);
        self::assertStringNotContainsString('typephp_call_method_cached', $body);
    }

    public function testInterfaceContractKeepsTypeAndResumesDirectDispatch(): void
    {
        $body = $this->functionBody('php_chaintype__columnstyles__runstyled');

        // The interface hop itself is polymorphic and must stay dynamic.
        self::assertStringContainsString('php_chaintype__column__styled(', $body);
        self::assertSame(1, substr_count($body, 'typephp_call_method_cached'));
        // The contract returns a concrete class, so the next hop is direct again.
        self::assertStringContainsString('php_chaintype__border__width(', $body);
        self::assertStringContainsString('php_chaintype__border__describe(', $body);
    }

    public function testOverriddenMethodKeepsRuntimeDispatchAndFinalMethodStaysDirect(): void
    {
        $body = $this->functionBody('php_chaintype__polymorphicchain__runpolymorphic');

        self::assertStringContainsString('php_chaintype__basefactory__make(', $body);
        self::assertSame(1, substr_count($body, 'typephp_call_method_cached'));
        self::assertStringContainsString('php_chaintype__basefactory__sealed(', $body);
    }

    public function testLateStaticReturnTypeIsAcceptedForSubclassProperty(): void
    {
        // `LsbSubFactory::make()` inherits `: static`, so the result is
        // LsbSubFactory; assigning it to `public LsbSubFactory $prop` was
        // rejected at compile time before the late-bound fix.
        $body = $this->functionBody('php_chaintype__lsbholder__runlsb');

        self::assertStringContainsString('php_chaintype__staticfactory__make(', $body);
        self::assertStringContainsString('typephp_write_property_scoped(this_', $body);
    }

    public function testDeepChainCompilesInLinearTime(): void
    {
        $elapsed = 0.0;
        $code = $this->compileFixture($elapsed);
        self::assertSame(1, preg_match(
            '/php::\w+ php_chaintype__deepchain__rundeep\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        $body = $matches['body'];

        self::assertSame(19, preg_match_all('/php_chaintype__deepchain__m\d+\(/', $body));
        self::assertSame(0, substr_count($body, 'typephp_call_method_cached'));
        // Regression guard: the previous implementation was exponential in
        // chain length (18 links took ~29s); linear stays well under a second.
        self::assertLessThan(10.0, $elapsed, 'Chain compilation must stay linear in chain length');
    }

    private function functionBody(string $function): string
    {
        $code = $this->compileFixture();
        self::assertSame(1, preg_match(
            '/php::\w+ ' . preg_quote($function, '/') . '\(.*?\) \{(?<body>.*?)\n\}/s',
            $code,
            $matches,
        ));
        return $matches['body'];
    }

    private function compileFixture(?float &$elapsed = null): string
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/chain-call-type-inference.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $start = microtime(true);
        $generated = $compiler->convertFile($source);
        $elapsed = microtime(true) - $start;
        $code = file_get_contents($generated);
        self::assertIsString($code);
        return $code;
    }
}
