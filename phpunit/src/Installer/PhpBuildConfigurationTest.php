<?php

namespace TypePhp\Tests\Installer;

use PHPUnit\Framework\TestCase;
use TypePhp\Installer\LinuxPackageManager;
use TypePhp\Installer\LibPhpInstaller;
use TypePhp\Installer\PhpBuildConfiguration;

final class PhpBuildConfigurationTest extends TestCase
{
    public function testDerivePreservesExtensionsAndReplacesInstallationOptions(): void
    {
        $options = PhpBuildConfiguration::derive(
            "'--prefix=/usr' '--with-curl' '--enable-mbstring' '--with-apxs2=/usr/bin/apxs' " .
            "'--with-config-file-path=/etc/php/8.4/cli' '--enable-fpm'",
            '/home/test/.typephp'
        );

        self::assertContains('--prefix=/home/test/.typephp', $options);
        self::assertContains('--enable-embed=shared', $options);
        self::assertContains('--with-curl', $options);
        self::assertContains('--enable-mbstring', $options);
        self::assertNotContains('--with-apxs2=/usr/bin/apxs', $options);
        self::assertNotContains('--enable-fpm', $options);
    }

    public function testParseShellWordsRemovesQuotesAroundAssignmentValues(): void
    {
        self::assertSame(
            ['CFLAGS=-g -O2', 'CPPFLAGS=-DNAME="Type PHP"', '--with-zlib=/opt/php libs'],
            PhpBuildConfiguration::parseShellWords(
                'CFLAGS=\'-g -O2\' CPPFLAGS="-DNAME=\"Type PHP\"" --with-zlib=/opt/php\ libs'
            )
        );
        self::assertSame(
            ['CFLAGS=-g -O2'],
            PhpBuildConfiguration::parseShellWords('CFLAGS="-g -O2"')
        );
    }

    public function testDeriveDropsUnquotedBuildFlagsFromPhpConfig(): void
    {
        $parsed = PhpBuildConfiguration::parsePhpConfigOptions(
            '--includedir=/usr/include --disable-all --with-zlib=/usr ' .
            'build_alias=x86_64-linux-gnu host_alias=x86_64-linux-gnu ' .
            'CFLAGS=-g -O2 -Werror=implicit-function-declaration -fno-omit-frame-pointer ' .
            '-fstack-protector-strong --param=ssp-buffer-size=4 -O2 -Wall -pedantic -g ' .
            'PHP_BUILD_PROVIDER=Ubuntu'
        );
        $options = PhpBuildConfiguration::derive(
            $parsed,
            '/home/test/.typephp'
        );

        self::assertSame(
            ['--includedir=/usr/include', '--disable-all', '--with-zlib=/usr'],
            $parsed
        );
        self::assertContains('--disable-all', $options);
        self::assertContains('--with-zlib=/usr', $options);
        self::assertNotContains('CFLAGS=-g', $options);
        self::assertNotContains('-O2', $options);
        self::assertNotContains('--param=ssp-buffer-size=4', $options);
        self::assertNotContains('PHP_BUILD_PROVIDER=Ubuntu', $options);
    }

    public function testDeriveDropsConfigureExecutableAndQuotedBuildAssignments(): void
    {
        $options = PhpBuildConfiguration::derive(
            "../configure '--enable-cli' CFLAGS='-g -O2' PHP_BUILD_PROVIDER=Ubuntu",
            '/home/test/.typephp'
        );

        self::assertNotContains('../configure', $options);
        self::assertNotContains('CFLAGS=-g -O2', $options);
        self::assertContains('--enable-cli', $options);
    }

