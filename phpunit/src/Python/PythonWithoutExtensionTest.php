<?php

namespace TypePhp\Tests\Python;

use PHPUnit\Framework\TestCase;

final class PythonWithoutExtensionTest extends TestCase
{
    public function testPythonCodeGenerationDoesNotRequirePhpy(): void
    {
        // CI normally loads phpy. Start a clean compiler host to keep this contract covered.
        $command = [PHP_BINARY, '-n'];
        foreach (['ctype', 'mbstring', 'tokenizer'] as $extension) {
            $path = ini_get('extension_dir') . DIRECTORY_SEPARATOR
                . (PHP_OS_FAMILY === 'Windows' ? 'php_' : '') . $extension . '.' . PHP_SHLIB_SUFFIX;
            if (is_file($path)) {
                $command[] = '-d';
                $command[] = 'extension=' . $path;
            }
        }
        $command[] = '-r';
        $command[] = <<<'PHP'
if (extension_loaded('phpy')) {
    throw new RuntimeException('The compiler host must not load phpy');
}
require 'phpunit/bootstrap.php';
$result = [];
foreach (['operators', 'object-protocol', 'object-conversion-methods', 'facade-methods', 'facade-class-name-collision'] as $fixture) {
    $translator = TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
    $source = TYPEPHP_ROOT_PATH . '/phpunit/code/python/' . $fixture . '.php';
    $translator->addFiles([$source]);
    $translator->prepareFile($source);
    $result[$fixture] = file_get_contents($translator->convertFile($source))
        . file_get_contents($translator->genExtension());
}
echo "\nTYPEPHP_RESULT:" . json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, TYPEPHP_ROOT_PATH);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame(1, preg_match('/\nTYPEPHP_RESULT:(.*)\z/s', $stdout, $matches), $stdout);
        $generated = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('php::python::', $generated['facade-class-name-collision']);
        self::assertStringContainsString('iadd', $generated['operators']);
        self::assertStringContainsString('is_not', $generated['operators']);
        self::assertStringContainsString('php::python::call(', $generated['object-protocol']);
        self::assertStringContainsString('php::python::toArray(', $generated['object-conversion-methods']);
        self::assertStringNotContainsString('php::toArray(', $generated['object-conversion-methods']);
        self::assertStringContainsString('php::Var value;', $generated['object-conversion-methods']);
        self::assertStringContainsString('php::python::toArray(', $generated['facade-methods']);
        self::assertStringNotContainsString('php::python::callMember(', $generated['facade-methods']);
    }
}
