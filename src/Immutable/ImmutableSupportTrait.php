<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Immutable;

use PhpParser\Node;
use PhpParser\NodeAbstract;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\FunctionDef;
use TypePhp\Type;

/** Compile-time effect checks; successful checks emit no runtime code. */
trait ImmutableSupportTrait
{
    protected function immutableTypeNodeMayBeObject(?NodeAbstract $type): bool
    {
        if ($type === null) {
            return true;
        }
        if ($type instanceof Node\NullableType) {
            return $this->immutableTypeNodeMayBeObject($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $member) {
                if ($this->immutableTypeNodeMayBeObject($member)) {
                    return true;
                }
            }
            return false;
        }
        if ($type instanceof Node\Name) {
            return true;
        }
        if ($type instanceof Node\Identifier) {
            return in_array(strtolower($type->toString()), ['mixed', 'object', 'iterable', 'callable'], true);
        }
        return false;
    }

    protected function initializeImmutableFunctionContext(): void
    {
        if ($this->functionDef?->immutable && $this->methodDef !== null) {
            $this->context->markImmutableBinding('this_', true);
        }
        foreach ($this->functionDef?->argInfoList ?? [] as $argument) {
            if (!$argument->immutable) {
                continue;
            }
            $this->context->markImmutableBinding(
                $argument->name,
                $argument->type === Type::OBJECT || $argument->type === Type::VAR,
            );
        }
    }

