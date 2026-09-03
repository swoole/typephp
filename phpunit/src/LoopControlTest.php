<?php

class LoopControlTest extends \BaseTest
{
    public function testContinueInStandaloneSwitchIsRejected(): void
    {
        $this->exec(
            'Cannot continue outside loop',
            'control-flow/standalone-switch-continue.php',
        );
    }

    public function testContinueInStandaloneDynamicSwitchIsRejected(): void
    {
        $this->exec(
            'Cannot continue outside loop',
            'control-flow/standalone-dynamic-switch-continue.php',
        );
    }

    public function testContinueInSwitchNestedInLoopsIsAllowed(): void
    {
        $this->compile('control-flow/loop-switch-continue.php');
    }

    public function testWhileConditionIsStillParsedBeforeItsBody(): void
    {
        $this->exec(
            'Undefined variable `$results`',
            'control-flow/while-body-defined-condition.php',
        );
    }

    public function testForLoopPostIncConvertedToPreInc(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/loop/for-loop-post-expr-opt.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        // for-loop post-expression $i++ → ++$i, $i-- → --$i
        $this->assertStringContainsString('++i', $cpp);
        $this->assertStringContainsString('--i', $cpp);

        // while/do-while body $i++ should NOT be converted — still i++
        $this->assertStringContainsString("\ti++;\n", $cpp);
    }
}
