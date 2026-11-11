<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

/**
 * @internal
 * @coversNothing
 */
final class TraitMemberValueCompatibilityTest extends PHPUnit\Framework\TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $this->testRoot = sys_get_temp_dir() . '/typephp-trait-values-' . bin2hex(random_bytes(8));
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

    /** @dataProvider compatibleMemberProvider */
    public function testEquivalentMemberValuesAreAccepted(string $declarations): void
    {
        [$compiler, $file] = $this->compilerFor("<?php\n{$declarations}\nfunction main(): void {}\n");
        $compiler->prepareFile($file);
        $compiler->composeTraitDeclarations([$file]);
        $compiler->convertFile($file);

        self::assertFileExists($compiler->getCppFile($file));
    }

    public static function compatibleMemberProvider(): iterable
    {
        yield 'equivalent constant expressions' => [
            'trait A { public const X = 1 + 1; } trait B { public const X = 2; } class C { use A, B; }',
        ];
        yield 'equivalent property arrays' => [
            'trait A { public array $value = [1, 2]; } trait B { public array $value = array(1, 2); } class C { use A, B; }',
        ];
        yield 'integer coerces to declared float' => [
            'trait A { public float $value = 1; public const float X = 1; } trait B { public float $value = 1.0; public const float X = 1.0; } class C { use A, B; }',
        ];
        yield 'same enum case identity' => [
            "enum Status: string { case Active = 'active'; case Disabled = 'disabled'; } trait A { public const X = Status::Active; } trait B { public const X = Status::Active; } class C { use A, B; }",
        ];
        yield 'same enum case nested in array' => [
            "enum Status: string { case Active = 'active'; } trait A { public const X = ['case' => Status::Active]; } trait B { public const X = array('case' => Status::Active); } class C { use A, B; }",
        ];
        yield 'indirect enum case identity' => [
            "enum Status: string { case Active = 'active'; } const CURRENT = Status::Active; trait A { public const X = CURRENT; } trait B { public const X = Status::Active; } class C { use A, B; }",
        ];
        yield 'inherited constant retaining enum identity' => [
            "enum Status: string { case Active = 'active'; } class Base { public const X = Status::Active; } class Values extends Base {} trait A { public const X = Values::X; } trait B { public const X = Status::Active; } class C { use A, B; }",
        ];
        yield 'enum case property defaults' => [
            "enum Status: string { case Active = 'active'; } trait A { public Status \$value = Status::Active; } trait B { public Status \$value = Status::Active; } class C { use A, B; }",
        ];
        yield 'trait self constant reference' => [
            'trait A { public const BASE = 1; public const X = self::BASE + 1; } trait B { public const BASE = 1; public const X = 2; } class C { use A, B; }',
        ];
        yield 'class declaration and trait expression are equivalent' => [
            'trait A { public const X = 1 + 1; public array $value = [1, 2]; } class C { use A; public const X = 2; public array $value = array(1, 2); }',
        ];
    }

    public function testClassMagicConstantIsComparedInConsumerScope(): void
    {
        [$compiler, $file] = $this->compilerFor(<<<'PHP'
<?php
trait A { public const X = __CLASS__; }
trait B { public const X = __CLASS__; }
class C { use A, B; }
function main(): void {}
PHP);
        $compiler->prepareFile($file);
        $compiler->composeTraitDeclarations([$file]);

        // gen_stub.php does not currently lower __CLASS__ in a class constant;
        // this assertion deliberately protects only Trait compatibility.
        self::addToAssertionCount(1);
    }

    public function testLexicalImportsAreUsedWhileComparingTraitConstants(): void
    {
        [$compiler, $file] = $this->compilerFor(<<<'PHP'
<?php
namespace Domain;
enum Status: string { case Active = 'active'; }
namespace App;
use Domain\Status as State;
use Domain\Status as Current;
trait A { public const X = State::Active; }
trait B { public const X = Current::Active; }
class C { use A, B; }
function main(): void {}
PHP);
        $compiler->prepareFile($file);
        $compiler->composeTraitDeclarations([$file]);

        // Stub registration still owns the separate lowering of imported
        // names; this test isolates compatibility evaluation.
        self::addToAssertionCount(1);
    }

    /** @dataProvider incompatibleMemberProvider */
    public function testDifferentMemberValuesAreRejected(string $declarations): void
    {
        [$compiler, $file] = $this->compilerFor("<?php\n{$declarations}\nfunction main(): void {}\n");
        $compiler->prepareFile($file);

        try {
            $compiler->composeTraitDeclarations([$file]);
            $compiler->convertFile($file);
            self::fail('Compilation unexpectedly succeeded');
        } catch (TestError $error) {
            self::assertMatchesRegularExpression('/conflict|already exists/i', $error->getMessage());
        }
    }

    public static function incompatibleMemberProvider(): iterable
    {
        yield 'different enum cases' => [
            "enum Status: string { case Active = 'active'; case Disabled = 'disabled'; } trait A { public const X = Status::Active; } trait B { public const X = Status::Disabled; } class C { use A, B; }",
        ];
        yield 'different enums with equal backing scalars' => [
            "enum First: string { case Active = 'active'; } enum Second: string { case Active = 'active'; } trait A { public const X = First::Active; } trait B { public const X = Second::Active; } class C { use A, B; }",
        ];
        yield 'different enum case inside array' => [
            "enum Status: string { case Active = 'active'; case Disabled = 'disabled'; } trait A { public const X = [Status::Active]; } trait B { public const X = [Status::Disabled]; } class C { use A, B; }",
        ];
        yield 'different enum property defaults' => [
            "enum Status: string { case Active = 'active'; case Disabled = 'disabled'; } trait A { public Status \$value = Status::Active; } trait B { public Status \$value = Status::Disabled; } class C { use A, B; }",
        ];
        yield '__TRAIT__ remains lexical' => [
            'trait A { public const X = __TRAIT__; } trait B { public const X = __TRAIT__; } class C { use A, B; }',
        ];
        yield 'class property and trait property differ' => [
            'trait A { public int $value = 1; } class C { use A; public int $value = 2; }',
        ];
        yield 'class constant and trait constant differ' => [
            'trait A { public const X = 1; } class C { use A; public const X = 2; }',
        ];
        yield 'explicit and inferred constant types differ' => [
            'trait A { public const int X = 2; } trait B { public const X = 2; } class C { use A, B; }',
        ];
    }

    /** @return array{CompilerTest, string} */
    private function compilerFor(string $source): array
    {
        $file = $this->testRoot . '/program.php';
        file_put_contents($file, $source);

        global $translator;
        $compiler = CompilerTest::create($this->testRoot);
        $translator = $compiler;
        $compiler->addFiles([$file]);

        return [$compiler, $file];
    }
}
