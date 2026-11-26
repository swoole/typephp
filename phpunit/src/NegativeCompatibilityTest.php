<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

use PhpParser\Node;
use TypePhp\CompilerTest;
use TypePhp\Diagnostics\DiagnosticReporter;
use TypePhp\Exception\TestError;

final class NegativeCompatibilityDiagnosticReporter implements DiagnosticReporter
{
    /** @var list<string> */
    public array $warnings = [];

    public function fatal(string $message): never
    {
        throw new TestError($message);
    }

    public function warning(Node $node, string $file, string $message): void
    {
        $this->warnings[] = $message . ' in ' . $file . ':' . $node->getStartLine();
    }
}

/**
 * Verifies that intentional PHP compatibility boundaries fail in a controlled,
 * stable compiler phase instead of warning, crashing, or emitting invalid C++.
 * @internal
 * @coversNothing
 */
final class NegativeCompatibilityTest extends PHPUnit\Framework\TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $this->testRoot = sys_get_temp_dir() . '/typephp-negative-' . bin2hex(random_bytes(8));
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
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->testRoot);
    }

    /**
     * @dataProvider incompatibilityProvider
     */
    public function testIntentionalIncompatibilityFailsCleanly(
        string $expectedPhase,
        string $expectedDiagnostic,
        string $source,
    ): void {
        $file = $this->testRoot . '/program.php';
        file_put_contents($file, $source);

        global $translator;
        $compiler = CompilerTest::create($this->testRoot);
        $translator = $compiler;
        $reporter = new NegativeCompatibilityDiagnosticReporter();
        $compiler->setDiagnosticReporter($reporter);
        $compiler->addFiles([$file]);

        $phpDiagnostics = [];
        $failure = null;
        $failurePhase = 'prepare';
        set_error_handler(static function (
            int $severity,
            string $message,
            string $diagnosticFile,
            int $line,
        ) use (&$phpDiagnostics): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $phpDiagnostics[] = $message . ' in ' . $diagnosticFile . ':' . $line;
            return true;
        });
        try {
            $compiler->prepareFile($file);
            $failurePhase = 'convert';
            $compiler->convertFile($file);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($failure, 'Compilation unexpectedly succeeded');
        self::assertInstanceOf(
            TestError::class,
            $failure,
            'The compiler boundary must use a controlled diagnostic, not ' . $failure::class,
        );
        self::assertSame($expectedPhase, $failurePhase, 'The diagnostic was raised in the wrong compiler phase');
        self::assertSame(
            $expectedDiagnostic . ' in ' . $file . ':' . $this->diagnosticLine($source, $expectedDiagnostic),
            $failure->getMessage(),
            'The compiler diagnostic changed',
        );
        self::assertSame([], $reporter->warnings, 'The compiler emitted warnings before failing');
        self::assertSame([], $phpDiagnostics, 'PHP emitted a warning/notice before the compiler failed');
        self::assertFileDoesNotExist($compiler->getCppFile($file), 'A failed conversion emitted a C++ file');
    }

    public static function incompatibilityProvider(): iterable
    {
        yield 'global executable statement' => [
            'prepare',
            'All execution code must be within a function, found stray code',
            "<?php\nprint \"outside main\"; // @diagnostic\n",
        ];

        yield 'variable variables' => [
            'convert',
            'The `$$` syntax is not supported',
            <<<'PHP'
<?php
function main(): void
{
    $name = 'value';
    $value = 42;
    print $$name; // @diagnostic
}
PHP,
        ];

        yield 'Decimal round mode' => [
            'convert',
            'round() with Decimal supports at most 2 arguments',
            <<<'PHP'
<?php
function main(): void
{
    round(std::decimal('2.5'), 0, PHP_ROUND_HALF_DOWN); // @diagnostic
}
PHP,
        ];

        yield 'round excessive arguments with Decimal' => [
            'convert',
            'round() expects at most 3 argument(s), 4 given',
            <<<'PHP'
<?php
function main(): void
{
    round(std::decimal('2.5'), 0, PHP_ROUND_HALF_DOWN, 4); // @diagnostic
}
PHP,
        ];

        yield 'break level exceeds enclosing depth' => [
            'convert',
            "Cannot 'break' 2 levels",
            <<<'PHP'
<?php
function main(): void
{
    while (true) {
        break 2; // @diagnostic
    }
}
PHP,
        ];

        yield 'continue level exceeds enclosing depth' => [
            'convert',
            "Cannot 'continue' 2 levels",
            <<<'PHP'
<?php
function main(): void
{
    while (true) {
        continue 2; // @diagnostic
    }
}
PHP,
        ];

        yield 'break level must be a positive integer literal' => [
            'convert',
            "'break' operator accepts only positive integer literals",
            <<<'PHP'
<?php
function main(): void
{
    while (true) {
        break 0; // @diagnostic
    }
}
PHP,
        ];

        yield 'continue level must be a positive integer literal' => [
            'convert',
            "'continue' operator accepts only positive integer literals",
            <<<'PHP'
<?php
function main(): void
{
    while (true) {
        continue 1 + 1; // @diagnostic
    }
}
PHP,
        ];

        yield 'duplicate implicit class import' => [
            'prepare',
            'Cannot use Webman\\Route\\Route as Route because the name is already in use',
            <<<'PHP'
<?php
namespace DuplicateImport;

use support\annotation\route\Route;
use Webman\Route\Route; // @diagnostic

function main(): void
{
}
PHP,
        ];

        yield 'duplicate class import alias is case insensitive' => [
            'prepare',
            'Cannot use Second\\Package\\Route as route because the name is already in use',
            <<<'PHP'
<?php
namespace DuplicateImport;

use First\Package\Route as Route;
use Second\Package\Route as route; // @diagnostic

function main(): void
{
}
PHP,
        ];

        yield 'duplicate function import alias is case insensitive' => [
            'prepare',
            'Cannot use function Second\\Package\\dispatch as handler because the name is already in use',
            <<<'PHP'
<?php
namespace DuplicateImport;

use function First\Package\dispatch as Handler;
use function Second\Package\dispatch as handler; // @diagnostic

function main(): void
{
}
PHP,
        ];

        yield 'reserved boolean constant import alias' => [
            'prepare',
            'Cannot use const ZERO as true because the alias is a reserved literal',
            <<<'PHP'
<?php
const ZERO = 0;
use const ZERO as true; // @diagnostic
PHP,
        ];

        yield 'reserved constant alias is case insensitive in a mixed group import' => [
            'prepare',
            'Cannot use const AliasSource\\ZERO as FaLsE because the alias is a reserved literal',
            <<<'PHP'
<?php
namespace AliasConsumer;
use AliasSource\{Thing, const ZERO as FaLsE}; // @diagnostic
PHP,
        ];

        yield 'implicit constant import cannot shadow null' => [
            'prepare',
            'Cannot use const AliasSource\\NULL as NULL because the alias is a reserved literal',
            <<<'PHP'
<?php
namespace AliasConsumer;
use const AliasSource\NULL; // @diagnostic
PHP,
        ];

        yield 'closure reference return' => [
            'convert',
            'Closure and arrow functions cannot return by reference',
            <<<'PHP'
<?php
function main(): void
{
    $callback = static function &(): mixed { // @diagnostic
        static $value = 42;
        return $value;
    };
}
PHP,
        ];

        yield 'property get hook reference return' => [
            'prepare',
            'Property get hooks returning by reference are not supported',
            <<<'PHP'
<?php
final class ReferencePropertyHook
{
    public string $value {
        &get => $this->value; // @diagnostic
    }
}
PHP,
        ];

        yield 'arrow function reference return' => [
            'convert',
            'Closure and arrow functions cannot return by reference',
            <<<'PHP'
<?php
function main(): void
{
    $value = 42;
    $callback = static fn &(): mixed => $value; // @diagnostic
}
PHP,
        ];

        yield 'dynamic Closure reference variadic parameter' => [
            'convert',
            'By-reference variadic parameters are not supported on dynamic Closures',
            <<<'PHP'
<?php
function main(): void
{
    $callback = static function (&...$values): void { // @diagnostic
    };
}
PHP,
        ];

        yield 'literal passed to reference variadic parameter' => [
            'convert',
            'The left value of assignment operation can only be variable, array item, object property, class static property',
            <<<'PHP'
<?php
function collect(&...$values): void
{
}

function main(): void
{
    collect(42); // @diagnostic
}
PHP,
        ];

        yield 'reference variadic override must preserve by-reference contract' => [
            'convert',
            'Declaration of `BrokenIncrementer::increment()` must be compatible with `IncrementContract::increment()`',
            <<<'PHP'
<?php
interface IncrementContract
{
    public function increment(int &...$values): void;
}

class BrokenIncrementer implements IncrementContract // @diagnostic
{
    public function increment(int ...$values): void
    {
    }
}

function main(): void
{
}
PHP,
        ];

        yield 'ticks declare' => [
            'convert',
            'declare(ticks=1) is not supported',
            <<<'PHP'
<?php
declare(ticks=1); // @diagnostic
function main(): void
{
}
PHP,
        ];

        yield 'non-UTF-8 encoding declare' => [
            'convert',
            'declare(encoding="ISO-8859-1") is not supported, only UTF-8 is supported',
            <<<'PHP'
<?php
declare(encoding='ISO-8859-1'); // @diagnostic
function main(): void
{
}
PHP,
        ];

        yield 'unknown declare directive' => [
            'convert',
            'declare(custom=1) is not supported',
            <<<'PHP'
<?php
declare(custom=1); // @diagnostic
function main(): void
{
}
PHP,
        ];

        yield 'disabled strict types declare' => [
            'convert',
            'TypePHP always uses strict types; declare(strict_types=0) is not allowed',
            <<<'PHP'
<?php
declare(strict_types=0); // @diagnostic
function main(): void
{
}
PHP,
        ];

        yield 'nested match arm condition' => [
            'convert',
            'Match expression cannot be used as a condition',
            <<<'PHP'
<?php
function main(): void
{
    $value = 1;
    $result = match ($value) {
        match ($value) { 1 => 1, default => 0 } => 'nested', // @diagnostic
        default => 'default',
    };
}
PHP,
        ];

        yield 'foreach reference property target' => [
            'convert',
            'Foreach by reference only supports variable as value',
            <<<'PHP'
<?php
final class Holder
{
    public mixed $value = null;
}
function main(): void
{
    $holder = new Holder();
    $values = [1, 2];
    foreach ($values as &$holder->value) { // @diagnostic
    }
}
PHP,
        ];

        yield 'foreach reference list destructuring' => [
            'convert',
            'Foreach list destructuring cannot bind items by reference',
            <<<'PHP'
<?php
function main(): void
{
    $rows = [[1, 2]];
    foreach ($rows as [&$left, &$right]) { // @diagnostic
    }
}
PHP,
        ];

        yield 'void cast result assignment' => [
            'prepare',
            'The (void) cast can only be used as a statement',
            <<<'PHP'
<?php
function result(): int
{
    return 1;
}
function main(): void
{
    $value = (void) result(); // @diagnostic
}
PHP,
        ];

        yield 'void cast return value' => [
            'prepare',
            'The (void) cast can only be used as a statement',
            <<<'PHP'
<?php
function result(): int
{
    return 1;
}
function invalid(): mixed
{
    return (void) result(); // @diagnostic
}
PHP,
        ];

        yield 'void cast argument value' => [
            'prepare',
            'The (void) cast can only be used as a statement',
            <<<'PHP'
<?php
function consume(mixed $value): void
{
}
function main(): void
{
    consume((void) 1); // @diagnostic
}
PHP,
        ];

        yield 'void cast condition value' => [
            'prepare',
            'The (void) cast can only be used as a statement',
            <<<'PHP'
<?php
function main(): void
{
    if ((void) true) { // @diagnostic
    }
}
PHP,
        ];

        yield 'void cast final for condition' => [
            'prepare',
            'The (void) cast can only be used as a statement',
            <<<'PHP'
<?php
function main(): void
{
    for (; (void) true; ) { // @diagnostic
    }
}
PHP,
        ];
    }

    private function diagnosticLine(string $source, string $diagnostic): int
    {
        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains($line, '@diagnostic')) {
                return $index + 1;
            }
        }
        self::fail('Missing @diagnostic marker for ' . $diagnostic);
    }
}
