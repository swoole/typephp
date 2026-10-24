<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers throw and try/catch/finally control flow.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use TypePhp\Generator\Symbol;

trait ExceptionControlFlowTrait
{
    protected function parseThrow(mixed $expr): string
    {
        if ($this->method === '__destruct') {
            $this->warning($expr, "Throwing exception in {$this->getFullClassName()}::__destruct() may cause memory leak");
        }
        $class = $this->detectDeclaredClassOfExpr($expr->expr);
        if ($this->isNativeObjectClass($class)) {
            $this->fatalError($expr, 'Native objects cannot be thrown as Zend exceptions');
        }
        $type = $this->detectTypeOfExpr($expr->expr);
        // A method call with a class return declaration is represented by a
        // php::Variant on the dynamic path, but it is still statically known
        // to be an object. Let throwValue() preserve that runtime value and
        // perform Zend's ordinary Throwable validation.
        if ($type !== Type::VAR && $type !== Type::OBJECT && $class === '') {
            $this->fatalError($expr, 'Can only throw objects');
        }

        [$ex, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr->expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        if ($afterStmts) {
            // Evaluate and retain the exception before releasing its receiver
            // or other operand temporaries. Cleanup after throw is unreachable.
            $tmpVar = $this->addTmpVar(Type::VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $ex . ';';
            $this->appendCapturedStmtLinesToContext($afterStmts);
            return 'php::throwValue(php::takeValue(' . $tmpVar . '))';
        }
        if ($this->isNewExpr($expr->expr)
            || ($this->isVarExpr($expr->expr) && $type === Type::OBJECT)) {
            return 'php::throwException(' . $ex . ')';
        }
        return 'php::throwValue(' . $ex . ')';
    }

    protected function parseTryCatch(mixed $v): string
    {
        $code = $this->parseBeforeStmtLines();
        $code .= 'try {';
        $finally = $v->finally;
        $stmts = $finally ? $this->injectFinallyBeforeReturn($v->stmts, $finally->stmts) : $v->stmts;

        $catches = $v->catches;

        // Pre-register catch variables before parsing the try block so that
        // injected finally code referencing catch variables (e.g. $e) does not
        // trigger undefined-variable errors.
        if ($finally) {
            foreach ($catches as $catch) {
                if ($catch->var) {
                    $varName = $this->parseIdentifier($catch->var);
                    if (!$this->hasVar($varName) && $this->stmtListUsesVariable($finally->stmts, $varName)) {
                        $this->addLocalVar($varName, Type::OBJECT);
                    }
                }
            }
        }

        $code .= PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->getIndent() . '}';

        $exVar = $this->genTmpVarName();
        $this->addLocalVar($exVar, Type::VAR);

        $code .= ' catch (zend_object *_ex) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $exVar . ' = php::catchException();' . PHP_EOL;
        if ($catches) {
            $catchMatched = $this->genTmpVarName();
            $code .= $this->getIndent() . 'bool ' . $catchMatched . ' = false;' . PHP_EOL;
            foreach ($catches as $catch) {
                $code .= $this->parseCatch($catch, $exVar, $catchMatched, $finally?->stmts ?? []) . PHP_EOL;
            }
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        if ($finally) {
            $code .= $this->parseStmts($finally->stmts);
            $code .= PHP_EOL;
        }
        $rethrow = $this->inGeneratorBody
            ? 'typephp_fiber_rethrow(' . $exVar . ');'
            : 'php::throwException(php::Object(' . $exVar . '));';
        $code .= 'if (' . $exVar . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $rethrow . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function injectFinallyBeforeReturn(array $stmts, array $finallyStmts, int $localControlDepth = 0): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_) {
                if ($stmt->expr) {
                    $tmpVar = $this->addTmpVar(Type::VAR);
                    $result[] = new Node\Stmt\Expression(new Expr\Assign(new Variable($tmpVar), $stmt->expr));
                    array_push($result, ...$this->cloneStmtList($finallyStmts));
                    $result[] = new Node\Stmt\Return_(new Variable($tmpVar));
                    continue;
                }
                array_push($result, ...$this->cloneStmtList($finallyStmts));
                $result[] = $stmt;
                continue;
            }

            if ($stmt instanceof Node\Stmt\Break_ || $stmt instanceof Node\Stmt\Continue_) {
                $level = $stmt->num instanceof Node\Scalar\Int_ ? $stmt->num->value : 1;
                if ($level > $localControlDepth) {
                    array_push($result, ...$this->cloneStmtList($finallyStmts));
                }
                $result[] = $stmt;
                continue;
            }

            if ($stmt instanceof Node\Stmt\Goto_) {
                array_push($result, ...$this->cloneStmtList($finallyStmts));
                $result[] = $stmt;
                continue;
            }

            $result[] = $this->injectFinallyBeforeReturnInStmt($stmt, $finallyStmts, $localControlDepth);
        }
        return $result;
    }

    protected function injectFinallyBeforeReturnInStmt(Node\Stmt $stmt, array $finallyStmts, int $localControlDepth): Node\Stmt
    {
        if ($stmt instanceof Node\Stmt\If_) {
            $stmt = clone $stmt;
            $stmt->stmts = $this->injectFinallyBeforeReturn($stmt->stmts, $finallyStmts, $localControlDepth);
            foreach ($stmt->elseifs as $index => $elseIf) {
                $elseIf = clone $elseIf;
                $elseIf->stmts = $this->injectFinallyBeforeReturn($elseIf->stmts, $finallyStmts, $localControlDepth);
                $stmt->elseifs[$index] = $elseIf;
            }
            if ($stmt->else) {
                $stmt->else = clone $stmt->else;
                $stmt->else->stmts = $this->injectFinallyBeforeReturn($stmt->else->stmts, $finallyStmts, $localControlDepth);
            }
            return $stmt;
        }

        if ($stmt instanceof Node\Stmt\For_
            || $stmt instanceof Node\Stmt\Foreach_
            || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_
        ) {
            $stmt = clone $stmt;
            $stmt->stmts = $this->injectFinallyBeforeReturn($stmt->stmts, $finallyStmts, $localControlDepth + 1);
            return $stmt;
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            $stmt = clone $stmt;
            foreach ($stmt->cases as $index => $case) {
                $case = clone $case;
                $case->stmts = $this->injectFinallyBeforeReturn($case->stmts, $finallyStmts, $localControlDepth + 1);
                $stmt->cases[$index] = $case;
            }
            return $stmt;
        }

        return $stmt;
    }

    protected function cloneStmtList(array $stmts): array
    {
        return array_map(static fn (Node\Stmt $stmt): Node\Stmt => clone $stmt, $stmts);
    }

    protected function stmtListUsesVariable(array $stmts, string $name): bool
    {
        $nodeFinder = new NodeFinder();
        foreach ($nodeFinder->findInstanceOf($stmts, Variable::class) as $var) {
            if (is_string($var->name) && $this->escapeVarName($var->name) === $name) {
                return true;
            }
        }
        return false;
    }

    protected function parseCatch(Node\Stmt\Catch_ $catch, string $exVar, string $catchMatched, array $finallyStmts = []): string
    {
        $types = $catch->types;
        $var = $catch->var ? $this->parseIdentifier($catch->var) : '';
        if ($var !== '' && !$this->hasVar($var)) {
            $this->addLocalVar($var, Type::OBJECT);
        }

        $code = $this->parseBeforeStmtLines();
        $code .= $this->getIndent() . 'if (!' . $catchMatched . ' && ' . $exVar . ' && ';
        $conditions = [];
        foreach ($types as $type) {
            if ($this->isNameExpr($type) or $this->isFullNameExpr($type)) {
                $class = $this->getNamespacedClassName($this->parseIdentifier($type));
                $ce = $this->getClassEntryPtr($class);
                $conditions[] = Symbol::instanceOf() . '(' . $exVar . ', ' . $ce . ')';
            } else {
                $this->fatalError($type, 'Unsupported catch type');
            }
        }

        $code .= '(' . implode(' || ', $conditions) . ')) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . $catchMatched . ' = true;' . PHP_EOL;
        if ($var !== '') {
            $code .= $this->getIndent() . $var . ' = ' . $exVar . ';' . PHP_EOL;
        }
        $code .= $this->getIndent() . "{$exVar} = php::null;" . PHP_EOL;
        $stmts = $finallyStmts ? $this->injectFinallyBeforeReturn($catch->stmts, $finallyStmts) : $catch->stmts;
        if ($finallyStmts) {
            $code .= $this->getIndent() . 'try {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->parseStmts($stmts);
            $this->indentLevel--;
            $code .= $this->getIndent() . '} catch(zend_object *_catch_throw_ex) {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . "{$exVar} = php::catchException();" . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        } else {
            $code .= $this->parseStmts($stmts);
        }
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';

        return $code;
    }

}
