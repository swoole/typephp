<?php

namespace TypePhpTest\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\FileScanner;
use TypePhp\CompilerTest;

final class FileScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/typephp-file-scanner-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/project/src', 0777, true);
        mkdir($this->root . '/outside/src', 0777, true);
        file_put_contents($this->root . '/project/src/Own.php', "<?php\nclass Own {}\n");
        file_put_contents($this->root . '/outside/src/Linked.php', "<?php\nclass Linked {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /**
     * A Composer path repository installs a package as a symlink, so the files
     * behind one have to be scanned like any other source.
     */
    public function testFollowsSymlinkedDirectory(): void
    {
        symlink($this->root . '/outside', $this->root . '/project/vendor-link');

        $files = (new FileScanner($this->root . '/project'))->scan();

        $this->assertSame([
            $this->root . '/project/src/Own.php',
            $this->root . '/project/vendor-link/src/Linked.php',
        ], $files);
    }

    public function testFollowsSymlinkedFile(): void
    {
        symlink($this->root . '/outside/src/Linked.php', $this->root . '/project/src/Linked.php');

        $files = (new FileScanner($this->root . '/project'))->scan();

        $this->assertSame([
            $this->root . '/project/src/Linked.php',
            $this->root . '/project/src/Own.php',
        ], $files);
    }

    /**
     * A link pointing at one of its own ancestors must not recurse forever.
     */
    public function testSymlinkCycleIsScannedOnce(): void
    {
        symlink($this->root . '/project', $this->root . '/project/src/loop');

        $files = (new FileScanner($this->root . '/project'))->scan();

        $this->assertSame([$this->root . '/project/src/Own.php'], $files);
    }

    /**
     * Two links to one directory are one source file seen twice, and compiling
     * it twice would define its symbols twice.
     */
    public function testAliasesOfOneFileAreScannedOnce(): void
    {
        symlink($this->root . '/outside', $this->root . '/project/link-a');
        symlink($this->root . '/outside', $this->root . '/project/link-b');

        $files = (new FileScanner($this->root . '/project'))->scan();

        $this->assertSame([
            $this->root . '/project/link-a/src/Linked.php',
            $this->root . '/project/src/Own.php',
        ], $files);
    }

    /**
     * Excluding one alias says nothing about the other. Deduplicating before
     * exclusions would drop the allowed path along with the excluded one.
     */
    public function testExcludingOneAliasKeepsTheOther(): void
    {
        symlink($this->root . '/outside', $this->root . '/project/link-a');
        symlink($this->root . '/outside', $this->root . '/project/link-b');

        foreach (['link-a' => 'link-b', 'link-b' => 'link-a'] as $excluded => $kept) {
            $files = (new FileScanner($this->root . '/project'))
                ->addExcludePattern($this->root . '/project/' . $excluded . '/src/*')
                ->scan();

            $this->assertSame([
                $this->root . '/project/' . $kept . '/src/Linked.php',
                $this->root . '/project/src/Own.php',
            ], $files, "excluding {$excluded} must not hide {$kept}");
        }
    }

    /**
     * `ignore` names the path that reaches a directory, not the path it points
     * at, so the scanned path is what an entry has to be compared against.
     */
    public function testYamlIgnoreExcludesLinkedDirectory(): void
    {
        symlink($this->root . '/outside', $this->root . '/project/vendor-link');
        file_put_contents(
            $this->root . '/project/project.yml',
            "name: demo\nsources:\n  - .\nignore:\n  - vendor-link\n",
        );

        $this->assertSame(
            [$this->root . '/project/src/Own.php'],
            $this->scanProject($this->root . '/project/project.yml'),
        );
    }

    /**
     * An entry that names the link target leaves the linked path compiled: it
     * describes a directory the scan never reached.
     */
    public function testYamlIgnoreOfTheLinkTargetLeavesTheLinkedPath(): void
    {
        symlink($this->root . '/outside', $this->root . '/project/vendor-link');
        file_put_contents(
            $this->root . '/project/project.yml',
            "name: demo\nsources:\n  - .\nignore:\n  - ../outside\n",
        );

        $this->assertSame([
            $this->root . '/project/src/Own.php',
            $this->root . '/project/vendor-link/src/Linked.php',
        ], $this->scanProject($this->root . '/project/project.yml'));
    }

    /** @return list<string> */
    private function scanProject(string $projectFile): array
    {
        $compiler   = CompilerTest::create(dirname($projectFile));
        $reflection = new \ReflectionClass($compiler);

        $parse  = $reflection->getMethod('parseProjectYaml');
        $filter = $reflection->getMethod('filterIgnoredFiles');

        return array_values($filter->invoke($compiler, $parse->invoke($compiler, $projectFile)));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }

            $this->removeDirectory($path);
        }

        rmdir($directory);
    }
}
