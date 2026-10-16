<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Resolver;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ClassLikeDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\EnumCaseRef;
use TypePhp\Exception\TestError;

trait ClassConstantValueTrait
{
    /** @var array<string, true> Class names are case-insensitive; case names are not. */
    private array $enumCaseBackingEvaluationsInProgress = [];

    /** @var array<string, true> */
    private array $globalConstantEvaluationsInProgress = [];

    /** @var array<string, true> Class names are case-insensitive; constant names are not. */
    private array $classConstantEvaluationsInProgress = [];

    public function getDefinedConstants(): array
    {
        return $this->internalConstants;
    }

    public function getClassConstValue(NodeAbstract $expr, string $_class, string $name, string $currentClass = ''): mixed
    {
        $namespace = $this->namespace;
        if (!$namespace and $currentClass and !str_contains($_class, '\\')) {
            $namespace = $this->getNamespaceOfClass($currentClass);
        }
        $class = $this->getNamespacedClassName($_class, $namespace);
        $nativeConst = $this->findNativeClassConst(
            $expr,
            $class,
            $name,
            $currentClass !== '' ? $currentClass : null,
        );
        if ($nativeConst and $expr->hasAttribute('nativeConst')) {
            $constDef = $expr->getAttribute('nativeConst');
            if ($constDef->valueExpr !== null) {
                return $this->evaluateClassConstValue($expr, $constDef, $class, $name);
            }
            if ($constDef->class !== '') {
                $refConst = $constDef->class . '::' . $name;
                if (defined($refConst)) {
                    return constant($refConst);
                }
            }
        }
        if ($this->isInternalClass($class)) {
            $constName = $class . '::' . $name;
            if (defined($constName)) {
                $value = constant($constName);
                // Internal enum cases (and internal constants holding one)
                // must keep their identity through constant evaluation.
                return $value instanceof \UnitEnum
                    ? new EnumCaseRef(get_class($value), $value->name)
                    : $value;
            }
        }
        if ($this->hasInterface($class)) {
            $constant = $this->findCompileTimeInterfaceConstant($class, $name);
            if ($constant !== null) {
                [$interface, $constantDef] = $constant;
                return $this->evaluateCompileTimeClassConstant(
                    $expr,
                    $interface,
                    $constantDef,
                    $name,
                    $interface,
                );
            }
        }
        [$inheritedFound, $inherited] = $this->resolveInheritedClassConst($class, $name);
        if ($inheritedFound) {
            return $inherited;
        }
        if ($this->hasClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->enum && array_key_exists($name, $classDef->enumCases)) {
                // The case IDENTITY is the constant's value; folding to the
                // backing scalar (or the case name) would make
                // `K::CONST === E::Case` false through every dynamic path.
                return new EnumCaseRef($classDef->getNamespacedName(false), $name);
            }
        }
        $this->fatalError($expr, "Class constant `{$class}::{$name}` not found");
    }

    /** @return array{bool, mixed} */
    protected function resolveInheritedClassConst(string $class, string $name): array
    {
        $current = ltrim($class, '\\');
        $visited = [];
        while ($current !== '' && $current !== '\\' && !isset($visited[strtolower($current)])) {
            $visited[strtolower($current)] = true;
            if ($this->hasClass($current)) {
                $classDef = $this->getClass($current);
                if ($classDef->hasConstant($name)) {
                    $constDef = $classDef->getConstant($name);
                    if ($constDef->valueExpr !== null) {
                        return [true, $this->evaluateClassConstValue(null, $constDef, $current, $name)];
                    }
                    if ($constDef->class !== '' && defined($constDef->class . '::' . $name)) {
                        return [true, constant($constDef->class . '::' . $name)];
                    }
                }
                $current = $classDef->extends;
            } elseif (($parent = $this->getParentClass($current)) !== '') {
                $current = $parent;
            } elseif (Reflection::isInternalClass($current)) {
                $constName = $current . '::' . $name;
                if (defined($constName)) {
                    $value = constant($constName);
                    return [true, $value instanceof \UnitEnum
                        ? new EnumCaseRef(get_class($value), $value->name)
                        : $value];
                }
                break;
            } else {
                break;
            }
        }
        return [false, null];
    }

    protected function evaluateClassConstValue(?NodeAbstract $origin, ConstantDef $constDef, string $class, string $name): mixed
    {
        $valueExpr = $constDef->valueExpr;
        if (!$valueExpr instanceof Node\Expr) {
            $this->fatalError($origin, "Class constant `{$class}::{$name}` has no constant expression");
        }

        $evaluator = new ConstExprEvaluator(function (Node\Expr $expr) use ($origin, $class) {
            if ($expr instanceof Node\Expr\ConstFetch) {
                $constName = $expr->name->toString();
                return match (strtolower($constName)) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => defined($constName)
                        ? constant($constName)
                        : throw new \RuntimeException("Constant `{$constName}` not found"),
                };
            }
            if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
                $constName = $expr->name->toString();
                $className = $expr->class->toString();
                if (strcasecmp($constName, 'class') === 0) {
                    // `::class` is a compile-time magic constant that resolves to the
                    // fully qualified class name of the referenced class.
                    if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
                        $className = $class;
                    } elseif (strcasecmp($className, 'parent') === 0) {
                        $className = $this->getParentClass($class);
                    }
                    return ltrim($this->getNamespacedClassName($className, $this->getNamespaceOfClass($class)), '\\');
                }
                if (strcasecmp($className, 'self') === 0) {
                    $className = $class;
                } elseif (strcasecmp($className, 'parent') === 0) {
                    $className = $this->getParentClass($class);
                }
                return $this->getClassConstValue($origin ?? $expr, $className, $constName, $class);
            }
            throw new \RuntimeException('Unsupported class constant expression');
        });

        return $evaluator->evaluateDirectly($valueExpr);
    }

    /**
     * The pre-AST representation of an enum case for consumers that cannot
     * register an IS_CONSTANT_AST (property and parameter defaults, attribute
     * arguments): internal enums degrade to the host case object, compiled
     * enums to the literal backing value or the case name — exactly the
     * values those paths consumed before case identity existed.
     */
    public function enumCaseLegacyValue(\TypePhp\Entity\EnumCaseRef $ref): mixed
    {
        if ($this->isInternalClass($ref->enumClass)) {
            $constName = $ref->enumClass . '::' . $ref->caseName;
            if (defined($constName)) {
                return constant($constName);
            }
        }
        if ($this->hasClass($ref->enumClass)) {
            $classDef = $this->getClass($ref->enumClass);
            if (array_key_exists($ref->caseName, $classDef->enumCases)) {
                return $classDef->enumCases[$ref->caseName] ?? $ref->caseName;
            }
        }
        return $ref->caseName;
    }

    protected function evaluatePreparedEnumCaseBackingValue(
        Node\Stmt\EnumCase $case,
        ClassDef $classDef,
        string $caseName,
    ): int|string {
        return $this->evaluateAndStoreEnumCaseBackingValue($case, $classDef, $caseName);
    }

    /**
     * Return the scalar produced during declaration-expression finalization.
     * gen_stub.php consumes this value but never evaluates the source AST.
     */
    public function getFinalizedEnumCaseBackingValue(string $enumClass, string $caseName): int|string
    {
        $classDef = $this->getClassDef(ltrim($enumClass, '\\'));
        if ($classDef === null || !$classDef->enum || $classDef->enumBackingType === null) {
            throw new \LogicException("Backed enum `{$enumClass}` is not declared");
        }
        if (isset($classDef->enumCaseExpressions[$caseName])) {
            throw new \LogicException(
                "Enum case `{$enumClass}::{$caseName}` reached code generation before constant-expression finalization",
            );
        }
        $value = $classDef->enumCases[$caseName] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \LogicException("Enum case `{$enumClass}::{$caseName}` has no finalized backing value");
        }
        return $value;
    }

    private function evaluateAndStoreEnumCaseBackingValue(
        NodeAbstract $origin,
        ClassDef $classDef,
        string $caseName,
    ): int|string {
        $enumName = $classDef->getNamespacedName(false);
        if (!isset($classDef->enumCaseExpressions[$caseName])) {
            return $this->getFinalizedEnumCaseBackingValue($enumName, $caseName);
        }

        $key = strtolower($enumName) . '::' . $caseName;
        if (isset($this->enumCaseBackingEvaluationsInProgress[$key])) {
            $this->fatalError($origin, "Cannot declare self-referencing constant `{$enumName}::{$caseName}`");
        }

        $this->enumCaseBackingEvaluationsInProgress[$key] = true;
        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $value = $this->evaluateCompileTimeExpression(
                $classDef->enumCaseExpressions[$caseName],
                $classDef,
            );
        } catch (TestError $error) {
            throw $error;
        } catch (\Throwable $error) {
            $detail = $error->getMessage();
            $suffix = $detail !== '' ? ": {$detail}" : '';
            $this->fatalError(
                $origin,
                "Enum case `{$enumName}::{$caseName}` backing value must be compile-time evaluable{$suffix}",
            );
        } finally {
            restore_error_handler();
            unset($this->enumCaseBackingEvaluationsInProgress[$key]);
        }

        $expectedType = $classDef->enumBackingType;
        $valid = $expectedType === 'int' ? is_int($value) : is_string($value);
        if (!$valid) {
            $actualType = get_debug_type($value);
            $this->fatalError(
                $origin,
                "Enum case `{$enumName}::{$caseName}` backing value must be of type {$expectedType}, {$actualType} given",
            );
        }

        $classDef->enumCases[$caseName] = $value;
        unset($classDef->enumCaseExpressions[$caseName]);
        return $value;
    }

    private function evaluateCompileTimeExpression(
        Node\Expr $expression,
        ClassLikeDef $scope,
        ?ClassLikeDef $lateBoundScope = null,
    ): mixed
    {
        $lateBoundScope ??= $scope;
        $evaluator = new ConstExprEvaluator(function (Node\Expr $expr) use ($scope, $lateBoundScope): mixed {
            if ($expr instanceof Node\Expr\Cast) {
                $value = $this->evaluateCompileTimeExpression($expr->expr, $scope, $lateBoundScope);
                return match (true) {
                    $expr instanceof Node\Expr\Cast\Int_ => (int) $value,
                    $expr instanceof Node\Expr\Cast\Double => (float) $value,
                    $expr instanceof Node\Expr\Cast\Bool_ => (bool) $value,
                    $expr instanceof Node\Expr\Cast\String_ => (string) $value,
                    $expr instanceof Node\Expr\Cast\Array_ => (array) $value,
                    default => throw new \RuntimeException('Unsupported constant-expression cast'),
                };
            }

            if ($expr instanceof Node\Expr\ConstFetch) {
                return $this->evaluateCompileTimeConstantFetch($expr, $scope);
            }

            if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
                $name = $expr->name instanceof Node\Identifier
                    ? $expr->name->toString()
                    : $this->evaluateCompileTimeExpression($expr->name, $scope, $lateBoundScope);
                if (!is_string($name)) {
                    throw new \RuntimeException('A compile-time class constant name must evaluate to string');
                }
                $class = $this->resolveCompileTimeClassName($expr->class, $scope, $lateBoundScope);
                if (strcasecmp($name, 'class') === 0) {
                    return ltrim($class, '\\');
                }
                // A Trait's self::CONST first refers to a constant declared by
                // that Trait template. If it has no such declaration, resolve
                // it against the consuming class (including its parents).
                if (strcasecmp($expr->class->toString(), 'self') === 0
                    && $scope instanceof ClassDef
                    && $scope->trait !== null
                    && $scope->hasConstant($name)
                ) {
                    $value = $this->evaluateCompileTimeClassConstant(
                        $expr,
                        $scope,
                        $scope->getConstant($name),
                        $name,
                        $lateBoundScope,
                    );
                } else {
                    $value = $this->evaluateCompileTimeClassConstantFetch(
                        $expr,
                        $class,
                        $name,
                        $scope,
                    );
                }
                if ($value instanceof EnumCaseRef && $this->isEnumCaseBackingEvaluationInProgress($value)) {
                    $this->fatalError(
                        $expr,
                        "Cannot declare self-referencing constant `{$value->enumClass}::{$value->caseName}`",
                    );
                }
                return $value;
            }

            if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
                $object = $this->evaluateCompileTimeExpression($expr->var, $scope, $lateBoundScope);
                if ($object === null && $expr instanceof Node\Expr\NullsafePropertyFetch) {
                    return null;
                }
                if (!$object instanceof EnumCaseRef) {
                    throw new \RuntimeException('Compile-time property fetch is only supported on enum cases');
                }
                if ($this->isEnumCaseBackingEvaluationInProgress($object)) {
                    $this->fatalError(
                        $expr,
                        "Cannot declare self-referencing constant `{$object->enumClass}::{$object->caseName}`",
                    );
                }
                $property = $expr->name instanceof Node\Identifier
                    ? $expr->name->toString()
                    : $this->evaluateCompileTimeExpression($expr->name, $scope, $lateBoundScope);
                if ($property === 'name') {
                    return $object->caseName;
                }
                if ($property === 'value') {
                    return $this->resolveCompileTimeEnumCaseBackingValue($expr, $object);
                }
                throw new \RuntimeException(
                    "Undefined enum case property `{$object->enumClass}::{$object->caseName}->{$property}`",
                );
            }

            if ($expr instanceof Node\Scalar\MagicConst) {
                return $this->evaluateCompileTimeMagicConstant($expr, $scope, $lateBoundScope);
            }

            throw new \RuntimeException("Expression `{$expr->getType()}` is not compile-time evaluable");
        });

        return $evaluator->evaluateDirectly($expression);
    }

    private function evaluateCompileTimeClassConstantFetch(
        NodeAbstract $origin,
        string $class,
        string $name,
        ClassLikeDef $scope,
    ): mixed {
        $class = ltrim($class, '\\');

        if ($this->hasClass($class)) {
            $current = $class;
            $visited = [];
            while ($current !== '' && !isset($visited[strtolower($current)])) {
                $visited[strtolower($current)] = true;
                $classDef = $this->getClass($current);
                if ($classDef->enum && array_key_exists($name, $classDef->enumCases)) {
                    return new EnumCaseRef($classDef->getNamespacedName(false), $name);
                }
                if ($classDef->hasConstant($name)) {
                    return $this->evaluateCompileTimeClassConstant(
                        $origin,
                        $classDef,
                        $classDef->getConstant($name),
                        $name,
                        // An explicit TraitName::CONST fetch evaluates in the
                        // Trait's own scope. Only a copied member's self::
                        // path above carries the consuming-class scope.
                        $classDef,
                    );
                }
                $current = ltrim($classDef->extends, '\\');
            }
        }

        if ($this->hasInterface($class)) {
            $constant = $this->findCompileTimeInterfaceConstant($class, $name);
            if ($constant !== null) {
                [$interface, $constantDef] = $constant;
                return $this->evaluateCompileTimeClassConstant($origin, $interface, $constantDef, $name, $interface);
            }
        }

        return $this->getClassConstValue(
            $origin,
            $class,
            $name,
            $scope->getNamespacedName(false),
        );
    }

    private function evaluateCompileTimeClassConstant(
        NodeAbstract $origin,
        ClassLikeDef $scope,
        ConstantDef $constant,
        string $name,
        ?ClassLikeDef $lateBoundScope = null,
    ): mixed {
        if (!$constant->valueExpr instanceof Node\Expr) {
            throw new \RuntimeException(
                "Class constant `{$scope->getNamespacedName(false)}::{$name}` has no compile-time expression",
            );
        }

        $key = strtolower($scope->getNamespacedName(false)) . '::' . $name;
        if (isset($this->classConstantEvaluationsInProgress[$key])) {
            $this->fatalError(
                $origin,
                "Cannot declare self-referencing constant `{$scope->getNamespacedName(false)}::{$name}`",
            );
        }

        $this->classConstantEvaluationsInProgress[$key] = true;
        try {
            return $this->evaluateCompileTimeExpression($constant->valueExpr, $scope, $lateBoundScope);
        } finally {
            unset($this->classConstantEvaluationsInProgress[$key]);
        }
    }

    /** @return array{ClassLikeDef, ConstantDef}|null */
    private function findCompileTimeInterfaceConstant(string $interface, string $name): ?array
    {
        $pending = [ltrim($interface, '\\')];
        $visited = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (!is_string($current) || isset($visited[strtolower($current)])) {
                continue;
            }
            $visited[strtolower($current)] = true;
            if (!$this->hasInterface($current)) {
                continue;
            }
            $interfaceDef = $this->getInterface($current);
            if ($interfaceDef->hasConstant($name)) {
                return [$interfaceDef, $interfaceDef->constants[$name]];
            }
            foreach ($interfaceDef->extendsList as $parent) {
                $pending[] = ltrim($parent, '\\');
            }
        }
        return null;
    }

    private function evaluateCompileTimeConstantFetch(Node\Expr\ConstFetch $expr, ClassLikeDef $scope): mixed
    {
        $name = ltrim($expr->name->toString(), '\\');
        $candidates = [];
        $resolved = $expr->name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            $candidates[] = ltrim($resolved->toString(), '\\');
        }
        $namespaced = $expr->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name) {
            $candidates[] = ltrim($namespaced->toString(), '\\');
        }
        if ($expr->name instanceof Node\Name\FullyQualified) {
            $candidates[] = $name;
        } elseif ($expr->name->isUnqualified()) {
            if (isset($this->useConstants[$name])) {
                $candidates[] = ltrim($this->useConstants[$name], '\\');
            } elseif ($scope->namespace !== '') {
                $candidates[] = $scope->namespace . '\\' . $name;
            }
            $candidates[] = $name;
        } else {
            $candidates[] = $scope->namespace !== '' ? $scope->namespace . '\\' . $name : $name;
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($this->hasConstant($candidate)) {
                return $this->evaluateProjectConstant($expr, $candidate, $scope);
            }
            if ($this->isInternalScalarConstant($candidate)) {
                return $this->internalConstants[$candidate];
            }
            if (defined($candidate)) {
                $value = constant($candidate);
                if (is_scalar($value) || $value === null) {
                    return $value;
                }
            }
        }

        throw new \RuntimeException("Constant `{$name}` is not known at compile time");
    }

    private function evaluateProjectConstant(
        NodeAbstract $origin,
        string $name,
        ClassLikeDef $enumScope,
    ): mixed {
        $key = $this->escapeConstVar($name);
        $constant = $this->constants[$key] ?? null;
        if ($constant === null || !$constant->valueExpr instanceof Node\Expr) {
            throw new \RuntimeException("Constant `{$name}` has no compile-time expression");
        }
        if (isset($this->globalConstantEvaluationsInProgress[$key])) {
            $this->fatalError($origin, "Cannot declare self-referencing constant `{$name}`");
        }

        $this->globalConstantEvaluationsInProgress[$key] = true;
        try {
            return $this->evaluateCompileTimeExpression($constant->valueExpr, $enumScope);
        } finally {
            unset($this->globalConstantEvaluationsInProgress[$key]);
        }
    }

    private function resolveCompileTimeClassName(
        Node\Name $name,
        ClassLikeDef $scope,
        ClassLikeDef $lateBoundScope,
    ): string
    {
        $raw = $name->toString();
        if (strcasecmp($raw, 'self') === 0) {
            return '\\' . $lateBoundScope->getNamespacedName(false);
        }
        if (strcasecmp($raw, 'parent') === 0) {
            if ($lateBoundScope->extends === '') {
                throw new \RuntimeException('Cannot use parent:: without a parent class');
            }
            return '\\' . ltrim($lateBoundScope->extends, '\\');
        }
        if (strcasecmp($raw, 'static') === 0) {
            throw new \RuntimeException('static:: is not compile-time evaluable');
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return '\\' . ltrim($resolved->toString(), '\\');
        }
        if ($name instanceof Node\Name\FullyQualified) {
            return '\\' . $name->toString();
        }
        return '\\' . $this->getNamespacedClassName($raw, $scope->namespace);
    }

    public function resolveCompileTimeEnumCaseBackingValue(NodeAbstract $origin, EnumCaseRef $case): int|string
    {
        if ($this->isInternalClass($case->enumClass)) {
            $value = constant(ltrim($case->enumClass, '\\') . '::' . $case->caseName);
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }
            throw new \RuntimeException("Enum case `{$case->enumClass}::{$case->caseName}` has no backing value");
        }

        $classDef = $this->getClassDef(ltrim($case->enumClass, '\\'));
        if ($classDef === null || !$classDef->enum || $classDef->enumBackingType === null) {
            throw new \RuntimeException("Enum case `{$case->enumClass}::{$case->caseName}` has no backing value");
        }
        return $this->evaluateAndStoreEnumCaseBackingValue($origin, $classDef, $case->caseName);
    }

    private function isEnumCaseBackingEvaluationInProgress(EnumCaseRef $case): bool
    {
        return isset($this->enumCaseBackingEvaluationsInProgress[
            strtolower(ltrim($case->enumClass, '\\')) . '::' . $case->caseName
        ]);
    }

    private function evaluateCompileTimeMagicConstant(
        Node\Scalar\MagicConst $expr,
        ClassLikeDef $scope,
        ClassLikeDef $lateBoundScope,
    ): int|string
    {
        return match (true) {
            $expr instanceof Node\Scalar\MagicConst\Line => $expr->getStartLine(),
            $expr instanceof Node\Scalar\MagicConst\File => $scope->sourceFile,
            $expr instanceof Node\Scalar\MagicConst\Dir => dirname($scope->sourceFile),
            $expr instanceof Node\Scalar\MagicConst\Class_ => $lateBoundScope->getNamespacedName(false),
            $expr instanceof Node\Scalar\MagicConst\Namespace_ => $scope->namespace,
            $expr instanceof Node\Scalar\MagicConst\Method,
            $expr instanceof Node\Scalar\MagicConst\Function_ => '',
            $expr instanceof Node\Scalar\MagicConst\Trait_ => $scope instanceof ClassDef && $scope->trait !== null
                ? $scope->getNamespacedName(false)
                : '',
            default => throw new \RuntimeException("Magic constant `{$expr->getType()}` is not compile-time evaluable"),
        };
    }

    public function getConstValue(string $name): mixed
    {
        if ($this->isInternalConstant($name)) {
            $value = $this->internalConstants[$name];
            if (is_int($value)) {
                $expr = $this->genIntegerLiteral($value);
            } elseif (is_float($value)) {
                return $value;
            } elseif (is_bool($value)) {
                return $value ? 1 : 0;
            } elseif (is_string($value)) {
                return $this->genCharPtr($value);
            } else {
                $this->error('Unsupported constant type: ' . gettype($value));
            }
            return $expr;
        }
        throw new \Exception('Constant ' . $name . ' not found');
    }
}