    protected function immutableRootName(NodeAbstract $expression): ?string
    {
        if ($this->context->immutableVars === []) {
            return null;
        }
        if ($expression instanceof Node\Expr\ErrorSuppress) {
            return $this->immutableRootName($expression->expr);
        }
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            $name = $this->parseVariable($expression);
            return isset($this->context->immutableVars[$name]) ? $name : null;
        }
        if ($expression instanceof Node\Expr\PropertyFetch
            || $expression instanceof Node\Expr\NullsafePropertyFetch
            || $expression instanceof Node\Expr\ArrayDimFetch
        ) {
            return $this->immutableRootName($expression->var);
        }
        if (($expression instanceof Node\Expr\MethodCall
                || $expression instanceof Node\Expr\NullsafeMethodCall)
            && $this->immutableRootName($expression->var) !== null
            && $this->immutableCalledMethod($expression)?->immutable
        ) {
            return $this->immutableRootName($expression->var);
        }
        return null;
    }

    protected function immutableDisplayName(string $name): string
    {
        return $name === 'this_' ? '$this' : '$' . $this->unescapeVarName($name);
    }

    protected function immutableValueMayBeObject(NodeAbstract $expression): bool
    {
        $root = $this->immutableRootName($expression);
        if ($root === null) {
            return false;
        }
        $type = $this->detectTypeOfExpr($expression);
        // Virtual dispatch may return a Variant despite a scalar contract.
        if ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
            $method = $this->immutableCalledMethod($expression);
            if ($method !== null && !$method->returnTypeUndeclared
                && !$this->hasInaccessibleDeclaredMethod($method->declaringClass, $expression->name->toString())) {
                $type = $method->returnType;
            }
        }
        // An immutable receiver may produce an ordinary scalar/COW value.
        // Preserve constness only when object identity is possible.
        if ($type !== Type::OBJECT && $type !== Type::VAR && $type !== Type::REF) {
            return false;
        }
        if (isset($this->context->immutableObjectVars[$root])) {
            return true;
        }
        return $this->detectClassOfExpr($expression) !== ''
            || $type === Type::OBJECT;
    }

    protected function assertImmutableMutationTarget(NodeAbstract $target): void
    {
        if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->assertImmutableMutationTarget($item->value);
                }
            }
            return;
        }
        $root = $this->immutableRootName($target);
        if ($root !== null) {
            $this->fatalError(
                $target,
                'Cannot modify immutable value `' . $this->immutableDisplayName($root) . '`',
            );
        }
    }

    protected function recordImmutableAlias(NodeAbstract $left, NodeAbstract $right): void
    {
        if ($right instanceof Node\Expr\Clone_) {
            return;
        }
        $root = $this->immutableRootName($right);
        if ($root === null || !$this->immutableValueMayBeObject($right)) {
            return;
        }
        if (!$left instanceof Node\Expr\Variable || !is_string($left->name)) {
            $this->fatalError(
                $right,
                'Immutable object `' . $this->immutableDisplayName($root)
                . '` cannot be stored in mutable state',
            );
        }
        $name = $this->parseVariable($left);
        if ($this->hasScopeGlobalVar($name) || $this->hasStaticVar($name)) {
            $this->fatalError(
                $right,
                'Immutable object `' . $this->immutableDisplayName($root)
                . '` cannot be stored in mutable state',
            );
        }
        $this->context->markImmutableBinding($name, true);
        $class = $this->detectClassOfExpr($right);
        if ($class !== '') {
            $this->addObject($name, $class);
        }
    }

    protected function assertImmutableObjectDoesNotEscape(NodeAbstract $expression, string $destination): void
    {
        $root = $this->immutableRootName($expression);
        if ($root !== null && $this->immutableValueMayBeObject($expression)) {
            $this->fatalError(
                $expression,
                'Immutable object `' . $this->immutableDisplayName($root)
                . '` cannot escape through ' . $destination,
            );
        }
    }

    protected function assertImmutableValueMethodDoesNotMutate(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call,
        string $root,
    ): void {
        if (!$call->name instanceof Node\Identifier) {
            return;
        }
        $type = $this->detectTypeOfExpr($call->var);
        $method = $call->name->toString();
        if ($this->isUniversalMethodMutating($type, $method)) {
            $this->fatalError(
                $call,
                "Cannot call mutating method `{$method}()` on immutable value `"
                . $this->immutableDisplayName($root) . '`',
            );
        }
    }

    protected function immutableExtensionAcceptsReceiver(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call,
    ): bool {
        if (!$call->name instanceof Node\Identifier) {
            return false;
        }
        $method = $call->name->toString();
        $class = $this->detectClassOfExpr($call->var);
        if ($class !== '') {
            $definition = $this->findObjectExtensionMethod($class, $method, true);
        } else {
            $definition = $this->findExtensionMethod($this->detectTypeOfExpr($call->var), $method);
        }
        $definition ??= $this->findKeywordExtensionMethod($method);
        return (bool) ($definition['receiver_immutable'] ?? false);
    }

    protected function immutableCalledMethod(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call,
    ): ?FunctionDef {
        $ordinary = $call instanceof Node\Expr\NullsafeMethodCall
            ? new Node\Expr\MethodCall($call->var, $call->name, $call->args, $call->getAttributes())
            : $call;
        return $this->resolveCalledFunctionDef($ordinary);
    }

    protected function immutableClassName(Node\Name $name): string
    {
        $class = $this->parseIdentifier($name);
        if ($class === 'self' || $class === 'static') {
            return $this->getFullClassName();
        }
        if ($class === 'parent') {
            return $this->classDef?->extends ?? '';
        }
        return $this->getNamespacedClassName($class);
    }

    protected function immutableArgInfo(
        FunctionDef $function,
        Node\Arg $argument,
        int $index,
    ): ?ArgInfo {
        if ($argument->name === null) {
            return $this->getArgInfoByIndex($function, $index);
        }
        if (!$argument->name instanceof Node\Identifier) {
            return null;
        }
        $name = $argument->name->toString();
        $variadic = null;
        foreach ($function->argInfoList as $info) {
            if ($info->variadic) {
                $variadic = $info;
            }
            if (($info->phpName ?: $this->unescapeVarName($info->name)) === $name) {
                return $info;
            }
        }
        return $variadic;
    }

    /** @return array{string, string} function/method name and class name */
    protected function immutableCallableName(Node\Expr\CallLike $call): array
    {
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            return [ltrim($this->parseIdentifier($call->name), '\\'), ''];
        }
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
            && $call->name instanceof Node\Identifier
        ) {
            $class = $this->detectClassOfExpr($call->var);
            if ($class === '' && $call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
                $name = $this->parseVariable($call->var);
                $class = $name === 'this_' ? $this->getFullClassName() : $this->getDeclaredObjectType($name);
            }
            return [$call->name->toString(), $class];
        }
        if ($call instanceof Node\Expr\StaticCall
            && $call->class instanceof Node\Name
            && $call->name instanceof Node\Identifier
        ) {
            $class = $this->immutableClassName($call->class);
            return [$call->name->toString(), $class];
        }
        if ($call instanceof Node\Expr\New_ && $call->class instanceof Node\Name) {
            return ['__construct', $this->immutableClassName($call->class)];
        }
        return ['', self::DYNAMIC_CALLED_CLASS];
    }

    protected function validateImmutableCall(Node\Expr\CallLike $call): void
    {
        if ($this->context->immutableVars === []) {
            return;
        }
        if ($call->getAttribute('typephpImmutableValidated', false)) {
            return;
        }
        $call->setAttribute('typephpImmutableValidated', true);

        $function = $this->resolveCalledFunctionDef($call);
        if ($call instanceof Node\Expr\NullsafeMethodCall) {
            $function = $this->immutableCalledMethod($call);
        } elseif ($call instanceof Node\Expr\New_ && $call->class instanceof Node\Name) {
            $class = $this->immutableClassName($call->class);
            $function = $class === '' ? null : $this->findAotMethodFunctionDef($class, '__construct');
        }

        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
            && $call->name instanceof Node\Identifier
            && ($root = $this->immutableRootName($call->var)) !== null
        ) {
            // A named call must be proven immutable. A variable method name
            // is an explicit escape hatch, similar to const_cast in C++;
            // #[Immutable] deliberately has no runtime component.
            if (!$this->immutableValueMayBeObject($call->var)) {
                $this->assertImmutableValueMethodDoesNotMutate($call, $root);
            } elseif (($function === null || !$function->immutable)
                && !$this->immutableExtensionAcceptsReceiver($call)
            ) {
                $method = $call->name->toString();
                $class = $this->detectClassOfExpr($call->var) ?: 'object';
                $this->fatalError(
                    $call,
                    "Cannot call mutable method `{$class}::{$method}()` on immutable value `"
                    . $this->immutableDisplayName($root) . '`',
                );
            }
        }

        [$callable, $class] = $this->immutableCallableName($call);
        $staticallyResolved = $function !== null
            || ($call instanceof Node\Expr\FuncCall && $callable !== '')
            || ($callable !== '' && $class !== '' && $class !== self::DYNAMIC_CALLED_CLASS);
        if (!$staticallyResolved) {
            return;
        }
        foreach ($call->getRawArgs() as $index => $argument) {
            if ($argument instanceof Node\VariadicPlaceholder) {
                continue;
            }
            $root = $this->immutableRootName($argument->value);
            if ($root === null) {
                continue;
            }
            $info = $function === null ? null : $this->immutableArgInfo($function, $argument, $index);
            $byRef = $info?->byRef ?? false;
            if ($function === null) {
                $byRef = $argument->name instanceof Node\Identifier
                    ? $this->isReferenceNamedArgument($callable, $class, $argument->name->toString())
                    : $this->isReferenceArgument($callable, $class, $index);
            }
            if ($byRef && !($info?->immutable ?? false)) {
                $this->fatalError(
                    $argument,
                    'Cannot pass immutable value `' . $this->immutableDisplayName($root)
                    . '` to reference parameter ' . ($index + 1) . ' of ' . $callable . '()',
                );
            }
            if ($this->immutableValueMayBeObject($argument->value) && !($info?->immutable ?? false)) {
                $this->fatalError(
                    $argument,
                    'Immutable object `' . $this->immutableDisplayName($root)
                    . '` requires an #[Immutable] parameter',
                );
            }
        }
    }
}
