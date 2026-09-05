<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\Generator\Symbol;

class SymbolTest extends TestCase
{
    public function testGetStaticProperty(): void
    {
        $this->assertEquals('php::getStaticProperty', Symbol::getStaticProperty());
    }

    public function testGetResolvedStaticProperty(): void
    {
        $this->assertEquals('typephp_get_static_property', Symbol::getResolvedStaticProperty());
    }

    public function testSetStaticProperty(): void
    {
        $this->assertEquals('php::setStaticProperty', Symbol::setStaticProperty());
    }

    public function testInstanceOf(): void
    {
        $this->assertEquals('php::instanceOf', Symbol::instanceOf());
    }

    public function testConcat(): void
    {
        $this->assertEquals('php::concat', Symbol::concat());
    }

    public function testConstant(): void
    {
        $this->assertEquals('php::constant', Symbol::constant());
    }

    public function testGetClassEntrySafe(): void
    {
        $this->assertEquals('php::getClassEntrySafe', Symbol::getClassEntrySafe());
    }

    public function testArgList(): void
    {
        $this->assertEquals('php::ArgList', Symbol::argList());
    }

    public function testVarList(): void
    {
        $this->assertEquals('php::VarList', Symbol::varList());
    }

    public function testGetCalledCe(): void
    {
        $this->assertSame('typephp_get_called_ce(this_)', Symbol::getCalledCe());
    }

    public function testGetCalledClass(): void
    {
        $this->assertSame('typephp_get_called_class(this_)', Symbol::getCalledClass());
    }

    public function testSafeIndex(): void
    {
        $result = Symbol::safeIndex('0', '10');
        $this->assertEquals('php::safeIndex(0, 10)', $result);
    }

    public function testSafeIndexWithVariables(): void
    {
        $result = Symbol::safeIndex('i', 'count');
        $this->assertEquals('php::safeIndex(i, count)', $result);
    }

    public function testSafeIndexWithFixedIntegerSize(): void
    {
        $result = Symbol::safeIndex('i', 10);
        $this->assertEquals('php::safeIndex(i, 10)', $result);
    }
}
