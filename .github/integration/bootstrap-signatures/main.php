<?php

use PhpParser\Node;
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

function main(): void
{
    require dirname(__DIR__, 3) . '/vendor/autoload.php';
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
