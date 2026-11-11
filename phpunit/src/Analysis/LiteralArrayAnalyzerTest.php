<?php

namespace TypePhp\Tests;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Analysis\LiteralArrayAnalyzer;

final class LiteralArrayAnalyzerTest extends TestCase
{
    #[DataProvider('arrayUses')]
    public function testOnlyProvenUnchangedBindingsAreEligible(string $source, bool $eligible): void
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source);
        $candidates = (new LiteralArrayAnalyzer())->analyze($statements);
        self::assertSame($eligible, isset($candidates['array']));
    }

    public static function arrayUses(): array
    {
        return [
            [' $array = [new stdClass()]; return $array[0];', true],
            [' $array = [1]; while (false) { echo $array[0]; }', true],
            [' echo $array[0]; $array = [1];', false],
            [' $array = [1]; $array[0] = 2;', false],
            [' $array = [1]; unset($array[0]);', false],
            [' $array = [1]; $array[0]++;', false],
            [' $array = [1]; replace($array[0]);', false],
            [' $array = [1]; $alias =& $array[0];', false],
            [' $array = [1]; $copy = $array;', false],
            [' $array = [1]; $read = function () use ($array) { return $array; };', false],
            [' $array = [1]; $read = fn() => $array[0];', false],
            [' $array = [1]; foreach ($array as &$item) {}', false],
            [' $array = [1]; $array = [2];', false],
            [' goto done; $array = [1]; done: return $array[0];', false],
            [' $array = [1]; $$name = [];', false],
        ];
    }
}
