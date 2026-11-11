<?php

use TypePhp\CompilerTest;

final class ConstantProjectTypeTest extends BaseTest
{
    public function testCrossFileScalarSlotsAndCleanupSurviveWarmConversion(): void
    {
        $root = $this->createProject();
        $first = $this->convertProject($root);
        $extension = file_get_contents($root . '/build/extension-constant_project.cc');
        self::assertStringContainsString('php::Int _const_var_ConstantProject__LIMIT;', $extension);
        self::assertStringContainsString('php::Str _const_var_ConstantProject__LABEL;', $extension);
        self::assertStringContainsString('_const_var_ConstantProject__LABEL.unset();', $extension);
        self::assertStringNotContainsString('_const_var_ConstantProject__LIMIT.unset();', $extension);
        $header = file_get_contents($first->getDeclarationHeaderFile($root . '/provider.php'));
        self::assertStringContainsString('extern php::Int _const_var_ConstantProject__LIMIT;', $header);
        self::assertStringContainsString('extern php::Str _const_var_ConstantProject__LABEL;', $header);
        $consumer = file_get_contents($first->getCppFile($root . '/consumer.php'));
        self::assertStringNotContainsString('php::Var(value)', $consumer);
        $second = $this->convertProject($root);
        self::assertSame($extension, file_get_contents($root . '/build/extension-constant_project.cc'));
        self::assertSame($consumer, file_get_contents($second->getCppFile($root . '/consumer.php')));
        self::assertSame($header, file_get_contents($second->getDeclarationHeaderFile($root . '/provider.php')));
    }

    public function testChangingProviderScalarTypeInvalidatesConsumer(): void
    {
        $root = $this->createProject();
        $first = $this->convertProject($root);
        $file = $first->getCppFile($root . '/consumer.php');
        $before = file_get_contents($file);
        $provider = $root . '/provider.php';
        file_put_contents($provider, str_replace('LIMIT = 36;', 'LIMIT = 36.5;', file_get_contents($provider)));
        $second = $this->convertProject($root);
        self::assertStringContainsString('extern php::Float _const_var_ConstantProject__LIMIT;',
            file_get_contents($second->getDeclarationHeaderFile($provider)));
        self::assertNotSame($before, file_get_contents($file));
    }

    private function createProject(): string
    {
        $root = TYPEPHP_ROOT_PATH . '/build/phpunit/constant-project-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/provider.php', <<<'PHP'
<?php
namespace ConstantProject;
const LIMIT = 36;
const LABEL = 'project-label';
PHP);
        file_put_contents($root . '/consumer.php', <<<'PHP'
<?php
namespace ConstantProject;
function compare(int $value): bool { return $value > LIMIT; }
function label(): string { return LABEL; }
PHP);
        return $root;
    }

    private function convertProject(string $root): CompilerTest
    {
        global $translator;
        $compiler = CompilerTest::create($root);
        $translator = $compiler;
        (new ReflectionProperty($compiler, 'forTest'))->setValue($compiler, false);
        $compiler->setBuildMode(CompilerTest::BUILD_MODE_EXT);
        $compiler->setTargetName('constant_project');
        (new ReflectionMethod($compiler, 'setBuildDir'))->invoke($compiler, $root . '/build');
        $files = [$root . '/consumer.php', $root . '/provider.php'];
        $compiler->addFiles($files);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        (new ReflectionMethod($compiler, 'initializeIncrementalCompilation'))->invoke($compiler, $files);
        $compiler->convert($files);
        return $compiler;
    }
}
