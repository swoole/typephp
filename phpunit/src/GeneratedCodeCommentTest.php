<?php

use TypePhp\CompilerTest;

final class GeneratedCodeCommentTest extends BaseTest
{
    private function compileProbe(bool $debug): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        if ($debug) {
            $property = new ReflectionProperty($compiler, 'debug');
            $property->setValue($compiler, true);
        }

        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/generated-code-comments.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        self::assertIsString($code);
        return $code;
    }

    private function compileFile(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        self::assertNotNull($generated);
        $code = file_get_contents($generated);
        self::assertIsString($code);
        return $code;
    }

    public function testReleaseCodeOmitsCompilerExplanatoryComments(): void
    {
        $code = $this->compileProbe(false);

        self::assertStringNotContainsString('// Stmt_', $code);
        self::assertStringNotContainsString('// Nullsafe Operator:', $code);
        self::assertStringNotContainsString('// Method Call:', $code);
    }

    public function testDebugCodeKeepsCompilerExplanatoryComments(): void
    {
        $code = $this->compileProbe(true);

        self::assertStringContainsString('// Stmt_', $code);
        self::assertStringContainsString('// Nullsafe Operator:', $code);
    }

    public function testDebugCodePreservesUtf8SourceComments(): void
    {
        foreach ([false, true] as $debug) {
            $code = $this->compileProbe($debug);
            self::assertTrue(mb_check_encoding($code, 'UTF-8'));
            if ($debug) {
                self::assertStringContainsString('// ★ 兵 入 先 中文。', $code);
            }
        }
    }

    public function testStatementFragmentsSplitOnlyPhysicalNewlines(): void
    {
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $compiler->setIndent('  ');
        $compiler->setIndentLevel(1);
        // Unicode line separators, vertical tabs and form feeds are comment
        // bytes, not physical C++ line boundaries. UTF-8 continuation bytes
        // must remain intact as well.
        $text = "★ 兵 入 先\u{0085}\u{2028}\u{2029}\v\f";
        $fragment = '// ' . $text . "\r\n// CR\r// LF\n// last\r\n";
        $formatted = (new ReflectionMethod($compiler, 'formatStatementFragment'))->invoke($compiler, $fragment);

        self::assertSame(
            '  // ' . $text . PHP_EOL . '  // CR' . PHP_EOL . '  // LF' . PHP_EOL . '  // last',
            $formatted,
        );
    }

    public function testCompileTimeUseDeclarationsDoNotEmitBlankLines(): void
    {
        $code = $this->compileFile('generated-code-use-spacing.php');

        self::assertDoesNotMatchRegularExpression('/0,};(?:[ \t]*\R){3,}/', $code);
    }
}
