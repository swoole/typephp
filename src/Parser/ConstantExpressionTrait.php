<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\MagicConst;
use TypePhp\Generator\Symbol;

trait ConstantExpressionTrait
{
    protected function parseConstFetch(Expr\ConstFetch $expr, bool $scalar = false): string
    {
        $pythonAttribute = $this->parsePythonModuleAttributeFetch($expr, $scalar);
        if ($pythonAttribute !== null) {
            return $pythonAttribute;
        }

        if ($expr->name->getType() != 'Name' and !($expr->name instanceof Node\Name\FullyQualified)) {
            $this->unsupportedSyntax($expr);
        }
        $name = $this->parseIdentifier($expr->name);
        $name = ltrim($name, '\\');
        if (strcasecmp($name, 'null') === 0) {
            return self::VALUE_NULL;
        }
        if (strcasecmp($name, 'true') === 0) {
            return 'true';
        }
        if (strcasecmp($name, 'false') === 0) {
            return 'false';
        }
        if ($this->isNameExpr($expr->name)) {
            if (str_contains($name, '::')) {
                $ns = explode('::', $name)[0];
                $fullName = $this->getNamespacedClassName($ns[0]);
                $ce = $this->getClassEntryPtr($fullName);
                return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }

            [$name, $runtimeNamespaceFallback] = $this->resolveConstantFetchName($expr, $name);
            if ($runtimeNamespaceFallback) {
                // PHP resolves an unqualified constant in a namespace at
                // runtime: first Namespace\NAME, then the global NAME. AOT
                // cannot select only the namespaced spelling because a
                // define() call may execute before this fetch.
                return Symbol::constant() . '('
                    . $this->getLiteralString($name)
                    . ', php::ConstantLookup::UnqualifiedInNamespace)';
            }

            if ($this->hasConstant($name)) {
                return $this->getConstant($name);
            }
            if ($name === 'PHP_EOL') {
                return '"' . $this->escapeString(PHP_EOL) . '"';
            }
            if ($this->isInternalScalarConstant($name)) {
                return $this->getInternalScalarConstantValue($name);
            }
            if ($this->isInternalConstant($name)) {
                return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
            }

            return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
        }
        return Symbol::constant() . '("' . $this->escapeString($name) . '")';
    }

    /**
     * Resolve a regular constant name and report whether PHP namespace
     * fallback must remain deferred until the source expression executes.
     *
     * @return array{string, bool}
     */
    private function resolveConstantFetchName(Expr\ConstFetch $expr, string $name): array
    {
        if (isset($this->useConstants[$name])) {
            return [$this->useConstants[$name], false];
        }
        if ($expr->name->isUnqualified()) {
            if ($this->namespace) {
                $namespacedName = $this->namespace . '\\' . $name;
                return [$namespacedName, !$this->hasConstant($namespacedName)];
            }

            // A class import with the same alias does not affect a bare
            // constant fetch. Only `use const` participates here.
            return [$name, false];
        }
        if ($expr->name instanceof Node\Name\FullyQualified) {
            // parseIdentifier() has already removed the leading slash.
            return [$name, false];
        }

        return [$this->getNamespacedClassName($name), false];
    }

    /**
     * Return true only when reading this constant can be moved to the hoisted
     * local declaration at function entry. Runtime define() constants and
     * namespace fallback must stay at their original source position.
     */
    protected function isHoistSafeConstFetch(Expr\ConstFetch $expr): bool
    {
        if ($this->resolvePythonModuleMember($expr->name) !== null
            || ($expr->name->getType() !== 'Name' && !$expr->name instanceof Node\Name\FullyQualified)
        ) {
            return false;
        }

        $name = ltrim($this->parseIdentifier($expr->name), '\\');
        if (in_array(strtolower($name), ['null', 'true', 'false'], true)) {
            return true;
        }
        if (str_contains($name, '::')) {
            return false;
        }

        [$name, $runtimeNamespaceFallback] = $this->resolveConstantFetchName($expr, $name);
        if ($runtimeNamespaceFallback) {
            return false;
        }

        return $this->hasConstant($name)
            || $name === 'PHP_EOL'
            || $this->isInternalScalarConstant($name);
    }

