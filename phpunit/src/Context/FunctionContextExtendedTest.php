<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Context\ScopeContext;

class FunctionContextExtendedTest extends TestCase
{
    public function testMultipleEnterLeaveScopeCycles(): void
    {
        $ctx = new FunctionContext();

        // First cycle
        $ctx->enterScope();
        $this->assertSame(1, $ctx->scopeLevel);
        $ctx->leaveScope();
        $this->assertSame(0, $ctx->scopeLevel);

        // Second cycle - should still work
        $ctx->enterScope();
        $this->assertSame(1, $ctx->scopeLevel);
        $ctx->leaveScope();
        $this->assertSame(0, $ctx->scopeLevel);
    }

    public function testDeepScopeNesting(): void
    {
        $ctx = new FunctionContext();
        for ($i = 1; $i <= 5; $i++) {
            $ctx->enterScope();
            $this->assertSame($i, $ctx->scopeLevel);
            $this->assertCount($i, $ctx->scopeLayouts);
            $this->assertInstanceOf(ScopeContext::class, $ctx->scopeLayouts[$i - 1]);
        }
        for ($i = 4; $i >= 0; $i--) {
            $ctx->leaveScope();
            $this->assertSame($i, $ctx->scopeLevel);
        }
    }

    public function testStaticVarsManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->staticVars['counter'] = 'php::Int';
        $ctx->staticVars['cache'] = 'php::Array';

        $this->assertArrayHasKey('counter', $ctx->staticVars);
        $this->assertArrayHasKey('cache', $ctx->staticVars);
        $this->assertEquals('php::Int', $ctx->staticVars['counter']);
    }

    public function testGlobalVarsManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->globalVars['_SESSION'] = 'php::Array';
        $ctx->globalVars['_ENV'] = 'php::Array';

        $this->assertArrayHasKey('_SESSION', $ctx->globalVars);
        $this->assertArrayHasKey('_ENV', $ctx->globalVars);
    }

    public function testStdArraysManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->registerStdStorage('arr1', ['kind' => 'array', 'decl' => 'php::StdArray<php::Int, 10>']);
        $ctx->registerStdStorage('arr2', ['kind' => 'array', 'decl' => 'php::StdArray<php::Float, 5>']);

        $this->assertSame('php::StdArray<php::Int, 10>', $ctx->getStdStorageInfo('arr1')['decl']);
        $this->assertSame('php::StdArray<php::Float, 5>', $ctx->getStdStorageInfo('arr2')['decl']);
        $this->assertCount(2, $ctx->localVars);
    }

    public function testStdContainersManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->registerStdStorage('vec', ['kind' => 'vector', 'decl' => 'php::StdVector<php::Int>']);
        $ctx->registerStdStorage('map', ['kind' => 'map', 'decl' => 'php::StdMap<php::Str, php::Int>']);

        $this->assertSame('php::StdVector<php::Int>', $ctx->getStdStorageInfo('vec')['decl']);
        $this->assertSame('php::StdMap<php::Str, php::Int>', $ctx->getStdStorageInfo('map')['decl']);
        $this->assertEquals('vector', $ctx->getStdStorageInfo('vec')['kind']);
        $this->assertEquals('map', $ctx->getStdStorageInfo('map')['kind']);
    }

    public function testArgumentsManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->arguments['arg1'] = 'php::Int';
        $ctx->arguments['arg2'] = 'php::Str';

        $this->assertArrayHasKey('arg1', $ctx->arguments);
        $this->assertArrayHasKey('arg2', $ctx->arguments);
    }

    public function testCeWrappersManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->getOrCreateClassWrapper('stdClass', static fn() => 'ce_wrapper_0');
        $ctx->getOrCreateClassWrapper('Exception', static fn() => 'ce_wrapper_1');

        $this->assertArrayHasKey('stdClass', $ctx->getClassWrappers());
        $this->assertArrayHasKey('Exception', $ctx->getClassWrappers());
    }

    public function testObjectPropsManipulation(): void
    {
        $ctx = new FunctionContext();
        $ctx->objectProps['_object_prop_obj__name'] = ['type' => 'php::Str', 'class' => ''];
        $ctx->objectProps['_object_prop_obj__age'] = ['type' => 'php::Int', 'class' => ''];

        $this->assertArrayHasKey('_object_prop_obj__name', $ctx->objectProps);
        $this->assertArrayHasKey('_object_prop_obj__age', $ctx->objectProps);
    }

    public function testScopeLayoutsEntriesAreUniqueInstances(): void
    {
        $ctx = new FunctionContext();
        $ctx->enterScope();
        $ctx->enterScope();

        $this->assertNotSame($ctx->scopeLayouts[0], $ctx->scopeLayouts[1]);
    }

    public function testInLoopToggleWithinScope(): void
    {
        $ctx = new FunctionContext();
        $ctx->enterScope();
        $ctx->inLoop = true;
        $this->assertTrue($ctx->inLoop);
        $ctx->leaveScope();
        // inLoop is not affected by scope leave
        $this->assertTrue($ctx->inLoop);
    }

    public function testTmpVarIndexIncrementsNormally(): void
    {
        $ctx = new FunctionContext();
        $indices = [];
        for ($i = 0; $i < 10; $i++) {
            $indices[] = $ctx->tmpVarIndex++;
        }
        $this->assertEquals(range(0, 9), $indices);
    }
}
