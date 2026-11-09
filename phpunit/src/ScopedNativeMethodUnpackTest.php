<?php

class ScopedNativeMethodUnpackTest extends \BaseTest
{
    public function testNativeMethodFallbackUsesCallableNamesForScopedDispatch(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/scoped-native-method-unpack.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cpp = file_get_contents($compiler->convertFile($testFile));
        $this->assertMatchesRegularExpression('/php::callScoped\([^\n]+get_str\(/', $cpp);
        $this->assertDoesNotMatchRegularExpression('/php::callScoped\([^\n]+get_persistent_method\(/', $cpp);
        $this->assertGreaterThanOrEqual(2, substr_count($cpp, '.appendUnpacked('));
    }
}
