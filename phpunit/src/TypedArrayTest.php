<?php

use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class TypedArrayTest extends BaseTest
{
    private function translate(string $source, bool $varIntTypes = false): array
    {
        global $translator;
        $directory = sys_get_temp_dir() . '/typephp-typed-array-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/typed.php';
        file_put_contents($file, '<?php ' . ($varIntTypes ? 'use varint_types; ' : '')
            . str_replace('function main(', 'function probe(', $source));
        try {
            $translator = CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator->addFiles([$file]);
            $translator->prepareFile($file);
            $generated = $translator->convertFile($file);
            return [file_get_contents($generated), $translator];
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testStoragePropagationAndNativeReferences(): void
    {
        [$code, $compiler] = $this->translate(<<<'PHP'
function copy_values(#[StdList(Type::Int)] $values): void { $values[] = 99; }
function append_values(#[StdList(Type::Int)] &$values): void { $values[] = 42; }
class User { public int $id = 1; }
class Receiver {
    public function accept(#[StdDict(Type::Str, User::class)] &$users): void { $users['alice'] = new User(); }
}
function main(): void {
    $values = std::list(Type::Int);
    $values[] = 7;
    $copy = $values;
    $alias =& $values;
    $alias[] = 8;
    copy_values($copy);
    append_values(values: $alias);
    var_dump(array_search(7, $values), array_keys($values), count($copy));
    $users = std::dict(Type::Str, User::class);
    $receiver = new Receiver();
    $receiver->accept($users);
    $user = $users['alice'];
    var_dump($user->id);
}
PHP);
        self::assertStringContainsString('php::Array &', $code);
        self::assertStringContainsString('php::Array{}', $code);
        self::assertStringNotContainsString('StdContainerBox', $code);
        self::assertStringNotContainsString('toIntExact', $code);
        self::assertStringNotContainsString('toObjectExact', $code);
        self::assertStringNotContainsString('ZEND_FUNCTION(copy_values)', $code);
        self::assertTrue($compiler->isNativeFunctionForStub('copy_values'));
        self::assertTrue($compiler->isNativeMethodForStub('Receiver', 'accept'));
        $function = (new ReflectionMethod($compiler, 'getFunction'))->invoke($compiler, 'append_values');
        $parameter = $function->argInfoList[0];
        self::assertSame(\TypePhp\Type::ARRAY_REF, $parameter->type);
        self::assertSame($parameter->typedArray, unserialize(serialize($parameter))->typedArray);
    }

    public function testWideningIntegerExpressionRequiresExplicitConversion(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('may widen require an explicit');
        $this->translate('function foo(int $value): void { $list = std::list(Type::Int); $list[] = $value + 1; }', true);
    }

    public function testExplicitNativeIntegerConversionInVarIntMode(): void
    {
        [$code] = $this->translate('function foo(int $value): void { $list = std::list(Type::Int); $list[] = std::int($value + 1); }', true);
        self::assertStringContainsString('.appendValue(', $code);
    }

    public function testExistingIntegerKeywordConversionInVarIntMode(): void
    {
        [$code] = $this->translate('function foo(int $value): void { $list = std::list(Type::Int); $list[] = ($value + 1)->toInt(); }', true);
        self::assertStringContainsString('php::toInt(', $code);
        self::assertStringNotContainsString('toIntExact(', $code);
    }

    public function testDynamicKeysUseInternalStrictChecks(): void
    {
        [$code] = $this->translate(<<<'PHP'
function foo($key): void {
    $list = std::list(Type::Int);
    $list[$key] = 1;
    var_dump($list[$key], isset($list[$key]), array_key_exists($key, $list));
    unset($list[$key]);
    $dict = std::dict(Type::Str, Type::Int);
    $dict[$key] = 2;
    var_dump($dict[$key], $dict->keyExists($key), $dict->get($key));
    unset($dict[$key]);
}
PHP);
        self::assertStringContainsString('php::toIntExact(', $code);
        self::assertStringContainsString('php::toStringExact(', $code);
        self::assertStringNotContainsString('toExactInt(', $code);
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidContractsAndEscapes(string $code, string $message): void
    {
        try {
            $this->translate($code);
            self::fail('Expected compilation failure');
        } catch (TestError|\TypePhp\Exception\SyntaxError $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    public static function invalidDeclarations(): iterable
    {
        yield 'wrong value' => ['function main(): void { $a = std::list(Type::Int); $a[] = "x"; }', 'must have type'];
        yield 'dynamic value' => ['function main($value): void { $a = std::list(Type::Int); $a[] = $value; }', 'must have type'];
        yield 'wrong key' => ['function main(): void { $a = std::dict(Type::Int, Type::Str); $a["x"] = "v"; }', 'must have type'];
        yield 'list string key' => ['function main(): void { $a = std::list(Type::Int); $a["x"] = 1; }', 'must have type'];
        yield 'string dict append' => ['function main(): void { $a = std::dict(Type::Str, Type::Int); $a[] = 1; }', 'Only typed lists'];
        yield 'integer dict append' => ['function main(): void { $a = std::dict(Type::Int, Type::Int); $a[] = 1; }', 'Only typed lists'];
        yield 'missing list type' => ['function main(): void { $a = std::list(); }', 'expects 1 type'];
        yield 'invalid dict key' => ['function main(): void { $a = std::dict(Type::Bool, Type::Int); }', 'key only supports'];
        yield 'annotation plus mixed' => ['function foo(#[StdList(Type::Int)] mixed $a): void {}', 'a PHP type cannot also'];
        yield 'duplicate annotation' => ['function foo(#[StdList(Type::Int), StdList(Type::Int)] $a): void {}', 'cannot be repeated'];
        yield 'conflicting annotation' => ['function foo(#[StdList(Type::Int), StdVector(Type::Int)] $a): void {}', 'cannot be applied'];
        yield 'named type argument' => ['function foo(#[StdList(valueType: Type::Int)] $a): void {}', 'positional type'];
        yield 'wrong argument type' => ['function foo(#[StdList(Type::Int)] $a): void {} function main(): void { $a = std::list(Type::Float); foo($a); }', 'identical list/dict contract'];
        yield 'list and dict remain distinct' => ['function foo(#[StdList(Type::Int)] $a): void {} function main(): void { $a = std::dict(Type::Int, Type::Int); foo($a); }', 'identical list/dict contract'];
        yield 'untyped array argument' => ['function foo(#[StdList(Type::Int)] $a): void {} function main(): void { $a = []; foo($a); }', 'identical list/dict contract'];
        yield 'unannotated function' => ['function foo(array $a): void {} function main(): void { $a = std::list(Type::Int); foo($a); }', 'matching annotated'];
        yield 'unannotated reference' => ['function foo(array &$a): void {} function main(): void { $a = std::list(Type::Int); foo($a); }', 'matching annotated'];
        yield 'array push' => ['function main(): void { $a = std::list(Type::Int); array_push($a, 1); }', 'matching annotated'];
        yield 'array sort' => ['function main(): void { $a = std::list(Type::Int); sort($a); }', 'matching annotated'];
        yield 'array walk' => ['function main(): void { $a = std::list(Type::Int); array_walk($a, "var_dump"); }', 'matching annotated'];
        yield 'dynamic ref wrapper' => ['function main($callback): void { $a = std::list(Type::Int); $callback(std::ref($a)); }', 'cannot escape'];
        yield 'dynamic element wrapper' => ['function main($callback): void { $a = std::list(Type::Int); $a[] = 1; $callback(std::ref($a[0])); }', 'cannot escape'];
        yield 'dynamic toRef wrapper' => ['function main($callback): void { $a = std::list(Type::Int); $callback($a->toRef()); }', 'cannot escape'];
        yield 'element reference' => ['function main(): void { $a = std::list(Type::Int); $a[] = 1; $v =& $a[0]; }', 'cannot escape'];
        yield 'reference return' => ['function &foo(#[StdList(Type::Int)] &$a) { return $a; }', 'cannot escape'];
        yield 'element ref assignment' => ['function main(): void { $a = std::list(Type::Int); $v = 1; $a[] =& $v; }', 'cannot escape'];
        yield 'property reference escape' => ['class A { public array $values = []; } function main(): void { $a = std::list(Type::Int); $object = new A(); $object->values =& $a; }', 'cannot escape'];
        yield 'wrong copied write' => ['function main(): void { $a = std::list(Type::Int); $b = $a; $b[] = "x"; }', 'must have type'];
        yield 'wrong alias write' => ['function main(): void { $a = std::list(Type::Int); $b =& $a; $b[] = "x"; }', 'must have type'];
        yield 'plain array replacement' => ['function main(): void { $a = std::list(Type::Int); $a = []; }', 'identical list/dict contract'];
        yield 'dynamic array replacement' => ['function main($other): void { $a = std::list(Type::Int); $a = $other; }', 'identical list/dict contract'];
        yield 'incompatible object' => ['class A {} class B {} function main(): void { $a = std::list(A::class); $a[] = new B(); }', 'must be an instance'];
        yield 'unknown object' => ['class A {} function main(object $value): void { $a = std::list(A::class); $a[] = $value; }', 'must be an instance'];
        yield 'native object' => ['#[Native] class A {} function main(): void { $a = std::list(A::class); }', 'cannot hold Native'];
        yield 'closure contract' => ['$fn = function(#[StdList(Type::Int)] $a) {};', 'named function or method'];
        yield 'arrow contract' => ['$fn = fn(#[StdList(Type::Int)] $a) => 1;', 'named function or method'];
        yield 'reference closure capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use (&$a) {}; }', 'cannot be captured by reference'];
        yield 'typed value closure capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $a[] = "x"; }; $fn(); }', 'must have type'];
        yield 'typed generator capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $a[] = "x"; yield 1; }; $fn(); }', 'must have type'];
        yield 'typed generator arrow capture' => ['function main(): void { $a = std::list(Type::Int); $fn = fn() => yield ($a[] = "x"); $fn(); }', 'must have type'];
        yield 'typed generator dict capture' => ['function main(): void { $a = std::dict(Type::Str, Type::Int); $fn = function() use ($a) { $a[1] = 2; yield 1; }; $fn(); }', 'must have type'];
        yield 'typed generator replacement' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $a = []; yield 1; }; $fn(); }', 'identical list/dict contract'];
        yield 'typed generator nested capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $inner = function() use ($a) { $a[] = "x"; }; $inner(); yield 1; }; $fn(); }', 'must have type'];
        yield 'typed nested reference capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $inner = function() use (&$a) {}; $inner(); }; $fn(); }', 'cannot be captured by reference'];
        yield 'typed generator nested reference capture' => ['function main(): void { $a = std::list(Type::Int); $fn = function() use ($a) { $inner = function() use (&$a) {}; yield 1; }; $fn(); }', 'cannot be captured by reference'];
        yield 'foreach ref' => ['function main(): void { $a = std::list(Type::Int); foreach ($a as &$v) {} }', 'cannot escape'];
        yield 'incompatible override' => ['class A { public function foo(#[StdList(Type::Int)] $a): void {} } class B extends A { public function foo(#[StdList(Type::Float)] $a): void {} }', 'must be compatible'];
        yield 'array reference escape' => ['function main(): void { $a = std::list(Type::Int); $bundle = [&$a]; }', 'cannot escape'];
        yield 'compound write' => ['function main(): void { $a = std::list(Type::Int); $a[] = 1; $a[0] += "x"; }', 'compound writes'];
        yield 'coalescing write' => ['function main(): void { $a = std::list(Type::Int); $a[0] ??= "x"; }', 'compound writes'];
        yield 'union write' => ['function main(): void { $a = std::list(Type::Int); $a += ["x"]; }', 'compound writes'];
        yield 'increment write' => ['function main(): void { $a = std::list(Type::Int); $a[] = 1; $a[0]++; }', 'increment/decrement'];
        yield 'universal mutator' => ['function main(): void { $a = std::list(Type::Int); $a->set(0, "x"); }', 'untyped mutating'];
        yield 'foreach value type' => ['function main(): void { $a = std::list(Type::Int); foreach ($a as $value) { $value = "x"; } }', 'Cannot re-assign'];
        yield 'foreach key type' => ['function main(): void { $a = std::dict(Type::Str, Type::Int); foreach ($a as $key => $value) { $key = 1; } }', 'Cannot re-assign'];
    }
}
