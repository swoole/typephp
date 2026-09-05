<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers instance property reads, writes, hooks, references, and unset operations.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Entity\PropertyDef;
use TypePhp\Transform\PropertyHookLowering;
use TypePhp\Resolver\InstancePropertyFetchTarget;
use TypePhp\Resolver\PropertyAssignTypeInfo;
use TypePhp\Resolver\PropertyWriteTarget;
use TypePhp\Resolver\StaticPropertyFetchResolution;
use TypePhp\Resolver\StaticPropertyFetchTarget;
use TypePhp\Generator\Symbol;

trait PropertyAccessTrait
{
    /**
     * Resolve a direct TypePHP magic-property body only for an exact, simple
     * compiled class. The runtime helper still rechecks handlers, lazy state,
     * declared/dynamic properties, and Zend's recursion guard before calling
     * this function; otherwise it falls back to the standard handler.
     *
     * @return array{function: string, classEntry: string}|null
     */
    private function resolveDirectMagicPropertyAccess(
        Expr\PropertyFetch $expr,
        string $object,
        string $magicMethod,
    ): ?array {
        if (!$this->isIdExpr($expr->name)
            || !$this->isVarExpr($expr->var)
            || $this->getVarType($object) !== Type::OBJECT
        ) {
            return null;
        }

        $class = $this->detectClassOfExpr($expr->var);
        if ($class === '' || !$this->hasClass($class)) {
            return null;
        }

        $exactClass = null;
        if ($object === 'this_' && $this->isCurrentClassFinal()) {
            $exactClass = $this->getFullClassName();
        } elseif (isset($this->context->exactObjects[$object])) {
            $exactClass = $this->context->exactObjects[$object];
        } elseif ($this->isFinalClass($class)) {
            $exactClass = $class;
        }
        if ($exactClass === null
            || strcasecmp(ltrim($exactClass, '\\'), ltrim($class, '\\')) !== 0
            || !$this->hasClass($exactClass)
        ) {
            return null;
        }

        $classDef = $this->getClass($exactClass);
        $property = $this->parseIdentifier($expr->name);
        // Keep the first implementation deliberately narrow. An internal or
        // compiled parent may install custom object handlers; inherited magic
        // methods also need a different generated receiver ABI.
        if ($classDef->extends !== ''
            || $classDef->nativeObject
            || $classDef->trait
            || $classDef->hasProperty($property)
            || !$classDef->hasMethod($magicMethod)
        ) {
            return null;
        }

        $method = $classDef->getMethod($magicMethod);
        if ($method->functionDef === null
            || ($magicMethod === '__get' && $method->functionDef->returnsByRef)
        ) {
            return null;
        }
        $nativeFunction = $this->getNativeName(
            $magicMethod,
            $classDef->namespace,
            $classDef->name,
        );
        if (!$this->hasFunction($nativeFunction)) {
            return null;
        }

        return [
            'function' => self::PREFIX . $nativeFunction,
            'classEntry' => $this->getLocalClassEntryPtr($exactClass),
        ];
    }

    protected function usesTraitPropertyScope(string $object): bool
    {
        return $this->classDef?->trait && $object === 'this_';
    }

    protected function emitDynamicPropertyRead(string $object, string $property, ?string $cache = null): string
    {
        if ($this->usesTraitPropertyScope($object)) {
            return 'typephp_read_property_scoped('
                . $object . ', ' . $property . ', php::FakeScopeGuard::current(), php::AttrMode::Get)';
        }
        if ($cache !== null) {
            return 'typephp_read_property_cached('
                . $object . ', ' . $property . ', php::AttrMode::Get, ' . $cache . ')';
        }
        return "{$object}.getProperty({$property})";
    }

    protected function emitDynamicPropertyWrite(
        string $object,
        string $property,
        string $value,
        ?string $cache = null,
    ): string
    {
        $scope = $this->usesTraitPropertyScope($object)
            ? 'php::FakeScopeGuard::current()'
            : ($this->class ? $this->getLocalClassEntryPtr($this->getFullClassName()) : 'nullptr');
        if ($cache !== null && !$this->usesTraitPropertyScope($object)) {
            return 'typephp_write_property_cached('
                . $object . ', ' . $property . ', ' . $value . ', ' . $scope . ', ' . $cache . ')';
        }
        return 'typephp_write_property_scoped('
            . $object . ', ' . $property . ', ' . $value . ', ' . $scope . ')';
    }

