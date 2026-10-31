<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\CompileTimeAttribute;
use TypePhp\Transform\NativeClassAttributeLowering;
use TypePhp\Transform\PropertyHookLowering;

final class Expansion
{
    private const MARK = 'typephpProviderExpanded';

    public static function apply(Stmt\ClassLike $node, string $sourceFile): void
    {
        if (!ProviderTable::active() || $node->getAttribute(self::MARK) === true) {
            return;
        }
        $fqcn = HostView::fqcn($node);
        $record = ExpansionMemo::get($sourceFile, $fqcn);
        if ($record === null) {
            $record = self::compute($node, $sourceFile);
            ExpansionMemo::put($sourceFile, $fqcn, $record);
        }
        if ($record !== []) {
            self::applyRecord($node, $record);
        }
        $node->setAttribute(self::MARK, true);
    }

    /** @return array<string, mixed> */
    private static function compute(Stmt\ClassLike $node, string $sourceFile): array
    {
        $sites = self::collect($node, $sourceFile);
        if ($sites === []) {
            return [];
        }

        $host = self::view($node);
        $errors = [];
        if (NativeClassAttributeLowering::isNative($node)) {
            foreach ($sites as $site) {
                $errors[] = $site['site']->format(
                    'compile-time attribute providers cannot be applied to a #[Native] class',
                );
            }

            throw new SyntaxError(implode("\n", $errors));
        }

        $members = [];
        $memberLines = [];
        $hooks = [];
        foreach ($sites as $site) {
            $buffer = self::run($host, $site, $errors);
            if ($buffer === null) {
                continue;
            }
            self::collectMembers($node, $site, $buffer, $members, $memberLines, $errors);
            self::collectHooks($node, $site, $buffer, $hooks, $errors);
        }
        self::validateHooks($host, $hooks, $errors);

        if ($errors !== []) {
            throw new SyntaxError(implode("\n", $errors));
        }

        return ['members' => $members, 'hooks' => $hooks];
    }

