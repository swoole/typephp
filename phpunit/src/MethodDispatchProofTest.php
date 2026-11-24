<?php

use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

final class MethodDispatchProofTest extends BaseTest
{
    public function testOnlyUniqueTargetsAreCalledDirectlyInEveryBuildMode(): void
    {
        foreach ([CompilerBase::BUILD_MODE_BIN, CompilerBase::BUILD_MODE_EXT, CompilerBase::BUILD_MODE_LIB] as $mode) {
            $code = $this->generatedCode($mode);
            foreach (['mid__current', 'rootparameter', 'midparameter', 'returnedbound', 'replacedlocal'] as $method) {
                $body = $this->body($code, $method);
                self::assertStringContainsString('typephp_call_method_', $body, "$mode: $method");
                self::assertStringNotContainsString('php_dispatchproof__root__value(', $body, "$mode: $method");
            }
            foreach (['branch__current', 'leafparameter', 'branchparameter', 'finalparameter', 'exactlocal', 'exacttemporary'] as $method) {
                $body = $this->body($code, $method);
                self::assertStringContainsString('php_dispatchproof__root__value(', $body, "$mode: $method");
                self::assertStringNotContainsString('typephp_call_method_', $body, "$mode: $method");
                self::assertStringNotContainsString('.ce() ==', $body, "$mode: $method");
            }
            self::assertStringContainsString('php_dispatchproof__root__fixed(', $this->body($code, 'finalmethod'));
            self::assertStringContainsString('php_dispatchproof__root__hidden(', $this->body($code, 'root__lexical'));
            $abstractBody = $this->body($code, 'abstractroot__current');
            self::assertStringContainsString('php_dispatchproof__concretereceiver__value(', $abstractBody);
            self::assertStringContainsString('php_dispatchproof__concretereceiver__value(', $this->body($code, 'abstractparameter'));
        }
    }

    private function generatedCode(string $mode): string
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode($mode);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/devirtualization-proof.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        return file_get_contents($compiler->convertFile($source));
    }

    private function body(string $code, string $method): string
    {
        self::assertSame(1, preg_match(
            '/[\w:]+ php_dispatchproof__' . preg_quote($method, '/') . '\([^\n]*\) \{(?<body>.*?)\n\}/s',
            $code,
            $match,
        ), "Generated body not found: $method");
        return $match['body'];
    }
}
