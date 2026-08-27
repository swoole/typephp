<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Generates hidden TypePHP functions for attribute values that Zend cannot
 * persistently construct during MINIT. Reflection invokes the compiled native
 * function at request time and receives an ordinary request-local php::Var.
 */
final class RuntimeAttributeFactoryLowering extends NodeVisitorAbstract
{
    public const FACTORY_NAME_ATTRIBUTE = 'typephpRuntimeAttributeFactory';
    public const FACTORY_FUNCTION_ATTRIBUTE = 'typephpRuntimeAttributeFactoryFunction';
    public const FACTORY_SCOPE_ATTRIBUTE = 'typephpRuntimeAttributeFactoryScope';
    public const FACTORY_LAZY_VALUE_ATTRIBUTE = 'typephpRuntimeAttributeFactoryLazyValue';

    /** @var list<array{namespace: string, parent: string}> */
    private array $classStack = [];
    /** @var list<Stmt\Function_> */
    private array $globalFactories = [];
    /** @var list<list<Stmt\Function_>> */
    private array $namespaceFactories = [];
    private string $namespace = '';
    private int $sequence = 0;

    public function __construct(private readonly string $sourceFile = '')
    {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            $this->namespaceFactories[] = [];
            return null;
        }

        if ($node instanceof Stmt\ClassLike) {
            $class = $node->getAttribute('namespacedName');
            $parent = $node instanceof Stmt\Class_ ? $node->extends : null;
            $this->classStack[] = [
                'namespace' => $class instanceof Node\Name
                    ? $class->toString()
                    : ltrim($this->namespace . '\\' . ($node->name?->toString() ?? ''), '\\'),
                'parent' => $parent?->toString() ?? '',
            ];
            return null;
        }

        if (!$node instanceof Node\Attribute) {
            return null;
        }
        if (CompileTimeAttributeRegistry::get($node->name->toString()) !== null) {
            return null;
        }

        foreach ($node->args as $argument) {
            if (!$this->requiresFactory($argument->value)) {
                continue;
            }

            $factory = $this->createFactory($argument->value);
            $argument->value->setAttribute(self::FACTORY_NAME_ATTRIBUTE, $factory['fullName']);
            if ($this->requiresLazyValue($argument->value)) {
                $argument->value->setAttribute(self::FACTORY_LAZY_VALUE_ATTRIBUTE, true);
            }
            if ($this->namespaceFactories !== []) {
                $index = array_key_last($this->namespaceFactories);
                $this->namespaceFactories[$index][] = $factory['node'];
            } else {
                $this->globalFactories[] = $factory['node'];
            }
        }
        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Stmt\Namespace_) {
            $factories = array_pop($this->namespaceFactories);
            if ($factories !== []) {
                array_push($node->stmts, ...$factories);
            }
            $this->namespace = '';
        }
        return null;
    }

    public function afterTraverse(array $nodes): ?array
    {
        if ($this->globalFactories !== []) {
            array_push($nodes, ...$this->globalFactories);
        }
        return $nodes;
    }

    private function requiresFactory(Expr $value): bool
    {
        return $this->requiresLazyValue($value)
            || $value instanceof Expr\ConstFetch
            || $value instanceof Expr\ClassConstFetch;
    }

    private function requiresLazyValue(Expr $value): bool
    {
        if ($value instanceof Expr\Array_ && $value->items !== []) {
            return true;
        }

        if ($value instanceof Expr\ClassConstFetch) {
            return true;
        }

        return (new NodeFinder())->findFirst($value, static function (Node $node): bool {
            return $node instanceof Expr\New_
                || $node instanceof Expr\Closure
                // A PHP 8.5 array cast may produce a non-empty array even
                // though it is not represented by an Array_ AST node.
                || $node instanceof Expr\Cast\Array_
                || $node instanceof Expr\Cast\Object_
                || (($node instanceof Expr\FuncCall || $node instanceof Expr\StaticCall)
                    && $node->isFirstClassCallable());
        }) !== null;
    }

    /** @return array{fullName: string, node: Stmt\Function_} */
    private function createFactory(Expr $value): array
    {
        $position = $value->getStartFilePos() . ':' . $value->getEndFilePos();
        $hash = substr(sha1($this->sourceFile . ':' . $position . ':' . $this->sequence++), 0, 20);
        $name = '__typephp_attribute_factory_' . $hash;
        $fullName = $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
        $expression = $this->cloneExpression($value);
        $description = (new Standard())->prettyPrintExpr($expression);
        $describeVariable = new Expr\Variable('__typephpDescribe');

        $function = new Stmt\Function_(
            new Node\Identifier($name),
            [
                'params' => [new Node\Param(
                    var: $describeVariable,
                    type: new Node\Identifier('bool'),
                )],
                'returnType' => new Node\Identifier('mixed'),
                'stmts' => [
                    new Stmt\If_($describeVariable, [
                        'stmts' => [new Stmt\Return_(new Node\Scalar\String_($description))],
                    ]),
                    new Stmt\Return_($expression),
                ],
            ],
            $value->getAttributes(),
        );
        $function->setAttribute(self::FACTORY_FUNCTION_ATTRIBUTE, true);
        if ($this->classStack !== []) {
            $context = $this->classStack[array_key_last($this->classStack)];
            $function->setAttribute(self::FACTORY_SCOPE_ATTRIBUTE, $context['namespace']);
        }
        $function->namespacedName = new Node\Name($fullName);

        return ['fullName' => $fullName, 'node' => $function];
    }

    private function cloneExpression(Expr $expression): Expr
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $context = $this->classStack === []
            ? ['namespace' => '', 'parent' => '']
            : $this->classStack[array_key_last($this->classStack)];
        $traverser->addVisitor(new class(
            $context['namespace'],
            $context['parent'],
            $this->namespace,
        ) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string $class,
                private readonly string $parent,
                private readonly string $namespace,
            ) {
            }

            public function enterNode(Node $node): ?Node
            {
                if (($node instanceof Expr\ClassConstFetch || $node instanceof Expr\New_)
                    && $node->class instanceof Node\Name) {
                    $name = strtolower($node->class->toString());
                    if ($name === 'self' || $name === 'static') {
                        $node->class = new Node\Name\FullyQualified($this->class);
                    } elseif ($name === 'parent' && $this->parent !== '') {
                        $node->class = new Node\Name\FullyQualified($this->parent);
                    }
                }
                if ($node instanceof Node\Name) {
                    // Attribute factories are created while the outer
                    // traverser is entering the Attribute node, before its
                    // argument names have been visited by NameResolver.
                    if ($node instanceof Node\Name\Relative) {
                        $name = ltrim($this->namespace . '\\' . $node->toString(), '\\');
                        return new Node\Name\FullyQualified($name, $node->getAttributes());
                    }
                    $resolved = $node->getAttribute('resolvedName');
                    if ($resolved instanceof Node\Name) {
                        return new Node\Name\FullyQualified($resolved->toString(), $resolved->getAttributes());
                    }
                }
                return null;
            }
        });
        /** @var Expr $clone */
        [$clone] = $traverser->traverse([$expression]);
        return $clone;
    }
}
