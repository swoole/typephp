<?php

use PhpParser\Node;
use TypePhp\Build\NativeBuilder;
use TypePhp\Entity\ClassDef;
use TypePhp\Resolver\PropertyAccessContext;

// Exercise the compiler's real contract while PhpParser types are still absent
// during MINIT. Identical signatures must not require request-time autoloading.
interface BootstrapSelfContract
{
    public function copy(): self;
}

trait BootstrapSelfImplementation
{
    public function copy(): self
    {
        return $this;
    }
}

trait BootstrapNestedSelfImplementation
{
    use BootstrapSelfImplementation;
}

final class BootstrapPropertyAccessContext implements PropertyAccessContext, BootstrapSelfContract
{
    use BootstrapNestedSelfImplementation;

    public function getClassDef(string $name): ?ClassDef
    {
        return null;
    }

    public function getParentClass(string $class): string
    {
        return '';
    }

    public function fatalError(Node $node, string $msg): never
    {
        throw new RuntimeException($msg . ':' . $node->getStartLine());
    }
}

function verifyCompiledCompletionCallbacks(): void
{
    $builder = (new ReflectionClass(NativeBuilder::class))->newInstanceWithoutConstructor();
    $objects = [];
    $tasks = [];
    // These workers execute only shell built-ins. Compiler/linker concurrency
    // remains governed by the enclosing build launcher's job limit.
    $command = PHP_OS_FAMILY === 'Windows' ? 'cmd /d /c exit 0' : 'true';
    foreach (['first', 'second'] as $source) {
        $object = tempnam(sys_get_temp_dir(), 'typephp-bootstrap-');
        if ($object === false) {
            throw new RuntimeException('Cannot create process-pool fixture');
        }
        $objects[] = $object;
        $tasks[] = ['source' => $source, 'object' => $object, 'command' => $command];
    }
    try {
        $completed = [];
        $result = $builder->dispatchProcessParallel(
            $tasks,
            2,
            static function (
                string $source, string $object, string $command, array $output,
                int $status, bool $success, int $count,
            ) use (&$completed): void {
                if ($status !== 0 || !$success || $count !== count($completed) + 1) {
                    throw new RuntimeException('Unexpected completion callback arguments');
                }
                $completed[] = $source;
            },
        );
        sort($objects);
        sort($completed);
        $actualObjects = $result['objects'];
        sort($actualObjects);
        if ($actualObjects !== $objects || $result['failures'] !== [] || $completed !== ['first', 'second']) {
            throw new RuntimeException('Compiled completion callbacks did not run');
        }
        $result = $builder->dispatchProcessParallel($tasks, 2);
        $actualObjects = $result['objects'];
        sort($actualObjects);
        if ($actualObjects !== $objects || $result['failures'] !== []) {
            throw new RuntimeException('A null completion callback changed the process-pool result');
        }
    } finally {
        foreach ($objects as $object) {
            unlink($object);
        }
    }
}

function main(): void
{
    require dirname(__DIR__, 3) . '/vendor/autoload.php';
    verifyCompiledCompletionCallbacks();
    $context = new BootstrapPropertyAccessContext();
    $returnType = (string) (new ReflectionMethod(BootstrapPropertyAccessContext::class, 'copy'))->getReturnType();
    if ($context->copy() !== $context || $returnType !== BootstrapPropertyAccessContext::class) {
        throw new RuntimeException('Trait self type was not bound to the consuming class');
    }
    try {
        $context->fatalError(new PhpParser\Node\Scalar\Int_(42, ['startLine' => 7]), 'expected');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'expected:7') {
            throw new RuntimeException('Unexpected diagnostic: ' . $error->getMessage());
        }
        echo "bootstrap-signatures-ok\n";
        return;
    }
    throw new RuntimeException('Expected a diagnostic exception');
}
