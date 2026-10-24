<?php

namespace TypePhp\Tests\Resolver;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use TypePhp\Entity\ClassDef;
use TypePhp\Resolver\PropertyAccessContext;
use TypePhp\Resolver\PropertyAccessResolver;

final class PropertyAccessResolverTest extends TestCase
{
    public function testSubclassLookupNormalizesEveryParentName(): void
    {
        $parents = [
            'app\\childexception' => 'RuntimeException',
        ];
        $context = new class($parents) implements PropertyAccessContext {
            public function __construct(private array $parents)
            {
            }

            public function getClassDef(string $name): ?ClassDef
            {
                return null;
            }

            public function getParentClass(string $class): string
            {
                return $this->parents[strtolower(ltrim($class, '\\'))] ?? '';
            }

            public function fatalError(Node $node, string $msg): never
            {
                throw new \LogicException($msg);
            }
        };

        $resolver = new PropertyAccessResolver($context);

        $this->assertTrue($resolver->isSameOrSubclassOf('App\\ChildException', 'runtimeexception'));
        $this->assertTrue($resolver->canAccessProtectedProperty('App\\ChildException', 'RuntimeException'));
    }

    public function testSubclassLookupUsesCompiledClassDeclarationWhenParentIndexIsMissing(): void
    {
        $child = new ClassDef('ChildException', 0, 'App');
        $child->extends = 'RuntimeException';

        $context = new class($child) implements PropertyAccessContext {
            public function __construct(private ClassDef $child)
            {
            }

            public function getClassDef(string $name): ?ClassDef
            {
                return strtolower(ltrim($name, '\\')) === 'app\\childexception' ? $this->child : null;
            }

            public function getParentClass(string $class): string
            {
                return '';
            }

            public function fatalError(Node $node, string $msg): never
            {
                throw new \LogicException($msg);
            }
        };

        $resolver = new PropertyAccessResolver($context);

        $this->assertTrue($resolver->isSameOrSubclassOf('App\\ChildException', 'runtimeexception'));
        $this->assertTrue($resolver->canAccessProtectedProperty('App\\ChildException', 'RuntimeException'));
    }
}
