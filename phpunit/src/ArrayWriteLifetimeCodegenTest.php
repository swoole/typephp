<?php

use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerTest;

final class ArrayWriteLifetimeCodegenTest extends BaseTest
{
    public static function receiverFixtures(): iterable
    {
        foreach (['reentrant-write-lifetime', 'array-access-write-evaluation', 'array-access-write-temporary-receiver',
            'array-access-compound-writes', 'array-access-object-key-writes', 'assignment-operand-snapshots'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('receiverFixtures')]
    public function testRegressionProgramConvertsAllReceiverKinds(string $name): void
    {
        global $translator;
        $contents = file_get_contents(TYPEPHP_ROOT_PATH . '/tests/compiler/array/' . $name . '.phpt');
        $source = explode('--EXPECT--', explode('--FILE--', $contents, 2)[1], 2)[0];
        $directory = TYPEPHP_ROOT_PATH . '/tmp/array-write-lifetime-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $file = $directory . '/fixture.php';
        file_put_contents($file, trim($source));
        try {
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $compiler->addFiles([$file]);
            $compiler->prepareFile($file);
            self::assertFileExists($compiler->convertFile($file));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testNestedSlotsAreBorrowedAfterTheRhsButKeysAreEvaluatedBeforeIt(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/array-write-rhs-lifetime.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $code = file_get_contents($compiler->convertFile($file));
        self::assertIsString($code);
        foreach (['assign', 'compound'] as $method) {
            $start = strpos($code, 'void php_reentrantwriteholder__' . $method . '(');
            self::assertIsInt($start);
            $end = strpos($code, "\n}", $start);
            self::assertIsInt($end);
            $body = substr($code, $start, $end - $start);
            $outer = strpos($body, 'php_writeouterkey()');
            $inner = strpos($body, 'php_writeinnerkey()');
            $rhs = strpos($body, 'php_reentrantwriteholder__rhs(');
            self::assertIsInt($outer);
            self::assertIsInt($inner);
            self::assertIsInt($rhs);
            self::assertLessThan($inner, $outer);
            self::assertLessThan($rhs, $inner);
            preg_match_all('/\.item\([^\n;]*, true\)/', $body, $matches, PREG_OFFSET_CAPTURE);
            self::assertNotEmpty($matches[0]);
            foreach ($matches[0] as [, $offset]) {
                self::assertGreaterThan($rhs, $offset, 'A borrowed array bucket must not survive the RHS callback');
            }
        }
        $start = strpos($code, 'void php_reentrantwriteholder__variablekeys(');
        self::assertIsInt($start);
        $end = strpos($code, "\n}", $start);
        self::assertIsInt($end);
        $body = substr($code, $start, $end - $start);
        preg_match_all('/key =/', $body, $assignments, PREG_OFFSET_CAPTURE);
        self::assertCount(2, $assignments[0]);
        preg_match('/php::Var tmp_var_\d+ = php::copyValue\(key\);/', $body, $capture, PREG_OFFSET_CAPTURE);
        self::assertNotEmpty($capture);
        self::assertGreaterThan($assignments[0][1][1], $capture[0][1], 'Variable keys must be read after the RHS');
    }
}
