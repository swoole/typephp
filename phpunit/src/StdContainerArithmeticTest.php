<?php

use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class StdContainerArithmeticTest extends BaseTest
{
    private function translate(string $source): string
    {
        global $translator;
        $directory = sys_get_temp_dir() . '/std-arithmetic-' . bin2hex(random_bytes(6));
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

    #[DataProvider('unsupportedOperators')]
    public function testRejectsWholeContainerArithmetic(string $source, string $operator, string $container): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage("Operator '{$operator}' is not supported for {$container} containers; operate on individual elements instead");
        $this->translate($source);
    }

    public static function unsupportedOperators(): iterable
    {
        $factories = [
            'std::array' => 'std::array(Type::Int, 2)',
            'std::vector' => 'std::vector(Type::Int)',
            'std::map' => 'std::map(Type::Int, Type::Int)',
            'std::orderedMap' => 'std::orderedMap(Type::Int, Type::Int)',
        ];
        foreach ($factories as $container => $factory) {
            foreach (['+', '-', '*', '/', '%', '**', '&', '|', '^', '<<', '>>'] as $operator) {
                $prefix = '$a = ' . $factory . '; $b = ' . $factory . '; $value = 2;';
                foreach (['$a ' . $operator . ' 2', '2 ' . $operator . ' $a', '$a ' . $operator . ' $b'] as $i => $expression) {
                    yield $container . ' binary ' . $operator . ' ' . $i => [
                        'function main() { ' . $prefix . ' ' . $expression . '; }', $operator, $container,
                    ];
                }
                $assignment = $operator . '=';
                foreach (['$a ' . $assignment . ' 2', '$value ' . $assignment . ' $a', '$b[0] ' . $assignment . ' $a'] as $i => $expression) {
                    yield $container . ' compound ' . $assignment . ' ' . $i => [
                        'function main() { ' . $prefix . ' ' . $expression . '; }', $assignment, $container,
                    ];
                }
            }
        }
    }

    #[DataProvider('containerContexts')]
    public function testRecognizesContainerContractsAndNestedRows(string $source, string $operator, string $container): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage("Operator '{$operator}' is not supported for {$container} containers");
        $this->translate($source);
    }

    public static function containerContexts(): iterable
    {
        yield 'nested row' => ['function main() { $a = std::array(std::array(Type::Int, 2), 2); $a[0] * 2; }', '*', 'std::array'];
        yield 'three dimensional row' => ['function main() { $a = std::array(std::array(std::array(Type::Int, 2), 2), 2); $a[0][1] += 2; }', '+=', 'std::array'];
        yield 'nested row RHS' => ['function main() { $a = std::array(std::array(Type::Int, 2), 2); $a[0][0] *= $a[1]; }', '*=', 'std::array'];
        yield 'suppressed operand' => ['function main() { $a = std::vector(Type::Int); (@$a) * 2; }', '*', 'std::vector'];
        yield 'value initializer' => ['function main() { $a = std::vector([1, 2]); $a * 2; }', '*', 'std::vector'];
        yield 'array parameter' => ['function bad(#[StdArray(Type::Int, [2, 2])] box $a) { $a[0] / 2; }', '/', 'std::array'];
        yield 'vector parameter' => ['function bad(#[StdVector(Type::Int)] box $a) { 2 + $a; }', '+', 'std::vector'];
        yield 'map parameter' => ['function bad(#[StdMap(Type::Int, Type::Int)] box $a) { $a *= 2; }', '*=', 'std::map'];
        yield 'ordered map parameter' => ['function bad(#[StdOrderedMap(Type::Int, Type::Int)] box $a) { $a ** 2; }', '**', 'std::orderedMap'];
        yield 'instance property' => ['class State { #[StdVector(Type::Int)] public box $a; } function bad(State $s) { $s->a * 2; }', '*', 'std::vector'];
        yield 'inherited property' => ['class State { #[StdVector(Type::Int)] public box $a; } class Child extends State {} function bad(Child $s) { $s->a += 2; }', '+=', 'std::vector'];
        yield 'nullsafe property' => ['class State { #[StdVector(Type::Int)] public box $a; } function bad(State $s) { $s?->a * 2; }', '*', 'std::vector'];
        yield 'this property' => ['class State { #[StdVector(Type::Int)] public box $a; public function bad() { $this->a * 2; } }', '*', 'std::vector'];
        yield 'nested property row' => ['class State { #[StdArray(Type::Int, [2, 2])] public box $a; } function bad(State $s) { $s->a[0] * 2; }', '*', 'std::array'];
        yield 'static property' => ['class State { #[StdMap(Type::Int, Type::Int)] public static box $a; } function bad() { State::$a + 2; }', '+', 'std::map'];
        yield 'static property compound' => ['class State { #[StdArray(Type::Int, 2)] public static box $a; } function bad() { State::$a *= 2; }', '*=', 'std::array'];
    }

    #[DataProvider('acceptedOperators')]
    public function testPreservesElementOperationsAndPhpArrayRules(string $source): void
    {
        self::assertNotSame('', $this->translate('function main() { ' . $source . ' }'));
    }

    public static function acceptedOperators(): iterable
    {
        yield 'nested scalar' => ['$a = std::array(std::array(Type::Int, 2), 2); $a[0][1] * 2; $a[0][1] *= 2;'];
        yield 'vector element' => ['$a = std::vector([1, 2]); $a[0] + $a[1]; $a[0] += $a[1];'];
        yield 'map element' => ['$a = std::map([0 => 1]); $a[0] * 2; $a[0] *= 2;'];
        yield 'PHP array union' => ['$a = [1, 2]; $b = [3, 4]; $a + $b; $a += $b;'];
        yield 'typed PHP array union' => ['$a = std::list([1, 2]); $b = std::list([3, 4]); $a + $b;'];
        yield 'PHP runtime error' => ['$a = [1, 2]; $a * 2;'];
        yield 'explicit erasure' => ['$v = std::any([1, 2]); $v * 2;'];
        yield 'logical and comparison operators' => ['$a = std::array(Type::Int, 2); $a == $a; $a < $a; $a > $a; $a <= $a; $a >= $a; $a && true; $a || false;'];
    }
}
