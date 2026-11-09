<?php
/**
 * SSA-based object stability and property reference hoisting optimizer.
 *
 * Extends the existing $this->intProp optimization to any SSA-proven stable
 * object. When an object variable has a single definition, no escape/reference/
 * kill flags, and its class has no magic methods that intercept property access,
 * int/float property accesses can be hoisted to C++ references aliasing the
 * zval's internal value slot.
 *
 * Prerequisites checked by this pass:
 *  1. Object has exactly one SSA definition (single assignment)
 *  2. No REFERENCE / ESCAPED / KILLED flags on the object's SSA vars
 *  3. Class has no __get / __set magic methods
 *  4. Property has a declared native type (int or float)
 *  5. No &$o->prop (reference capture of the property)
 *  6. No func(&$o->prop) (property passed by reference)
 *  7. Object is not exposed to dynamic user code before later property access
 *  8. First access is not inside a loop or nested block scope
 *  9. Property is not readonly (including properties of a readonly class)
 *
 * Direct unset($o->prop) invalidates that property slot; unset($o) invalidates
 * every property slot associated with the object. These properties must use
 * dynamic attr() access for the whole function, independent of branch order.
 */

namespace TypePhp\Optimizer;

use TypePhp\Entity\PropertyDef;
use TypePhp\Type;

use TypePhp\Analysis\SsaBuilder;
use TypePhp\Analysis\SsaFlags;
use TypePhp\Resolver\Reflection;
use PhpParser\Node;
use PhpParser\Node\Expr;

trait SsaPropOptimizer
{
    /**
     * Analyze object stability for method dispatch and other non-native
     * optimizations.
     * Called after SSA build and var type optimization in parseFunction().
     *
     * Scans the function body AST to find object assignments (e.g. $o = new Foo()),
     * resolves class names, and checks SSA stability for each object variable.
     * This must be done during analysis because $this->context->objects is only
     * populated during code generation (after analysis).
     */
    protected function analyzeStableObjects(SsaBuilder $ssa): void
    {
        [$objectAssigns] = $this->collectStableObjectCandidates($ssa);

        foreach ($objectAssigns as $objName => $className) {
            if (!$className || $className === 'stdClass' || !$this->hasClass($className)) {
                continue;
            }

            if (!$this->isObjectSsaStable($ssa, $objName, $objectAssigns)) {
                continue;
            }

            $this->context->stableObjects[$objName] = $className;
            if ($this->isExactNewObjectDefinition($ssa, $objName)) {
                $this->context->exactObjects[$objName] = $className;
            }
        }
    }

    /**
     * A declared return type is only an upper bound and may contain a subclass.
     * Only a sole, concrete `new` definition proves the runtime class exactly.
     */
    protected function isExactNewObjectDefinition(SsaBuilder $ssa, string $objName): bool
    {
        foreach ($ssa->ssaVars as $ssaVar) {
            if ($ssaVar->origName !== $objName || $ssaVar->flags & SsaFlags::PHI) {
                continue;
            }

            $definition = $ssaVar->definition;
            if (!$definition instanceof Node\Stmt\Expression
                || !$definition->expr instanceof Expr\Assign
                || !$definition->expr->expr instanceof Expr\New_
                || !$definition->expr->expr->class instanceof Node\Name) {
                return false;
            }

            return strtolower($definition->expr->expr->class->toString()) !== 'static';
        }

        return false;
    }

