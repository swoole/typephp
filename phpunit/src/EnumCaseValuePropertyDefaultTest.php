<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;

/**
 * PHP 8.2+ constant expressions may fetch `->value` and `->name` from enum
 * cases (php-src "Fetch properties of enums in constant expressions"). The
 * stub generator must fold those fetches into scalar property defaults the
 * same way the declaration evaluator does; every other property fetch in a
 * constant expression stays rejected.
 * @internal
 * @coversNothing
 */
final class EnumCaseValuePropertyDefaultTest extends PHPUnit\Framework\TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $this->testRoot = sys_get_temp_dir() . '/typephp-enum-case-propdefault-' . bin2hex(random_bytes(8));
        mkdir($this->testRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->testRoot)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->testRoot);
    }

    /** @dataProvider foldedDefaultProvider */
    public function testEnumCaseFetchFoldsIntoPropertyDefault(string $source, string $expectedInitCode): void
    {
        $compiler = $this->compilerFor($source);
        $file = $this->testRoot . '/program.php';
        $compiler->prepareFile($file);
        $compiler->convertFile($file);

        $header = file_get_contents($compiler->getArgInfoHeaderFile($file));
        self::assertIsString($header);
        self::assertStringContainsString($expectedInitCode, $header);
    }

    public static function foldedDefaultProvider(): iterable
    {
        yield 'int-backed ->value folds to negative backing value' => [
            <<<'PHP'
<?php
enum TextRole: int { case Unset = -1; case User = 1; }
class RoleHolder { private int $role = TextRole::Unset->value; }
function main(): void {}
PHP,
            'ZVAL_LONG(&property_role_default_value, -1);',
        ];
        yield 'string-backed ->value folds to backing string' => [
            <<<'PHP'
<?php
enum Level: string { case Guest = 'guest'; }
class LevelHolder { public string $level = Level::Guest->value; }
function main(): void {}
PHP,
            'zend_string_init_interned("guest", sizeof("guest") - 1, 1);',
        ];
        yield '->name folds to the case name string' => [
            <<<'PHP'
<?php
enum Suit { case Hearts; }
class SuitHolder { public string $suit = Suit::Hearts->name; }
function main(): void {}
PHP,
            'zend_string_init_interned("Hearts", sizeof("Hearts") - 1, 1);',
        ];
    }

    public function testNonEnumPropertyFetchRemainsRejected(): void
    {
        $compiler = $this->compilerFor(<<<'PHP'
<?php
class Container { public const DATA = ['key' => 1]; }
class Broken { public int $value = Container::DATA->key; }
function main(): void {}
PHP);
        $file = $this->testRoot . '/program.php';
        $compiler->prepareFile($file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a global, non-magic constant, Expr_PropertyFetch given');
        $compiler->convertFile($file);
    }

    private function compilerFor(string $source): CompilerTest
    {
        $file = $this->testRoot . '/program.php';
        file_put_contents($file, $source);

        global $translator;
        $compiler = CompilerTest::create($this->testRoot);
        $translator = $compiler;
        $compiler->addFiles([$file]);
        return $compiler;
    }
}
