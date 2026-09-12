<?php

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

final class NanoCapabilityPolicyCompiler extends CompilerTest
{
    public function enableNanoForTest(): void
    {
        $this->nanoMode = true;
        $this->forTest = true;
        $this->file = 'nano-policy.php';
    }

    public function enableWasiForTest(): void
    {
        $this->nanoMode = true;
        $this->nanoPolicyMode = true;
        $this->targetPlatform = 'wasm32-wasip2';
        $this->forTest = true;
        $this->file = 'nano-policy.php';
    }

    public function enableFullRuntimeNanoPolicyForTest(): void
    {
        $this->nanoMode = false;
        $this->nanoPolicyMode = true;
        $this->forTest = true;
        $this->file = 'nano-policy.php';
    }

    public function validateNanoFunction(string $name): void
    {
        $this->assertNanoFunctionSupported(new FuncCall(new Name($name)), $name);
    }

    public function validateWasiFunction(string $name): void
    {
        $this->assertWasiFunctionSupported(new FuncCall(new Name($name)), $name);
    }

    public function forgetBuildTimeFunction(string $name): void
    {
        unset($this->internalFunctions[$name]);
    }
}

final class NanoCapabilityPolicyTest extends BaseTest
{
    public function testFunctionImportCannotBypassNanoPolicy(): void
    {
        $this->assertImportedFunctionRejected(false);
    }

    public function testFunctionImportCannotBypassWasiPolicy(): void
    {
        $this->assertImportedFunctionRejected(true);
    }

