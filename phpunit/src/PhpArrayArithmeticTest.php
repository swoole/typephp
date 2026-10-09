<?php

use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class PhpArrayArithmeticTest extends BaseTest
{
    private function translate(string $source): string
    {
        global $translator;
        $directory = sys_get_temp_dir() . '/php-array-arithmetic-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/operators.php';
        file_put_contents($file, '<?php ' . $source);
        try {
            $translator = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator->addFiles([$file]);
            $translator->prepareFile($file);
            return file_get_contents($translator->convertFile($file));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    #[DataProvider('invalidBinaryOperands')]
    public function testRejectsPhpUnsupportedOperandTypes(string $source, string $phpExpression, string $diagnostic): void
    {
        // PHP is the oracle for the rejected combinations, including numeric
        // strings, null, resources and arrays on either side of the operator.
        // PHP may emit a float-to-int deprecation before detecting invalid
        // shift operands; this test checks the resulting TypeError.
        set_error_handler(static fn () => true, E_DEPRECATED);
        try {
            eval('return ' . $phpExpression . ';');
            self::fail('PHP accepted an expression expected to be unsupported');
        } catch (TypeError $error) {
            self::assertStringStartsWith('Unsupported operand types:', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->expectException(TestError::class);
        $this->expectExceptionMessage($diagnostic);
        $this->translate($source);
    }

    public static function invalidBinaryOperands(): iterable
    {
        $operands = [
            'int' => '2',
            'float' => '2.5',
            'bool' => 'true',
            'string' => '"2.5"',
            'null' => 'null',
            'resource' => 'fopen("php://memory", "r+")',
            'object' => 'new stdClass()',
            'array' => '[2]',
        ];
        foreach (['+', '-', '*', '/', '%', '**', '&', '|', '^', '<<', '>>'] as $operator) {
            foreach ($operands as $type => $operand) {
                if ($operator === '+' && $type === 'array') {
                    continue;
                }
                yield "$operator forward binary/$type" => [
                    'function main() { [1] ' . $operator . ' ' . $operand . '; }',
                    '[1] ' . $operator . ' ' . $operand,
                    "Unsupported operand types: array $operator $type",
                ];
                yield "$operator reverse binary/$type" => [
                    'function main() { ' . $operand . ' ' . $operator . ' [1]; }',
                    $operand . ' ' . $operator . ' [1]',
                    "Unsupported operand types: $type $operator array",
                ];
                yield "$operator forward compound/$type" => [
                    'function main() { $a = [1]; $a ' . $operator . '= ' . $operand . '; }',
                    '[1] ' . $operator . ' ' . $operand,
                    "Unsupported operand types: array {$operator}= $type",
                ];
                // A null local has dynamic storage rather than a fixed null
                // type, so it cannot be rejected from its declared type alone.
                if ($type !== 'null') {
                    yield "$operator reverse compound/$type" => [
                        'function main() { $a = ' . $operand . '; $a ' . $operator . '= [1]; }',
                        $operand . ' ' . $operator . ' [1]',
                        "Unsupported operand types: $type {$operator}= array",
                    ];
                }
            }
        }
    }

    #[DataProvider('invalidContexts')]
    public function testUsesAstTypesAcrossExpressionContexts(string $source, string $diagnostic): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage($diagnostic);
        $this->translate($source);
    }

    public static function invalidContexts(): iterable
    {
        yield 'locals' => ['function main() { $a = [1]; $b = 2; $a + $b; }', 'array + int'];
        yield 'typed parameters' => ['function bad(array $a, float $b) { $a + $b; }', 'array + float'];
        yield 'typed references' => ['function bad(array &$a, bool &$b) { $b + $a; }', 'bool + array'];
        yield 'local reference' => ['function main() { $a = [1]; $alias =& $a; $alias += 2; }', 'array += int'];
        yield 'array return' => ['function values(): array { return [1]; } function main() { values() + 2; }', 'array + int'];
        yield 'builtin array return' => ['function main() { array_keys([1]) + 2; }', 'array + int'];
        yield 'instance property' => ['class State { public array $a = []; } function bad(State $s) { $s->a + true; }', 'array + bool'];
        yield 'instance property compound' => ['class State { public array $a = []; } function bad(State $s) { $s->a += 2.5; }', 'array += float'];
        yield 'static property compound' => ['class State { public static array $a = []; } function bad() { State::$a *= 2; }', 'array *= int'];
        yield 'typed list' => ['function main() { $a = std::list([1]); $a + 2; }', 'array + int'];
        yield 'union result' => ['function main() { ([1] + [2]) + 2; }', 'array + int'];
        yield 'stored union result' => ['function main() { $a = [1] + [2]; $a * 2; }', 'array * int'];
        yield 'ternary arrays' => ['function bad(bool $c) { ($c ? [1] : [2]) + false; }', 'array + bool'];
        yield 'suppressed array' => ['function main() { (@[1]) + 2; }', 'array + int'];
        yield 'fresh assignment' => ['function main() { ($a = [1]) * 2; }', 'array * int'];
        yield 'fresh assignment power' => ['function main() { ($a = [1]) ** 2; }', 'array ** int'];
        yield 'suppressed null' => ['function main() { [1] + (@null); }', 'array + null'];
        yield 'cast array' => ['function bad(mixed $v) { (array) $v + 2; }', 'array + int'];
        yield 'known array with dynamic multiplier' => ['function bad(array $a, mixed $b) { $a * $b; }', 'array * mixed'];
        yield 'dynamic target with known array' => ['function bad(mixed $a, array $b) { $a /= $b; }', 'mixed /= array'];
        yield 'declared int in varint mode' => ['use varint_types; function bad(array $a, int $b) { $a + $b; }', 'array + int'];
        yield 'high precision type' => ['function main() { [1] + std::bigInt("2"); }', 'array + bigint'];
        yield 'declared resource' => ['function bad(array $a, stream $s) { $a + $s; }', 'array + resource'];
        yield 'constant array' => ['const VALUES = [1]; function main() { VALUES + 2; }', 'array + int'];
        yield 'class constant array' => ['class State { public const array VALUES = [1]; } function main() { State::VALUES + 2; }', 'array + int'];
        yield 'scalar class constant' => ['class State { public const int VALUE = 2; } function main() { [1] + State::VALUE; }', 'array + int'];
        yield 'scalar alias constant' => ['class State { public const int VALUE = 2; public const ALIAS = self::VALUE; } function main() { [1] + State::ALIAS; }', 'array + int'];
        yield 'imported constant array' => ['namespace Values { const ITEMS = [1]; } namespace App { use const Values\ITEMS as ITEMS; function bad() { ITEMS + 2; } }', 'array + int'];
    }

    #[DataProvider('invalidUnaryOperands')]
    public function testRejectsUnaryArrayArithmetic(string $source, string $operator): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage("Operator '$operator' is not supported for array operands");
        $this->translate($source);
    }

    public static function invalidUnaryOperands(): iterable
    {
        foreach (['+', '-', '~'] as $operator) {
            yield "$operator literal" => ['function main() { ' . $operator . '[1]; }', $operator];
            yield "$operator parameter" => ['function bad(array &$a) { ' . $operator . '$a; }', $operator];
            yield "$operator assignment" => ['function main() { ' . $operator . '($a = [1]); }', $operator];
        }
        foreach (['++', '--'] as $operator) {
            yield "$operator prefix" => ['function main() { $a = [1]; ' . $operator . '$a; }', $operator];
            yield "$operator postfix" => ['function main() { $a = [1]; $a' . $operator . '; }', $operator];
            yield "$operator property" => ['class State { public array $a = []; } function bad(State $s) { $s->a' . $operator . '; }', $operator];
        }
    }

    #[DataProvider('acceptedExpressions')]
    public function testPreservesLegalArrayOperationsAndDynamicChecks(string $source): void
    {
        self::assertNotSame('', $this->translate($source));
    }

    public static function acceptedExpressions(): iterable
    {
        yield 'array union' => ['function main() { $a = [1]; $b = [2, 3]; $a + $b; $a += $b; }'];
        yield 'long native addition' => ['function total(int $a): int { return ' . implode(' + ', array_fill(0, 32, '$a')) . '; }'];
        yield 'chained union' => ['function main() { [1] + [2] + [3]; }'];
        yield 'constant union' => ['const VALUES = [1]; class State { public const array VALUES = [2]; } function main() { VALUES + State::VALUES; }'];
        yield 'unary scalar constant' => ['class State { public const int VALUE = 2; } function main() { $a = +State::VALUE; var_dump($a); }'];
        yield 'runtime namespace fallback' => ['namespace Values; function dynamic() { ITEMS + [1]; }'];
        yield 'late static constant' => ['class State { public const VALUES = [1]; public static function dynamic() { static::VALUES + 2; } }'];
        yield 'typed array result' => ['function union_values(array $a, array $b): array { return $a + $b; }'];
        yield 'typed references' => ['function merge(array &$a, array &$b) { $a += $b; }'];
        yield 'comparisons and logical operations' => ['function main() { $a = [1]; $b = [2]; $a == $b; $a === $b; $a != $b; $a !== $b; $a < $b; $a <= $b; $a > $b; $a >= $b; $a <=> $b; !$a; $a && true; $a || false; }'];
        yield 'array element arithmetic' => ['function main() { $a = [1]; $a[0] + 2; $a[0] *= 2; }'];
        yield 'mixed addition' => ['function maybe_merge(array $a, mixed $b) { $a + $b; $b + $a; $a += $b; $b += $a; }'];
        yield 'mixed arithmetic' => ['function dynamic(mixed $a, mixed $b) { $a + $b; $a * $b; $a ** $b; $a *= $b; -$a; +$a; ~$a; }'];
        yield 'nullable parameter' => ['function dynamic(?array $a) { $a + 2; }'];
        yield 'nullable property' => ['class State { public ?array $a = null; } function dynamic(State $s) { $s->a + 2; +$s->a; }'];
        yield 'union parameter' => ['function dynamic(array|int $a) { $a + 2; }'];
        yield 'explicit erasure' => ['function main() { $a = std::any([1]); $a * 2; }'];
    }
}
