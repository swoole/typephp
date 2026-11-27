<?php

use TypePhp\CompilerBase;
use TypePhp\CompilerTest;

/**
 * Lifecycle contract for persistent enum-case AST class constants: Zend's
 * internal-class teardown (destroy_zend_class) tolerates them only after the
 * module's MSHUTDOWN released them. The generated module must therefore
 * (a) refuse to start as a MODULE_TEMPORARY (dl()-loaded) module before any
 * class is registered, because module_destructor() destroys temporary-module
 * classes before the shutdown callback runs, and (b) order every fallible
 * MINIT step before the first class registration, so a FAILURE return (which
 * suppresses MSHUTDOWN) can never leave a foreign AST in the class table.
 */
final class EnumCaseAstConstantLifecycleTest extends \BaseTest
{
    public function testModuleTemporaryGuardPrecedesEveryRegistrationStep(): void
    {
        $minit = $this->generateMinitBody('enum-case-class-constant.php', 'ast_lifecycle_guard');

        $guardPos = strpos($minit, 'if (type == MODULE_TEMPORARY) {');
        self::assertIsInt($guardPos, 'MINIT must reject dl()-loaded temporary modules');
        self::assertStringContainsString(
            'registers AST class constants that must be released by MSHUTDOWN',
            $minit,
        );
        self::assertStringContainsString('Load the extension from php.ini instead.', $minit);

        $handlersPos = strpos($minit, 'typephp_install_reflection_attribute_handlers()');
        $firstRegisterPos = strpos($minit, 'register_class_');
        self::assertIsInt($handlersPos);
        self::assertIsInt($firstRegisterPos);
        self::assertLessThan($handlersPos, $guardPos, 'the lifecycle guard must be the first MINIT statement');
        self::assertLessThan($firstRegisterPos, $guardPos, 'the lifecycle guard must precede every class registration');
    }

    public function testEmbedBinaryLeavesTemporaryModuleOrderingToRuntime(): void
    {
        $extension = $this->generateExtension(
            'enum-case-class-constant.php',
            'ast_lifecycle_embed',
            CompilerBase::BUILD_MODE_BIN,
        );
        $minit = $this->sliceFunction($extension, 'PHP_MINIT_FUNCTION', 'PHP_MSHUTDOWN_FUNCTION');

        self::assertStringNotContainsString(
            'registers AST class constants that must be released by MSHUTDOWN',
            $minit,
        );
        self::assertStringContainsString('register_class_', $minit);
        self::assertMatchesRegularExpression(
            '/EG\(current_module\)->type = MODULE_PERSISTENT;\s*'
                . 'zend_interned_strings_switch_storage\(false\);.*register_class_.*'
                . 'zend_interned_strings_switch_storage\(true\);\s*'
                . 'EG\(current_module\)->type = MODULE_TEMPORARY;/s',
            $minit,
        );
        self::assertMatchesRegularExpression(
            '/TYPEPHP_EMBED_PRE_SHUTDOWN_FUNCTION\(ast_lifecycle_embed\)\s*\{\s*'
                . 'typephp_release_ast_constants_enum_case_class_constant\(\);\s*\}/',
            $extension,
        );
    }

    public function testAstConstantRegistrationIsOrderedAfterEveryFallibleMinitStep(): void
    {
        $minit = $this->generateMinitBody('enum-case-class-constant.php', 'ast_lifecycle_order');

        $firstRegisterPos = strpos($minit, 'register_class_');
        $lastFailurePos = strrpos($minit, 'return FAILURE;');
        self::assertIsInt($firstRegisterPos);
        self::assertIsInt($lastFailurePos);
        self::assertLessThan(
            $firstRegisterPos,
            $lastFailurePos,
            'no MINIT step after the first class registration may return FAILURE: '
                . 'a failed MINIT never reaches MSHUTDOWN, so the persistent class '
                . 'table would keep an AST that destroy_zend_class() cannot handle',
        );
        self::assertGreaterThan(
            strrpos($minit, 'register_class_'),
            strpos($minit, 'return SUCCESS;'),
        );
    }

    public function testMshutdownReleasesAstConstantsBeforeAnyOtherTeardown(): void
    {
        $extension = $this->generateExtension('enum-case-class-constant.php', 'ast_lifecycle_shutdown');
        $mshutdown = $this->sliceFunction($extension, 'PHP_MSHUTDOWN_FUNCTION', 'THREAD_LOCAL zval globals_array');

        $releasePos = strpos($mshutdown, 'typephp_release_ast_constants_enum_case_class_constant();');
        self::assertIsInt($releasePos, 'MSHUTDOWN must release the persistent AST constants');
        // The release must run before anything else so the class table is
        // Zend-safe no matter what the rest of the teardown does.
        $firstStatementPos = strpos($mshutdown, ';');
        self::assertSame($firstStatementPos, $releasePos + strlen('typephp_release_ast_constants_enum_case_class_constant();') - 1);
    }

    public function testModulesWithoutAstConstantsCarryNeitherGuardNorRelease(): void
    {
        $extension = $this->generateExtension('class-constant-codegen.php', 'ast_lifecycle_none');

        self::assertStringNotContainsString(
            'registers AST class constants that must be released by MSHUTDOWN',
            $extension,
        );
        self::assertStringNotContainsString('typephp_release_ast_constants_', $extension);
    }

    private function generateMinitBody(
        string $fixture,
        string $target,
        string $mode = CompilerBase::BUILD_MODE_EXT,
    ): string
    {
        return $this->sliceFunction(
            $this->generateExtension($fixture, $target, $mode),
            'PHP_MINIT_FUNCTION',
            'PHP_MSHUTDOWN_FUNCTION',
        );
    }

    private function generateExtension(
        string $fixture,
        string $target,
        string $mode = CompilerBase::BUILD_MODE_EXT,
    ): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $compiler->setBuildMode($mode);
        $compiler->setTargetName($target);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $fixture;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $extension = file_get_contents($compiler->genExtension());

        self::assertIsString($extension);
        return $extension;
    }

    /**
     * The generated function bodies keep statements at column zero, so the
     * closing brace is not recognizable; slice up to the next known emission
     * instead.
     */
    private function sliceFunction(string $extension, string $startMarker, string $endMarker): string
    {
        $start = strpos($extension, $startMarker);
        self::assertIsInt($start, "generated extension must contain {$startMarker}");
        $end = strpos($extension, $endMarker, $start + strlen($startMarker));
        self::assertIsInt($end, "generated extension must contain {$endMarker}");
        return substr($extension, $start, $end - $start);
    }
}
