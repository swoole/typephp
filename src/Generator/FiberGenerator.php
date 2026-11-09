<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use TypePhp\Context\FunctionContext;
use TypePhp\Entity\FunctionDef;

trait FiberGenerator
{
    protected function containsYield(Function_|ClassMethod $v): bool
    {
        return $this->containsYieldInNodes($v->stmts ?? []);
    }

    protected function containsYieldInNodes(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node && $this->containsYieldInNode($node)) {
                return true;
            }
        }
        return false;
    }

    protected function containsYieldInNode(Node $node): bool
    {
        if ($node instanceof Yield_ || $node instanceof YieldFrom) {
            return true;
        }
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->{$name};
            if ($subNode instanceof Node) {
                if ($this->containsYieldInNode($subNode)) {
                    return true;
                }
            } elseif (is_array($subNode) && $this->containsYieldInNodes($subNode)) {
                return true;
            }
        }
        return false;
    }

    protected function prepareGeneratorFunction(Function_|ClassMethod $v, FunctionDef $functionDef): void
    {
        if ($this->isWasiTarget()) {
            $this->fatalError($v, 'Fiber and Generator are not supported by the WASI target');
        }
        if ($v->byRef) {
            $this->fatalError($v, 'Generators returning by reference are not supported yet');
        }
        foreach ($v->params as $param) {
            if ($param->byRef || $param->variadic) {
                $this->fatalError($param, 'Generators with by-reference or variadic parameters are not supported yet');
            }
        }
        if (!$this->generatorReturnTypeAcceptsFiber($v->returnType)) {
            $this->fatalError($v, 'Generator return type must accept \\FiberGenerator; use Iterator, Traversable, iterable, object, mixed, or omit the return type');
        }
        // Preserve the source-level declared return type before neutralizing the
        // runtime return type. The override compatibility check still needs it so
        // a generator method can satisfy an interface/abstract contract such as
        // `: \Generator` (the runtime object is a `\FiberGenerator`, not a Zend
        // `Generator`, so the C++ return type and runtime check stay neutral).
        if ($v->returnType !== null) {
            $declared = $this->buildTypeCheckFromNode($v->returnType);
            $functionDef->declaredReturnTypeCheck = $declared['check'] ?: null;
        }
        $functionDef->declaredReturnType = $functionDef->returnType;
        $functionDef->declaredReturnClass = $functionDef->returnClass;
        $functionDef->declaredReturnTypeStr = $functionDef->returnTypeStr;
        $functionDef->generator = true;
        $functionDef->returnType = Type::VAR;
        $functionDef->returnClass = '';
        $functionDef->returnTypeCheck = null;
        $functionDef->returnTypeStr = '';
        $functionDef->returnTypeNode = null;
    }

    protected function generatorReturnTypeAcceptsFiber(?Node $type): bool
    {
        if ($type === null) {
            return true;
        }
        if ($type instanceof NullableType) {
            return $this->generatorReturnTypeAcceptsFiber($type->type);
        }
        if ($type instanceof UnionType) {
            foreach ($type->types as $member) {
                if ($this->generatorReturnTypeAcceptsFiber($member)) {
                    return true;
                }
            }
            return false;
        }
        if ($type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                if (!$this->generatorReturnTypeAcceptsFiber($member)) {
                    return false;
                }
            }
            return true;
        }

        $typeName = strtolower($this->parseIdentifier($type));
        if (in_array($typeName, ['mixed', 'object', 'iterable'], true)) {
            return true;
        }

        [, $class] = $this->resolveTypeDecl($type, self::DECL_TYPE_OF_RETURN);
        $class = strtolower(ltrim($class, '\\'));
        // `\Generator` is the return type PHP programmers naturally write for a
        // generator. TypePHP generators actually return a `\FiberGenerator`, so
        // accepting the declared `Generator` type keeps PHP source compatible
        // while the runtime object remains a `\FiberGenerator`.
        return in_array($class, ['iterator', 'traversable', 'fibergenerator', 'generator'], true);
    }

    protected function parseYieldExpr(Yield_ $expr): string
    {
        if (!$this->inGeneratorBody) {
            $this->fatalError($expr, 'The `Expr_Yield` is not supported outside generator functions');
        }
        return 'typephp_fiber_suspend(' . $this->genYieldPayload($expr) . ', nullptr)';
    }

    protected function parseYieldStmt(Yield_ $expr): string
    {
        $payload = $this->genYieldPayload($expr);
        $closed = $this->genTmpVarName();
        $this->addLocalVar($closed, Type::BOOL);
        return $closed . ' = false;' . PHP_EOL
            . $this->getIndent() . $closed . ' = typephp_fiber_yield(' . $payload . ');' . PHP_EOL
            . $this->getIndent() . 'if (' . $closed . ') {' . PHP_EOL
            . $this->getIndent() . '    return ' . self::VALUE_NULL . ';' . PHP_EOL
            . $this->getIndent() . '}';
    }

    protected function parseYieldFromStmt(YieldFrom $expr): string
    {
        $closed = $this->genTmpVarName();
        $this->addLocalVar($closed, Type::BOOL);
        return $closed . ' = false;' . PHP_EOL
            . $this->getIndent() . 'typephp_fiber_yield_from(' . $this->genYieldFromIterable($expr) . ', &' . $closed . ');' . PHP_EOL
            . $this->getIndent() . 'if (' . $closed . ') {' . PHP_EOL
            . $this->getIndent() . '    return ' . self::VALUE_NULL . ';' . PHP_EOL
            . $this->getIndent() . '}';
    }

    protected function genYieldPayload(Yield_ $expr): string
    {
        if ($expr->key) {
            // PHP evaluates an explicit yield key before its value. Materialize
            // both operands so lowering helpers cannot hoist value side effects
            // ahead of the key or defer postfix side effects until after resume.
            $key = $this->materializeYieldOperand($expr->key, true);
            $value = $expr->value
                ? $this->materializeYieldOperand($expr->value, true)
                : self::VALUE_NULL;
            return 'php::Array(php::StdStrKeyMap{{"key", ' . $key . '}, {"value", ' . $value . '}, {"has_key", true}})';
        }
        $value = $expr->value
            ? $this->materializeYieldOperand($expr->value)
            : self::VALUE_NULL;
        return 'php::Array(php::StdStrKeyMap{{"value", ' . $value . '}, {"has_key", false}})';
    }

    private function materializeYieldOperand(Node $expr, bool $force = false): string
    {
        $this->assertImmutableObjectDoesNotEscape($expr, 'a yielded value');
        if ($this->isNativeObjectClass($this->detectClassOfExpr($expr))) {
            // Yield payloads are stored in a Zend array and cross the Fiber /
            // Generator object boundary. A Native pointer has no zval form.
            $this->fatalError($expr, 'Native objects cannot be yielded through a Zend Generator');
        }
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        foreach ($beforeStmts as $stmt) {
            $this->addBeforeStmtLine($stmt);
        }
        if (!$force && !$afterStmts) {
            return $value;
        }

        $tmpVar = $this->addTmpVar(Type::VAR);
        $this->addBeforeStmtLine($tmpVar . ' = ' . $value . ';');
        foreach ($afterStmts as $stmt) {
            $this->addBeforeStmtLine($stmt);
        }
        return $tmpVar;
    }

    private function genYieldFromIterable(YieldFrom $expr): string
    {
        return $this->materializeYieldOperand($expr->expr);
    }

    protected function parseYieldFromExpr(YieldFrom $expr): string
    {
        if (!$this->inGeneratorBody) {
            $this->fatalError($expr, 'The `Expr_YieldFrom` is not supported outside generator functions');
        }
        return 'typephp_fiber_yield_from(' . $this->genYieldFromIterable($expr) . ', nullptr)';
    }

    protected function genFiberGeneratorFunction(Function_|ClassMethod $v, FunctionDef $functionDef, string $nativeName): string
    {
        return $this->withFunctionContext(
            $this->context,
            fn() => $this->doGenFiberGeneratorFunction($v, $functionDef, $nativeName),
        );
    }

    private function doGenFiberGeneratorFunction(Function_|ClassMethod $v, FunctionDef $functionDef, string $nativeName): string
    {
        $functionDeclCode = $this->getFunctionOptimizationAttribute($functionDef)
            . Type::VAR . ' ' . self::PREFIX . $nativeName . '(';
        if ($this->class) {
            $functionDeclCode .= Type::OBJECT . ' &this_';
            if ($functionDef->params) {
                $functionDeclCode .= ', ';
            }
        }
        $functionDeclCode .= $functionDef->params . ')';

        $uses = [];
        foreach ($functionDef->argInfoList as $argInfo) {
            $uses[] = $argInfo->name;
        }

        $code = $functionDeclCode . ' {' . PHP_EOL;
        $this->indentLevel++;
        foreach ($functionDef->argInfoList as $i => $argInfo) {
            if (!empty($argInfo->typeCheck)) {
                $code .= $this->genUnionParamCheck($argInfo, $i);
            }
        }
        foreach ($functionDef->argInfoList as $argInfo) {
            if ($argInfo->property) {
                $code .= $this->getIndent() . $this->genPropertyPromotion($argInfo);
            }
        }

        $closureVar = $this->genTmpVarName();
        $code .= $this->getIndent() . 'php::ClosureFn ' . $closureVar . ' = []('
            . 'INTERNAL_FUNCTION_PARAMETERS, '
            . Type::OBJECT . ' &this_, '
            . Type::ARGS . ' &vars_) -> ' . Type::VAR . ' {' . PHP_EOL;

        $code .= $this->withFunctionContext(new FunctionContext(), function () use ($v, $functionDef): string {
            $code = '';
            $this->context->inClosure = true;
            $this->inGeneratorBody = true;
            $this->indentLevel++;

            $this->prepareReferenceCaptureDegradations($v->stmts);

            foreach ($functionDef->argInfoList as $i => $argInfo) {
                $code .= $this->getIndent() . Type::VAR . ' ' . $argInfo->name . ' = vars_.get(' . $i . ');' . PHP_EOL;
                $this->addArgument($argInfo->name, Type::VAR);
                $argumentClass = $argInfo->declaredClass ?: $argInfo->class;
                if ($argumentClass !== '') {
                    if ($argInfo->byRef && $argInfo->type === Type::REF) {
                        $this->context->declareObjectClass($argInfo->name, $argumentClass);
                    } else {
                        $this->addObject($argInfo->name, $argumentClass);
                    }
                }
            }
            if ($this->class) {
                $this->addArgument('this_', Type::OBJECT);
            }
            // The Fiber body has its own FunctionContext. Reapply compile-time
            // effect metadata so suspension does not erase Immutable guarantees.
            $this->initializeImmutableFunctionContext();

            $body = '';
            $this->indentLevel++;
            if ($v->stmts) {
                $body .= $this->parseStmts($v->stmts);
            }
            if ($this->context->nativeObjects !== []) {
                // TypePHP Generators are represented by Zend Closure/Fiber state.
                // Keep Native values out of that generated state even though the
                // runtime root registry itself now tolerates Fiber suspension.
                $this->fatalError(
                    $v,
                    'Generator functions cannot retain Native objects across suspension',
                );
            }
            $body .= $this->getIndent() . 'return ' . self::VALUE_NULL . ';' . PHP_EOL;
            if ($this->context->needsUserCodeCallableScope) {
                $body = $this->genUserCodeCallableScopeGuard() . $body;
            }
            $this->indentLevel--;
            $code .= $this->genScopeVarDecl();
            $code .= $this->getIndent() . 'try {' . PHP_EOL;
            $code .= $body;
            $code .= $this->getIndent() . '} catch (zend_object *) {' . PHP_EOL;
            $code .= $this->getIndent() . '    return ' . self::VALUE_NULL . ';' . PHP_EOL;
            $code .= $this->getIndent() . '}' . PHP_EOL;
            return $code;
        });

        $code .= $this->getIndent() . '};' . PHP_EOL;
        $args = $uses ? '{ ' . implode(', ', $uses) . ' }' : '{}';
        $closureExpr = $this->genNewClosure($closureVar, $args, $this->class !== '');
        $code .= $this->getIndent() . 'return typephp_new_fiber_generator(' . $closureExpr . ');' . PHP_EOL;
        $this->indentLevel--;
        $code .= '}' . PHP_EOL;

        return $code;
    }
}
