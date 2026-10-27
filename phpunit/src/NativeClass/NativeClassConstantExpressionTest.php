<?php

namespace TypePhp\Tests\NativeClass;

use TypePhp\CompilerTest;

final class NativeClassConstantExpressionTest extends \BaseTest
{
    /** @dataProvider literalStringsProvider */
    public function testExpressionConstantsDoNotLeakDeclarationTemporaries(bool $noLiteralStrings): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        (new \ReflectionProperty(\TypePhp\CompilerBase::class, 'noLiteralStrings'))
            ->setValue($compiler, $noLiteralStrings);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/native-class-expression-constants.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        self::assertDoesNotMatchRegularExpression('/php::Var\(tmp_var_\d+\)/', $code);
        self::assertDoesNotMatchRegularExpression('/php::(?:constant|classConstant)\(/', $code);
        self::assertStringNotContainsString('get_persistent_class(', $code);
        foreach ([
            'BaseFlags' => ['A' => 1, 'B' => 2, 'MASK' => 3],
            'ChildFlags' => ['TOTAL' => 7, 'LABEL' => 'tmp_var_7', 'ENABLED' => true, 'NOTHING' => null, 'RATIO' => 3.5],
        ] as $class => $constants) {
            foreach ($constants as $name => $expected) {
                $constant = $compiler->getClassDef('NativeConstantExpressions\\' . $class)->getConstant($name);
                self::assertFalse($constant->hasMaterializedTemporaries, "$class::$name");
                self::assertSame('', $constant->arrayExpr, "$class::$name");
                self::assertSame($expected, $compiler->getClassConstValue(
                    new \PhpParser\Node\Expr\ClassConstFetch(
                        new \PhpParser\Node\Name\FullyQualified('NativeConstantExpressions\\' . $class),
                        $name,
                    ),
                    '\\NativeConstantExpressions\\' . $class,
                    $name,
                ));
            }
        }
    }

    public static function literalStringsProvider(): array
    {
        return ['pooled strings' => [false], 'inline strings' => [true]];
    }

    public function testFoldingDoesNotBypassConstantVisibility(): void
    {
        $this->exec('is not accessible', 'native-class-expression-constant-private.php');
    }
}
