<?php

use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerTest;

final class ForeachTypedLocalTest extends BaseTest
{
    #[DataProvider('locals')]
    public function testReusedLocalUsesNormalAssignmentConversion(string $initial, string $helper, bool $keyTarget = false): void
    {
        global $translator;
        $directory = sys_get_temp_dir() . '/foreach-local-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/main.php';
        $target = $keyTarget ? '$value => $unused' : '$value';
        file_put_contents($file, '<?php function rows(): array { return []; } function main(): void { $value = '
            . $initial . '; foreach (rows() as ' . $target . ') { var_dump($value); } }');
        try {
            $translator = CompilerTest::create($directory);
            $translator->addFiles([$file]);
            $translator->prepareFile($file);
            $output = file_get_contents($translator->convertFile($file));
            self::assertStringContainsString('php::ForeachIterator', $output);
            $assignment = $helper === '' ? 'tmp_var_\d+' : 'php::' . $helper . '\(tmp_var_\d+\)';
            self::assertMatchesRegularExpression('/value = ' . $assignment . ';/', $output);
            self::assertSame(1, substr_count($output, $keyTarget ? '.key()' : '.value()'));
        } finally {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public static function locals(): iterable
    {
        yield 'array' => ['[]', ''];
        yield 'string' => ['""', ''];
        yield 'int' => ['0', 'toInt'];
        yield 'float' => ['0.0', 'toFloat'];
        yield 'bool' => ['false', 'toBool'];
        yield 'string key' => ['""', '', true];
        yield 'int key' => ['0', 'toInt', true];
    }
}