    private function assertImportedFunctionRejected(bool $wasi): void
    {
        global $translator;
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        if ($wasi) {
            $compiler->enableWasiForTest();
        } else {
            $compiler->enableNanoForTest();
        }
        $translator = $compiler;
        $source = __DIR__ . '/../code/function-import-policy.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Function `exec` is not supported');
        $compiler->convertFile($source);
    }

    public function testRejectsForbiddenDirectCallMissingFromBuildTimePhp(): void
    {
        global $translator;
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();
        $compiler->forgetBuildTimeFunction('pcntl_setns');
        $translator = $compiler;
        $source = __DIR__ . '/../code/nano-unavailable-host-function.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Function `pcntl_setns` is not supported in nano mode');
        $compiler->convertFile($source);
    }

    public function testRejectsHostCapabilityFunctions(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();

        foreach (['shell_exec', 'stream_socket_client', 'stream_select', 'parse_str'] as $name) {
            try {
                $compiler->validateNanoFunction($name);
                self::fail("{$name} was accepted");
            } catch (TestError $error) {
                self::assertStringContainsString("Function `{$name}` is not supported in nano mode", $error->getMessage());
            }
        }
    }

    public function testFullRuntimeNanoPolicyRejectsExternalCommandsOnly(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableFullRuntimeNanoPolicyForTest();

        foreach (['exec', 'passthru', 'pcntl_exec', 'popen', 'proc_open', 'proc_terminate', 'shell_exec', 'system'] as $name) {
            try {
                $compiler->validateNanoFunction($name);
                self::fail("{$name} was accepted");
            } catch (TestError $error) {
                self::assertStringContainsString("Function `{$name}` is not supported in nano mode", $error->getMessage());
            }
        }

        // Windows uses the complete PHP/PHPX DLL runtime. The php-nano-only
        // capability reductions are therefore not applied to that target.
        foreach (['getenv', 'parse_str', 'stream_socket_client'] as $name) {
            $compiler->validateNanoFunction($name);
        }
        self::addToAssertionCount(3);
    }

    public function testFullRuntimeNanoEntryCallsGeneratedMainWithoutEval(): void
    {
        global $translator;
        $previousTranslator = $translator ?? null;
        $directory = sys_get_temp_dir() . '/typephp_nano_policy_' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $source = $directory . '/main.php';
        file_put_contents($source, "<?php\nfunction main(): void {}\n");

        try {
            $compiler = new NanoCapabilityPolicyCompiler($directory);
            $compiler->enableFullRuntimeNanoPolicyForTest();
            $compiler->setBuildMode(\TypePhp\CompilerBase::BUILD_MODE_BIN);
            $compiler->setTargetName('nano_policy_entry');
            $translator = $compiler;
            $compiler->addFiles([$source]);
            $compiler->prepareFile($source);
            $compiler->convertFile($source);
            $extension = file_get_contents($compiler->genExtension());

            self::assertIsString($extension);
            self::assertStringContainsString('php_main();', $extension);
            self::assertStringNotContainsString('php::eval(', $extension);
            self::assertStringContainsString(
                'zend_disable_functions("exec,passthru,pcntl_exec,popen,proc_close,proc_get_status,proc_nice,proc_open,proc_terminate,shell_exec,system")',
                $extension,
            );
            self::assertStringContainsString('_SERVER.item("SCRIPT_FILENAME", true)', $extension);
        } finally {
            $translator = $previousTranslator;
            self::removeDirectory($directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    public function testKeepsFileStreamsAndFileHashesAvailable(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();

        foreach (['fopen', 'file_get_contents', 'file_put_contents', 'is_file', 'realpath', 'hash_file', 'stream_get_contents', 'flock', 'umask', 'chown'] as $name) {
            $compiler->validateNanoFunction($name);
        }
        self::addToAssertionCount(10);
    }

    public function testWasiOnlyRejectsItsMissingFileCapabilities(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableWasiForTest();

        foreach (['flock', 'umask', 'chown', 'stream_socket_client', 'gethostbyname', 'dns_get_record'] as $name) {
            try {
                $compiler->validateWasiFunction($name);
                self::fail("{$name} was accepted");
            } catch (TestError $error) {
                self::assertStringContainsString("Function `{$name}` is not supported by the WASI target", $error->getMessage());
            }
        }

        foreach (['fopen', 'file_get_contents', 'hash_file'] as $name) {
            $compiler->validateWasiFunction($name);
        }
        self::addToAssertionCount(3);
    }

    public function testWasiUnsupportedDirectCallFailsBeforeCodeGeneration(): void
    {
        global $translator;
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableWasiForTest();
        $translator = $compiler;
        $source = __DIR__ . '/../code/wasi-unavailable-host-function.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            'Function `stream_socket_client` is not supported by the WASI target',
        );
        $compiler->convertFile($source);
    }

    public function testNanoKeepsTypedFileStreamMethods(): void
    {
        global $translator;
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();
        $translator = $compiler;
        $source = __DIR__ . '/../code/nano-file-stream-method.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);
        self::assertStringContainsString('php::toStream(', $code);
        self::assertStringContainsString('php::call(', $code);
    }

    public function testRejectsUnavailableStringMethodAtCompileTime(): void
    {
        global $translator;
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();
        $translator = $compiler;
        $source = __DIR__ . '/../code/nano-unavailable-string-method.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Function `parse_str` is not supported in nano mode');
        $compiler->convertFile($source);
    }

    public function testKeepsZendIniFunctionsAvailable(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();
        $compiler->validateNanoFunction('ini_get');
        $compiler->validateNanoFunction('ini_set');
        self::addToAssertionCount(2);
    }

    public function testKeepsCompilerCtypeIntrinsicAvailable(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();
        $compiler->validateNanoFunction('ctype_digit');
        $this->addToAssertionCount(1);
    }

    public function testKeepsStandardCppTimeFunctionsAvailable(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();

        foreach (['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until', 'hrtime', 'microtime'] as $name) {
            $compiler->validateNanoFunction($name);
        }
        $this->addToAssertionCount(6);
    }

    public function testKeepsStandardCppRandomFunctionsAvailable(): void
    {
        $compiler = new NanoCapabilityPolicyCompiler(TYPEPHP_ROOT_PATH);
        $compiler->enableNanoForTest();

        $compiler->validateNanoFunction('random_bytes');
        $compiler->validateNanoFunction('random_int');
        $this->addToAssertionCount(2);
    }
}
