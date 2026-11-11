<?php

namespace TypePhp\Tests;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Analysis\ObjectBindingAnalyzer;

final class ObjectBindingAnalyzerTest extends TestCase
{
    #[DataProvider('bindingUses')]
    public function testReferenceExposureAndBranchWrites(string $source, bool $includeWrites, bool $unsafe): void
    {
        $body = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source);
        $bindings = (new ObjectBindingAnalyzer())->analyze($body, $includeWrites);
        self::assertSame($unsafe, isset($bindings['*']) || isset($bindings['value']));
    }

    public static function bindingUses(): array
    {
        return [
            ['$value->method();', true, false],
            ['$value = new stdClass();', false, false],
            ['$value = new stdClass();', true, true],
            ['unset($value);', true, true],
            ['replace($value);', false, true],
            ['$alias =& $value;', false, true],
            ['$array = [&$value];', false, true],
            ['$ref = $value->toRef();', false, true],
            ['$read = function () use (&$value) {};', false, true],
            ['$read = function () use ($value) { $value = null; };', true, false],
            ['$read = fn() => ($value = null);', true, false],
            ['foreach ($array as &$value) {}', false, true],
            ['foreach ($array as $value) {}', true, true],
            ['global $value;', false, true],
            ['static $value;', false, true],
            ['goto done; done: echo 1;', false, true],
        ];
    }
}
