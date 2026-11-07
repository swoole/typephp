<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Resolver;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;

trait NameResolutionTrait
{
    public function getNamespacedClassName(string $class, string $currentNamespace = ''): string
    {
        if ($class === '') {
            $this->error('Class name can not be empty');
        }
        if ($class[0] === '\\') {
            return $this->recordReferencedClass($class);
        }

        $ns2 = explode('\\', trim($class, '\\'));

        $aliasTarget = $this->getClassImportAlias($ns2[0]);
        if ($aliasTarget !== null) {
            $ns = '\\' . $aliasTarget;
            _return:
            if (count($ns2) > 1) {
                $ns .= '\\' . implode('\\', array_slice($ns2, 1));
            }
            return $this->recordReferencedClass($ns);
        }

        foreach ($this->useNamespaces as $useNamespace) {
            $ns1 = explode('\\', trim($useNamespace, '\\'));
            if (strcasecmp($ns1[array_key_last($ns1)], $ns2[0]) === 0) {
                $ns = '\\' . implode('\\', $ns1);
                goto _return;
            }
        }

        // Handle qualified names that exactly match a use import (e.g. the extends
        // of an anonymous class may already be a qualified name like "A\B\C" when the
        // use import is also "A\B\C").
        if (count($ns2) > 1) {
            foreach ($this->useNamespaces as $useNamespace) {
                if (strcasecmp(trim($useNamespace, '\\'), $class) === 0) {
                    return $this->recordReferencedClass($class);
                }
            }
        }

        if (!$currentNamespace) {
            $currentNamespace = $this->namespace;
        }
        if (!empty($currentNamespace)) {
            return $this->recordReferencedClass(trim($currentNamespace, '\\') . '\\' . $class);
        }

        return $this->recordReferencedClass($class);
    }

    /**
     * Upgrade the class-name Name node in a trait method parameter to Name\FullyQualified.
     * For qualified names (containing \) already resolved by parseTypeDecl(), upgrade the node type directly;
     * for unresolved unqualified names (such as the inner type of a NullableType, which parseTypeDecl skips by returning TYPE_VAR),
     * resolve them via useAliases/useNamespaces first and then upgrade.
     * gen_stub.php's SimpleType::fromNode() relies on isFullyQualified() to decide whether to re-resolve;
     * if the name is not upgraded to FullyQualified, the current namespace prefix is wrongly appended once the context is lost.
     */
    protected function upgradeToFullyQualifiedName(
        Node\ComplexType|Node\Identifier|Node\Name|null $type,
    ): Node\ComplexType|Node\Identifier|Node\Name|null
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return new Node\NullableType($this->upgradeDeclaredTypeName($type->type));
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $i => $subType) {
                $type->types[$i] = $subType instanceof Node\IntersectionType
                    ? $this->upgradeIntersectionType($subType)
                    : $this->upgradeDeclaredTypeName($subType);
            }
            return $type;
        }
        if ($type instanceof Node\IntersectionType) {
            return $this->upgradeIntersectionType($type);
        }
        return $this->upgradeDeclaredTypeName($type);
    }

    private function upgradeIntersectionType(Node\IntersectionType $type): Node\IntersectionType
    {
        foreach ($type->types as $i => $subType) {
            $type->types[$i] = $this->upgradeDeclaredTypeName($subType);
        }
        return $type;
    }

    private function upgradeDeclaredTypeName(
        Node\Identifier|Node\Name $type,
    ): Node\Identifier|Node\Name {
        if ($type instanceof Node\Name\FullyQualified) {
            return $type;
        }
        if ($type instanceof Node\Name) {
            $typeName = $type->toString();
            if (isset($this->zendTypeMap[strtolower($typeName)]) || in_array(strtolower($typeName), ['self', 'static', 'parent'], true)) {
                return $type;
            }
            // NameResolver has already applied the declaring file's namespace
            // imports. Keep that canonical identity when a trait signature is
            // copied into its consuming class. In particular, after
            // `use X\X; use X\Y;`, resolving Y produces X\Y; feeding that string
            // through the import table again would incorrectly produce X\X\Y.
            $resolvedName = $type->getAttribute('resolvedName');
            if ($resolvedName instanceof Node\Name\FullyQualified) {
                return new Node\Name\FullyQualified($resolvedName->toString(), $type->getAttributes());
            }
            $resolved = $typeName;
            $firstSegment = explode('\\', $typeName, 2)[0];
            $hasImportedPrefix = $this->getClassImportAlias($firstSegment) !== null;
            if (!$hasImportedPrefix) {
                foreach ($this->useNamespaces as $useNamespace) {
                    $segments = explode('\\', trim($useNamespace, '\\'));
                    if (strcasecmp($segments[array_key_last($segments)], $firstSegment) === 0) {
                        $hasImportedPrefix = true;
                        break;
                    }
                }
            }
            if (!$type->isQualified() || $hasImportedPrefix) {
                $resolved = $this->getNamespacedClassName($typeName);
            }
            return new Node\Name\FullyQualified($resolved, $type->getAttributes());
        }
        return $type;
    }

    private function getClassImportAlias(string $name): ?string
    {
        return $this->useAliases[strtolower($name)] ?? null;
    }

    /**
     * Process the function name and prepend the namespace when required.
     */
    public function getNamespacedFuncName(string $funcName): string
    {
        if ($funcName[0] == '\\') {
            return ltrim($funcName, '\\');
        }
        $import = strtolower($funcName);
        if (isset($this->useFunctions[$import])) {
            return $this->useFunctions[$import];
        }
        return $funcName;
    }

    /**
     * @param string $class must be a fully qualified class name including the namespace
     */
    protected function resolveTypeDecl(?NodeAbstract $type, int $what): array
    {
        $class = '';
        $declaredType = $this->parseTypeDecl($type, $what, $class);
        return [$declaredType, $class];
    }

    protected function parseTypeDecl(?NodeAbstract $type, int $what, string &$class): string
    {
        // An undefined type is treated as var (mixed, any)
        if ($type === null) {
            return Type::VAR;
        }
        $this->validateCompoundTypeDeclaration($type);
        if ($type instanceof UnionType || $type instanceof NullableType || $type instanceof IntersectionType) {
            // Complex types are uniformly treated as mixed/var at the static stage; the runtime typeCheck provides the fallback.
            return Type::VAR;
        } else {
            $typeName = $this->parseIdentifier($type);
            $typeNameLower = strtolower($typeName);
            // Property and class-constant types cannot be declared void/never; only return types can
            if ($what !== self::DECL_TYPE_OF_RETURN and ($typeNameLower === 'void' or $typeNameLower === 'never')) {
                $this->fatalError($type, 'The type `void`/`never` is allowed only for return type');
            } elseif (isset($this->zendTypeMap[$typeNameLower])) {
                return $this->getTypeFromZendType($typeNameLower);
            } else {
                if ($typeName === 'self') {
                    $class = $this->getFullClassLikeName();
                } elseif ($typeName === 'parent') {
                    if (!$this->classDef) {
                        $this->fatalError($type, 'Cannot use "parent" type declaration outside a class');
                    }
                    $class = $this->classDef->extends;
                } elseif ($typeName === 'static') {
                    // Late-bound: the declaring class is a sound lower bound.
                    // Direct dispatch stays gated by the devirtualization checks.
                    $class = $this->getFullClassLikeName();
                } else {
                    $class = $this->getNamespacedClassName($typeName);
                }
                // When a trait is injected into a class, the fully qualified class name is required
                if ($class and $this->classDef and $this->classDef->trait) {
                    $type->name = $class;
                }
                return Type::OBJECT;
            }
        }
    }

}