    /**
     * Identify safe property accesses for native typed property hoisting.
     */
    protected function optimizeObjectProps(SsaBuilder $ssa): void
    {
        if ($this->classDef && !$this->classDef->trait) {
            if ($this->isClassSafeForPropHoisting($this->getFullClassName())) {
                $unsafeProps = $this->collectDangerousPropOps('this_', $ssa->getStmts());
                if ($unsafeProps) {
                    $this->context->unsafeObjectProps['this_'] = $unsafeProps;
                }
            } else {
                $this->context->unsafeObjectProps['this_'] = ['*' => true];
            }
        }

        [, $objectAliases] = $this->collectStableObjectCandidates($ssa);

        foreach ($this->context->stableObjects as $objName => $className) {
            if (!$this->isClassSafeForPropHoisting($className)) {
                $this->context->unsafeObjectProps[$objName] = ['*' => true];
                continue;
            }

            $unsafeProps = $this->collectDangerousPropOpsForObjects(
                $this->getObjectAliasNames($objName, $objectAliases),
                $ssa->getStmts()
            );
            if ($unsafeProps) {
                $this->context->unsafeObjectProps[$objName] = $unsafeProps;
            }
        }
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function collectStableObjectCandidates(SsaBuilder $ssa): array
    {
        if (empty($ssa->ssaVars)) {
            return [[], []];
        }

        $objectAssigns = [];
        foreach ($this->context->objects as $objName => $className) {
            if ($objName === 'this_') {
                continue;
            }
            $objectAssigns[$objName] = $className;
        }
        $objectAliases = [];

        return [
            $this->collectObjectAssignments($ssa->getStmts(), $objectAssigns, $objectAliases),
            $objectAliases,
        ];
    }

    /**
     * Walk the function body AST to find variable assignments that produce
     * typed objects. Returns map of varName => className.
     */
    protected function collectObjectAssignments(array $stmts, array $knownObjects = [], array &$aliases = []): array
    {
        $result = $knownObjects;
        do {
            $count = count($result);
            foreach ($stmts as $stmt) {
                $this->scanStmtForObjectAssign($stmt, $result, $aliases);
            }
        } while (count($result) !== $count);

        return $result;
    }

    protected function scanStmtForObjectAssign($stmt, array &$result, array &$aliases): void
    {
        if (!$stmt instanceof Node) {
            return;
        }

        if ($stmt instanceof Node\Stmt\Expression) {
            $this->scanExprForObjectAssign($stmt->expr, $result, $aliases);
            return;
        }

        // Recurse
        if ($stmt instanceof Node\Stmt\If_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            foreach ($stmt->elseifs as $elseif) {
                foreach ($elseif->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            }
            if ($stmt->else) {
                foreach ($stmt->else->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            }
        } elseif ($stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
        } elseif ($stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
        } elseif ($stmt instanceof Node\Stmt\TryCatch) {
            foreach ($stmt->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            foreach ($stmt->catches as $catch) {
                foreach ($catch->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            }
            if ($stmt->finally) {
                foreach ($stmt->finally->stmts as $s) $this->scanStmtForObjectAssign($s, $result, $aliases);
            }
        }
    }

    protected function scanExprForObjectAssign($expr, array &$result, array &$aliases): void
    {
        if (!$expr instanceof Node) {
            return;
        }

        if ($expr instanceof Expr\Assign) {
            if ($expr->expr instanceof Node) {
                $this->scanExprForObjectAssign($expr->expr, $result, $aliases);
            }

            $assign = $expr;
            $var = $assign->var;
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $className = $this->resolveAssignedObjectClass($assign->expr, $result);
                if ($className) {
                    $result[$var->name] = $className;
                    if ($assign->expr instanceof Expr\Variable && is_string($assign->expr->name)) {
                        $aliases[$var->name] = $assign->expr->name;
                    }
                }
            }
            return;
        }

        foreach ($expr->getSubNodeNames() as $subNodeName) {
            $subNode = $expr->$subNodeName;
            if ($subNode instanceof Node) {
                $this->scanExprForObjectAssign($subNode, $result, $aliases);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->scanExprForObjectAssign($item, $result, $aliases);
                    }
                }
            }
        }
    }

    protected function resolveAssignedObjectClass(Expr $expr, array $knownObjects): ?string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $knownObjects[$expr->name] ?? null;
        }

        return $this->resolveNewExprClass($expr);
    }