    protected function emitDynamicPropertyTargetRead(PropertyWriteTarget $target, ?string $cache = null): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyRead(
            $target->getDynamicObjectExpr(),
            $target->getDynamicPropertyExpr(),
            $cache,
        );
    }

    protected function emitDynamicPropertyTargetWrite(
        PropertyWriteTarget $target,
        string $value,
        ?string $cache = null,
    ): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyWrite(
            $target->getDynamicObjectExpr(),
            $target->getDynamicPropertyExpr(),
            $value,
            $cache,
        );
    }

    protected function emitDynamicPropertyTargetUnset(PropertyWriteTarget $target): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.unsetProperty(' . $target->getDynamicPropertyExpr() . ')';
    }

    protected function emitDynamicPropertyTargetRef(PropertyWriteTarget $target): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $target->getDynamicObjectExpr() . '.attrRef(' . $target->getDynamicPropertyExpr() . ')';
    }

    protected function emitDynamicPropertyTargetAppendArray(
        PropertyWriteTarget $target,
        string $value,
        ?string $cache = null,
    ): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyAppendArray(
            $target->getDynamicObjectExpr(),
            $target->getDynamicPropertyExpr(),
            $value,
            $cache,
        );
    }

    protected function emitDynamicPropertyTargetUpdateArray(
        PropertyWriteTarget $target,
        string $dim,
        string $value,
        ?string $cache = null,
    ): string
    {
        $this->assertDynamicPropertyTarget($target);

        return $this->emitDynamicPropertyUpdateArray(
            $target->getDynamicObjectExpr(),
            $target->getDynamicPropertyExpr(),
            $dim,
            $value,
            $cache,
        );
    }

    protected function canEmitDynamicPropertyTarget(?PropertyWriteTarget $target): bool
    {
        return $target !== null && $target->isDynamicObjectProperty();
    }

    protected function emitDynamicPropertyFetchRead(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        $cache = $this->isIdExpr($expr->name) && !$this->isNativePropertyAccess($expr)
            ? $this->getPropertyAccessCache()
            : null;
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetRead($target, $cache);
        }

        return $this->emitDynamicPropertyRead(
            $this->parseIdentifier($expr->var),
            $this->propertyNameToStr($expr->name, literal: true),
            $cache,
        );
    }

    protected function emitDynamicPropertyFetchWrite(Expr\PropertyFetch $expr, string $value, ?PropertyWriteTarget $target = null): string
    {
        $cache = $this->isIdExpr($expr->name) && !$this->isNativePropertyAccess($expr)
            ? $this->getPropertyAccessCache()
            : null;
        $object = $this->canEmitDynamicPropertyTarget($target)
            ? $target->getDynamicObjectExpr()
            : $this->parseIdentifier($expr->var);
        $direct = $cache !== null && $this->hasVar($value) && $this->getVarType($value) === Type::VAR
            ? $this->resolveDirectMagicPropertyAccess($expr, $object, '__set')
            : null;
        if ($direct !== null) {
            $property = $this->propertyNameToStr($expr->name, literal: true);
            $scope = $this->class
                ? $this->getLocalClassEntryPtr($this->getFullClassName())
                : 'nullptr';
            return 'typephp_write_magic_property_direct('
                . $object . ', ' . $property . ', ' . $value . ', ' . $scope . ', '
                . $direct['classEntry'] . ', ' . $cache . ', [&]() {'
                . $direct['function'] . '(' . $object . ', ' . $property . ', ' . $value . '); })';
        }
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetWrite($target, $value, $cache);
        }

        return $this->emitDynamicPropertyWrite(
            $object,
            $this->propertyNameToStr($expr->name, literal: true),
            $value,
            $cache,
        );
    }

    protected function getDynamicPropertyFetchObjectExpr(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $target->getDynamicObjectExpr();
        }

        return $this->parseIdentifier($expr->var);
    }

    protected function emitDynamicPropertyFetchUnset(Expr\PropertyFetch $expr, ?PropertyWriteTarget $target = null): string
    {
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetUnset($target);
        }

        return $this->parseIdentifier($expr->var) . '.unsetProperty(' . $this->propertyNameToStr($expr->name, literal: true) . ')';
    }

    protected function emitDynamicPropertyFetchAppendArray(Expr\PropertyFetch $expr, string $value, ?PropertyWriteTarget $target = null): string
    {
        if ($this->isNativePropertyAccess($expr)) {
            return $this->parseWritableIdentifier($expr) . ".newItem() = {$value}";
        }
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetAppendArray(
                $target,
                $value,
                $this->isIdExpr($expr->name) ? $this->getPropertyAccessCache() : null,
            );
        }

        return $this->emitDynamicPropertyAppendArray(
            $this->parseIdentifier($expr->var),
            $this->propertyNameToStr($expr->name, literal: true),
            $value,
            $this->isIdExpr($expr->name) ? $this->getPropertyAccessCache() : null,
        );
    }

    protected function emitDynamicPropertyFetchUpdateArray(Expr\PropertyFetch $expr, string $dim, string $value, ?PropertyWriteTarget $target = null): string
    {
        if ($this->isNativePropertyAccess($expr)) {
            return $this->parseWritableIdentifier($expr) . ".item({$dim}, true) = {$value}";
        }
        if ($this->canEmitDynamicPropertyTarget($target)) {
            return $this->emitDynamicPropertyTargetUpdateArray(
                $target,
                $dim,
                $value,
                $this->isIdExpr($expr->name) ? $this->getPropertyAccessCache() : null,
            );
        }

        return $this->emitDynamicPropertyUpdateArray(
            $this->parseIdentifier($expr->var),
            $this->propertyNameToStr($expr->name, literal: true),
            $dim,
            $value,
            $this->isIdExpr($expr->name) ? $this->getPropertyAccessCache() : null,
        );
    }

    protected function emitDynamicPropertyAppendArray(
        string $object,
        string $property,
        string $value,
        ?string $cache = null,
    ): string
    {
        if ($this->usesTraitPropertyScope($object)) {
            return 'typephp_read_property_scoped('
                . $object . ', ' . $property . ', php::FakeScopeGuard::current(), php::AttrMode::Update)'
                . ".newItem() = {$value}";
        }
        if ($cache !== null) {
            return 'typephp_read_property_cached('
                . $object . ', ' . $property . ', php::AttrMode::Update, ' . $cache . ')'
                . ".newItem() = {$value}";
        }
        return "{$object}.attr({$property}, php::AttrMode::Update).newItem() = {$value}";
    }

    protected function emitDynamicPropertyUpdateArray(
        string $object,
        string $property,
        string $dim,
        string $value,
        ?string $cache = null,
    ): string
    {
        if ($this->usesTraitPropertyScope($object)) {
            return 'typephp_read_property_scoped('
                . $object . ', ' . $property . ', php::FakeScopeGuard::current(), php::AttrMode::Update)'
                . ".item({$dim}, true) = {$value}";
        }
        if ($cache !== null) {
            return 'typephp_read_property_cached('
                . $object . ', ' . $property . ', php::AttrMode::Update, ' . $cache . ')'
                . ".item({$dim}, true) = {$value}";
        }
        return "{$object}.attr({$property}, php::AttrMode::Update).item({$dim}, true) = {$value}";
    }

    protected function assertDynamicPropertyTarget(PropertyWriteTarget $target): void
    {
        if (!$target->isDynamicObjectProperty()) {
            $this->fatalError($target->node, 'Internal error: property write target is not a dynamic object property');
        }
    }

    protected function emitDynamicPropertyFetchRef(Expr\PropertyFetch $expr, NodeAbstract $errorNode): string
    {
        $receiverClass = $this->detectClassOfExpr($expr->var);
        if ($this->isNativeObjectClass($receiverClass)) {
            $this->assertNativeObjectReferenceForbidden($expr, $errorNode);
            return $this->parsePropertyFetch($expr) . '.toReference()';
        }

        // Reference diagnostics are more specific than the generic readonly
        // mutation error emitted by preparePropertyWriteTarget().
        $target = $this->preparePropertyWriteTarget($expr, true);
        if ($this->canEmitDynamicPropertyTarget($target)) {
            $objectExpr = $target->getDynamicObjectExpr();
            if (!$this->hasVar($objectExpr)) {
                $this->fatalError($errorNode, 'Undefined variable `$' . $objectExpr . '`');
            }
            return $this->emitDynamicPropertyTargetRef($target);
        }

        if (!$this->isVarExpr($expr->var)) {
            return $this->parseExpr($expr->var) . '.attrRef(' . $this->propertyNameToStr($expr->name) . ')';
        }

        $objectExpr = $this->parseIdentifier($expr->var);
        if (!$this->hasVar($objectExpr)) {
            $this->fatalError($errorNode, 'Undefined variable `$' . $objectExpr . '`');
        }

        return $objectExpr . '.attrRef(' . $this->propertyNameToStr($expr->name) . ')';
    }

    protected function emitStaticPropertyFetchRef(Expr\StaticPropertyFetch $expr, NodeAbstract $errorNode): string
    {
        $resolution = $this->resolveNativeStaticPropertyFetch($expr);
        if ($this->isIdExpr($expr->name)) {
            $this->assertPropertySetVisibility($expr);
        }

        if ($resolution !== null) {
            $property = $this->propertyNameToStr($expr->name, literal: true);
            // Reference acquisition must run getStaticPropertyRef(): it
            // converts the live slot to IS_REFERENCE and attaches a typed
            // property's zend_property_info as a reference type source. The
            // ordinary value-slot cache deliberately does neither operation.
            if ($resolution->class !== null) {
                $classPtr = $this->getClassEntryPtr($resolution->class);
                return Symbol::getStaticPropertyRef() . '(' . $classPtr . ', ' . $property . ')';
            }
            if ($resolution->expression !== null) {
                // Dynamic target, e.g. `self` resolved through the called class inside a trait.
                return Symbol::getStaticPropertyRef() . '(' . $this->getCalledCeExpr() . ', ' . $property . ')';
            }
        }

        // Fully dynamic path: `static` keyword, dynamic class name, or dynamic property name.
        return $this->parseDynamicStaticPropertyFetch($expr, true);
    }


    protected function resolveNativeStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?StaticPropertyFetchResolution
    {
        $target = $this->resolveStaticPropertyFetchTarget($expr);
        if ($target === null) {
            return null;
        }
        if ($target->isDynamic()) {
            return new StaticPropertyFetchResolution(null, $target->dynamicExpression, false);
        }
        $class = $target->class;
        if ($class === null) {
            return null;
        }
        $result = $this->resolveNativeStaticProperty($expr, $target->property, $class);
        if ($result !== null) {
            $expression = $this->applyNativePropertyAccessResult($expr, $result);
            return new StaticPropertyFetchResolution($class, $expression, true);
        }
        return null;
    }

    private function resolveStaticPropertyFetchTarget(Expr\StaticPropertyFetch $expr): ?StaticPropertyFetchTarget
    {
        if (!$this->isNameExpr($expr->class) or !$this->isIdExpr($expr->name)) {
            return null;
        }

        $class = $this->parseIdentifier($expr->class);
        $propertyName = $this->parseIdentifier($expr->name);
        if ($class === 'static') {
            return null;
        }
        if ($class === 'self') {
            if ($this->classDef->trait) {
                $expression = Symbol::getStaticProperty() . '(' . $this->getCalledCeExpr() . ', ' . $this->getLiteralString($propertyName) . ')';
                return new StaticPropertyFetchTarget($propertyName, null, $expression);
            }
            return new StaticPropertyFetchTarget($propertyName, $this->getFullClassName(), null);
        }
        if ($class === 'parent') {
            if (!$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot access parent:: when current class does not extend any class');
            }
            return new StaticPropertyFetchTarget($propertyName, $this->classDef->extends, null);
        }

        return new StaticPropertyFetchTarget($propertyName, $this->getNamespacedClassName($class), null);
    }


    protected function parseNativeStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?string
    {
        if ($this->isNameExpr($expr->class)
            && $this->parseIdentifier($expr->class) === 'static'
            && $this->isIdExpr($expr->name)
        ) {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            $propertyName = $this->parseIdentifier($expr->name);
            $slot = $this->registerStaticPropertySlot(
                'static::$' . $propertyName,
                'typephp_get_static_property_slot(' . $this->getCalledCeExpr() . ', '
                    . $this->getLiteralString($propertyName) . ')',
            );
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
            return $slot;
        }

        $resolution = $this->resolveNativeStaticPropertyFetch($expr);
        if ($resolution !== null) {
            $nativeProp = $resolution->expression;
            $def = $this->getNativePropertyDef($expr);
            $class = $resolution->class;
            if ($this->nativeTypes && $def && $class !== null) {
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
                return $this->emitNativeStaticPropertyTypedFetch($expr, $class, $def, $nativeProp);
            }

            if ($resolution->nativeProperty && $class !== null) {
                $classPtr = $this->getClassEntryPtr($class);
                $property = $this->parseIdentifier($expr->name);
                $slot = $this->registerStaticPropertySlot(
                    $class . '::$' . $property,
                    'typephp_get_static_property_slot(' . $classPtr . ', ' . $nativeProp . ')',
                );
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
                return $slot;
            } elseif ($resolution->expression !== null && $this->isIdExpr($expr->name)) {
                // A trait's self::$property binds to the consuming class. The
                // called CE is stable for this function invocation, just like
                // static::$property, but not across separate invocations.
                $propertyName = $this->parseIdentifier($expr->name);
                $slot = $this->registerStaticPropertySlot(
                    'trait-self::$' . $propertyName,
                    'typephp_get_static_property_slot(' . $this->getCalledCeExpr() . ', '
                        . $this->getLiteralString($propertyName) . ')',
                );
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
                return $slot;
            } else {
                $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
                return $nativeProp;
            }
        }
        return null;
    }

    private function emitNativeStaticPropertyTypedFetch(
        Expr\StaticPropertyFetch $expr,
        string $class,
        PropertyDef $def,
        string $nativeProp,
    ): string {
        $info = $this->getHoistedObjectPropInfo($def->type);
        $propName = $this->parseIdentifier($expr->name);
        $classPtr = $this->getClassEntryPtr($class);
        $slot = $this->registerStaticPropertySlot(
            $class . '::$' . $propName,
            'typephp_get_static_property_slot(' . $classPtr . ', ' . $nativeProp . ')',
        );

        if ($info['kind'] === 'zval') {
            $helper = $def->type === Type::FLOAT ? 'typephp_static_float_ref' : 'typephp_static_int_ref';
            return $helper . '(' . $slot . '.direct_ptr())';
        }

        return $slot;
    }

    /**
     * Cache only the raw Zend static-property slot in the current C++ function
     * invocation. Its value and reference/indirect state remain live and are
     * deliberately re-read on every use.
     */
    private function registerStaticPropertySlot(string $key, string $resolver): string
    {
        if (isset($this->context->staticPropRefs[$key])) {
            return $this->context->staticPropRefs[$key]['accessorName'] . '()';
        }

        $name = '_typephp_static_property_slot_' . count($this->context->staticPropRefs);
        $accessorName = '_typephp_static_property_' . count($this->context->staticPropRefs);
        $this->context->staticPropRefs[$key] = [
            'name' => $name,
            'accessorName' => $accessorName,
            'resolver' => $resolver,
        ];
        return $accessorName . '()';
    }

    protected function parseStaticPropertyFetch(Expr\StaticPropertyFetch $expr): string
    {
        $pythonProperty = $this->parsePythonModuleStaticPropertyFetch($expr);
        if ($pythonProperty !== null) {
            return $pythonProperty;
        }

        $native = $this->parseNativeStaticPropertyFetch($expr);
        if ($native !== null) {
            return $native;
        }

        return $this->parseDynamicStaticPropertyFetch($expr);
    }

    /**
     * Resolve a static-property target through the runtime path.
     *
     * PHP permits the class operand to be either a class-name string or an
     * object. Materialising both operands preserves PHP's left-to-right
     * evaluation order and avoids ambiguous C++ overload resolution for Var.
     */
    private function parseDynamicStaticPropertyFetch(Expr\StaticPropertyFetch $expr, bool $reference = false): string
    {
        $classValue = $this->getDynamicStaticClassValue($expr->class);
        $propertyValue = $this->propertyNameToStr($expr->name, literal: true);

        $classVar = $this->addTmpVar(Type::VAR);
        $propertyVar = $this->addTmpVar(Type::VAR);
        $this->context->beforeStmtLines[] = $classVar . ' = ' . $classValue . ';';
        $this->context->beforeStmtLines[] = $propertyVar . ' = ' . $propertyValue . ';';

        $className = '(' . $classVar . '.isObject() ? php::fn::get_class(' . $classVar . ') : php::toString(' . $classVar . '))';
        $helper = $reference ? Symbol::getStaticPropertyRef() : Symbol::getStaticProperty();
        return $helper . '(' . $className . ', php::toString(' . $propertyVar . '))';
    }

    private function getDynamicStaticClassValue(NodeAbstract $class): string
    {
        if (!$this->isNameExpr($class)) {
            return $this->parseExprAsValue($class);
        }

        $name = $this->parseIdentifier($class);
        if ($name === 'self') {
            return $this->getLiteralString($this->getFullClassName());
        }
        if ($name === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($class, 'Cannot access parent:: when current class does not extend any class');
            }
            return $this->getLiteralString($this->classDef->extends);
        }
        if ($name === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $class,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($class, "The 'static' keyword can only be used as the class name in class methods");
            }
            return $this->getCalledClassExpr();
        }

        return $this->getLiteralString($this->getNamespacedClassName($name));
    }


    protected function getFixedObjectPropDefaultValue(PropertyDef $def): ?string
    {
        return (new PropertyAssignTypeInfo())->getFixedDefaultValue($def);
    }

    protected function isFixedObjectProp(PropertyDef $def): bool
    {
        return (new PropertyAssignTypeInfo())->isFixed($def);
    }

    protected function preparePropertyWriteTarget(NodeAbstract $left, bool $allowReadonlyAssignment = false): ?PropertyWriteTarget
    {
        if ($left instanceof Expr\PropertyFetch) {
            $objectExpr = null;
            $propertyExpr = null;
            if (!$this->isNativePropertyAccess($left) && $this->isVarExpr($left->var)) {
                $objectExpr = $this->parseIdentifier($left->var);
                $propertyExpr = $this->propertyNameToStr($left->name, literal: true);
            }
            if ($this->isIdExpr($left->name)) {
                $this->getPropertyIdentifier($left, $left->var, $left->name);
                $this->assertPropertySetVisibility($left);
                if (!$allowReadonlyAssignment) {
                    $this->assertReadonlyPropertyDirectAssignmentOnly($left);
                }
            }
            return new PropertyWriteTarget($left, 'object property', $objectExpr, $propertyExpr);
        }

        if ($left instanceof Expr\StaticPropertyFetch) {
            if ($this->isIdExpr($left->name)) {
                $this->resolveNativeStaticPropertyFetch($left);
                $this->assertPropertySetVisibility($left);
            }
            return new PropertyWriteTarget($left, 'static property');
        }

        return null;
    }

    private function assertReadonlyPropertyDirectAssignmentOnly(Expr\PropertyFetch $property): void
    {
        $access = $this->getNativePropertyAccess($property);
        if ($access === null || !$access->getPropertyDef()->isReadonly()) {
            return;
        }

        $declaringClass = $access->resolution->declaringClass;
        $propertyName = $this->parseIdentifier($property->name);
        $display = $declaringClass . '::$' . $propertyName;

        $this->fatalError($property, "Readonly property `{$display}` only supports direct assignment");
    }

    protected function assertReadonlyPropertyReferenceForbidden(
        NodeAbstract $expr,
        NodeAbstract $errorNode,
        bool $assignmentTarget,
    ): void {
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }
        if (!$expr instanceof Expr\PropertyFetch || !$this->isIdExpr($expr->name)) {
            return;
        }

        $this->getPropertyIdentifier($expr, $expr->var, $expr->name);
        $access = $this->getNativePropertyAccess($expr);
        if ($access === null || !$access->getPropertyDef()->isReadonly()) {
            return;
        }

        $display = $access->resolution->declaringClass . '::$' . $this->parseIdentifier($expr->name);
        $message = $assignmentTarget
            ? "Cannot assign readonly property `{$display}` by reference"
            : "Cannot take reference to readonly property `{$display}`";
        $this->fatalError($errorNode, $message);
    }

    private function assertPropertySetVisibility(NodeAbstract $property): void
    {
        if ($this->isPropertyHookBackingAccess($property)) {
            return;
        }
        $access = $this->getNativePropertyAccess($property);
        if ($access === null) {
            return;
        }
        $def = $access->getPropertyDef();
        $declaringClass = $access->resolution->declaringClass;
        $scope = $this->class ? $this->getFullClassName() : '';
        $propertyName = $this->parseIdentifier($property->name);
        if ($def->isPrivateSet() && !$this->isSameClassName($scope, $declaringClass)) {
            $this->fatalError($property, "Cannot modify private(set) property `{$declaringClass}::\${$propertyName}`");
        }
        if ($def->isProtectedSet() && !$this->canAccessProtectedProperty($scope, $declaringClass)) {
            $this->fatalError($property, "Cannot modify protected(set) property `{$declaringClass}::\${$propertyName}`");
        }
    }

    protected function assertCanAssignPropertyWrite(PropertyWriteTarget $target, Expr $right): void
    {
        $this->assertCanAssignObjectProperty($target->node, $right, $target->label);
    }

    protected function wrapPropertyWriteTypeCheck(PropertyWriteTarget $target, Expr $right, string $rightExpr): string
    {
        return $this->wrapObjectPropertyAssignTypeCheck($target->node, $right, $rightExpr);
    }

    private function assertCanAssignObjectProperty(NodeAbstract $left, Expr $right, string $label): void
    {
        $def = $this->getNativePropertyDef($left);
        if (!$def) {
            return;
        }

        $propName = $this->parseIdentifier($left->name);

        if ($this->isNull($right)) {
            // Untyped properties retain normal PHP mixed semantics: assigning
            // null is valid. Only an explicitly typed non-nullable property
            // can be rejected at compile time.
            if ($def->type !== Type::VAR && !$def->nullable) {
                $typeStr = $this->getObjectPropertyTypeCheckTypeString($def);
                $this->fatalError(
                    $left,
                    "Cannot assign null to {$label} `{$propName}` of type `{$typeStr}`"
                );
            }
            return;
        }

        $rightType = $this->detectTypeOfExpr($right);
        if ($this->isFixedObjectProp($def) && $rightType !== Type::VAR) {
            if (!$this->canAssignStaticTypeToObjectProperty($def, $rightType)) {
                $this->fatalError(
                    $left,
                    'Cannot assign ' . $this->getPropertyAssignmentTypeName($rightType)
                    . ' to property ' . $this->getObjectPropertyTypeCheckDisplayName($left)
                    . ' of type ' . $this->getObjectPropertyTypeCheckTypeString($def)
                );
            }
            return;
        }

        if ($def->type !== Type::OBJECT) {
            return;
        }

        if ($rightType !== Type::VAR && $rightType !== Type::OBJECT) {
            $this->fatalError(
                $left,
                "Cannot assign value of type `{$rightType}` to {$label} `{$propName}` of type `{$def->type}`"
            );
        }

        if ($def->class === '' or $this->isAbstractClass($def->class) or $this->isInterface($def->class) or !$this->hasClass($def->class)) {
            // If the property's declared class is an interface, abstract class, or dynamic class, the current property layout optimization cannot statically determine the final object type.
            // Do not report a fatal error here; wrapObjectPropertyAssignTypeCheck() inserts a runtime check later when needed.
            return;
        }

        $rightClass = $this->detectClassOfExpr($right);
        // TODO: the exact type cannot be determined at the static compilation stage; a runtime check is required
        if ($rightClass === '') {
            return;
        }
        if (!$this->isObjectClassStaticallyAssignableTo($rightClass, $def->class)) {
            $this->fatalError(
                $left,
                "Cannot assign object of class `{$rightClass}` to {$label} `{$propName}` of class `{$def->class}`"
            );
        }
    }

    protected function wrapObjectPropertyAssignTypeCheck(NodeAbstract $left, Expr $right, string $rightExpr): string
    {
        $def = $this->getNativePropertyDef($left);
        if (!$def) {
            return $rightExpr;
        }

        if ($def->type === Type::STREAM) {
            return $this->detectTypeOfExpr($right) === Type::STREAM
                ? $rightExpr
                : 'php::toStream(' . $rightExpr . ')';
        }

        $typeCheck = $this->getObjectPropertyAssignTypeCheck($def);
        if (empty($typeCheck)) {
            return $rightExpr;
        }

        $rightType = $this->detectTypeOfExpr($right);
        $compositeRelation = null;
        if (!empty($def->typeCheck)) {
            $compositeRelation = $this->checkCompositeTypeAssignment(
                $left,
                $def->typeCheck,
                $def->typeStr,
                $right,
                'property assignment'
            );
        }
        if ($compositeRelation === self::COMPOSITE_TYPE_MATCH && $rightType !== Type::VAR) {
            // A statically known member of the composite type needs no
            // Variant runtime guard on this property write.
            return $rightExpr;
        }

        if ($rightType !== Type::VAR && $this->canAssignStaticTypeToObjectProperty($def, $rightType)) {
            return $rightExpr;
        }
        if ($rightType === Type::VAR && ($helper = $this->getFixedPropertyTypeCheckHelper($def)) !== null) {
            return $helper . '(' . $rightExpr . ', ' . $this->genCharPtr($this->getObjectPropertyTypeCheckDisplayName($left), true) . ')';
        }

        $rightClass = $this->detectClassOfExpr($right);
        if ($rightClass !== '' && $compositeRelation === null) {
            return $rightExpr;
        }

        $tmpVar = $this->addTmpVar(Type::VAR);
        $conditions = [];
        foreach ($typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($tmpVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return $rightExpr;
        }

        $propDisplay = $this->getObjectPropertyTypeCheckDisplayName($left);
        $typeStr = $this->getObjectPropertyTypeCheckTypeString($def);
        if ($this->usesPhpStylePropertyAssignTypeError($def)) {
            $throwExpr = 'php::throwExceptionEx(zend_ce_type_error, 0, '
                . $this->genCharPtr('Cannot assign %s to property ' . $propDisplay . ' of type ' . $typeStr, true)
                . ', ' . $tmpVar . '.typeStr())';
        } else {
            $throwExpr = 'php::throwExceptionEx(zend_ce_type_error, 0, '
                . $this->genCharPtr($propDisplay . ' must be of type ' . $typeStr . ', %s given', true)
                . ', ' . $tmpVar . '.typeStr())';
        }

        $code = '([&]() -> ' . Type::VAR . ' {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $tmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
        if ($this->compositeTypeNeedsIntToFloatCoercion($typeCheck)) {
            $code .= $this->getIndent() . 'if (' . $tmpVar . '.isInt()) {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . $tmpVar . ' = php::toFloat(' . $tmpVar . ');' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . implode(' || ', $conditions) . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $throwExpr . ';' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $code .= $this->getIndent() . 'return ' . $tmpVar . ';' . PHP_EOL;
        $this->indentLevel--;

        return $code . $this->getIndent() . '}())';
    }

    private function getObjectPropertyAssignTypeCheck(PropertyDef $def): array
    {
        return (new PropertyAssignTypeInfo())->getRuntimeTypeCheck($def);
    }

    private function getObjectPropertyTypeCheckDisplayName(NodeAbstract $left): string
    {
        $propName = $this->parseIdentifier($left->name);
        $classDef = $this->getNativePropertyClassDef($left);
        if ($classDef) {
            $class = $classDef->getNamespacedName(false);
            return $class . '::$' . $propName;
        }

        if ($left instanceof Expr\StaticPropertyFetch) {
            return $this->identifierToStr($left->class, literal: true) . '::$' . $propName;
        }

        return '$' . $propName;
    }

    private function getObjectPropertyTypeCheckTypeString(PropertyDef $def): string
    {
        return (new PropertyAssignTypeInfo())->getTypeString($def);
    }

    private function usesPhpStylePropertyAssignTypeError(PropertyDef $def): bool
    {
        return empty($def->typeCheck) && $def->class === '' && in_array($def->type, [
            Type::INT,
            Type::FLOAT,
            Type::BOOL,
            Type::STR,
            Type::ARRAY,
        ], true);
    }

    protected function getFixedPropertyTypeCheckHelper(PropertyDef $def): ?string
    {
        if (!empty($def->typeCheck) || $def->class !== '' || $def->nullable) {
            return null;
        }

        return match ($def->type) {
            Type::INT => 'php::toIntExact',
            Type::FLOAT => 'php::toFloatExact',
            Type::BOOL => 'php::toBoolExact',
            Type::STR => 'php::toStringExact',
            Type::ARRAY => 'php::toArrayExact',
            default => null,
        };
    }

    protected function canAssignStaticTypeToObjectProperty(PropertyDef $def, string $rightType): bool
    {
        return match ($def->type) {
            Type::FLOAT => $rightType === Type::FLOAT || $rightType === Type::INT,
            default => $rightType === $def->type,
        };
    }

    protected function getPropertyAssignmentTypeName(string $type): string
    {
        return match ($type) {
            Type::INT => 'int',
            Type::FLOAT => 'float',
            Type::BOOL => 'bool',
            Type::STR => 'string',
            Type::ARRAY => 'array',
            Type::OBJECT => 'object',
            default => 'value',
        };
    }

    protected function parseUnset(Node\Stmt\Unset_ $node): string
    {
        $vars = $node->vars;
        $lines = [];
        foreach ($vars as $var) {
            $this->assertImmutableMutationTarget($var);
            $this->assertNotNullsafeWriteContext($var);
            $this->assertNativePropertyHookDirectWriteTarget($var);
            if ($this->isArrayDimFetch($var)) {
                if ($var->dim === null) {
                    $this->fatalError($var, 'Cannot use [] for array unset');
                }
                if ($this->isNativeObjectClass($this->detectClassOfExpr($var->var))) {
                    $lines[] = $this->parseNativeArrayAccessCall(
                        $var,
                        'offsetUnset',
                        [new Node\Arg($var->dim)],
                    ) . ';';
                } elseif ($this->isStdContainerExpr($var)) {
                    $lines[] = $this->parseStdContainerOffsetUnset($var) . ';';
                } else {
                    $array = $this->parseIdentifier($var->var);
                    $dim = $this->parseIdentifier($var->dim);
                    $lines[] = $array . '.offsetUnset(' . $dim . ');';
                }
            } elseif ($this->isPropertyFetch($var)) {
                if ($this->isVarExpr($var->var)) {
                    $nativeObject = $this->parseIdentifier($var->var);
                    if ($this->isNativeObjectVar($nativeObject)) {
                        $this->fatalError($var, 'Native object properties cannot be unset');
                    }
                }
                // unset has its own unconditional readonly diagnostic below;
                // it is forbidden even while __construct is running.
                $propertyWriteTarget = $this->preparePropertyWriteTarget($var, true);
                $object = $this->getDynamicPropertyFetchObjectExpr($var, $propertyWriteTarget);
                $restoreDefault = null;
                if ($this->isIdExpr($var->name)) {
                    $propertyId = $this->getPropertyIdentifier($var, $var->var, $var->name);
                    $def = $this->getNativePropertyDef($var);
                    if ($def) {
                        if ($def->isReadonly()) {
                            $this->fatalError(
                                $var,
                                'Cannot unset readonly property `'
                                . $this->getObjectPropertyTypeCheckDisplayName($var)
                                . '`'
                            );
                        }
                        // Object typed properties are backed by Zend object
                        // properties, so PHP can represent their uninitialized
                        // state after unset(). Keep that behavior instead of
                        // restoring a fixed default value.
                        if ($this->isFixedObjectProp($def) && $def->type !== Type::OBJECT) {
                            $restoreDefault = $this->getFixedObjectPropDefaultValue($def);
                            if ($restoreDefault === null) {
                                $this->fatalError($var, "Cannot unset object property `{$this->parseIdentifier($var->name)}` of fixed type `{$def->type}` without default value");
                            }
                            $this->warning($var, "Object property `{$this->parseIdentifier($var->name)}` of fixed type cannot be unset; restoring its default value");
                            $propName = $this->parseIdentifier($var->name);
                            $propVar = $this->getObjectPropVarName($object, $propName);
                            if ($this->hasObjectPropVar($propVar)) {
                                $lines[] = $propVar . ' = ' . $restoreDefault . ';';
                            } else {
                                $lines[] = $object . '.attr(' . $propertyId . ', php::AttrMode::Update) = ' . $restoreDefault . ';';
                            }
                        }
                    }
                }
                if ($restoreDefault === null) {
                    $lines[] = $this->emitDynamicPropertyFetchUnset($var, $propertyWriteTarget) . ';';
                }
            } elseif ($this->isStaticPropertyFetch($var)) {
                $this->fatalError($var, 'Attempt to unset static property ' . $this->parseIdentifier($var->class) . '::$' . $this->parseIdentifier($var->name));
            } elseif ($this->isVarExpr($var)) {
                $name = $this->parseIdentifier($var);
                if (!$this->hasVar($name)) {
                    $this->errorUndefinedVariable($var);
                }
                $type = $this->getVarType($name);
                if ($this->isNativeObjectVar($name)) {
                    $this->forgetNativeObjectNonNull($name);
                    $lines[] = "{$name} = nullptr;";
                } elseif ($this->isNativeType($type)) {
                    $this->warning($var, "Variable of native type `\${$name}` cannot be unset");
                } elseif ($type === Type::OBJECT) {
                    // A PHP local read after unset() evaluates to null (and may
                    // emit an undefined-variable warning). Keep the Object
                    // wrapper so later object assignments remain valid, but
                    // store NULL rather than IS_UNDEF so strict null checks
                    // retain PHP value semantics.
                    //
                    // Keep the declared class: unset() changes only the value
                    // state and does not make null or another class assignable.
                    $lines[] = "{$name} = php::null;";
                } else {
                    $lines[] = "{$name}.unset();";
                }
            } else {
                $this->fatalError($var, "Unsupported unset type `{$var->getType()}`");
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $lines);
    }

    protected function getPropertyIdentifier(Expr\PropertyFetch $expr, NodeAbstract $object, NodeAbstract $property): ?string
    {
        $target = $this->resolveInstancePropertyFetchTarget($object, $property);
        if ($target !== null) {
            $result = $this->resolveNativeInstanceProperty($expr, $target->property, $target->class);
            if ($result !== null) {
                return $this->applyNativePropertyAccessResult($expr, $result);
            }
        }

        return $this->propertyNameToStr($property, literal: true);
    }

    private function resolveInstancePropertyFetchTarget(
        NodeAbstract $object,
        NodeAbstract $property,
    ): ?InstancePropertyFetchTarget {
        if (!$this->isVarExpr($object) or !$this->isIdExpr($property)) {
            return null;
        }

        $objectName = $this->parseIdentifier($object);
        $propertyName = $this->parseIdentifier($property);
        if ($objectName === 'this_') {
            if ($this->classDef->trait) {
                return null;
            }
            return new InstancePropertyFetchTarget($propertyName, $this->getFullClassName());
        }
        if ($this->isTypedObject($objectName)) {
            return new InstancePropertyFetchTarget($propertyName, $this->getObjectType($objectName));
        }

        return null;
    }

    protected function parsePropertyFetchUpdate(Expr\PropertyFetch $expr): string
    {
        return $this->parsePropertyFetchWithUpdate($expr, true);
    }

    protected function parsePropertyFetchWithUpdate(Expr\PropertyFetch $expr, bool $update): string
    {
        return $this->parseNodeWithUpdateAttribute(
            $expr,
            self::ATTR_PROPERTY_FETCH_UPDATE,
            $update,
            fn() => $this->parsePropertyFetch($expr)
        );
    }

    protected function isPropertyFetchUpdate(Expr\PropertyFetch|Expr\NullsafePropertyFetch $expr): bool
    {
        return $expr->getAttribute(self::ATTR_PROPERTY_FETCH_UPDATE, false) === true;
    }

    protected function parsePropertyFetch(Expr\PropertyFetch $expr): string
    {
        if ($this->containsNullsafeChain($expr->var)) {
            return $this->parseNullsafeExpr($expr);
        }

        $pythonProperty = $this->parsePythonObjectPropertyFetch($expr);
        if ($pythonProperty !== null) {
            return $pythonProperty;
        }

        $object = $expr->var;
        $property = $expr->name;
        $nativeExpressionClass = !$this->isVarExpr($object) ? $this->detectClassOfExpr($object) : '';
        if ($this->isNativeObjectClass($nativeExpressionClass)) {
            if (!$property instanceof Node\Identifier) {
                $this->fatalError($expr, 'Dynamic native object property access is not supported');
            }
            $propertyName = $property->toString();
            $resolution = $this->resolveNativeInstanceProperty($expr, $propertyName, $nativeExpressionClass);
            if ($resolution === null) {
                $this->fatalError(
                    $expr,
                    "Native class `{$nativeExpressionClass}` has no property `\${$propertyName}`"
                );
            }
            $id = $this->applyNativePropertyAccessResult($expr, $resolution);
        } else {
            $id = $this->getPropertyIdentifier($expr, $object, $property);
        }
        $hook = $this->getPropertyHookGetter($expr);
        if ($hook !== null) {
            return $this->emitPropertyHookGetterCall($expr, $hook);
        }

        if ($this->isNativeObjectClass($nativeExpressionClass)) {
            $objectName = $this->materializeNativeObjectReceiver($object, $nativeExpressionClass);
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
            return $this->getNativeObjectMemberReceiver($objectName)
                . $this->getNativeObjectPropertyCppName($resolution->propertyDef, $resolution->classDef);
        }

        $update = $this->isPropertyFetchUpdate($expr);
        $objectName = $update ? $this->parseWritableIdentifier($object) : $this->parseIdentifier($object);
        if ($this->isVarExpr($object) and !$this->hasVar($objectName)) {
            $this->errorUndefinedVariable($object);
        }
        if ($this->isVarExpr($object) && $this->isNativeObjectVar($objectName)) {
            if (!$property instanceof Node\Identifier) {
                $this->fatalError($expr, 'Dynamic native object property access is not supported');
            }
            $propertyName = $property->toString();
            $class = $this->getNativeObjectVarClass($objectName);
            $resolution = $this->resolveNativeInstanceProperty($expr, $propertyName, $class);
            if ($resolution === null) {
                $this->fatalError($expr, "Native class `{$class}` has no property `\${$propertyName}`");
            }
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
            return $this->getNativeObjectMemberReceiver($objectName)
                . $this->getNativeObjectPropertyCppName($resolution->propertyDef, $resolution->classDef);
        }
        $objectVar = $this->parenthesizeOpenOperand($objectName);
        $directMagic = !$update && !$this->isNativePropertyAccess($expr)
            ? $this->resolveDirectMagicPropertyAccess($expr, $objectVar, '__get')
            : null;
        if ($directMagic !== null) {
            $getProperty = 'typephp_read_magic_property_direct('
                . $objectVar . ', ' . $id . ', ' . $directMagic['classEntry'] . ', '
                . $this->getPropertyAccessCache() . ', [&]() {'
                . ' return ' . $directMagic['function'] . '(' . $objectVar . ', ' . $id . '); })';
        } elseif ($this->usesTraitPropertyScope($objectVar)) {
            $getProperty = 'typephp_read_property_scoped('
                . $objectVar . ', ' . $id . ', php::FakeScopeGuard::current(), ' . $this->escapeAttrMode($update) . ')';
        } elseif ($this->isIdExpr($property) && !$this->isNativePropertyAccess($expr)) {
            $getProperty = 'typephp_read_property_cached('
                . $objectVar . ', ' . $id . ', ' . $this->escapeAttrMode($update) . ', '
                . $this->getPropertyAccessCache() . ')';
        } else {
            $getProperty = $objectVar . '.attr(' . $id . ', ' . $this->escapeAttrMode($update) . ')';
        }
        $def = $this->getNativePropertyDef($expr);
        if ($def and $this->nativeTypes) {
            $propName = $this->parseIdentifier($property);
            $typedFetch = $this->emitNativeInstancePropertyTypedFetch(
                $expr,
                $objectVar,
                $propName,
                $id,
                $def,
                $getProperty,
            );
            if ($typedFetch !== null) {
                return $typedFetch;
            }
        }
        if ($def) {
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
        }
        return $getProperty;
    }

    protected function isPropertyHookBackingAccess(NodeAbstract $expr): bool
    {
        if ($expr->getAttribute(PropertyHookLowering::BACKING_ACCESS_ATTRIBUTE, false) === true) {
            return true;
        }
        if (!$expr instanceof Expr\PropertyFetch
            || !$expr->var instanceof Expr\Variable
            || $expr->var->name !== 'this'
            || !$expr->name instanceof Node\Identifier) {
            return false;
        }

        $property = $expr->name->toString();
        return $this->method === PropertyHookLowering::getterName($property)
            || $this->method === PropertyHookLowering::setterName($property);
    }

    protected function getPropertyHookGetter(NodeAbstract $expr): ?string
    {
        if ($this->isPropertyHookBackingAccess($expr)) {
            return null;
        }
        return $this->getNativePropertyDef($expr)?->getter;
    }

    protected function getPropertyHookSetter(NodeAbstract $expr): ?string
    {
        if ($this->isPropertyHookBackingAccess($expr)) {
            return null;
        }
        return $this->getNativePropertyDef($expr)?->setter;
    }

    protected function isNativeObjectPropertyHook(NodeAbstract $expr): bool
    {
        $class = $this->getNativePropertyClassDef($expr);
        $property = $this->getNativePropertyDef($expr);
        return $class?->nativeObject === true
            && $property !== null
            && ($property->getter !== null || $property->setter !== null);
    }

    /**
     * Native hooks lower to ordinary getter/setter calls. An indirect write
     * would only mutate the value returned by the getter and cannot reliably
     * invoke the setter, so only a direct property assignment is meaningful.
     */
    protected function assertNativePropertyHookDirectWriteTarget(NodeAbstract $expr): void
    {
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isIdExpr($expr->name)) {
            // Resolve the property before querying its hook metadata. Indirect
            // write paths reach this guard before normal property lowering.
            $this->getPropertyIdentifier($expr, $expr->var, $expr->name);
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isNativeObjectPropertyHook($expr)) {
            $this->fatalError(
                $expr,
                'Native property hooks only support direct reads and assignments',
            );
        }
    }

    protected function isReadOnlyPropertyHook(NodeAbstract $expr): bool
    {
        if ($this->isPropertyHookBackingAccess($expr)) {
            return false;
        }
        $def = $this->getNativePropertyDef($expr);
        return $def !== null && $def->getter !== null && $def->setter === null;
    }

    protected function emitPropertyHookGetterCall(Expr\PropertyFetch $expr, string $getter): string
    {
        $call = new Expr\MethodCall($expr->var, $getter, [], $expr->getAttributes());
        return $this->parseMethodCall($call);
    }

    protected function emitPropertyHookSetterCall(Expr\PropertyFetch $expr, string $setter, Expr $value): string
    {
        $call = new Expr\MethodCall($expr->var, $setter, [new Node\Arg($value)], $expr->getAttributes());
        return $this->parseMethodCall($call);
    }

    private function emitNativeInstancePropertyTypedFetch(
        Expr\PropertyFetch $expr,
        string $objectVar,
        string $propName,
        string $propertyId,
        PropertyDef $def,
        string $getter,
    ): ?string {
        if ($this->isPropertyFetchUpdate($expr) && !in_array($def->type, [Type::INT, Type::FLOAT], true)) {
            return null;
        }

        if ($def->type === Type::BOOL) {
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_DYNAMIC);
            return $this->convertBoolExpr($getter);
        }

        $propVar = $this->getObjectPropVarName($objectVar, $propName);
        if ($objectVar === 'this_') {
            if (!$this->canHoistObjectProp($objectVar, $propName, $def)) {
                return null;
            }
            $this->registerHoistedObjectPropVar($propVar, $def->type, $getter);
            $this->setNativePropertyVar($expr, $propVar);
            $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
            return $propVar;
        }

        if (!$this->canHoistStableObjectProp($objectVar, $propName, $def)) {
            return null;
        }

        // SSA-stable object: lazily create reference at first access point.
        $result = $this->hoistStableObjectProp($objectVar, $propName, $propertyId, $def->type);
        $this->setNativePropertyVar($expr, $result);
        $this->setNativePropertyValueSource($expr, self::NATIVE_PROPERTY_VALUE_VAR);
        return $result;
    }


    /**
     * A folded constant value can be a full C++ expression (e.g. the ternary
     * of `const VALUE = cond ? E::A : E::B;`). Appending `.attr(...)` to it
     * unparenthesized would bind the member access to the last operand only,
     * so any operand with top-level operators is wrapped first. Simple
     * identifiers and closed call chains stay untouched.
     */
    private function parenthesizeOpenOperand(string $code): string
    {
        $depth = 0;
        $inString = false;
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && ($char === ' ' || $char === '?')) {
                return '(' . $code . ')';
            }
        }
        return $code;
    }
}
