<?php

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class EnumConstantFetchTest extends BaseTest
{
    public function testOnlyDeclaredCasesUseTheUncheckedEnumLookup(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/enum-constant-fetch.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);

        $validCase = $this->functionBody($code, 'validcase');
        self::assertSame(1, preg_match('/= (php::getEnumCase\(.+\));/', $validCase, $lookup));
        // An alias may inline its initializer, but the lookup must still name
        // the actual case, rather than the ALIAS constant itself.
        self::assertStringContainsString($lookup[1], $this->functionBody($code, 'aliasedcase'));
        self::assertStringContainsString('php::getEnumCase(', $this->functionBody($code, 'mixedcasetype'));
        self::assertStringNotContainsString('php::getEnumCase(', $this->functionBody($code, 'scalarconstant'));
        foreach (['arrayconstant', 'inheritedconstant', 'nullconstant'] as $function) {
            self::assertStringNotContainsString('php::getEnumCase(', $this->functionBody($code, $function));
        }
    }

    /** @dataProvider undefinedCaseProvider */
    public function testUndefinedCaseIsRejectedAtCompileTime(string $source, string $constant): void
    {
        global $translator;

        $root = sys_get_temp_dir() . '/typephp-missing-enum-case-' . bin2hex(random_bytes(8));
        mkdir($root);
        $file = $root . '/program.php';
        file_put_contents($file, "<?php\n" . $source);
        $compiler = CompilerTest::create($root);
        $translator = $compiler;
        try {
            $compiler->addFiles([$file]);
            $compiler->prepareFile($file);
            $this->expectException(TestError::class);
            $this->expectExceptionMessage("Class constant `{$constant}` not found in {$file}:");
            $compiler->convertFile($file);
        } finally {
            self::assertFileDoesNotExist($compiler->getCppFile($file));
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public static function undefinedCaseProvider(): iterable
    {
        yield 'reported nullable argument' => [
            'enum try_type { case t_int; } function trying(?try_type $type = null) {}'
                . ' function main(int $argc, array $argv): void { trying(try_type::try_type2); }',
            'try_type::try_type2',
        ];
        yield 'case-sensitive name' => [
            'enum Suit { case Hearts; } function main(): void { var_dump(Suit::hearts); }',
            'Suit::hearts',
        ];
        yield 'backed enum' => [
            'enum Suit: int { case Hearts = 1; } function main(): void { var_dump(Suit::Missing); }',
            'Suit::Missing',
        ];
        yield 'ordinary constant name is case-sensitive' => [
            'enum Suit { case Hearts; public const LABEL = "hearts"; }'
                . ' function main(): void { var_dump(Suit::label); }',
            'Suit::label',
        ];
        yield 'self in enum method' => [
            'enum Suit { case Hearts; public static function missing(): mixed { return self::Missing; } }'
                . ' function main(): void {}',
            'Suit::Missing',
        ];
        yield 'namespace and class alias' => [
            'namespace Report; use Report\Suit as Alias; enum Suit { case Hearts; }'
                . ' function main(): void { var_dump(Alias::Missing); }',
            'Report\Suit::Missing',
        ];
    }

    private function functionBody(string $code, string $name): string
    {
        preg_match('/php_' . $name . '\([^)]*\) \{[\s\S]*?\n\}/', $code, $matches);
        self::assertCount(1, $matches, "Missing body for {$name}");
        return $matches[0];
    }
}
