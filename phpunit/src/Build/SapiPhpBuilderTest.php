<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\CompilerToolchain;
use TypePhp\Build\SapiPhpBuilder;

/**
 * @internal
 * @coversNothing
 */
final class SapiPhpBuilderTest extends TestCase
{
    public function testExtractsUniqueMakeObjectTargetsForProgress(): void
    {
        $builder = new SapiPhpBuilder(__DIR__, static function (string $message): void {});
        $method = new \ReflectionMethod($builder, 'extractObjectTargets');

        self::assertSame([
            'ext/json/json.lo',
            'Zend/zend.lo',
            'main/main.lo',
        ], $method->invoke($builder, implode("\n", [
            'cc -c ext/json/json.c -o ext/json/json.lo -MMD',
            "cc -c Zend/zend.c -o 'Zend/zend.lo' -MMD",
            'cc -c main/main.c -o "main/main.lo" -MMD',
            'cc -c ext/json/json.c -o ext/json/json.lo -MMD',
            'cc objects.o -o sapi/cli/php',
        ])));
    }

    public function testAbbreviatesLongArchiverCommands(): void
    {
        $builder = new SapiPhpBuilder(__DIR__, static function (string $message): void {});
        $method = new \ReflectionMethod($builder, 'displayCommand');

        self::assertSame(
            "'ar' 'rcs' '/tmp/libphp.a' '@<6 object files>'",
            $method->invoke($builder, [
                'ar',
                'rcs',
                '/tmp/libphp.a',
                'one.o',
                'two.o',
                'three.o',
                'four.o',
                'five.o',
                'six.o',
            ]),
        );
    }

    public function testBuildsArchiveCommandForSelectedToolchain(): void
    {
        $gcc = new SapiPhpBuilder(
            __DIR__,
            static function (string $message): void {},
            toolchain: new CompilerToolchain(
                '/opt/gcc/bin/gcc',
                '/opt/gcc/bin/g++',
                '/opt/gcc/bin/gcc-ar',
                CompilerToolchain::ARCHIVER_UNIX,
            ),
        );
        $method = new \ReflectionMethod($gcc, 'archiveCommand');
        self::assertSame(
            ['/opt/gcc/bin/gcc-ar', 'rcs', '/tmp/libphp.a', '@/tmp/objects.rsp'],
            $method->invoke($gcc, '/tmp/libphp.a', '@/tmp/objects.rsp'),
        );

        $msvc = new SapiPhpBuilder(
            __DIR__,
            static function (string $message): void {},
            toolchain: new CompilerToolchain('cl', 'cl', 'lib.exe', CompilerToolchain::ARCHIVER_MSVC),
        );
        self::assertSame(
            ['lib.exe', '/NOLOGO', '/OUT:C:\\tmp\\php.lib', '@C:\\tmp\\objects.rsp'],
            $method->invoke($msvc, 'C:\\tmp\\php.lib', '@C:\\tmp\\objects.rsp'),
        );
    }

    public function testReservesOneProgressStepUntilMakeActuallyFinishes(): void
    {
        $builder = new SapiPhpBuilder(__DIR__, static function (string $message): void {});
        $method = new \ReflectionMethod($builder, 'liveProgressValue');

        self::assertSame(630, $method->invoke($builder, 634, 634));
        self::assertSame(512, $method->invoke($builder, 512, 634));
        self::assertSame(0, $method->invoke($builder, 1, 1));
    }

    public function testCacheRequiresTheSameRequestedExtensions(): void
    {
        $directory = sys_get_temp_dir() . '/typephp-runtime-cache-' . bin2hex(random_bytes(6));
        $root = $directory . '/php-8.4.25-test';
        foreach (['build', 'install/bin', 'install/lib', 'phpx-build/lib', 'source'] as $child) {
            mkdir($root . '/' . $child, 0777, true);
        }
        foreach (['build/Makefile', 'install/lib/libphp.a', 'phpx-build/lib/libphpx.a'] as $file) {
            file_put_contents($root . '/' . $file, 'fixture');
        }
        file_put_contents($root . '/install/bin/php', '#!/bin/sh');
        chmod($root . '/install/bin/php', 0755);
        $metadata = [
            'source' => $root . '/source',
            'compatibility' => 'same-platform',
            'requested_extensions' => ['curl', 'mbstring'],
            'enabled_extensions' => ['Core', 'curl', 'mbstring'],
            'sapis' => ['embed'],
        ];
        $builder = new SapiPhpBuilder(__DIR__, static function (string $message): void {});
        $method = new \ReflectionMethod($builder, 'findCompatibleRuntime');
        try {
            file_put_contents($root . '/runtime.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($method->invoke($builder, $directory, '8.4.25', 'same-platform', ['curl']));
            self::assertNull($method->invoke($builder, $directory, '8.4.25', 'same-platform', []));
            self::assertNotNull($method->invoke($builder, $directory, '8.4.25', 'same-platform', ['curl', 'mbstring']));
            self::assertNull($method->invoke($builder, $directory, '8.4.25', 'old-policy', ['curl', 'mbstring']));

            $metadata['requested_extensions'] = ['curl'];
            $metadata['enabled_extensions'] = ['Core'];
            file_put_contents($root . '/runtime.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($method->invoke($builder, $directory, '8.4.25', 'same-platform', ['curl']));

            unset($metadata['requested_extensions']);
            file_put_contents($root . '/runtime.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($method->invoke($builder, $directory, '8.4.25', 'same-platform', []));
        } finally {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testDetectsPostCompilationBuildStages(): void
    {
        $builder = new SapiPhpBuilder(__DIR__, static function (string $message): void {});
        $method = new \ReflectionMethod($builder, 'detectBuildStage');

        self::assertSame(
            'Linking OPcache',
            $method->invoke($builder, 'libtool --mode=link gcc -shared -o ext/opcache/opcache.la objects.o'),
        );
        self::assertSame(
            'Linking PHP CLI',
            $method->invoke($builder, 'libtool --mode=link gcc objects.o -o sapi/cli/php'),
        );
        self::assertSame(
            'Linking PHP FPM',
            $method->invoke($builder, 'libtool --mode=link gcc objects.o -o sapi/fpm/php-fpm'),
        );
        self::assertSame('Generating phar.php', $method->invoke($builder, 'Generating phar.php'));
        self::assertSame('Generating phar.phar', $method->invoke($builder, 'Generating phar.phar'));
    }
}