    protected function parseMagicConst(MagicConst $expr): string
    {
        $class = $this->classDef?->getNamespacedName(false)
            ?? (($this->namespace ? $this->namespace . '\\' : '') . $this->class);
        $function = $this->function;
        if ($function !== '' && $this->methodDef === null && $this->namespace !== '') {
            $function = $this->namespace . '\\' . $function;
        }
        switch ($expr->getType()) {
            case 'Scalar_MagicConst_Dir':
                return '"' . $this->escapeString($this->dir) . '"';
            case 'Scalar_MagicConst_File':
                return '"' . $this->escapeString($this->file) . '"';
            case 'Scalar_MagicConst_Line':
                return (string) $expr->getStartLine();
            case 'Scalar_MagicConst_Namespace':
                return '"' . $this->escapeString($this->namespace) . '"';
            case 'Scalar_MagicConst_Property':
                // Visitor normally folds this constant before property hooks
                // are lowered to generated methods. Keep the fallback aligned
                // with PHP, where it is an empty string outside a property.
                return '""';
            case 'Scalar_MagicConst_Function':
                if ($this->context->closureMagicName !== null) {
                    return '"' . $this->escapeString($this->context->closureMagicName) . '"';
                }
                return '"' . $this->escapeString($function) . '"';
            case 'Scalar_MagicConst_Class':
                if (!$this->classDef) {
                    $this->fatalError($expr, 'The magic constant `__CLASS__` is not allowed in global scope');
                }
                if ($this->classDef->trait) {
                    return $this->getCalledClassExpr();
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Trait':
                if ($this->methodDef?->traitOrigin !== '') {
                    return '"' . $this->escapeString($this->methodDef->traitOrigin) . '"';
                }
                if (!$this->classDef or !$this->classDef->trait) {
                    $this->fatalError($expr, 'The magic constant `__TRAIT__` is not allowed in global scope');
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Method':
                if ($this->context->closureMagicName !== null) {
                    return '"' . $this->escapeString($this->context->closureMagicName) . '"';
                }
                if ($this->methodDef === null) {
                    return '"' . $this->escapeString($function) . '"';
                }
                return '"' . $this->escapeString($class) . '::' . $this->escapeString($this->method) . '"';
            default:
                $this->unsupportedSyntax($expr);
                break;
        }
    }

    protected function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        $name = ltrim($name, '\\');

        // true and false are language constants and never participate in
        // namespace fallback. Keep type resolution consistent with
        // parseConstFetch(), which handles them before resolving the name.
        if (strcasecmp($name, 'true') === 0 || strcasecmp($name, 'false') === 0) {
            return Type::BOOL;
        }

        if (isset($this->useConstants[$name])) {
            $name = $this->useConstants[$name];
        } elseif ($expr->name->isUnqualified() && $this->namespace) {
            $namespacedName = $this->namespace . '\\' . $name;
            if ($this->hasConstant($namespacedName)) {
                return $this->getConstantType($namespacedName);
            }

            // PHP checks Namespace\NAME before falling back to global NAME.
            // A runtime define() can therefore shadow even an internal global
            // constant, so its type cannot be inferred statically here.
            return Type::VAR;
        } elseif (!($expr->name instanceof Node\Name\FullyQualified)) {
            $name = $this->getNamespacedClassName($name);
        }

        if ($this->hasConstant($name)) {
            return $this->getConstantType($name);
        }
        if ($this->isInternalConstant($name)) {
            return $this->getTypeFromZendType(gettype($this->internalConstants[$name]));
        }
        if ($name === 'NAN' or $name === 'INF') {
            return Type::FLOAT;
        }
        return Type::VAR;
    }

    protected function isInternalScalarConstant(string $name): bool
    {
        return $this->isInternalConstant($name) && is_scalar($this->internalConstants[$name]);
    }

    protected function getInternalScalarConstantValue(string $name): string
    {
        return $this->genInternalScalarConstantValue($this->internalConstants[$name]);
    }

    protected function genInternalScalarConstantValue(mixed $value): string
    {
        if (is_int($value)) {
            return $this->genIntegerLiteral($value);
        }
        if (is_float($value)) {
            return $this->genFloatLiteral($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $this->genCharPtr($value, true);
        }
        $this->error('Unsupported constant type: ' . gettype($value));
    }
}
