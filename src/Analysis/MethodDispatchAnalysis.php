<?php

namespace TypePhp\Analysis;

use PhpParser\Modifiers;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Symbol\SymbolRepository;

/** Method targets in the complete compilation's closed class hierarchy. */
final class MethodDispatchAnalysis
{
    /** @var array<string, ClassDef> Keyed by normalized PHP names, not escaped C++ symbols. */
    private array $classes = [];
    /** @var array<string, list<string>> */
    private array $children = [];
    /** @var array<string, list<string>> */
    private array $concreteReceivers = [];
    /** @var array<string, MethodDef|null> */
    private array $resolvedMethods = [];
    /** @var array<string, MethodDef|null> */
    private array $uniqueMethods = [];

    public function __construct(SymbolRepository $symbols)
    {
        foreach ($symbols->classes() as $class) {
            $this->classes[self::classKey($class->getNamespacedName(false))] = $class;
            if ($class->extends !== '') {
                $this->children[self::classKey($class->extends)][] = self::classKey($class->getNamespacedName(false));
            }
        }
    }

    /** Resolve the effective declaration, including inherited and abstract methods. */
    public function resolve(string $class, string $method): ?MethodDef
    {
        $class = self::classKey($class);
        $key = $class . '::' . strtolower($method);
        if (array_key_exists($key, $this->resolvedMethods)) {
            return $this->resolvedMethods[$key];
        }
        $visited = [];
        while ($class !== '' && !isset($visited[$class])) {
            $visited[$class] = true;
            $definition = $this->classes[$class] ?? null;
            if ($definition === null) {
                break;
            }
            if ($definition->hasMethod($method)) {
                return $this->resolvedMethods[$key] = $definition->getMethod($method);
            }
            if ($definition->hasAbstractMethod($method)) {
                return $this->resolvedMethods[$key] = $definition->getAbstractMethod($method);
            }
            $class = self::classKey($definition->extends);
        }
        return $this->resolvedMethods[$key] = null;
    }

    /** All constructible receivers within this bound must select the same body. */
    public function uniqueImplementation(string $class, string $method): ?MethodDef
    {
        $class = self::classKey($class);
        $key = $class . '::' . strtolower($method);
        if (array_key_exists($key, $this->uniqueMethods)) {
            return $this->uniqueMethods[$key];
        }
        $target = null;
        foreach ($this->possibleReceivers($class) as $receiver) {
            $implementation = $this->resolve($receiver, $method);
            if ($implementation === null || ($implementation->flags & Modifiers::ABSTRACT)
                || $implementation->functionDef === null
                || ($target !== null && $target->functionDef !== $implementation->functionDef)) {
                return $this->uniqueMethods[$key] = null;
            }
            $target = $implementation;
        }
        return $this->uniqueMethods[$key] = $target;
    }

    /** @return list<string> */
    private function possibleReceivers(string $class): array
    {
        if (isset($this->concreteReceivers[$class])) {
            return $this->concreteReceivers[$class];
        }
        $receivers = [];
        $pending = [$class];
        $visited = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($visited[$name])) {
                continue;
            }
            $visited[$name] = true;
            $definition = $this->classes[$name] ?? null;
            if ($definition === null || $definition->trait !== null) {
                continue;
            }
            if (!($definition->flags & Modifiers::ABSTRACT)) {
                $receivers[] = $name;
            }
            array_push($pending, ...($this->children[$name] ?? []));
        }
        return $this->concreteReceivers[$class] = $receivers;
    }

    private static function classKey(string $class): string
    {
        return strtolower(ltrim($class, '\\'));
    }
}
