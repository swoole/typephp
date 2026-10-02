<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers PHP array literals, dimensions, writable targets, and mixed array initialization.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait ArrayExpressionTrait
{
    protected function parseArray(Expr\Array_ $node): string
    {
        $items = $node->items;
        // Optimize code style: return {} directly for an empty array, otherwise it would produce empty entries
        if (count($items) === 0) {
            return Type::ARRAY . '{}';
        }

        $hasKey = false;
        $hasIntKey = false;
        $hasStrKey = false;
        $hasUnpack = false;
        $hasVarKey = false;
        $hasNextInsert = false;
        $hasReference = false;
        foreach ($items as $item) {
            $valueClass = $this->detectClassOfExpr($item->value);
            if ($this->isNativeObjectClass($valueClass)) {
                $this->fatalError($item->value, 'Native objects cannot be stored in PHP arrays');
            }
            if ($item->unpack) {
                $hasUnpack = true;
            }
            if ($item->byRef) {
                $hasReference = true;
            }
            if ($item->key) {
                if ($item->key instanceof Node\Scalar\LNumber) {
                    $hasIntKey = true;
                } elseif ($item->key instanceof Node\Scalar\String_) {
                    $hasStrKey = true;
                } else {
                    $hasVarKey = true;
                }
                $hasKey = true;
            } else {
                $hasNextInsert = true;
            }
        }

        // Mixed keys are present, so split the insertion into multiple statements
        if ($hasReference or $hasUnpack or $hasVarKey or ($hasNextInsert && $hasKey) or ($hasIntKey and $hasStrKey)) {
            return $this->parseArrayMixed($node);
        }

        $list = [];
        $this->indentLevel++;
        foreach ($items as $item) {
            $this->assertExprCanBeUsedAsValue($item->value, 'array value');
            // A call may bridge a fixed typed value through php::Ref. Finish
            // the call and its RefWrap::commit() before evaluating the next
            // array item, because PHP makes the writeback observable there:
            // `[mutate($value), $value]`. C++ initializer ordering alone is
            // insufficient when the commit is otherwise queued for the end
            // of the containing PHP statement.
            $value = $this->materializeRefReturnAsValue(
                $item->value,
                $this->parseOrderedOperand($item->value, false),
            );
            if ($item->key) {
                $this->assertExprCanBeUsedAsValue($item->key, 'array key');
                $key = $this->parseArrayKey($item->key);
                $list[] = $this->getIndent() . '{ ' . $key . ', ' . Type::VAR . '(' . $value . ') }';
            } else {
                $list[] = $this->getIndent() . Type::VAR . '(' . $value . ')';
            }
        }
        $this->indentLevel--;

        return Type::ARRAY . '{' . PHP_EOL .
            implode(', ' . PHP_EOL, $list) . PHP_EOL .
            $this->getIndent() .
            '}';
    }

    /**
     * Resolve a `$GLOBALS[...]` array-dim fetch to its static slot when the key
     * is a known global name, or to a php::global() lookup otherwise.
     */

    protected function parseGlobalsArrayDimFetch(Expr\ArrayDimFetch $node): string
    {
        if ($node->dim === null) {
            $this->fatalError($node, 'Cannot use [] for GLOBALS');
        }
        $staticSlot = $this->getStaticGlobalsSlot($node);
        if ($staticSlot !== null) {
            $name = $staticSlot;
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, Type::VAR);
            }
            if (!$this->hasScopeGlobalVar($name)) {
                $this->addScopeGlobalVar($name, $this->globalVars[$name]);
            }
            if (isset($this->nativeGlobalObjects[$name])) {
                $this->addNativeObject($name, $this->nativeGlobalObjects[$name]);
            }
            return $name;
        }
        return 'php::global(' . $this->parseIdentifier($node->dim) . ')';
    }

    protected function parseWritableIdentifier(NodeAbstract $expr): string
    {
        if ($expr instanceof Expr\ArrayDimFetch) {
            $dimension = $this->findNativeArrayAccessDimension($expr);
            if ($dimension !== null) {
                $this->getNativeArrayAccessClass($dimension->var, $dimension);
                $this->fatalError(
                    $expr,
                    'Indirect modification of Native ArrayAccess elements is not supported',
                );
            }
            return $this->parseArrayDimFetchUpdate($expr);
        }

        if ($expr instanceof Expr\PropertyFetch) {
            // Keep the write-policy check at the common writable-expression
            // boundary as well as at assignment lowering sites. This covers
            // destructuring, foreach targets, and future write forms that use
            // parseWritableIdentifier() directly.
            $this->preparePropertyWriteTarget($expr);
            return $this->parsePropertyFetchUpdate($expr);
        }

        if ($expr instanceof Expr\NullsafePropertyFetch) {
            return $this->parseNullsafePropertyFetchUpdate($expr);
        }

        return $this->parseIdentifier($expr);
    }

    protected function parseNodeWithUpdateAttribute(NodeAbstract $node, string $attribute, bool $update, callable $parser): string
    {
        $hadAttribute = $node->hasAttribute($attribute);
        $previousValue = $node->getAttribute($attribute);
        $node->setAttribute($attribute, $update);
        try {
            return $parser();
        } finally {
            if ($hadAttribute) {
                $node->setAttribute($attribute, $previousValue);
            } else {
                $attributes = $node->getAttributes();
                unset($attributes[$attribute]);
                $node->setAttributes($attributes);
            }
        }
    }

    protected function parseArrayDimFetchRead(Expr\ArrayDimFetch $node): string
    {
        return $this->parseArrayDimFetchWithUpdate($node, false);
    }

    protected function parseArrayDimFetchUpdate(Expr\ArrayDimFetch $node): string
    {
        return $this->parseArrayDimFetchWithUpdate($node, true);
    }

    protected function parseArrayDimFetchWithUpdate(Expr\ArrayDimFetch $node, bool $update): string
    {
        return $this->parseNodeWithUpdateAttribute(
            $node,
            self::ATTR_ARRAY_DIM_FETCH_UPDATE,
            $update,
            fn() => $this->parseArrayDimFetch($node)
        );
    }

    protected function isArrayDimFetchUpdate(Expr\ArrayDimFetch $node): bool
    {
        return $node->getAttribute(self::ATTR_ARRAY_DIM_FETCH_UPDATE, false) === true;
    }

    protected function parseArrayDimFetch(Expr\ArrayDimFetch $node): string
    {
        if ($this->getTypedArrayAccessDefinition($node) !== null) {
            if ($this->isArrayDimFetchUpdate($node)) {
                $this->fatalError($node, 'Typed array writes must use checked element assignment');
            }
            return $this->parseTypedArrayRead($node);
        }
        if ($this->isNativeObjectClass($this->detectClassOfExpr($node->var))) {
            if ($node->dim === null) {
                $this->fatalError($node, 'Cannot use [] for reading');
            }
            return $this->parseNativeArrayAccessCall(
                $node,
                'offsetGet',
                [new Node\Arg($node->dim)],
            );
        }
        if ($node->dim !== null) {
            $this->assertNotNativeObjectArrayKey($node->dim);
        }
        $write = $this->isArrayDimFetchUpdate($node);
        if ($this->isStdContainerExpr($node)) {
            if ($write && $node->dim === null) {
                return $this->parseIdentifier($node->var);
            }
            return $this->parseStdContainerDimFetch($node);
        }

        $isGlobals = $this->isVarExpr($node->var) && $node->var->name === 'GLOBALS';
        $var = $write ? $this->parseWritableIdentifier($node->var) : $this->parseIdentifier($node->var);
        if ($this->isVarExpr($node->var)) {
            if ($isGlobals) {
                return $this->parseGlobalsArrayDimFetch($node);
            }
            if (!$this->hasVar($var)) {
                if ($write) {
                    $this->addLocalVar($var, Type::ARRAY);
                } else {
                    $this->errorUndefinedVariable($node->var);
                }
            }
            $this->assertArrayDimVariableTypeIsSupported($node, $var);
        }

        if ($node->dim === null) {
            if (!$write) {
                $this->fatalError($node, 'Cannot use [] for reading');
            } else {
                return $var . '.newItem()';
            }
        } else {
            $dim = $this->parseIdentifier($node->dim);
            // Only fixed local variables and parameters are emitted as
            // php::Array or php::Str. Globals/statics, properties, and call
            // expressions may carry the same PHP type while their C++ storage
            // remains php::Variant.
            $fixedReceiver = $this->isVarExpr($node->var)
                && !$this->hasScopeGlobalVar($var)
                && !$this->hasStaticVar($var);
            if (!$write && $fixedReceiver) {
                $receiverType = $this->detectTypeOfExpr($node->var);
                $dimType = $this->detectTypeOfExpr($node->dim);
                if ($receiverType === Type::STR) {
                    $offset = $dimType === Type::INT ? $dim : 'php::toInt(' . $dim . ')';
                    return $var . '.offsetGet(' . $offset . ')';
                }
                if ($receiverType === Type::ARRAY) {
                    if ($dimType === Type::INT) {
                        return $var . '.get(' . $dim . ')';
                    }
                    if ($dimType === Type::FLOAT) {
                        return $var . '.get(php::toInt(' . $dim . '))';
                    }
                    if ($dimType === Type::STR) {
                        return $var . '.get(' . $dim . ')';
                    }
                }
            }
            return $var . '.item(' . $dim . ', ' . $this->escapeBool($write) . ')';
        }
    }

    /**
     * Fixed native scalar variables cannot defer an offset operation to
     * PHPX. Diagnose them here so invalid TypePHP does not become invalid C++.
     * A php::Var remains runtime-checked because its value may have changed.
     */
    protected function assertArrayDimVariableTypeIsSupported(Expr\ArrayDimFetch $node, string $var): void
    {
        $type = $this->getVarType($var);
        if ($type === Type::BOOL || $type === Type::INT || $type === Type::FLOAT) {
            $this->fatalError($node, 'Cannot use [] for numbers');
        }
        if ($type === Type::STR && $node->dim === null) {
            $this->fatalError($node, 'Cannot use [] for strings');
        }
    }

    private function parseArrayMixed(Expr\Array_ $node): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, Type::ARRAY);
        // Release the temporary variable to avoid array copies when the array is modified
        $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.clean();';

        $items = $node->items;
        foreach ($items as $item) {
            // PHP evaluates a key before its value. A side-effecting key must
            // be materialized first: the value may hoist a nested call into
            // a pre-statement temporary, which would otherwise run before an
            // inline key expression.
            $orderedKey = null;
            if ($item->key && !$item->unpack && $this->shouldMaterializeOrderedOperand($item->key)) {
                $this->assertExprCanBeUsedAsValue($item->key, 'array key');
                $this->assertNotNativeObjectArrayKey($item->key);
                $orderedKey = $this->parseOrderedOperand($item->key, false, true);
            }
            $this->assertExprCanBeUsedAsValue($item->value, $item->unpack ? 'array unpack value' : 'array value');
            if ($item->byRef) {
                if ($item->unpack) {
                    $this->fatalError($item, 'Cannot unpack references in array literals');
                }
                $value = $this->convertToRef($item->value);
            } else {
                $value = $this->materializeRefReturnAsValue(
                    $item->value,
                    $this->parseOrderedOperand($item->value, false),
                );
            }
            if ($item->unpack) {
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.merge(' . $value . ');';
            } elseif ($item->key) {
                $this->assertExprCanBeUsedAsValue($item->key, 'array key');
                $key = $orderedKey ?? $this->parseArrayKey($item->key);
                $method = $item->byRef ? 'set' : 'setValue';
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.' . $method . '(' . $key . ', ' . $value . ');';
            } else {
                $method = $item->byRef ? 'append' : 'appendValue';
                $this->context->beforeStmtLines[] = $this->getIndent() . $tmpVar . '.' . $method . '(' . $value . ');';
            }
        }

        return $tmpVar;
    }
}
