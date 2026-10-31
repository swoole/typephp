<?php

namespace TypePhp\Tests\CompileTimeAttribute;

use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;
use TypePhp\CompileTimeAttribute\ProviderTable;
use TypePhp\CompilerTest;
use TypePhp\Transform\PropertyHookLowering;

final class ExpansionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/typephp-expansion-' . bin2hex(random_bytes(6));
        $this->write('src/.keep', '');
        $this->write('project.yml', "name: expansion\nsources:\n  - src\ncompile-time-attributes:\n  providers:\n    - file: providers/Provider.php\n");
    }

    protected function tearDown(): void
    {
        ProviderTable::reset();
        $this->remove($this->root);
    }

    public function testGeneratedMembersAndPropertyHooksReachThePreparedTree(): void
    {
        $this->write('src/Counter.php', <<<'PHP'
<?php
namespace AttributeFixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Audit
{
}

#[Audit]
final class Counter
{
    public int $total = 0;

    public function add(int $amount): void
    {
        $this->total = $this->total + $amount;
    }
}
PHP);
        $this->write('providers/Provider.php', <<<'PHP'
<?php
namespace AttributeFixture;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(Audit::class, target: 'class')]
final class AuditProvider
{
    public function compile(Generator $g): void
    {
        $g->member('private array $__audit = [];');
        $g->member('public function report(): array { return $this->__audit; }');
        $g->property('total')->setter('$this->__audit[] = {{value}}; {{call}}');
    }
}
PHP);

        $class = $this->compile('Counter');
        $methods = array_map(static fn (Stmt\ClassMethod $method): string => $method->name->toString(), $class->getMethods());
        $metadata = $this->property($class, 'total')->getAttribute(PropertyHookLowering::PROPERTY_ATTRIBUTE);

        $this->assertContains('report', $methods);
        $this->assertContains('__typephp_property_set_746f74616c', $methods);
        $this->assertSame(['set' => '__typephp_property_set_746f74616c'], $metadata['methods']);
        $this->assertFalse($metadata['virtual']);
    }

    public function testArgumentsArriveInSourceOrder(): void
    {
        $this->write('src/Greeter.php', <<<'PHP'
<?php
namespace AttributeFixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Greet
{
}

#[Greet('hi', times: 3, kind: Kind::class)]
final class Greeter
{
}

final class Kind
{
}
PHP);
        $this->write('providers/Provider.php', <<<'PHP'
<?php
namespace AttributeFixture;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(Greet::class, target: 'class')]
final class GreetProvider
{
    public static array $seen = [];

    public function compile(Generator $g): void
    {
        self::$seen[] = $g->args();
    }
}
PHP);

        $this->compile('Greeter');

        $this->assertSame(
            [[0 => 'hi', 'times' => 3, 'kind' => 'AttributeFixture\\Kind']],
            \AttributeFixture\GreetProvider::$seen,
        );
    }

    public function testEveryProviderErrorIsReportedWithItsOwnSite(): void
    {
        $this->write('src/Pair.php', <<<'PHP'
<?php
namespace AttributeFixture;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class First
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Second
{
}

final class Pair
{
    #[First]
    public int $a = 0;

    #[Second]
    public int $b = 0;
}
PHP);
        $this->write('providers/Provider.php', <<<'PHP'
<?php
namespace AttributeFixture;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(First::class, target: 'property')]
final class FirstProvider
{
    public function compile(Generator $g): void
    {
        $g->error('first refuses');
    }
}

#[ProviderFor(Second::class, target: 'property')]
final class SecondProvider
{
    public function compile(Generator $g): void
    {
        $g->error('second refuses');
    }
}
PHP);

        $message = $this->prepareFailure();

        $this->assertStringContainsString('first refuses', $message);
        $this->assertStringContainsString('second refuses', $message);
        $this->assertSame(2, substr_count($message, '[compile-time attribute:'));
    }

    public function testSetterLayersComposeOutermostFirstIntoASingleHook(): void
    {
        $this->write('src/Layered.php', <<<'PHP'
<?php
namespace AttributeFixture;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Outer
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Inner
{
}

#[Outer]
final class Layered
{
    #[Inner]
    public int $v = 0;

    private int $__outer = 0;
    private int $__inner = 0;
}
PHP);
        $this->write('providers/Provider.php', <<<'PHP'
<?php
namespace AttributeFixture;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(Outer::class, target: 'class')]
final class OuterProvider
{
    public function compile(Generator $g): void
    {
        $g->property('v')->setter('$this->__outer++; {{call}}');
    }
}

#[ProviderFor(Inner::class, target: 'property')]
final class InnerProvider
{
    public function compile(Generator $g): void
    {
        $g->property()->setter('$this->__inner++;');
    }
}
PHP);

        $class = $this->compile('Layered');
        $body = (new Standard())->prettyPrint($this->property($class, 'v')->hooks[0]->body);

        $this->assertLessThan(strpos($body, '__inner'), strpos($body, '__outer'));
        $this->assertSame(1, substr_count($body, '$this->v = $value'));
    }

    private function compile(string $shortName): Stmt\ClassLike
    {
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $compiler->prepare($this->root . '/project.yml');
        $compiler->convertFile($this->root . '/src/' . $shortName . '.php');

        return $this->classNode($compiler, $shortName);
    }

    private function prepareFailure(): string
    {
        try {
            CompilerTest::create(TYPEPHP_ROOT_PATH)->prepare($this->root . '/project.yml');
        } catch (\Throwable $error) {
            return $error->getMessage();
        }

        $this->fail('expected the expansion to fail');
    }

    private function classNode(CompilerTest $compiler, string $shortName): Stmt\ClassLike
    {
        $asts = (new \ReflectionProperty($compiler, 'preparedFileAsts'))->getValue($compiler);
        foreach ($asts as $stmts) {
            foreach ((new NodeFinder())->findInstanceOf($stmts, Stmt\ClassLike::class) as $class) {
                if ($class->name?->toString() === $shortName) {
                    return $class;
                }
            }
        }

        $this->fail('class ' . $shortName . ' was not prepared');
    }

    private function property(Stmt\ClassLike $class, string $name): Stmt\Property
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property && $stmt->props[0]->name->toString() === $name) {
                return $stmt;
            }
        }

        $this->fail('property $' . $name . ' was not prepared');
    }

    private function write(string $path, string $contents): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
