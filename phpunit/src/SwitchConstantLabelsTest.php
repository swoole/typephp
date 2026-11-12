<?php

use TypePhp\CompilerTest;

final class SwitchConstantLabelsTest extends BaseTest
{
    public function testProvenIntegerConstantsUseNativeSwitchAndUnsafeLabelsKeepPhpComparison(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/switch-constant-labels.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertSame(1, preg_match('/php::Int php_switchconstantlabels__native\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertStringContainsString('switch (', $match[1]);
        self::assertMatchesRegularExpression('/case 2L{1,2}:/', $match[1]);
        self::assertStringNotContainsString('php::equals(', $match[1]);
        self::assertSame(1, preg_match('/php::Str php_switchconstantlabels__arithmetic\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertMatchesRegularExpression('/case 2L{1,2}:/', $match[1]);
        self::assertStringNotContainsString('php::equals(', $match[1]);
        foreach (['duplicate', 'boolean', 'text'] as $name) {
            self::assertSame(1, preg_match('/php::Str php_switchconstantlabels__' . $name . '\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
            self::assertStringContainsString('php::equals(', $match[1], $name);
            self::assertStringNotContainsString('switch (', $match[1], $name);
        }
    }
}
