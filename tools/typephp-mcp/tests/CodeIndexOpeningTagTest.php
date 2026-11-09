<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/TextUtil.php';
require_once __DIR__ . '/../src/RepoRoot.php';
require_once __DIR__ . '/../src/CodeIndex.php';

final class CodeIndexOpeningTagTest extends TestCase
{
    private string $directory;
    private CodeIndex $index;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typephp-opening-tag-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->index = new CodeIndex(RepoRoot::detect());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function declarations(): array
    {
        return [
            'function' => ['function InlineFunction(): int { return 42; }', 'InlineFunction'],
            'class' => ['final class InlineClass {}', 'InlineClass'],
            'interface' => ['interface InlineInterface {}', 'InlineInterface'],
            'trait' => ['trait InlineTrait {}', 'InlineTrait'],
            'enum' => ['enum InlineEnum { case Ready; }', 'InlineEnum'],
            'constant' => ['const INLINE_CONSTANT = 42;', 'INLINE_CONSTANT'],
        ];
    }

    #[DataProvider('declarations')]
    public function testDeclarationAfterOpeningTagIsFoundOnItsOriginalLine(string $declaration, string $name): void
    {
        file_put_contents($this->directory . '/inline.php', '<?php ' . $declaration);

        $result = $this->index->getSymbol($name, [$this->directory]);

        self::assertStringContainsString('inline.php:1  ' . $declaration, $result);
    }

    public function testOpeningTagNamespaceAppliesToFollowingDeclarations(): void
    {
        file_put_contents($this->directory . '/namespaced.php', "<?php namespace InlineNamespace;\n\nfunction namespacedProbe(): void {}\n");

        $result = $this->index->getSymbol('InlineNamespace\\namespacedProbe', [$this->directory]);

        self::assertStringContainsString('namespaced.php:3  function namespacedProbe', $result);
    }

    public function testClassAfterOpeningTagSupportsMethodLookup(): void
    {
        file_put_contents($this->directory . '/class.php', "<?php final class FirstLineClass {\n    public function value(): int { return 42; }\n}\n");

        $result = $this->index->getSymbol('FirstLineClass::value', [$this->directory]);

        self::assertStringContainsString('class.php:2  public function value', $result);
        self::assertStringContainsString('(in class FirstLineClass)', $result);
    }

    public function testSearchReportsTheEnclosingDeclarationAfterOpeningTag(): void
    {
        file_put_contents($this->directory . '/search.php', '<?php function searchInline(): string { return "opening-tag-marker"; }');

        $result = $this->index->searchCode('opening-tag-marker', 5, [$this->directory]);

        self::assertStringContainsString('search.php:1  in function searchInline', $result);
    }

    public function testTagWhitespaceAndCaseDoNotChangeLineNumbers(): void
    {
        file_put_contents($this->directory . '/tabs.php', "<?PHP\tfunction tabbedProbe(): void {}\r\n");
        file_put_contents($this->directory . '/later.php', "<?php\n\nfunction laterProbe(): void {}\n");

        self::assertStringContainsString('tabs.php:1  function tabbedProbe', $this->index->getSymbol('tabbedProbe', [$this->directory]));
        self::assertStringContainsString('later.php:3  function laterProbe', $this->index->getSymbol('laterProbe', [$this->directory]));
    }

    public function testTagInsideAStringDoesNotCreateADeclaration(): void
    {
        file_put_contents($this->directory . '/string.php', '<?php $text = "<?php function hiddenProbe(): void {}";');

        self::assertStringContainsString('No symbol found', $this->index->getSymbol('hiddenProbe', [$this->directory]));
    }

    public function testInvalidOpeningTagDoesNotCreateADeclaration(): void
    {
        file_put_contents($this->directory . '/invalid.php', '<?phpfunction invalidProbe(): void {}');

        self::assertStringContainsString('No symbol found', $this->index->getSymbol('invalidProbe', [$this->directory]));
    }
}
