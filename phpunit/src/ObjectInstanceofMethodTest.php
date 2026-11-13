<?php

use PhpParser\Node;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class ObjectInstanceofMethodTest extends BaseTest
{
    public function testCompilerSymbolClassCanBeCompiledWithTheReservedKeyword(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $file = TYPEPHP_ROOT_PATH . '/src/Generator/Symbol.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $code = file_get_contents($compiler->convertFile($file));
        self::assertStringContainsString('php_typephp__generator__symbol__instanceof_(', $code);
    }

    public function testObjectReceiversUseTheOperatorAndPreserveNativeBranchRefinement(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/object-instanceof-method.php';
        $compiler->addFiles([$file]);
        $compiler->prepareFile($file);
        $code = file_get_contents($compiler->convertFile($file));
        self::assertStringContainsString('php::instanceOf(', $code);
        self::assertStringNotContainsString('typephp_call_method_cached(', $code);
        self::assertStringNotContainsString('.call(', $code);
        self::assertStringContainsString('php_instanceofmethodfixture__child__value(', $code);
        self::assertSame(1, preg_match('/php::Bool php_instanceofmethodfixture__chained\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertSame(1, substr_count($match[1], 'php_instanceofmethodfixture__make()'));
        self::assertSame(1, preg_match('/php::Bool php_instanceofmethodfixture__native\([^\n]*\) \{(.*?)\n\}/s', $code, $match));
        self::assertStringContainsString('object != nullptr', $match[1]);
        self::assertStringNotContainsString('php::instanceOf(', $match[1]);
    }

    public function testKeywordReturnTypeIsBoolCaseInsensitively(): void
    {
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        foreach (['instanceOf', 'instanceof', 'INSTANCEOF'] as $name) {
            $call = new Node\Expr\MethodCall(new Node\Expr\Variable('object'), $name, [
                new Node\Arg(new Node\Scalar\String_('stdClass')),
            ]);
            self::assertSame(Type::BOOL, (new ReflectionMethod($compiler, 'detectTypeOfExpr'))->invoke($compiler, $call));
        }
    }

    public function testKeywordCannotBeRedefined(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Method name `INSTANCEOF()` is reserved for a TypePHP keyword method');
        $this->compile('reserved-instanceof-method.php');
    }

    public function testNativeDynamicTargetKeepsTheOperatorRestriction(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Dynamic instanceof is not supported for native objects');
        $this->compile('native-dynamic-instanceof-method.php');
    }

    public function testRejectsInvalidKeywordArguments(): void
    {
        foreach ([
            [[], 'expects exactly one'],
            [[new Node\Arg(new Node\Scalar\String_('a')), new Node\Arg(new Node\Scalar\String_('b'))], 'expects exactly one'],
            [[new Node\Arg(new Node\Expr\Array_(), unpack: true)], 'non-unpacked'],
            [[new Node\Arg(new Node\Scalar\String_('a'), name: new Node\Identifier('type'))], 'Unknown named parameter $type'],
            [[new Node\VariadicPlaceholder()], 'cannot be converted to a callable'],
        ] as [$args, $message]) {
            $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
            (new ReflectionProperty($compiler, 'context'))->setValue($compiler, new FunctionContext());
            (new ReflectionProperty($compiler, 'file'))->setValue($compiler, TYPEPHP_ROOT_PATH . '/phpunit/code/object-instanceof-method.php');
            $call = new Node\Expr\MethodCall(new Node\Expr\Variable('object'), 'instanceOf', $args);
            try {
                (new ReflectionMethod($compiler, 'createInstanceofKeywordExpression'))->invoke($compiler, $call);
                self::fail('Invalid instanceOf arguments were accepted');
            } catch (TestError $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }
}
