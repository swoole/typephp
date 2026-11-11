<?php

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

final class PreparedProjectCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typephp_prepared_' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/source.php', <<<'PHP'
<?php
namespace Prepared;
trait Helper { public function value(): int { return 42; } }
class ParentClass { public const ITEMS = ['ready']; }
class Child extends ParentClass { use Helper; public array $items = ['ready']; }
PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    private function compiler(): CompilerTest
    {
        global $translator;
        $compiler = CompilerTest::create(dirname(__DIR__, 3));
        $compiler->setTargetName('prepared');
        $compiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $this->invoke($compiler, 'setBuildDir', $this->directory . '/build');
        $translator = $compiler;
        return $compiler;
    }

    private function invoke(CompilerTest $compiler, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($compiler, $method))->invoke($compiler, ...$arguments);
    }

    public function testPreparedSymbolsTraitsAndDefaultsRoundTrip(): void
    {
        $file = $this->directory . '/source.php';
        $first = $this->compiler();
        $key = $this->invoke($first, 'preparedProjectKey', [$file]);
        $first->prepareFile($file);
        $this->invoke($first, 'composeTraitDeclarations', [$file]);
        $this->invoke($first, 'discoverNativeGlobalObjects', [$file]);
        $this->invoke($first, 'storePreparedProject', $key);
        $first->convert([$file]);
        $extension = file_get_contents($this->directory . '/build/extension-prepared.cc');
        $second = $this->compiler();
        self::assertTrue($this->invoke($second, 'restorePreparedProject', $key));
        self::assertTrue($this->invoke($second, 'hasClass', 'Prepared\\Child'));
        $second->convert([$file]);
        self::assertSame($extension, file_get_contents($this->directory . '/build/extension-prepared.cc'));
        self::assertFalse($this->invoke($second, 'restorePreparedProject', $key));
    }

    public function testSourceChangesInvalidateEvenWithTheSameMtime(): void
    {
        $file = $this->directory . '/source.php';
        $compiler = $this->compiler();
        $key = $this->invoke($compiler, 'preparedProjectKey', [$file]);
        $mtime = filemtime($file);
        file_put_contents($file, str_replace('42', '43', file_get_contents($file)));
        touch($file, $mtime);
        self::assertNotSame($key, $this->invoke($compiler, 'preparedProjectKey', [$file]));
    }

    public function testDependencyRecordsRoundTripWithoutSerializingTheMembershipIndex(): void
    {
        $file = $this->directory . '/source.php';
        $first = $this->compiler();
        $key = $this->invoke($first, 'preparedProjectKey', [$file]);
        $first->prepareFile($file);
        $this->invoke($first, 'recordSymbolUse', $file, 'constant:Additional');
        $before = $this->invoke($first, 'getFileSymbolUses', $file);
        $this->invoke($first, 'storePreparedProject', $key);
        $cache = file_get_contents($this->invoke($first, 'preparedProjectCacheFile'));
        $state = unserialize(substr($cache, strlen($key) + 1));
        self::assertSame($before, $state['symbolCallInFile'][$file]);
        self::assertArrayHasKey('symbolDeclInFile', $state);
        self::assertArrayHasKey('globalVarDeclInFile', $state);
        self::assertArrayNotHasKey('symbolUseIndex', $state);
        $second = $this->compiler();
        self::assertTrue($this->invoke($second, 'restorePreparedProject', $key));
        $this->invoke($second, 'recordSymbolUse', $file, 'constant:Additional');
        self::assertSame($before, $this->invoke($second, 'getFileSymbolUses', $file));
        $this->invoke($second, 'recordSymbolUse', $file, 'constant:Later');
        self::assertSame([...$before, 'constant:Later'], $this->invoke($second, 'getFileSymbolUses', $file));
        self::assertSame($file, $this->invoke($second, 'getSymbolDeclarationFile', 'class:prepared\\child'));
    }

    public function testCorruptSnapshotFallsBackWithoutInstallingSymbols(): void
    {
        $compiler = $this->compiler();
        $file = $this->invoke($compiler, 'preparedProjectCacheFile');
        mkdir(dirname($file), 0777, true);
        $key = str_repeat('a', 64);
        file_put_contents($file, $key . "\ncorrupt");
        self::assertFalse($this->invoke($compiler, 'restorePreparedProject', $key));
        self::assertFalse($this->invoke($compiler, 'hasClass', 'Prepared\\Child'));
    }
}
