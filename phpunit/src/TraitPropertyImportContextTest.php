<?php

use TypePhp\CompilerTest;

class TraitPropertyImportContextTest extends BaseTest
{
    public function testPropertyDefaultsKeepTheirDeclaringFileImports(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $directory = TYPEPHP_ROOT_PATH . '/phpunit/code/trait-property-import-context/';
        $files = [$directory . 'levels.php', $directory . 'trait.php', $directory . 'consumer.php'];
        $compiler->addFiles($files);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        foreach ($files as $file) {
            $compiler->convertFile($file);
        }

        $this->addToAssertionCount(1);
    }
}
