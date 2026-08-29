<?php

namespace TypePhp\Tests\Generator;

use TypePhp\Type;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;

class UtilsTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/utils_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map('unlink', glob($this->testDir . '/*'));
        rmdir($this->testDir);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $ref = new \ReflectionClass($this->compiler);
        $meth = $ref->getMethod($method);
        $meth->setAccessible(true);
        return $meth->invoke($this->compiler, ...$args);
    }

    // ========================================================================
    // genCValue
    // ========================================================================

    public function testGenCValueInt(): void
    {
        $this->assertSame($this->invokeMethod('genIntegerLiteral', 42), $this->invokeMethod('genCValue', 42));
        $this->assertSame($this->invokeMethod('genIntegerLiteral', -1), $this->invokeMethod('genCValue', -1));
        $this->assertSame($this->invokeMethod('genIntegerLiteral', 0), $this->invokeMethod('genCValue', 0));
    }

    public function testGenCValueFloat(): void
    {
        $result = $this->invokeMethod('genCValue', 3.14);

        // The literal has to read back as the same double. Its exact spelling
        // is not part of the contract, but a string cast would depend on the
        // precision ini and lose digits.
        $this->assertSame(3.14, (float) $result);
        $this->assertMatchesRegularExpression('/[.E]/i', $result);
    }

    public function testGenCValueBool(): void
    {
        $this->assertSame('1', $this->invokeMethod('genCValue', true));
        $this->assertSame('0', $this->invokeMethod('genCValue', false));
    }

    public function testGenCValueString(): void
    {
        $result = $this->invokeMethod('genCValue', 'hello');
        $this->assertEquals('"hello"', $result);
    }

    // ========================================================================
    // genCharPtr
    // ========================================================================

    public function testGenCharPtr(): void
    {
        $this->assertEquals('"hello"', $this->invokeMethod('genCharPtr', 'hello'));
        $this->assertEquals('""', $this->invokeMethod('genCharPtr', ''));
    }

    public function testGenCharPtrEscape(): void
    {
        $result = $this->invokeMethod('genCharPtr', 'hello "world"', true);
        $this->assertStringContainsString('\\"', $result);
    }

    // ========================================================================
    // genArray
    // ========================================================================

    public function testGenArray(): void
    {
        $result = $this->invokeMethod('genArray', ['1', '2', '3']);
        $this->assertStringStartsWith(Type::ARRAY . '{', $result);
        $this->assertStringContainsString('1, 2, 3', $result);
    }

    public function testGenArrayEmpty(): void
    {
        $result = $this->invokeMethod('genArray', []);
        $this->assertStringStartsWith(Type::ARRAY . '{', $result);
    }

    // ========================================================================
    // escapeString
    // ========================================================================

    public function testEscapeStringSimple(): void
    {
        $this->assertEquals('hello', $this->invokeMethod('escapeString', 'hello'));
    }

    public function testEscapeStringQuotes(): void
    {
        $result = $this->invokeMethod('escapeString', 'he"llo');
        $this->assertStringContainsString('\\"', $result);
    }

    public function testEscapeStringBackslash(): void
    {
        $result = $this->invokeMethod('escapeString', 'a\\b');
        $this->assertStringContainsString('\\\\', $result);
    }

    public function testEscapeStringNewline(): void
    {
        $result = $this->invokeMethod('escapeString', "a\nb");

        // newline may be escaped as \n or literal depending on addcslashes behavior
        $this->assertNotEquals("a\nb", $result);
    }

    // ========================================================================
    // escapeBool
    // ========================================================================

    public function testEscapeBool(): void
    {
        $this->assertEquals('true', $this->invokeMethod('escapeBool', true));
        $this->assertEquals('false', $this->invokeMethod('escapeBool', false));
    }

    // ========================================================================
    // escapeVarName / unescapeVarName
    // ========================================================================

    public function testEscapeVarNameNormal(): void
    {
        $this->assertEquals('foo', $this->invokeMethod('escapeVarName', 'foo'));
        $this->assertEquals('bar', $this->invokeMethod('escapeVarName', 'bar'));
    }

    public function testEscapeVarNameThis(): void
    {
        $this->assertEquals('this_', $this->invokeMethod('escapeVarName', 'this'));
    }

    public function testEscapeVarNameReservedKeyword(): void
    {
        // 'class' is in CPP_RESERVED_NAMES
        $result = $this->invokeMethod('escapeVarName', 'class');
        $this->assertStringStartsWith('_php__var__', $result);
    }

    public function testEscapeVarNameStdioMacros(): void
    {
        foreach (['stdin', 'stdout', 'stderr'] as $name) {
            $this->assertEquals('_php__var__' . $name, $this->invokeMethod('escapeVarName', $name));
        }
    }

    public function testUnescapeVarName(): void
    {
        $this->assertEquals('foo', $this->invokeMethod('unescapeVarName', '_php__var__foo'));
        $this->assertEquals('bar', $this->invokeMethod('unescapeVarName', 'bar'));
    }

    // ========================================================================
    // escapeNamespace
    // ========================================================================

    public function testEscapeNamespace(): void
    {
        $result = $this->invokeMethod('escapeNamespace', 'App\\Lib\\Module');
        $this->assertEquals('app__lib__module', $result);
    }

    public function testEscapeNamespaceNoBackslash(): void
    {
        $result = $this->invokeMethod('escapeNamespace', 'app');
        $this->assertEquals('app', $result);
    }

    // ========================================================================
    // escapeZendFnName / escapeCeName
    // ========================================================================

    public function testEscapeZendFnNameLower(): void
    {
        $result = $this->invokeMethod('escapeZendFnName', 'App\\Foo\\bar', true);
        $this->assertEquals('app_foo_bar', $result);
    }

    public function testEscapeZendFnNameNoLower(): void
    {
        $result = $this->invokeMethod('escapeZendFnName', 'App\\Foo\\bar', false);
        $this->assertEquals('App_Foo_bar', $result);
    }

    public function testEscapeCeName(): void
    {
        $result = $this->invokeMethod('escapeCeName', 'App\\Entity\\User');
        $this->assertEquals('App_Entity_User', $result);
    }

    // ========================================================================
    // escapeName
    // ========================================================================

    public function testEscapeName(): void
    {
        $this->assertEquals('foo', $this->invokeMethod('escapeName', 'FOO'));
        $this->assertEquals('bar', $this->invokeMethod('escapeName', 'BAR'));
    }

    // ========================================================================
    // escapeClass / escapeFunction
    // ========================================================================

    public function testEscapeClass(): void
    {
        $result = $this->invokeMethod('escapeClass', '\\App\\Lib\\MyClass');
        $this->assertEquals('app_lib_myclass', $result);
    }

    public function testEscapeFunction(): void
    {
        $result = $this->invokeMethod('escapeFunction', 'App\\Foo\\run');
        $this->assertEquals('app_foo_run', $result);
    }

    // ========================================================================
    // escapeFileName
    // ========================================================================

    public function testEscapeFileName(): void
    {
        $this->assertEquals('my_file', $this->invokeMethod('escapeFileName', 'my-file'));
        $this->assertEquals('a_b_c', $this->invokeMethod('escapeFileName', 'a-b-c'));
    }

    public function testEscapeFileNameNoDash(): void
    {
        $this->assertEquals('already_good', $this->invokeMethod('escapeFileName', 'already_good'));
    }

    // ========================================================================
    // escapeGlobalVar
    // ========================================================================

    public function testEscapeGlobalVar(): void
    {
        $result = $this->invokeMethod('escapeGlobalVar', 'myvar');
        $this->assertStringStartsWith('_global_var_', $result);
        $this->assertStringEndsWith('myvar', $result);
    }

    // ========================================================================
    // isClosedExpr
    // ========================================================================

    public function testIsClosedExprSimple(): void
    {
        $this->assertTrue($this->invokeMethod('isClosedExpr', '(a + b)', ''));
        $this->assertTrue($this->invokeMethod('isClosedExpr', '(1)', ''));
    }

    public function testIsClosedExprNotClosed(): void
    {
        $this->assertFalse($this->invokeMethod('isClosedExpr', 'a + b', ''));
        $this->assertFalse($this->invokeMethod('isClosedExpr', '(a + b', ''));
    }

    public function testIsClosedExprNested(): void
    {
        $this->assertTrue($this->invokeMethod('isClosedExpr', '((a + b) * c)', ''));
    }

    public function testIsClosedExprWithCall(): void
    {
        $this->assertTrue($this->invokeMethod('isClosedExpr', 'foo(1, 2)', 'foo'));
        $this->assertFalse($this->invokeMethod('isClosedExpr', 'bar(1, 2)', 'foo'));
    }

}
