<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use TypePhp\CompileTimeAttribute\Expansion;
use TypePhp\Exception\CompileTimeAttributeError;
use TypePhp\Exception\SyntaxError;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;

class Visitor extends NodeVisitorAbstract
{
    private string $namespaceMagicName = '';
    private string $propertyMagicName = '';
    /** @var list<array{string, int}> */
    private array $propertyMagicContextStack = [];
    private int $propertyMagicBoundaryDepth = 0;
    /** @var array<int, true> */
    private array $propertyMagicBoundaries = [];

    /** @param null|Closure(Node, string): void $warning */
    public function __construct(
        private readonly ?Closure $warning = null,
        private readonly string $sourceFile = '',
    ) {
    }

    public function enterNode(Node $node): null|Node
    {
        if ($node instanceof Stmt\Const_ && $node->attrGroups !== []) {
            throw new SyntaxError('Attributes on global constants are not supported by TypePHP');
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespaceMagicName = $node->name?->toString() ?? '';
        }
        if ($node instanceof Node\Name\Relative) {
            // namespace\name is bound to the current namespace and never
            // participates in imports or global function/constant fallback.
            $resolved = $node->getAttribute('resolvedName');
            $name = $resolved instanceof Node\Name
                ? $resolved->toString()
                : ltrim($this->namespaceMagicName . '\\' . $node->toString(), '\\');
            return new Node\Name\FullyQualified($name, $node->getAttributes());
        }

        if ($node instanceof Stmt\Property) {
            $this->propertyMagicContextStack[] = [
                $this->propertyMagicName,
                $this->propertyMagicBoundaryDepth,
            ];
            $this->propertyMagicName = $node->props[0]->name->toString();
            $this->propertyMagicBoundaryDepth = 0;
        } elseif ($node instanceof Node\PropertyItem) {
            // Multi-property declarations resolve __PROPERTY__ separately for
            // every initializer. Attributes on the declaration use the first
            // property, matching ZendPHP.
            $this->propertyMagicName = $node->name->toString();
        } elseif ($this->propertyMagicName !== ''
            && (($node instanceof Node\FunctionLike && !$node instanceof Node\PropertyHook)
                || $node instanceof Stmt\ClassLike)
        ) {
            ++$this->propertyMagicBoundaryDepth;
            $this->propertyMagicBoundaries[spl_object_id($node)] = true;
        }

        if ($node instanceof Node\Scalar\MagicConst\Property) {
            $value = $this->propertyMagicBoundaryDepth === 0
                ? $this->propertyMagicName
                : '';
            return new Node\Scalar\String_($value, $node->getAttributes());
        }
        if ($node instanceof Node\Scalar\MagicConst\Namespace_) {
            return new Node\Scalar\String_($this->namespaceMagicName, $node->getAttributes());
        }

        $this->guard($node, static fn () => CompileTimeAttribute::validateNode($node));
        $this->guard($node, static fn () => NativeClassAttributeLowering::lower($node), 'Native');
        $this->guard($node, static fn () => FunctionAttributeLowering::lower($node));
        $this->guard($node, static fn () => GetterLowering::validateTarget($node), 'Getter');
        $this->guard($node, static fn () => PropertyMethodLowering::validateTarget($node));
        $this->guard($node, static fn () => ConstructorLowering::validateTarget($node), 'Constructor');
        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\FunctionLike) {
            $this->guard($node, static fn () => CompileTimeAttribute::validateStdContainerParameterTarget($node));
        }
        $nodeId = spl_object_id($node);
        if (isset($this->propertyMagicBoundaries[$nodeId])) {
            unset($this->propertyMagicBoundaries[$nodeId]);
            --$this->propertyMagicBoundaryDepth;
        }
        if ($node instanceof Stmt\Property) {
            [$this->propertyMagicName, $this->propertyMagicBoundaryDepth]
                = array_pop($this->propertyMagicContextStack);
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespaceMagicName = '';
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Node\Expr\Closure) {
            $this->guard(
                $node,
                fn () => ParameterValidationLowering::lowerFunction($node, $this->warning),
            );
        } elseif ($node instanceof Node\Expr\ArrowFunction) {
            $this->guard($node, static fn () => ParameterValidationLowering::rejectArrowFunction($node));
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Stmt\Property) {
                    PropertyHookLowering::markAbstractInterfaceProperty($stmt);
                }
            }
            return null;
        }

        if (!$node instanceof Stmt\Class_ && !$node instanceof Stmt\Trait_ && !$node instanceof Stmt\Enum_) {
            return null;
        }

        $methods = [];
        $classReadonly = $node instanceof Stmt\Class_ && $node->isReadonly();
        if ($node instanceof Stmt\Class_ && NativeClassAttributeLowering::isNative($node)) {
            $this->guard(
                $node,
                static fn () => NativePropertyTypeLowering::lowerClass($node),
                'Native',
            );
        }
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach (PropertyHookLowering::lowerProperty($stmt) as $method) {
                    $methods[] = $method;
                }
                foreach ($this->guard(
                    $stmt,
                    static fn () => GetterLowering::lowerProperty($stmt),
                    'Getter',
                ) as $method) {
                    $methods[] = $method;
                }
                foreach ($this->guard(
                    $stmt,
                    static fn () => PropertyMethodLowering::lowerProperty($stmt, $classReadonly),
                ) as $method) {
                    $methods[] = $method;
                }
            } elseif ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    $marker = PropertyHookLowering::lowerPromotedProperty($param);
                    if ($marker !== null) {
                        $methods[] = $marker;
                    }
                    $getter = $this->guard(
                        $param,
                        static fn () => GetterLowering::lowerPromotedProperty($param),
                        'Getter',
                    );
                    if ($getter !== null) {
                        $methods[] = $getter;
                    }
                    foreach ($this->guard(
                        $param,
                        static fn () => PropertyMethodLowering::lowerPromotedProperty($param, $classReadonly),
                    ) as $method) {
                        $methods[] = $method;
                    }
                }
            }
        }
        if ($methods !== []) {
            foreach ($methods as $method) {
                $node->stmts[] = $method;
            }
        }
        $this->guard($node, static fn () => ConstructorLowering::lowerClassLike($node), 'Constructor');
        if ($node instanceof Stmt\Class_) {
            if (CompileTimeAttribute::find($node, 'Printer') !== null) {
                $this->guard($node, static fn () => PrinterLowering::lowerClass($node), 'Printer');
            }
            if (CompileTimeAttribute::find($node, 'Arrayable') !== null) {
                $this->guard($node, static fn () => ArrayableLowering::lowerClass($node), 'Arrayable');
            }
        }
        $this->guard($node, fn () => Expansion::apply($node, $this->sourceFile));
        return null;
    }

    private function guard(Node $target, Closure $operation, ?string $attribute = null): mixed
    {
        try {
            return $operation();
        } catch (SyntaxError $error) {
            if (str_contains($error->getMessage(), '[compile-time attribute:')) {
                throw $error;
            }
            $source = $target;
            $conflictAttribute = null;
            $conflictSource = null;
            if ($error instanceof CompileTimeAttributeError) {
                $target = $error->target;
                $attribute = $error->attribute ?? $attribute;
                $source = $error->attributeSource ?? $target;
                $conflictAttribute = $error->conflictAttribute;
                $conflictSource = $error->conflictSource;
            } else {
                [$detected, $attributeSource] = $this->detectAttribute($target);
                $attribute ??= $detected;
                $source = $attributeSource ?? $target;
            }

            $attribute ??= 'unknown';
            $file = $this->sourceFile !== '' ? $this->sourceFile : '<unknown>';
            throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                $error->getMessage(),
                $attribute,
                $target,
                $file,
                $source,
                $conflictAttribute,
                $conflictSource,
            ), 0, $error);
        }
    }

    /** @return array{?string, ?Node} */
    private function detectAttribute(Node $node): array
    {
        if (!property_exists($node, 'attrGroups')) {
            return [null, null];
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $definition = CompileTimeAttributeRegistry::get(CompileTimeAttribute::resolvedName($attribute));
                if ($definition !== null) {
                    return [$definition['name'], $attribute];
                }
            }
        }
        return [null, null];
    }

}
