<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Context;

use PhpParser\Node\Expr\Variable;
use PhpParser\NodeAbstract;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Exception\Skip;

trait CompilationStateTrait
{
    protected function addLocalVar(string $name, string $type): void
    {
        $this->context->localVars[$name] = $type;
    }

    protected function registerStdType(string $key): int
    {
        if (isset($this->stdTypeMap[$key])) {
            return $this->stdTypeMap[$key];
        }
        $typeId = count($this->stdTypeMap) + 1;
        $this->stdTypeMap[$key] = $typeId;
        return $typeId;
    }

    protected function addTmpVar(string $type): string
    {
        $var = $this->genTmpVarName();
        $this->addLocalVar($var, $type);
        return $var;
    }

    protected function addStaticVar(Variable $var, string $name, string $type): string
    {
        if ($this->hasVar($name)) {
            $this->fatalError($var, 'Duplicate variable `$' . $var->name . '`');
        }
        $this->context->staticVars[$name] = $type;
        // A static variable is actually a reference to a global variable.
        $globalVar = $this->escapeStaticVar($name);
        $this->addGlobalVar($globalVar, $type);
        return $globalVar;
    }

    protected function hasArgument(string $name): bool
    {
        return isset($this->context->arguments[$name]);
    }

    protected function addArgument(string $name, string $type): void
    {
        $this->context->arguments[$name] = $type;
        $this->addLocalVar($name, $type);
    }

    protected function addLiteralString(string $value): int
    {
        $index                        = $this->literalStringIndex++;
        $this->literalStrings[$value] = $index;

        return $index;
    }

    protected function addGlobalVar(string $name, string $type): void
    {
        $this->globalVars[$name] = $type;
    }

    protected function promoteGlobalOrStaticToNativeObject(
        string $name,
        string $class,
        ?NodeAbstract $node = null,
    ): void
    {
        $class = ltrim($class, '\\');
        if ($this->hasStaticVar($name)) {
            $slot = $this->escapeStaticVar($name);
            $this->context->staticVars[$name] = $this->getNativeObjectPointerType($class);
        } elseif ($this->hasScopeGlobalVar($name)) {
            $slot = $name;
            $this->context->globalVars[$name] = $this->getNativeObjectPointerType($class);
        } else {
            return;
        }
        $class = $this->registerNativeGlobalObject($slot, $class, $node);
        $this->addNativeObject($name, $class);
    }

    /**
     * Fix the C++ pointer ABI of a project-wide global/static slot.
     *
     * The project discovery pass calls this before C++ emission; the ordinary
     * convert path calls it again to validate every concrete assignment.
     */
    protected function registerNativeGlobalObject(
        string $slot,
        string $class,
        ?NodeAbstract $node = null,
    ): string {
        $class = ltrim($class, '\\');
        if (isset($this->nativeGlobalObjects[$slot])) {
            $existing = $this->nativeGlobalObjects[$slot];
            if (!$this->isObjectClassStaticallyAssignableTo($class, $existing)) {
                $message = "Native global/static slot cannot change from `{$existing}` to `{$class}`";
                if ($node !== null) {
                    $this->fatalError($node, $message);
                }
                $this->error($message);
            }
            // The first assignment fixes the C++ slot type. A derived object
            // remains assignable, but must not narrow later uses of the slot.
            $class = $existing;
        }
        $this->globalVars[$slot] = $this->getNativeObjectPointerType($class);
        $this->nativeGlobalObjects[$slot] = $class;
        return $class;
    }

    protected function addScopeGlobalVar(string $name, string $type): void
    {
        $this->context->globalVars[$name] = $type;
    }

    protected function addObject(string $name, string $class): void
    {
        if ($this->isNativeObjectClass($class)) {
            $this->addNativeObject($name, $class);
            return;
        }
        // Interfaces have no concrete method body for native calls. Abstract classes may have concrete methods.
        if ($this->isInterface($class)) {
            $this->context->declaredObjects[$name] = $class;
        } elseif ($this->isNativeClass($class) or $this->isInternalClass($class)) {
            $this->context->objects[$name] = $class;
        }
    }

    protected function hasVar(string $name): bool
    {
        return $this->hasLocalVar($name) || $this->hasStaticVar($name) || $this->hasScopeGlobalVar($name) || $this->isSuperGlobal($name);
    }

    protected function hasLocalVar(string $name): bool
    {
        return isset($this->context->localVars[$name]);
    }

    protected function hasObjectPropVar(string $name): bool
    {
        return isset($this->context->objectProps[$name]);
    }

    protected function addFunction(string $name, FunctionDef $functionDef): void
    {
        $escaped = $this->escapeFunction($name);
        unset($this->traitMethodFunctions[$escaped], $this->traitMethodFunctions[$name]);
        $this->symbols->putFunction($escaped, $functionDef);
    }

    /**
     * @param string $name Must be a fully qualified class name including the namespace; it will be automatically escaped to a native name.
     */
    protected function hasFunction(string $name): bool
    {
        $escaped = $this->escapeFunction($name);
        return $this->symbols->hasFunction($escaped)
            || isset($this->traitMethodFunctions[$escaped])
            || isset($this->traitMethodFunctions[$name]);
    }

    protected function getFunction(string $name): FunctionDef
    {
        $escaped = $this->escapeFunction($name);
        if ($this->symbols->hasFunction($escaped)) {
            return $this->symbols->function($escaped);
        }
        if (isset($this->traitMethodFunctions[$escaped])) {
            return $this->traitMethodFunctions[$escaped];
        }
        if (isset($this->traitMethodFunctions[$name])) {
            return $this->traitMethodFunctions[$name];
        }
        return $this->symbols->function($escaped);
    }

    protected function addClass(string $name, ClassDef $classDef): void
    {
        $this->symbols->putClass($this->escapeClass($name), $classDef);
    }

    protected function getClass(string $name): ClassDef
    {
        return $this->symbols->class($this->escapeClass($name));
    }

    public function getClassDef(string $name): ?ClassDef
    {
        return $this->symbols->findClass($this->escapeClass($name));
    }

    public function isDeclaredEnumCase(string $class, string $case): bool
    {
        $classDef = $this->getClassDef(ltrim($class, '\\'));
        return $classDef !== null
            && $classDef->enum
            && array_key_exists($case, $classDef->enumCases);
    }

    public function getParentClass(string $class): string
    {
        return $this->symbols->parent(strtolower(ltrim($class, '\\')));
    }

    protected function hasClass(string $name): bool
    {
        return $this->symbols->hasClass($this->escapeClass($name));
    }

    protected function hasInterface(string $name): bool
    {
        return $this->symbols->hasInterface($this->escapeClass($name));
    }

    protected function getInterface(string $name): InterfaceDef
    {
        return $this->symbols->interface($this->escapeClass($name));
    }

    protected function checkFunction(string $name): void
    {
        // The function declaration was detected during the preprocessing stage but is not yet defined,
        // meaning it is in the current file but appears in the wrong order. Skip it and handle it later.
        if (isset($this->symbolDeclInFile[$name])
            and $this->symbolDeclInFile[$name] === $this->file
            and !$this->hasFunction($name)) {
            $this->redoAfterDeclare[$name] = true;
            throw new Skip();
        }
    }
}
