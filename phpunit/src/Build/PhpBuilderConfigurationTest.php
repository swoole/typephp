<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\Build\PhpBuilderConfiguration;

/**
 * @internal
 * @coversNothing
 */
final class PhpBuilderConfigurationTest extends TestCase
{
    public function testParsesYamlMapping(): void
    {
        $configuration = PhpBuilderConfiguration::fromYaml([
            'zts' => 'on',
            'debug' => 'off',
            'extensions' => ['swoole', 'ext-mongodb', 'swoole'],
        ]);

        self::assertTrue($configuration->zts);
        self::assertFalse($configuration->debug);
        self::assertSame(['mongodb', 'swoole'], $configuration->extensions);
    }

    public function testParsesCommandLineSyntax(): void
    {
        $configuration = PhpBuilderConfiguration::fromCommandLine(
            'extensions: [curl, mbstring]; zts: off; debug: on;',
        );

        self::assertFalse($configuration->zts);
        self::assertTrue($configuration->debug);
        self::assertSame(['curl', 'mbstring'], $configuration->extensions);
    }

    public function testEmptyCommandLineConfigurationUsesMappingDefaults(): void
    {
        $configuration = PhpBuilderConfiguration::fromCommandLine('');

        self::assertSame(PHP_ZTS, $configuration->zts);
        self::assertSame(PHP_DEBUG, $configuration->debug);
        self::assertSame([], $configuration->extensions);
    }

    public function testOmittedYamlFlagsFollowTheCompilerRuntime(): void
    {
        $configuration = PhpBuilderConfiguration::fromYaml(['extensions' => ['curl']]);

        self::assertSame(PHP_ZTS, $configuration->zts);
        self::assertSame(PHP_DEBUG, $configuration->debug);
        self::assertSame(['curl'], $configuration->extensions);
    }

    #[DataProvider('explicitFlags')]
    public function testExplicitFlagsOverrideOnlyTheSelectedDefault(string $name, string $value, bool $expected): void
    {
        foreach ([
            PhpBuilderConfiguration::fromYaml([$name => $value]),
            PhpBuilderConfiguration::fromCommandLine($name . ': ' . $value),
        ] as $configuration) {
            self::assertSame($name === 'zts' ? $expected : PHP_ZTS, $configuration->zts);
            self::assertSame($name === 'debug' ? $expected : PHP_DEBUG, $configuration->debug);
        }
    }

    public static function explicitFlags(): array
    {
        return [
            ['zts', 'on', true],
            ['zts', 'off', false],
            ['debug', 'on', true],
            ['debug', 'off', false],
        ];
    }

    public function testRejectsInvalidDebugFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`php-builder.debug` must be on or off');

        PhpBuilderConfiguration::fromYaml(['debug' => 'invalid']);
    }

    public function testRejectsLegacyListOfMappingsYamlShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`php-builder` must be a mapping');

        PhpBuilderConfiguration::fromYaml([
            ['zts' => true],
            ['extensions' => []],
        ]);
    }

    public function testRejectsUnknownOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown `php-builder` option');

        PhpBuilderConfiguration::fromYaml(['sapi' => ['embed']]);
    }
}
