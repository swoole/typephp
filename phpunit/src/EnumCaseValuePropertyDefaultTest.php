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

    /** @dataProvider expressionDefaultProvider */
    public function testFoldedExpressionUsesTheClassDefaultTable(string $declaration, string $expectedInitCode): void
    {
        $compiler = $this->compilerFor(<<<PHP
<?php
enum Code: int { case One = 1; }
class Holder { {$declaration} }
function main(): void {}
PHP);
        $file = $this->testRoot . '/program.php';
        $compiler->prepareFile($file);
        $compiler->convertFile($file);

        $property = $compiler->getClassDef('Holder')->getProperty('value');
        self::assertFalse($property->requiresRuntimeDefaultInit);
        $header = file_get_contents($compiler->getArgInfoHeaderFile($file));
        self::assertIsString($header);
        self::assertStringContainsString($expectedInitCode, $header);
    }

    public static function expressionDefaultProvider(): iterable
    {
        yield 'arithmetic' => [
            'public int $value = Code::One->value + 2;',
            'ZVAL_LONG(&property_value_default_value, 3);',
        ];
        yield 'nullsafe enum' => [
            'public int $value = Code::One?->value;',
            'ZVAL_LONG(&property_value_default_value, 1);',
        ];
        yield 'nullsafe null' => [
            'public ?int $value = null?->value;',
            'ZVAL_NULL(&property_value_default_value);',
        ];
        yield 'conditional' => [
            'public int $value = Code::One->value === 1 ? 10 : 20;',
            'ZVAL_LONG(&property_value_default_value, 10);',
        ];
        yield 'string concatenation' => [
            'public string $value = Code::One->name . "!";',
            'zend_string_init_interned("One!", sizeof("One!") - 1, 1);',
        ];
        yield 'static property' => [
            'public static int $value = Code::One->value + 2;',
            'ZVAL_LONG(&property_value_default_value, 3);',
        ];
        yield 'class constant indirection' => [
            'private const BASE = Code::One->value; public int $value = self::BASE + 2;',
            'ZVAL_LONG(&property_value_default_value, 3);',
        ];
    }

    public function testEnumCasesAndNonEmptyArraysStillRequireRuntimeInitialization(): void
    {
        $compiler = $this->compilerFor(<<<'PHP'
<?php
enum Code: int { case One = 1; }
class Holder {
    public Code $case = Code::One;
    public array $values = [Code::One];
    public bool $same = Code::One === Code::One;
}
function main(): void {}
PHP);
        $file = $this->testRoot . '/program.php';
        $compiler->prepareFile($file);
        $compiler->convertFile($file);

        $class = $compiler->getClassDef('Holder');
        self::assertTrue($class->getProperty('case')->requiresRuntimeDefaultInit);
        self::assertTrue($class->getProperty('values')->requiresRuntimeDefaultInit);
        self::assertTrue($class->getProperty('same')->requiresRuntimeDefaultInit);
        self::assertNotNull($class->getProperty('values')->arrayInitPlan);
    }

    public function testForwardEnumReferenceIsFoldedAfterAllFilesArePrepared(): void
    {
        $compiler = $this->compilerFor(<<<'PHP'
<?php
class Holder { public int $value = ForwardCode::One?->value + 2; }
function main(): void {}
PHP);
        $source = $this->testRoot . '/program.php';
        $enum = $this->testRoot . '/enum.php';
        file_put_contents($enum, '<?php enum ForwardCode: int { case One = 1; }');
        $compiler->addFiles([$enum]);
        $compiler->prepareFile($source);
        $compiler->prepareFile($enum);
        $compiler->convertFile($source);

        self::assertFalse($compiler->getClassDef('Holder')->getProperty('value')->requiresRuntimeDefaultInit);
        self::assertStringContainsString(
            'ZVAL_LONG(&property_value_default_value, 3);',
            file_get_contents($compiler->getArgInfoHeaderFile($source)),
        );
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
