<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves static and dynamic class constant fetches.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Generator\Symbol;
use TypePhp\Resolver\Reflection;

trait ClassConstantFetchTrait
{
    /**
     * Return true when a class constant fetch can be evaluated at the hoisted
     * local declaration without changing PHP execution order. Only a literal
     * ::class name, constants owned by a statically known TypePHP class and
     * public scalar constants from an internal class/interface are eligible.
     * ZendVM lookup, late static binding and dynamic operands stay at their
     * original source position.
     */
    protected function isHoistSafeClassConstFetch(Expr\ClassConstFetch $expr): bool
    {
        if (!$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)) {
            return false;
        }
        if ($this->resolvePythonModule($expr->class) !== null) {
            return false;
        }

        $class = $this->parseIdentifier($expr->class);
        if ($class === 'static') {
            return false;
        }
        if ($class === 'self' || $class === 'this_') {
            if (!$this->classDef) {
                return false;
            }
            $class = '\\' . $this->getFullClassName();
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                return false;
            }
            $class = '\\' . $this->classDef->extends;
        }

        $class = $this->getNamespacedClassName($class);
        $const = $this->parseIdentifier($expr->name);
        if (strcasecmp($const, 'class') === 0) {
            return true;
        }
        if ($this->hasClass($class)) {
            if ($this->getClass($class)->enum) {
                return false;
            }
            $nativeConst = $this->findNativeClassConst($expr, $class, $const);
            return $nativeConst !== false
                && !$this->classConstantValueRequiresRuntimeCall($nativeConst);
        }

        return $this->getInternalScalarClassConstant($class, $const) !== null;
    }

    private function classConstantValueRequiresRuntimeCall(string $value): bool
    {
        // get_str() is a pure accessor for the module's immutable literal pool,
        // so moving it into a hoisted local initializer preserves semantics.
        $value = preg_replace('/\bget_str\(\d+\)/', '', $value);
        return preg_match('/\b[A-Za-z_][A-Za-z0-9_:]*\s*\(/', $value ?? '') === 1;
    }

    /** @return array{mixed}|null */
    private function getInternalScalarClassConstant(string $class, string $const): ?array
    {
        if (!$this->isInternalClass($class) && !$this->isInternalInterface($class)) {
            return null;
        }

        $reflection = Reflection::getClass($class);
        $constant = $reflection?->getReflectionConstant($const);
        if ($constant === false || $constant === null || !$constant->isPublic()) {
            return null;
        }

        $value = $constant->getValue();
        return is_scalar($value) ? [$value] : null;
    }

    protected function parseClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        $expr->setAttribute(self::ATTR_LOWERED_TYPE, Type::VAR);
        if (!$this->isNameExpr($expr->class)) {
            $this->assertNotNativeObjectDynamicClassTarget($expr->class, $expr);
        }
        $this->rejectPythonModuleClassConstantFetch($expr);

        if (!$this->isIdExpr($expr->name)) {
            return $this->parseDynamicClassConstNameFetch($expr);
        }

        if (!$this->isNameExpr($expr->class)) {
            return $this->parseDynamicClassConstFetch($expr);
        }

        $class = $this->parseIdentifier($expr->class);
        $self = false;
        if ($class === 'self' or $class === 'this_') {
            $self = true;
            // Trait methods are compiled only after their AST is composed into
            // the consuming class. During trait preprocessing, however, class
            // constant initializers still belong to the trait itself. Resolve
            // `self` lexically in both cases; rewriting it to `static` would
            // incorrectly require a method scope for expressions such as
            // `const B = [...self::A]`.
            $class = '\\' . $this->getFullClassName();
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot use "parent" outside a class or class does not extend any class');
            }
            // extends is already fully resolved. Keep the leading slash so the
            // current namespace is not applied again below.
            $class = '\\' . $this->classDef->extends;
            $self = true;
        }

        $const = $this->escapeString($this->parseIdentifier($expr->name));
        if ($class === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            if ($const === 'class') {
                $expr->setAttribute(self::ATTR_LOWERED_TYPE, Type::STR);
                return $this->getCalledClassExpr();
            } else {
                return Symbol::constant() . '(' . $this->getCalledCeExpr() . ', ' . $this->getLiteralString($const) . ')';
            }
        }

        if ($self or $this->isNameExpr($expr->class)) {
            $class = $this->getNamespacedClassName($class);
        }
        if ($const === 'class') {
            if ($self or $this->isNameExpr($expr->class)) {
                $expr->setAttribute(self::ATTR_LOWERED_TYPE, Type::STR);
                return $this->getLiteralString($class);
            }
        }
        if (($self or $this->isNameExpr($expr->class)) and $this->isIdExpr($expr->name)) {
            if ($this->hasClass($class)) {
                $classDef = $this->getClass($class);
                if ($classDef->enum) {
                    $expr->setAttribute(self::ATTR_LOWERED_TYPE, Type::OBJECT);
                    $ce = $this->getClassEntryPtr($class);
                    return 'php::getEnumCase(' . $ce . ', ' . $this->getLiteralString($const) . ')';
                }
                $nativeConst = $this->findNativeClassConst($expr, $class, $const);
                if ($nativeConst) {
                    $constant = $expr->getAttribute('nativeConst');
                    $expr->setAttribute(self::ATTR_LOWERED_TYPE, $constant?->valueExpr !== null
                        ? $this->getLoweredExpressionType($constant->valueExpr)
                        : null);
                    return $nativeConst;
                }
            }
            $internalConst = $this->getInternalScalarClassConstant($class, $const);
            if ($internalConst !== null) {
                $value = $internalConst[0];
                $expr->setAttribute(self::ATTR_LOWERED_TYPE, is_string($value)
                    ? 'const char *'
                    : $this->getTypeFromZendType(gettype($value)));
                return $this->genInternalScalarConstantValue($internalConst[0]);
            }
            $ce = $this->getClassEntryPtr($class);
            return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($const) . ')';
        }
        $name = $class . '::' . $const;
        $name = $this->getLiteralString($name);
        return Symbol::constant() . '(' . $name . ')';
    }

    protected function parseDynamicClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        $const = $this->escapeString($this->parseIdentifier($expr->name));

        // PhpParser represents `$this::CONST` as a dynamic class target even
        // though the receiver class is the class currently being compiled.
        // TypePHP class constants are compile-time data, so do not route this
        // common path through get_class(), string concatenation and ZendVM's
        // global constant table.
        if ($this->isVarExpr($expr->class)
            && $this->parseVariable($expr->class) === 'this_'
            && $this->classDef
            && $this->hasClass($this->getFullClassName())
        ) {
            $nativeConst = $this->findNativeClassConst(
                $expr,
                $this->getFullClassName(),
                $const,
            );
            if ($nativeConst !== false) {
                return $nativeConst;
            }
        }

        $target = $this->materializeDynamicClassConstTarget($expr->class);

        if ($const === 'class') {
            return 'php::fn::get_class(' . $target . ')';
        }

        $scope = $this->methodDef && $this->classDef
            ? $this->getClassEntryPtr($this->getFullClassName())
            : 'nullptr';
        return 'php::classConstant('
            . $target . ', '
            . $this->getLiteralString($const) . ', '
            . $scope
            . ')';
    }

    protected function parseDynamicClassConstNameFetch(Expr\ClassConstFetch $expr): string
    {
        $scope = $this->methodDef && $this->classDef
            ? $this->getClassEntryPtr($this->getFullClassName())
            : 'nullptr';

        if (!$this->isNameExpr($expr->class)) {
            // PHP evaluates the class target before the dynamic constant name.
            $target = $this->materializeDynamicClassConstOperand($expr->class, 'class constant target');
            $name = $this->materializeDynamicClassConstOperand($expr->name, 'class constant name');
            return 'php::classConstant(' . $target . ', ' . $name . ', ' . $scope . ')';
        }

        $class = $this->parseIdentifier($expr->class);
        if ($class === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            $ce = $this->getCalledCeExpr();
        } elseif ($class === 'self' or $class === 'this_') {
            $ce = $this->getClassEntryPtr($this->getFullClassName());
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot use "parent" outside a class or class does not extend any class');
            }
            $ce = $this->getClassEntryPtr($this->classDef->extends);
        } else {
            $ce = $this->getClassEntryPtr($this->getNamespacedClassName($class));
        }

        $name = $this->materializeDynamicClassConstOperand($expr->name, 'class constant name');
        return 'php::classConstant(' . $ce . ', ' . $name . ', ' . $scope . ')';
    }

    protected function materializeDynamicClassConstTarget(NodeAbstract $expr): string
    {
        return $this->materializeDynamicClassConstOperand($expr, 'class constant target');
    }

    protected function materializeDynamicClassConstOperand(NodeAbstract $expr, string $description): string
    {
        $this->assertExprCanBeUsedAsValue($expr, $description);
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $tmpVar = $this->addTmpVar(Type::VAR);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        return $tmpVar;
    }

}
