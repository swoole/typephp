<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;

final class StaticPropertySlotTest extends TestCase
{
    public function testRepeatedUseKeepsTheFirstResolverAndDeclarationOrder(): void
    {
        $context = new FunctionContext();
        $first = $context->registerStaticPropertySlot('Example::$value', 'first_resolver()');
        $second = $context->registerStaticPropertySlot('static::$value', 'called_resolver()');

        self::assertSame($first, $context->registerStaticPropertySlot('Example::$value', 'unused_resolver()'));
        self::assertNotSame($first, $second);
        $slots = $context->getStaticPropertySlots();
        self::assertSame(['Example::$value', 'static::$value'], array_keys($slots));
        self::assertSame('first_resolver()', $slots['Example::$value']['resolver']);
        self::assertSame($slots['Example::$value']['accessorName'] . '()', $first);
        self::assertSame($slots['static::$value']['accessorName'] . '()', $second);
    }

    public function testPropertyCaseAndTraitBindingHaveDistinctSlots(): void
    {
        $context = new FunctionContext();
        $calls = [];
        foreach (['Example::$value', 'Example::$Value', 'static::$value', 'trait-self::$value'] as $key) {
            $calls[] = $context->registerStaticPropertySlot($key, 'resolver()');
        }

        self::assertCount(4, array_unique($calls));
        self::assertCount(4, array_unique(array_column($context->getStaticPropertySlots(), 'name')));
        self::assertCount(4, $context->getStaticPropertySlots());
    }

    public function testContextsAndDeclarationSnapshotsCannotChangeEachOthersSlots(): void
    {
        $outer = new FunctionContext();
        $first = $outer->registerStaticPropertySlot('Example::$value', 'outer_resolver()');
        $inner = new FunctionContext();
        self::assertSame([], $inner->getStaticPropertySlots());
        self::assertSame($first, $inner->registerStaticPropertySlot('static::$value', 'inner_resolver()'));

        $snapshot = $outer->getStaticPropertySlots();
        $snapshot['Example::$value']['resolver'] = 'changed()';
        unset($snapshot['Example::$value']);
        $snapshot['injected'] = [];

        self::assertSame(['Example::$value'], array_keys($outer->getStaticPropertySlots()));
        self::assertSame('outer_resolver()', $outer->getStaticPropertySlots()['Example::$value']['resolver']);
        self::assertSame(['static::$value'], array_keys($inner->getStaticPropertySlots()));
        self::assertSame('inner_resolver()', $inner->getStaticPropertySlots()['static::$value']['resolver']);
    }
}
