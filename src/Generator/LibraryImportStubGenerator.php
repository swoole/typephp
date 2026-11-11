<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\PrettyPrinter;
use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\CompileTimeAttribute;
use TypePhp\Transform\CompileTimeAttributeRegistry;

final class LibraryImportStubGenerator
{
    public function __construct(
        private readonly Parser $parser,
        private readonly PrettyPrinter $printer,
    ) {
    }

    /**
     * @param array<string> $files
     * @param array<string, true> $externalImportStubFiles
     */
    public function generate(array $files, array $externalImportStubFiles): string
    {
        /** @var array<string, array<Node\Stmt>> $namespaces */
        $namespaces = [];

        foreach ($files as $file) {
            $realFile = realpath($file);
            if ($realFile === false || isset($externalImportStubFiles[$realFile])) {
                continue;
            }
            if (pathinfo($realFile, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $code = file_get_contents($realFile);
            if ($code === false) {
                throw new \RuntimeException('Can not read file: ' . $realFile);
            }
            $ast = $this->parser->parse($code) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            $ast = $traverser->traverse($ast);

            foreach ($ast as $stmt) {
                if ($stmt instanceof Node\Stmt\Namespace_) {
                    $namespaceName = $stmt->name?->toString() ?? '';
                    $this->appendDeclarations($namespaces, $namespaceName, $stmt->stmts);
                    continue;
                }
                $this->appendDeclarations($namespaces, '', [$stmt]);
            }
        }

        $namespaceNodes = [];
        foreach ($namespaces as $namespace => $stmts) {
            if ($stmts === []) {
                continue;
            }
            $namespaceNodes[] = new Node\Stmt\Namespace_(
                $namespace === '' ? null : new Node\Name($namespace),
                $stmts,
            );
        }

        $code = "<?php\n\n/** @import-library */\n\n";
        if ($namespaceNodes !== []) {
            $code .= $this->printer->prettyPrint($namespaceNodes) . "\n";
        }
        return $code;
    }

    /**
     * @param array<string, array<Node\Stmt>> $namespaces
     * @param array<Node\Stmt> $stmts
     */
    private function appendDeclarations(array &$namespaces, string $namespace, array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($namespace === '' && $stmt instanceof Node\Stmt\Function_
                && strtolower($stmt->name->toString()) === 'main') {
                continue;
            }
            $declaration = $this->makeImportDeclaration($stmt);
            if ($declaration !== null) {
                $namespaces[$namespace][] = $declaration;
            }
        }
    }

    private function makeImportDeclaration(Node\Stmt $stmt): ?Node\Stmt
    {
        if ($this->hasNoExportAttribute($stmt)) {
            return null;
        }
        if ($stmt instanceof Node\Stmt\Class_ && $this->hasNativeAttribute($stmt)) {
            $name = isset($stmt->namespacedName)
                ? $stmt->namespacedName->toString()
                : ($stmt->name?->toString() ?? '<anonymous>');
            throw new SyntaxError(
                "Native class `{$name}` cannot be exported through a library stub; mark it with #[NoExport]",
            );
        }

        $comments = array_filter(
            $stmt->getComments(),
            static fn(\PhpParser\Comment $comment): bool => preg_match(
                '/@import-library\b/',
                $comment->getText(),
            ) !== 1,
        );
        $stmt->setAttribute('comments', array_values($comments));
        $this->filterAttributesForLibraryStub($stmt);

        if ($stmt instanceof Node\Stmt\Function_) {
            foreach ($stmt->params as $param) {
                $this->filterAttributesForLibraryStub($param);
            }
            $stmt->stmts = [];
            return $stmt;
        }

        if ($stmt instanceof Node\Stmt\ClassLike) {
            $members = [];
            foreach ($stmt->stmts as $member) {
                if ($member instanceof Node\Stmt\ClassMethod) {
                    if ($this->hasNoExportAttribute($member)) {
                        continue;
                    }
                    if (!($member->flags & Modifiers::ABSTRACT)
                        && !($stmt instanceof Node\Stmt\Interface_)) {
                        $member->stmts = [];
                    }
                    $this->filterAttributesForLibraryStub($member);
                    foreach ($member->params as $param) {
                        $this->filterAttributesForLibraryStub($param);
                    }
                    $members[] = $member;
                    continue;
                }
                if ($member instanceof Node\Stmt\Property) {
                    $this->filterAttributesForLibraryStub($member);
                    foreach ($member->hooks as $hook) {
                        if ($hook->body !== null) {
                            $hook->body = [];
                        }
                    }
                    $members[] = $member;
                    continue;
                }
                if ($member instanceof Node\Stmt\ClassConst
                    || $member instanceof Node\Stmt\TraitUse
                    || $member instanceof Node\Stmt\EnumCase) {
                    $members[] = $member;
                }
            }
            $stmt->stmts = $members;
            return $stmt;
        }

        return null;
    }

    private function hasNativeAttribute(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (CompileTimeAttribute::is($attribute, 'Native')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasNoExportAttribute(Node $node): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (CompileTimeAttribute::is($attribute, 'NoExport')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function filterAttributesForLibraryStub(Node $node): void
    {
        if (!property_exists($node, 'attrGroups')) {
            return;
        }
        foreach ($node->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                $definition = CompileTimeAttributeRegistry::get(CompileTimeAttribute::resolvedName($attribute));
                if ($definition !== null && !$definition['preserve_in_library_stub']) {
                    unset($group->attrs[$attributeIndex]);
                }
            }
            $group->attrs = array_values($group->attrs);
            if ($group->attrs === []) {
                unset($node->attrGroups[$groupIndex]);
            }
        }
        $node->attrGroups = array_values($node->attrGroups);
    }
}
