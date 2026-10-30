<?php

namespace TypePhp\Tests\CompileTimeAttribute;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TypePhp\Build\PhpxLocator;

/** @coversNothing */
final class ProviderBuildTest extends TestCase
{
    public function testGeneratedMembersAndHooksReachTheBuiltBinary(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The provider build integration test requires Linux');
        }
        $phpx = PhpxLocator::resolve(TYPEPHP_ROOT_PATH);
        if (!is_file($phpx . '/lib/libphpx.so')) {
            self::markTestSkipped('A built PHPX shared library is required');
        }

        $directory = sys_get_temp_dir() . '/typephp-ledger-' . bin2hex(random_bytes(6));
        mkdir($directory . '/src', 0777, true);
        mkdir($directory . '/providers');

        try {
            file_put_contents($directory . '/src/Attributes.php', <<<'PHP'
<?php

namespace Ledger;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Audited
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Clamped
{
    public function __construct(public int $min)
    {
    }
}
PHP);
            file_put_contents($directory . '/src/Account.php', <<<'PHP'
<?php

namespace Ledger;

#[Audited]
final class Account
{
    #[Clamped(0)]
    public int $balance = 0;
}
PHP);
            file_put_contents($directory . '/src/main.php', <<<'PHP'
<?php

function main(): void
{
    $account = new \Ledger\Account();
    $account->balance = 120;
    $account->balance = -5;

    echo $account->balance, ' ', $account->writes(), "\n";
}
PHP);
            file_put_contents($directory . '/providers/Audited.php', <<<'PHP'
<?php

namespace Ledger\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Ledger\Audited::class, target: 'class')]
final class AuditedProvider
{
    public function compile(Generator $g): void
    {
        $g->member('private int $writes = 0;');
        $g->member('public function writes(): int { return $this->writes; }');
        $g->property('balance')->setter('$this->writes++; {{call}}');
    }
}
PHP);
            file_put_contents($directory . '/providers/Clamped.php', <<<'PHP'
<?php

namespace Ledger\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Ledger\Clamped::class, target: 'property')]
final class ClampedProvider
{
    public function compile(Generator $g): void
    {
        [$min] = $g->args();
        $g->property()->setter('if ($value < ' . $min . ') { $value = ' . $min . '; } {{call}}');
    }
}
PHP);

            $this->compile($directory);

            $environment = getenv();
            $environment['LD_LIBRARY_PATH'] = $phpx . '/lib:' . ($environment['LD_LIBRARY_PATH'] ?? '');
            $result = $this->execute([$directory . '/ledger'], $directory, $environment);
            self::assertSame(0, $result['status'], $result['output']);
            self::assertSame("0 2\n", $result['output']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function compile(string $directory): void
    {
        $yaml = Yaml::dump([
            'name' => 'ledger',
            'mode' => 'bin',
            'build-dir' => 'build-ledger',
            'sources' => ['src'],
            'compile-time-attributes' => [
                'providers' => [
                    ['file' => 'providers/Audited.php'],
                    ['file' => 'providers/Clamped.php'],
                ],
            ],
        ]);
        file_put_contents($directory . '/ledger.yml', $yaml);
        $result = $this->execute(
            [PHP_BINARY, TYPEPHP_ROOT_PATH . '/bin/tpc.php', 'ledger.yml', '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress', '--no-color'],
            $directory,
        );
        self::assertSame(0, $result['status'], $result['output']);
    }

    /** @return array{status: int, output: string} */
    private function execute(array $command, string $directory, ?array $environment = null): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ], $pipes, $directory, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['status' => proc_close($process), 'output' => $output];
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