    public function testDeriveKeepsInstallationDirectoriesInsideThePrefix(): void
    {
        // A Debian/Ubuntu build records absolute installation directories; reusing
        // them makes `make install` write to /usr/share/man and /usr/include, which
        // an unprivileged user cannot do.
        $options = PhpBuildConfiguration::derive(
            "'--prefix=/usr' '--exec-prefix=/usr' '--includedir=/usr/include' " .
            "'--mandir=/usr/share/man' '--infodir=/usr/share/info' '--sysconfdir=/etc' " .
            "'--localstatedir=/var' '--libdir=\${prefix}/lib/php' " .
            "'--libexecdir=/usr/lib/x86_64-linux-gnu' '--datadir=\${prefix}/share/php/8.5' " .
            "'--with-layout=GNU' '--with-openssl'",
            '/home/test/.typephp'
        );

        foreach ($options as $option) {
            self::assertStringNotContainsString('/usr/', $option);
            self::assertStringNotContainsString('${prefix}', $option);
        }
        self::assertSame(['--prefix=/home/test/.typephp'], array_values(array_filter(
            $options,
            static fn(string $option): bool => str_starts_with($option, '--prefix=')
        )));
        self::assertContains('--with-layout=GNU', $options);
        self::assertContains('--with-openssl', $options);
    }

    public function testDeriveDropsProgramNameTransformsAndStaleConfigureCaches(): void
    {
        // --program-suffix would install bin/php8.5 instead of bin/php, which the
        // installer and the platform probes both look for by its plain name.
        $options = PhpBuildConfiguration::derive(
            "'--program-suffix=8.5' '--program-prefix=x' '--program-transform-name=s,x,x,' " .
            "'--config-cache' '--cache-file=/tmp/buildd/nonexistent/config.cache' '--enable-pcntl'",
            '/home/test/.typephp'
        );

        self::assertNotContains('--program-suffix=8.5', $options);
        self::assertNotContains('--program-prefix=x', $options);
        self::assertNotContains('--program-transform-name=s,x,x,', $options);
        self::assertNotContains('--config-cache', $options);
        self::assertNotContains('--cache-file=/tmp/buildd/nonexistent/config.cache', $options);
        self::assertContains('--enable-pcntl', $options);
    }

    public function testParseShellWordsRejectsIncompleteInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated quote');

        PhpBuildConfiguration::parseShellWords("CFLAGS='-g -O2");
    }

    public function testDetectPackageManagerUsesSupportedPriority(): void
    {
        $manager = LinuxPackageManager::detect(static fn(string $command): bool => in_array($command, ['dnf', 'yum'], true));
        self::assertSame('dnf', $manager?->command);
    }

    public function testPackagesFollowEnabledConfigureOptions(): void
    {
        $manager = new LinuxPackageManager('apt-get');
        $packages = $manager->packagesForConfigureOptions(['--with-curl', '--enable-mbstring']);

        self::assertContains('build-essential', $packages);
        self::assertContains('cmake', $packages);
        self::assertContains('libcurl4-openssl-dev', $packages);
        self::assertContains('libonig-dev', $packages);
        self::assertNotContains('libzip-dev', $packages);
    }

    public function testBuildToolPackagesIncludeCompilerMakeAndCmake(): void
    {
        self::assertSame(
            ['build-essential', 'cmake', 'pkg-config'],
            (new LinuxPackageManager('apt-get'))->packagesForBuildTools()
        );
        self::assertSame(
            ['gcc', 'gcc-c++', 'make', 'cmake', 'pkgconf-pkg-config'],
            (new LinuxPackageManager('dnf'))->packagesForBuildTools()
        );
    }

    public function testMissingPackagesFiltersInstalledPackages(): void
    {
        $manager = new LinuxPackageManager('apt-get');
        $missing = $manager->missingPackages(
            ['make', 're2c'],
            static fn(string $package): bool => $package === 'make'
        );
        self::assertSame(['re2c'], $missing);
    }

    public function testExtensionNamesWithoutSuffixAlsoResolveSharedObjects(): void
    {
        self::assertSame(
            ['/php/extensions/swoole', '/php/extensions/swoole.so'],
            LibPhpInstaller::extensionFileCandidates('/php/extensions/swoole')
        );
        self::assertSame(
            ['/php/extensions/opcache.so'],
            LibPhpInstaller::extensionFileCandidates('/php/extensions/opcache.so')
        );
    }
}