    /**
     * Resolve the class name from a `new ClassName()` expression,
     * or a function/method call that returns a known object type.
     */
    protected function resolveNewExprClass(Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                if ($className === 'self') {
                    if ($this->classDef) {
                        return $this->getFullClassName();
                    }
                    return null;
                }
                if ($className === 'static') {
                    return null;
                }
                return $this->getNamespacedClassName($className);
            }
        }

        // For function/method calls, try to detect the return class
        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall || $expr instanceof Expr\NullsafeMethodCall) {
            return $this->detectClassOfExpr($expr) ?: null;
        }

        return null;
    }

    /**
     * Check if an object variable has a single stable SSA definition.
     */
    protected function isObjectSsaStable(SsaBuilder $ssa, string $objName, array $knownObjects = []): bool
    {
        $foundDef = false;

        foreach ($ssa->ssaVars as $ssaVar) {
            if ($ssaVar->origName !== $objName) {
                continue;
            }

            if ($ssaVar->flags & SsaFlags::PHI) {
                continue;
            }

            if ($ssaVar->flags & (SsaFlags::REFERENCE | SsaFlags::ESCAPED | SsaFlags::KILLED)) {
                return false;
            }

            if ($foundDef) {
                return false; // Multiple definitions
            }

            if (!$this->isObjectDefinition($ssaVar, $knownObjects)) {
                return false;
            }

            $foundDef = true;
        }

        return $foundDef;
    }

    /**
     * Check if an SSA definition sets the variable to an object value.
     * Accepts both `new ClassName()` and calls that return a typed object.
     */
    protected function isObjectDefinition($ssaVar, array $knownObjects = []): bool
    {
        $def = $ssaVar->definition;
        if (!$def) {
            return false;
        }

        if ($def instanceof Node\Stmt\Expression && $def->expr instanceof Expr\Assign) {
            $rhs = $def->expr->expr;
            if ($rhs instanceof Expr\Variable && is_string($rhs->name)) {
                return isset($knownObjects[$rhs->name]);
            }
            if ($rhs instanceof Expr\New_) {
                return true;
            }
            // Allow function/method calls that return a matching typed object
            if ($rhs instanceof Expr\FuncCall || $rhs instanceof Expr\MethodCall
                || $rhs instanceof Expr\StaticCall || $rhs instanceof Expr\NullsafeMethodCall) {
                return true;
            }
        }

        return false;
    }

    protected function getObjectAliasNames(string $objName, array $aliases): array
    {
        $names = [$objName => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($aliases as $alias => $source) {
                if (isset($names[$alias]) && !isset($names[$source])) {
                    $names[$source] = true;
                    $changed = true;
                }
                if (isset($names[$source]) && !isset($names[$alias])) {
                    $names[$alias] = true;
                    $changed = true;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * Check if a class has no magic methods that intercept property access.
     */
    protected function isClassSafeForPropHoisting(string $className): bool
    {
        $classDef = $this->symbols->findClass($this->escapeClass($className));
        if (!$classDef) {
            return false;
        }

        if ($classDef->hasMethod('__get') || $classDef->hasMethod('__set')) {
            return false;
        }

        return true;
    }

    /**
     * Scan function body for dangerous operations on object properties.
     *
     * Detects:
     *  - $ref = &$o->prop — property becomes reference, zval type changes
     *  - func(&$o->prop) or $obj->method(&$o->prop) — property passed by ref
     *  - mutate($o) or $o->method() — dynamic code may turn the property slot
     *    into a reference through the exposed object
     *
     * Direct unset($o->prop) disables hoisting for that property. unset($o)
     * disables all property hoisting for the object.
     */
    protected function hasDangerousPropOps(string $objName, array $stmts): bool
    {
        return $this->collectDangerousPropOps($objName, $stmts) !== [];
    }

    /**
     * @return array<string, bool> property name map; '*' means any property may be invalidated.
     */
    protected function collectDangerousPropOps(string $objName, array $stmts): array
    {
        return $this->collectDangerousPropOpsForObjects([$objName], $stmts);
    }

    /**
     * @param string[] $objNames aliases that may point at the same object
     * @return array<string, bool> property name map; '*' means any property may be invalidated.
     */
    protected function collectDangerousPropOpsForObjects(array $objNames, array $stmts): array
    {
        $events = [];
        foreach ($stmts as $stmt) {
            $this->collectPropEvents($stmt, $objNames, $events);
        }
        return $this->unsafePropsFromEvents($events);
    }

    /**
     * @param array<int, array{kind: string, prop: string}> $events
     */
    protected function collectPropEvents($node, string|array $objName, array &$events): void
    {
        if (!$node instanceof Node) {
            return;
        }

        if ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $propName = $this->getPropNameOfObj($var, $objName);
                if ($propName !== null) {
                    $events[] = ['kind' => 'danger_always', 'prop' => $propName];
                    $this->collectPropEventsInDynamicParts($var, $objName, $events);
                } elseif ($this->isVarNamedAny($var, $objName)) {
                    // Object lifetime is no longer continuous. A reference to
                    // any property slot could outlive the object in one branch,
                    // so disable slot hoisting for every branch of the function.
                    $events[] = ['kind' => 'danger_always', 'prop' => '*'];
                } else {
                    $this->collectPropEvents($var, $objName, $events);
                }
            }
            return;
        }

        if ($node instanceof Expr\AssignRef) {
            $leftProp = $this->getPropNameOfObj($node->var, $objName);
            if ($leftProp !== null) {
                // $o->prop =& $ref would parse the left property as a normal
                // assignment target, so it must never use a hoisted property var.
                $events[] = ['kind' => 'danger_always', 'prop' => $leftProp];
                $this->collectPropEventsInDynamicParts($node->var, $objName, $events);
            } else {
                $this->collectPropEvents($node->var, $objName, $events);
            }

            $rightProp = $this->getPropNameOfObj($node->expr, $objName);
            if ($rightProp !== null) {
                // $ref = &$o->prop changes the zval slot itself from the
                // declared scalar into IS_REFERENCE. A C++ reference hoisted
                // from any earlier access would then alias the zend_reference
                // pointer bits rather than the referenced scalar. Disable
                // hoisting for this property for the complete function.
                $events[] = ['kind' => 'danger_always', 'prop' => $rightProp];
                $this->collectPropEventsInDynamicParts($node->expr, $objName, $events);
            } else {
                $this->collectPropEvents($node->expr, $objName, $events);
            }
            return;
        }

        if ($this->isStdRefCall($node)
            && isset($node->args[0])
            && $node->args[0] instanceof Node\Arg) {
            $propName = $this->getPropNameOfObj($node->args[0]->value, $objName);
            if ($propName !== null) {
                // std::ref($o->prop) has the same slot-changing effect as an
                // explicit reference assignment.
                $events[] = ['kind' => 'danger_always', 'prop' => $propName];
                $this->collectPropEventsInDynamicParts($node->args[0]->value, $objName, $events);
                return;
            }
        }

        if ($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall
            || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall) {
            if ($node instanceof Expr\StaticCall && $node->class instanceof Expr) {
                $this->collectPropEvents($node->class, $objName, $events);
            }
            if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                $this->collectPropEvents($node->var, $objName, $events);
            }
            foreach ($node->args as $arg) {
                // A first-class callable such as $object->method(...) stores a
                // VariadicPlaceholder in args. It denotes callable creation,
                // not an argument expression, and has no byRef/value fields.
                if (!$arg instanceof Node\Arg) {
                    continue;
                }
                $propName = $arg->byRef ? $this->getPropNameOfObj($arg->value, $objName) : null;
                if ($propName !== null) {
                    $events[] = ['kind' => 'danger', 'prop' => $propName];
                    $this->collectPropEventsInDynamicParts($arg->value, $objName, $events);
                } else {
                    $this->collectPropEvents($arg->value, $objName, $events);
                }
            }
            if (!$this->isSafeObjectExposureCall($node)) {
                if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
                    && $this->isVarNamedAny($node->var, $objName)) {
                    $events[] = ['kind' => 'danger', 'prop' => '*'];
                }
                foreach ($node->args as $arg) {
                    if (!$arg instanceof Node\Arg) {
                        continue;
                    }
                    if ($this->exprMayExposeObject($arg->value, $objName)) {
                        $events[] = ['kind' => 'danger', 'prop' => '*'];
                    }
                }
            }
            return;
        }

        if ($node instanceof Expr\Assign) {
            $this->collectPropEvents($node->expr, $objName, $events);
            $this->collectPropEvents($node->var, $objName, $events);
            if ($this->isDynamicPropWriteOfObj($node->var, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            if ($this->exprMayExposeObject($node->expr, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            return;
        }

        if ($node instanceof Expr\AssignOp || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $target = $node instanceof Expr\AssignOp ? $node->var : $node->var;
            if ($node instanceof Expr\AssignOp) {
                $this->collectPropEvents($node->expr, $objName, $events);
            }
            $this->collectPropEvents($target, $objName, $events);
            if ($this->isDynamicPropWriteOfObj($target, $objName)) {
                $events[] = ['kind' => 'danger', 'prop' => '*'];
            }
            return;
        }

        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if ($this->isVarNamedAny($use->var, $objName)) {
                    $events[] = ['kind' => 'danger', 'prop' => '*'];
                }
            }
        }

        $propName = $this->getPropNameOfObj($node, $objName);
        if ($propName !== null && $propName !== '*') {
            $events[] = ['kind' => 'access', 'prop' => $propName];
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if ($subNode instanceof Node) {
                $this->collectPropEvents($subNode, $objName, $events);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->collectPropEvents($item, $objName, $events);
                    }
                }
            }
        }
    }

    protected function isSafeObjectExposureCall(Expr\FuncCall|Expr\MethodCall|Expr\StaticCall|Expr\NullsafeMethodCall $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return $node->name instanceof Node\Name
                && $this->isInternalFunctionName($node->name);
        }

        if ($node instanceof Expr\StaticCall) {
            return $node->class instanceof Node\Name
                && $node->name instanceof Node\Identifier
                && $this->isInternalClassCall($this->resolveStaticCallClassForSafety($node->class), $node->name->toString());
        }

        if (!$node->name instanceof Node\Identifier) {
            return false;
        }

        $className = $this->detectClassOfExpr($node->var);
        return $this->isInternalClassCall($className, $node->name->toString());
    }

    protected function isInternalFunctionName(Node\Name $name): bool
    {
        $functionName = ltrim($name->toString(), '\\');

        if (str_contains($functionName, '\\')) {
            return false;
        }

        return $this->isInternalFunction($functionName) || $this->isInternalFunction(strtolower($functionName));
    }

    protected function resolveStaticCallClassForSafety(Node\Name $classNode): string
    {
        $className = $classNode->toString();
        if ($className === 'self') {
            return $this->classDef ? $this->getFullClassName() : '';
        }
        if ($className === 'parent') {
            return $this->classDef ? $this->classDef->extends : '';
        }
        if ($className === 'static') {
            return '';
        }
        return $this->getNamespacedClassName($className);
    }

    protected function isInternalClassCall(string $className, string $methodName): bool
    {
        return $className !== ''
            && ($this->isInternalClass($className) || $this->isInternalInterface($className))
            && Reflection::hasMethod($className, $methodName);
    }

    /**
     * Dynamic property name expressions can contain normal property reads:
     * unset($o->{$other->name}) should still record $other->name if relevant.
     *
     * @param array<int, array{kind: string, prop: string}> $events
     */
    protected function collectPropEventsInDynamicParts($node, string|array $objName, array &$events): void
    {
        if (!$node instanceof Expr\PropertyFetch) {
            return;
        }
        if (!$node->name instanceof Node\Identifier) {
            $this->collectPropEvents($node->name, $objName, $events);
        }
    }

    /**
     * @param array<int, array{kind: string, prop: string}> $events
     * @return array<string, bool>
     */
    protected function unsafePropsFromEvents(array $events): array
    {
        $liveProps = [];
        $unsafeProps = [];

        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            $propName = $event['prop'];

            if ($event['kind'] === 'access') {
                $liveProps[$propName] = true;
                continue;
            }

            if ($event['kind'] === 'danger_always') {
                $unsafeProps[$propName] = true;
                continue;
            }

            if ($propName === '*') {
                foreach ($liveProps as $liveProp => $_) {
                    $unsafeProps[$liveProp] = true;
                }
            } elseif (isset($liveProps[$propName])) {
                $unsafeProps[$propName] = true;
            }
        }

        return $unsafeProps;
    }

    /**
     * Check if an expression is a property fetch on a specific object.
     */
    protected function isPropOfObj($node, string|array $objName): bool
    {
        return $this->getPropNameOfObj($node, $objName) !== null;
    }

    protected function getPropNameOfObj($node, string|array $objName): ?string
    {
        if (!$node instanceof Expr\PropertyFetch
            || !$node->var instanceof Expr\Variable
            || !is_string($node->var->name)
            || !$this->isVarNamedAny($node->var, $objName)) {
            return null;
        }

        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return '*';
    }

    protected function isVarNamedAny($node, string|array $varNames): bool
    {
        foreach ((array)$varNames as $varName) {
            if ($this->isVarNamed($node, $varName)) {
                return true;
            }
        }
        return false;
    }

    protected function exprMayExposeObject($node, string|array $objName): bool
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($this->isVarNamedAny($node, $objName)) {
            return true;
        }

        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && $this->isVarNamedAny($node->var, $objName)) {
            return false;
        }

        if ($node instanceof Expr\BinaryOp || $node instanceof Expr\BooleanNot
            || $node instanceof Expr\Cast || $node instanceof Expr\UnaryMinus
            || $node instanceof Expr\UnaryPlus) {
            return false;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if ($subNode instanceof Node) {
                if ($this->exprMayExposeObject($subNode, $objName)) {
                    return true;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($this->exprMayExposeObject($item, $objName)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function isDynamicPropWriteOfObj($node, string|array $objName): bool
    {
        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && is_string($node->var->name)
            && $this->isVarNamedAny($node->var, $objName)) {
            return $this->getPropNameOfObj($node, $objName) === '*';
        }

        if ($node instanceof Expr\ArrayDimFetch) {
            return $this->isDynamicPropWriteOfObj($node->var, $objName);
        }

        return false;
    }

    /**
     * Check if an object variable is SSA-stable.
     * Public for use by parsePropertyFetch() in code generation.
     */
    public function isStableObject(string $objName): bool
    {
        return isset($this->context->stableObjects[$objName]);
    }

    public function canHoistStableObjectProp(string $objName, string $propName, PropertyDef $property): bool
    {
        if (!$this->isStableObject($objName)) {
            return false;
        }
        return $this->canHoistObjectPropBySafety($objName, $propName, $property);
    }

    public function canHoistObjectProp(string $objName, string $propName, PropertyDef $property): bool
    {
        if ($objName !== 'this_' && !$this->isStableObject($objName)) {
            return false;
        }
        return $this->canHoistObjectPropBySafety($objName, $propName, $property);
    }

    protected function canHoistObjectPropBySafety(
        string $objName,
        string $propName,
        PropertyDef $property,
    ): bool
    {
        if ($property->isReadonly()) {
            return false;
        }
        $unsafeProps = $this->context->unsafeObjectProps[$objName] ?? [];
        return !isset($unsafeProps['*']) && !isset($unsafeProps[$propName]);
    }

    /**
     * @return array{type: string, kind: string}
     */
    protected function getHoistedObjectPropInfo(string $declaredType): array
    {
        if ($declaredType === Type::INT || $declaredType === Type::FLOAT) {
            return ['type' => $declaredType, 'kind' => 'zval'];
        }

        return ['type' => Type::VAR, 'kind' => 'var'];
    }

    protected function getZvalValueMacroForPropType(string $type): ?string
    {
        return match ($type) {
            Type::INT => 'Z_LVAL_P',
            Type::FLOAT => 'Z_DVAL_P',
            default => null,
        };
    }

    /**
     * Generate the property reference declaration for a stable object.
     * Emits via beforeStmtLines so the reference is declared before the
     * current statement at function scope.
     *
     * Skips hoisting when inside a loop or nested block scope, since the
     * reference must be declared at function scope to be accessible later.
     */
    public function hoistStableObjectProp(string $objName, string $propName, string $id, string $cType): string
    {
        $propVar = $this->getObjectPropVarName($objName, $propName);

        if (isset($this->context->hoistedProps[$objName][$propName])) {
            return $propVar;
        }

        if ($this->context->inLoop || $this->context->scopeLevel > 1) {
            $refGetter = $objName . '.attr(' . $id . ', php::AttrMode::Update)';
            $zvalMacro = $this->getZvalValueMacroForPropType($cType);
            if ($zvalMacro !== null) {
                return $zvalMacro . '(' . $refGetter . '.unwrap_ptr())';
            }
            return $refGetter;
        }

        $refGetter = $objName . '.attr(' . $id . ', php::AttrMode::Update)';
        $zvalMacro = $this->getZvalValueMacroForPropType($cType);
        if ($zvalMacro !== null) {
            $this->context->beforeStmtLines[] = $cType . ' &' . $propVar . ' = ' . $zvalMacro . '(' . $refGetter . '.unwrap_ptr());';
        } else {
            $this->context->beforeStmtLines[] = Type::VAR . ' ' . $propVar . ' = ' . $refGetter . ';';
        }
        $this->context->hoistedProps[$objName][$propName] = true;

        return $propVar;
    }
}
