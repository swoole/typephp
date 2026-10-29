<?php

namespace TypePhp\Tests\CompileTimeAttribute;

use PHPUnit\Framework\TestCase;
use TypePhp\CompileTimeAttribute\ProviderTable;
use TypePhp\CompilerTest;

final class RejectionTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        ProviderTable::reset();
        foreach ($this->roots as $root) {
            $this->remove($root);
        }
    }

    public function testRejectedExpansionsNameTheProblem(): void
    {
        foreach ($this->rejections() as $case => [$declaration, $providers, $expected]) {
            $this->assertStringContainsString(
                $expected,
                $this->compile((string) $case, $declaration, $providers),
                'case ' . $case,
            );
        }
    }

    /** @return array<string, array{string, array<string, string>, string}> */
    private function rejections(): array
    {
        return [
            'static property' => [
                <<<'PHP'
final class SubjectStaticProperty
{
    #[Clamped]
    public static int $s = 0;
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'static property $s cannot have hooks',
            ],
            'readonly property' => [
                <<<'PHP'
final class SubjectReadonly
{
    #[Clamped]
    public readonly int $r;

    public function __construct()
    {
        $this->r = 0;
    }
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'readonly property $r cannot have hooks',
            ],
            'promoted property' => [
                <<<'PHP'
#[Audited]
final class SubjectPromoted
{
    public function __construct(public int $p)
    {
    }
}
PHP,
                ['audited' => "\$g->property('p')->setter('{{call}}');"],
                'promoted property $p cannot have hooks',
            ],
            'existing hook' => [
                <<<'PHP'
final class SubjectExistingHook
{
    #[Clamped]
    public int $v = 0 {
        set => $value;
    }
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'property $v already declares a `set` hook',
            ],
            'multi property declaration' => [
                <<<'PHP'
final class SubjectMultiProperty
{
    #[Clamped]
    public int $a = 0, $b = 0;
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'multi-property declaration',
            ],
            'native class' => [
                <<<'PHP'
#[\Native]
final class SubjectNative
{
    #[Clamped]
    public int $x = 0;
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'cannot be applied to a #[Native] class',
            ],
            'interface' => [
                <<<'PHP'
#[Audited]
interface ShapeContract
{
}
PHP,
                ['audited' => "\$g->member('public function area(): float { return 0.0; }');"],
                'can only be applied to a class',
            ],
            'trait property hook' => [
                <<<'PHP'
trait SubjectTrait
{
    #[Clamped]
    public int $x = 0;
}
PHP,
                ['clamped' => "\$g->property()->setter('{{call}}');"],
                'only classes support generated property hooks',
            ],
            'virtual getter on defaulted property' => [
                <<<'PHP'
final class SubjectVirtualGetter
{
    #[Clamped]
    public int $d = 1;
}
PHP,
                ['clamped' => "\$g->property()->getter('42');"],
                'must read {{value}}',
            ],
            'outer setter layer without a call' => [
                <<<'PHP'
final class SubjectOuterSetter
{
    #[Clamped]
    #[Logged]
    public int $v = 0;
}
PHP,
                [
                    'clamped' => "\$g->property()->setter('\$this->outer++;');",
                    'logged' => "\$g->property()->setter('{{call}}');",
                ],
                'must contain {{call}}',
            ],
            'reserved generated name' => [
                '#[Audited] final class SubjectReservedName {}',
                ['audited' => "\$g->member('public function __typephp_hook(): void {}');"],
                'is reserved',
            ],
            'constructor generated' => [
                '#[Audited] final class SubjectConstructor {}',
                ['audited' => "\$g->member('public function __construct() {}');"],
                'is not allowed',
            ],
            'host member collision' => [
                <<<'PHP'
#[Audited]
final class SubjectCollision
{
    public function run(): void
    {
    }
}
PHP,
                ['audited' => "\$g->member('public function run(): void {}');"],
                'collides with the host declaration',
            ],
            'attribute inside a snippet' => [
                '#[Audited] final class SubjectSnippetAttribute {}',
                ['audited' => "\$g->member('#[Deprecated] public function f(): void {}');"],
                'attribute groups are not allowed in generated code',
            ],
            'unknown property' => [
                '#[Audited] final class SubjectUnknownProperty { public int $x = 0; }',
                ['audited' => "\$g->property('nope')->setter('{{call}}');"],
                'property $nope does not exist',
            ],
        ];
    }

    /** @param array<string, string> $providers */
    private function compile(string $case, string $declaration, array $providers): string
    {
        $root = sys_get_temp_dir() . '/typephp-rejection-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        $suffix = str_replace(' ', '', ucwords($case));

        $this->write($root . '/src/Subject.php', $this->hostFile($declaration));
        $this->write($root . '/providers/Providers.php', $this->providerFile($providers, $suffix));
        $this->write(
            $root . '/project.yml',
            "name: rejection\nsources:\n  - src\ncompile-time-attributes:\n  providers:\n    - file: providers/Providers.php\n",
        );

        try {
            CompilerTest::create(TYPEPHP_ROOT_PATH)->prepare($root . '/project.yml');
        } catch (\Throwable $error) {
            return $error->getMessage();
        }

        $this->fail('expected the expansion of case ' . $case . ' to fail');
    }

    private function hostFile(string $declaration): string
    {
        return <<<PHP
<?php
namespace Reject;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Audited
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Clamped
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Logged
{
}

{$declaration}
PHP;
    }

    /** @param array<string, string> $providers */
    private function providerFile(array $providers, string $suffix): string
    {
        $classes = '';
        foreach ($providers as $annotation => $statement) {
            $target = $annotation === 'audited' ? 'class' : 'property';
            $name = ucfirst($annotation);
            $class = $name . 'Provider' . $suffix;
            $classes .= <<<PHP

#[ProviderFor(\\Reject\\{$name}::class, target: '{$target}')]
final class {$class}
{
    public function compile(Generator \$g): void
    {
        {$statement}
    }
}

PHP;
        }

        return <<<PHP
<?php
namespace RejectProviders;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;
{$classes}
PHP;
    }

    private function write(string $file, string $contents): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    private function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
