<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\PhpSourceExtensionIndex;
use TypePhp\Build\SapiExtensionConfiguration;
use TypePhp\Build\SapiExtensionRequirements;

/**
 * @internal
 * @coversNothing
 */
final class SapiExtensionRequirementsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typephp-sapi-ext-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            exec('rm -rf ' . escapeshellarg($this->directory));
        }
    }

    public function testComposerRequireExtensionsAreNormalizedAndSorted(): void
    {
        mkdir($this->directory . '/vendor/package/name', 0777, true);
        file_put_contents($this->directory . '/vendor/autoload.php', '<?php');
        $embedded = $this->directory . '/vendor/package/name/file.php';
        file_put_contents($embedded, '<?php');
        file_put_contents($this->directory . '/composer.json', json_encode([
            'require' => [
                'php' => '^8.4',
                'ext-Zend-OPcache' => '*',
                'ext-pdo_mysql' => '*',
            ],
            'require-dev' => ['ext-xdebug' => '*'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(
            ['opcache', 'pdo-mysql'],
            SapiExtensionRequirements::fromEmbeddedVendorFiles([$embedded]),
        );
    }

    public function testComposerOutsideEmbeddedVendorIsIgnored(): void
    {
        file_put_contents($this->directory . '/composer.json', json_encode([
            'require' => ['ext-curl' => '*'],
        ], JSON_THROW_ON_ERROR));
        $application = $this->directory . '/index.php';
        file_put_contents($application, '<?php');

        self::assertSame([], SapiExtensionRequirements::fromEmbeddedVendorFiles([$application]));
    }

    public function testPhpSourceConfigureSwitchesFollowExtensionConfig(): void
    {
        mkdir($this->directory . '/ext/curl', 0777, true);
        mkdir($this->directory . '/ext/mbstring', 0777, true);
        file_put_contents($this->directory . '/ext/curl/config.m4', 'PHP_ARG_WITH([curl], [curl])');
        file_put_contents($this->directory . '/ext/mbstring/config.m4', 'PHP_ARG_ENABLE([mbstring], [mb])');

        self::assertSame(
            ['--with-curl', '--enable-mbstring'],
            SapiExtensionConfiguration::configureOptions(
                $this->directory,
                ['curl', 'Core', 'mbstring'],
            ),
        );
    }

    public function testRequiredDependenciesAreEnabledWithoutOptionalDependencies(): void
    {
        $configs = [
            'pdo_sqlite' => 'PHP_ARG_WITH([pdo-sqlite], [sqlite]) PHP_ADD_EXTENSION_DEP(pdo_sqlite, pdo)',
            'pdo' => 'PHP_ARG_ENABLE([pdo], [pdo]) PHP_ADD_EXTENSION_DEP(pdo, spl)',
            'xmlreader' => 'PHP_ARG_ENABLE([xmlreader], [reader]) PHP_ADD_EXTENSION_DEP(xmlreader, libxml) PHP_ADD_EXTENSION_DEP(xmlreader, dom, true)',
            'libxml' => 'PHP_ARG_WITH([libxml], [xml])',
        ];
        foreach ($configs as $extension => $configuration) {
            mkdir($this->directory . '/ext/' . $extension, 0777, true);
            file_put_contents($this->directory . '/ext/' . $extension . '/config.m4', $configuration);
        }

        self::assertSame(
            ['--with-pdo-sqlite', '--enable-xmlreader', '--enable-pdo', '--with-libxml'],
            SapiExtensionConfiguration::configureOptions($this->directory, ['pdo_sqlite', 'xmlreader']),
        );
        self::assertSame([], SapiExtensionConfiguration::configureOptions($this->directory, []));
    }

    public function testTransitiveDependenciesHandleCyclesCommentsAndNumberedConfigFiles(): void
    {
        mkdir($this->directory . '/ext/pdo_mysql', 0777, true);
        mkdir($this->directory . '/ext/pdo', 0777, true);
        mkdir($this->directory . '/ext/mysqlnd', 0777, true);
        file_put_contents($this->directory . '/ext/pdo_mysql/config.m4', <<<'M4'
PHP_ARG_WITH([pdo-mysql], [mysql])
PHP_ADD_EXTENSION_DEP([pdo_mysql], [pdo])
PHP_ADD_EXTENSION_DEP(pdo_mysql, mysqlnd)
dnl PHP_ADD_EXTENSION_DEP(pdo_mysql, ignored)
# PHP_ADD_EXTENSION_DEP(pdo_mysql, ignored)
PHP_ADD_EXTENSION_DEP(unrelated, ignored)
M4);
        file_put_contents($this->directory . '/ext/pdo/config.m4', 'PHP_ARG_ENABLE(pdo, pdo) PHP_ADD_EXTENSION_DEP(pdo, mysqlnd)');
        file_put_contents($this->directory . '/ext/mysqlnd/config9.m4', 'PHP_ARG_ENABLE(mysqlnd, mysqlnd) PHP_ADD_EXTENSION_DEP(mysqlnd, pdo)');

        self::assertSame(
            ['--with-pdo-mysql', '--enable-pdo', '--enable-mysqlnd'],
            SapiExtensionConfiguration::configureOptions($this->directory, ['pdo_mysql', 'pdo_mysql']),
        );
    }

    public function testPhpSourceStubsProvideFunctionAndClassOwnership(): void
    {
        mkdir($this->directory . '/ext/example', 0777, true);
        file_put_contents($this->directory . '/ext/example/example.stub.php', <<<'PHP'
<?php
namespace {
    function example_open(): void {}
}
namespace Example {
    class Client {}
}
PHP);

        $index = PhpSourceExtensionIndex::forSource($this->directory);
        self::assertSame('example', $index->functionExtension('example_open'));
        self::assertSame('example', $index->classExtension('Example\Client'));
    }
}