    /**
     * @return list<array{definition: ProviderDefinition, attribute: Node\Attribute, kind: string, member: ?string, site: Site, multi: bool}>
     */
    private static function collect(Stmt\ClassLike $node, string $sourceFile): array
    {
        $kind = HostView::kind($node);
        $sites = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $definition = self::definition($attribute, $kind);
                if ($definition !== null) {
                    $sites[] = self::site($node, $definition, $attribute, null, $sourceFile, false);
                }
            }
        }
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                $multi = count($stmt->props) !== 1;
                foreach ($stmt->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $definition = self::definition($attribute, 'property');
                        if ($definition !== null) {
                            $sites[] = self::site(
                                $stmt,
                                $definition,
                                $attribute,
                                $stmt->props[0]->name->toString(),
                                $sourceFile,
                                $multi,
                            );
                        }
                    }
                }
                continue;
            }
            if (!$stmt instanceof Stmt\ClassMethod) {
                continue;
            }
            foreach ($stmt->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $definition = self::definition($attribute, 'method');
                    if ($definition !== null) {
                        $sites[] = self::site(
                            $stmt,
                            $definition,
                            $attribute,
                            $stmt->name->toString(),
                            $sourceFile,
                            false,
                        );
                    }
                }
            }
        }

        return $sites;
    }

    private static function definition(Node\Attribute $attribute, string $kind): ?ProviderDefinition
    {
        $definition = ProviderTable::get(CompileTimeAttribute::resolvedName($attribute));
        if ($definition === null || $definition->target !== $kind) {
            return null;
        }

        return $definition;
    }

    /** @return array{definition: ProviderDefinition, attribute: Node\Attribute, kind: string, member: ?string, site: Site, multi: bool} */
    private static function site(
        Node $target,
        ProviderDefinition $definition,
        Node\Attribute $attribute,
        ?string $member,
        string $sourceFile,
        bool $multi,
    ): array {
        return [
            'definition' => $definition,
            'attribute' => $attribute,
            'kind' => $definition->target,
            'member' => $member,
            'site' => new Site(
                $definition->attribute,
                CompileTimeAttributeDiagnostic::describeTarget($target),
                $sourceFile,
                $attribute->getStartLine(),
            ),
            'multi' => $multi,
        ];
    }

    /** @param list<string> $errors */
    private static function run(array $host, array $site, array &$errors): ?ProductBuffer
    {
        if ($site['multi']) {
            $errors[] = $site['site']->format(
                'provider attributes cannot be applied to a multi-property declaration; declare one property per line',
            );

            return null;
        }
        $arguments = [];
        try {
            foreach ($site['attribute']->args as $argument) {
                $value = Literal::evaluate($argument->value);
                if ($argument->name !== null) {
                    $arguments[$argument->name->toString()] = $value;
                } else {
                    $arguments[] = $value;
                }
            }
        } catch (ProviderException $error) {
            $errors[] = $site['site']->format($error->getMessage());

            return null;
        }

        $buffer = new ProductBuffer();
        $generator = new Generator(
            new Target($host['fqcn'], $host['shortName'], $site['kind'], $site['member']),
            $host['classInfo'],
            $host['methods'],
            $host['properties'],
            $host['constants'],
            $arguments,
            $buffer,
            $site['site'],
        );
        try {
            $site['definition']->provider->compile($generator);
        } catch (ProviderException $error) {
            $errors[] = $error->isFormatted()
                ? $error->getMessage()
                : $site['site']->format($error->getMessage());

            return null;
        } catch (\Throwable $error) {
            $errors[] = $site['site']->format(
                $site['definition']->providerClass . ' threw ' . get_class($error) . ': ' . $error->getMessage(),
            );

            return null;
        }

        return $buffer;
    }

    /**
     * @param list<array{source: string, site: string}> $members
     * @param array<string, array{site: string, line: int}> $memberLines
     * @param list<string> $errors
     */
    private static function collectMembers(
        Stmt\ClassLike $node,
        array $site,
        ProductBuffer $buffer,
        array &$members,
        array &$memberLines,
        array &$errors,
    ): void {
        foreach ($buffer->members() as $source) {
            try {
                $member = SnippetParser::member($source, $site['site']->describe());
            } catch (ProviderException $error) {
                $errors[] = $site['site']->format($error->getMessage());
                continue;
            }
            $key = self::memberKey($member);
            if ($key === null) {
                $members[] = ['source' => $source, 'site' => $site['site']->describe()];
                continue;
            }
            if ($member instanceof Stmt\Property && HostView::kind($node) === 'enum') {
                $errors[] = $site['site']->format(sprintf(
                    'generated property $%s cannot be added to enum %s; enums cannot declare properties',
                    $member->props[0]->name->toString(),
                    HostView::fqcn($node),
                ));
                continue;
            }
            if (isset($memberLines[$key])) {
                $errors[] = $site['site']->format(sprintf(
                    'generated member `%s` collides with another generated member requested at %s:%d',
                    self::memberName($key),
                    $memberLines[$key]['site'],
                    $memberLines[$key]['line'],
                ));
                continue;
            }
            $hostLine = self::hostMemberLine($node, $key);
            if ($hostLine !== null) {
                $errors[] = $site['site']->format(sprintf(
                    'generated member `%s` collides with the host declaration at %s:%d',
                    self::memberName($key),
                    $site['site']->file,
                    $hostLine,
                ));
                continue;
            }
            $memberLines[$key] = ['site' => $site['site']->file, 'line' => $site['site']->line];
            $members[] = ['source' => $source, 'site' => $site['site']->describe()];
        }
    }

    /**
     * @param array<string, array<string, list<string>>> $hooks
     * @param list<string> $errors
     */
    private static function collectHooks(
        Stmt\ClassLike $node,
        array $site,
        ProductBuffer $buffer,
        array &$hooks,
        array &$errors,
    ): void {
        foreach ($buffer->hooks() as $property => $channels) {
            foreach ($channels as $channel => $bodies) {
                foreach ($bodies as $body) {
                    $hooks[$property][$channel][] = [
                        'body' => $body,
                        'site' => $site['site'],
                    ];
                }
            }
        }
    }

    /**
     * @param array<string, array<string, list<array{body: string, site: Site}>>> $hooks
     * @param list<string> $errors
     */
    private static function validateHooks(array $host, array &$hooks, array &$errors): void
    {
        $properties = $host['properties'];
        $kind = $host['kind'];
        foreach ($hooks as $property => $channels) {
            $info = $properties[$property] ?? null;
            foreach ($channels as $channel => $layers) {
                $first = $layers[0]['site'];
                if ($info === null) {
                    $errors[] = $first->format(
                        'property $' . $property . ' does not exist on ' . $host['fqcn']
                        . '; generated properties cannot be intercepted by another annotation',
                    );
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($kind !== 'class') {
                    $errors[] = $first->format(
                        'only classes support generated property hooks; ' . $kind . ' '
                        . $host['fqcn'] . ' cannot declare properties',
                    );
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($info->isStatic) {
                    $errors[] = $first->format('static property $' . $property . ' cannot have hooks');
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($info->isReadonly) {
                    $errors[] = $first->format('readonly property $' . $property . ' cannot have hooks');
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($info->promoted) {
                    $errors[] = $first->format('promoted property $' . $property . ' cannot have hooks');
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($info->hasHook($channel)) {
                    $errors[] = $first->format(
                        'property $' . $property . ' already declares a `' . $channel . '` hook',
                    );
                    unset($hooks[$property][$channel]);
                    continue;
                }
                if ($channel === 'get') {
                    $hooks[$property][$channel] = self::validateGetter($info, $layers, $errors);
                    continue;
                }
                $hooks[$property][$channel] = self::validateSetter($property, $layers, $errors);
            }
            if ($hooks[$property] === []) {
                unset($hooks[$property]);
            }
        }
    }

    /**
     * @param list<array{body: string, site: Site}> $layers
     * @param list<string> $errors
     */
    private static function validateGetter(PropertyInfo $info, array $layers, array &$errors): string
    {
        $site = $layers[0]['site'];
        if (count($layers) > 1) {
            foreach (array_slice($layers, 1) as $layer) {
                $errors[] = $layer['site']->format(
                    'property $' . $info->name . ' already has a generated getter requested at '
                    . $site->file . ':' . $site->line . '; only one getter layer is supported',
                );
            }
        }
        $body = $layers[0]['body'];
        if ($info->hasDefault && !str_contains($body, '{{value}}')) {
            $errors[] = $site->format(
                'getter for $' . $info->name . ' must read {{value}}: the property has a default value, '
                . 'so a virtual getter is not allowed',
            );
        }
        $composed = str_replace('{{value}}', '$this->' . $info->name, $body);
        try {
            SnippetParser::expression($composed, $site->describe());
        } catch (ProviderException $error) {
            $errors[] = $site->format($error->getMessage());
        }

        return $composed;
    }

    /**
     * @param list<array{body: string, site: Site}> $layers
     * @param list<string> $errors
     */
    private static function validateSetter(string $property, array $layers, array &$errors): string
    {
        $last = count($layers) - 1;
        foreach ($layers as $index => $layer) {
            $body = str_replace('{{value}}', '$value', $layer['body']);
            if ($index !== $last && !str_contains($body, '{{call}}')) {
                $errors[] = $layer['site']->format(
                    'setter layer for $' . $property . ' must contain {{call}}, otherwise the inner layers '
                    . 'would never run',
                );
            }
            $layers[$index]['body'] = $body;
        }
        $inner = '$this->' . $property . ' = $value;';
        for ($index = $last; $index >= 0; $index--) {
            $body = $layers[$index]['body'];
            $inner = str_contains($body, '{{call}}')
                ? str_replace('{{call}}', $inner, $body)
                : rtrim($body) . "\n" . $inner;
        }
        try {
            SnippetParser::statements($inner, $layers[0]['site']->describe());
        } catch (ProviderException $error) {
            $errors[] = $layers[0]['site']->format($error->getMessage());
        }

        return $inner;
    }

    /** @return array{fqcn: string, shortName: string, kind: string, classInfo: ClassInfo, methods: array<string, MethodSignature>, properties: array<string, PropertyInfo>, constants: array<string, mixed>} */
    private static function view(Stmt\ClassLike $node): array
    {
        return [
            'fqcn' => HostView::fqcn($node),
            'shortName' => HostView::shortName($node),
            'kind' => HostView::kind($node),
            'classInfo' => HostView::classInfo($node),
            'methods' => HostView::methods($node),
            'properties' => HostView::properties($node),
            'constants' => HostView::constants($node),
        ];
    }

    /** @param array<string, mixed> $record */
    private static function applyRecord(Stmt\ClassLike $node, array $record): void
    {
        foreach ($record['members'] as $member) {
            $node->stmts[] = SnippetParser::member($member['source'], $member['site']);
        }
        foreach ($record['hooks'] as $property => $channels) {
            $target = self::findProperty($node, $property);
            if ($target === null) {
                continue;
            }
            foreach ($channels as $channel => $body) {
                $target->hooks[] = new Node\PropertyHook(
                    $channel,
                    $channel === 'get'
                        ? SnippetParser::expression($body, 'getter for $' . $property)
                        : SnippetParser::statements($body, 'setter for $' . $property),
                );
            }
            foreach (PropertyHookLowering::lowerProperty($target) as $method) {
                $node->stmts[] = $method;
            }
        }
    }

    private static function findProperty(Stmt\ClassLike $node, string $name): ?Stmt\Property
    {
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property
                && count($stmt->props) === 1
                && $stmt->props[0]->name->toString() === $name
            ) {
                return $stmt;
            }
        }

        return null;
    }

    private static function memberKey(Stmt $member): ?string
    {
        if ($member instanceof Stmt\ClassMethod) {
            return 'method:' . strtolower($member->name->toString());
        }
        if ($member instanceof Stmt\Property) {
            return 'property:' . $member->props[0]->name->toString();
        }
        if ($member instanceof Stmt\ClassConst) {
            return 'const:' . $member->consts[0]->name->toString();
        }

        return null;
    }

    private static function memberName(string $key): string
    {
        return substr($key, (int) strpos($key, ':') + 1);
    }

    private static function hostMemberLine(Stmt\ClassLike $node, string $key): ?int
    {
        $name = self::memberName($key);
        if (str_starts_with($key, 'method:')) {
            foreach ($node->getMethods() as $method) {
                if (strtolower($method->name->toString()) === $name) {
                    return $method->getStartLine();
                }
            }

            return null;
        }
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property && str_starts_with($key, 'property:')) {
                foreach ($stmt->props as $property) {
                    if ($property->name->toString() === $name) {
                        return $stmt->getStartLine();
                    }
                }
            }
            if ($stmt instanceof Stmt\ClassConst && str_starts_with($key, 'const:')) {
                foreach ($stmt->consts as $const) {
                    if ($const->name->toString() === $name) {
                        return $stmt->getStartLine();
                    }
                }
            }
        }

        return null;
    }
}
