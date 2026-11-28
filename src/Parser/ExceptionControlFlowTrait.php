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
use TypePhp\Generator\Symbol;
use TypePhp\Transform\FinallyLowering;

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
            $this->addBeforeStmtLine($tmpVar . ' = ' . $ex . ';');
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
        if ($v->finally !== null) {
            $lowering = new FinallyLowering(
                fn () => $this->genTmpVarName(),
                !$this->context->inClosure && ($this->functionDef?->returnsByRef ?? false),
            );
            return $this->parseStmts($lowering->lower($v, $this->context->breakableDepth));
        }
        $code = $this->parseBeforeStmtLines();
        $exVar = $this->genTmpVarName();
        $this->addLocalVar($exVar, Type::VAR);
        // A protected region may be re-entered after an enclosing catch
        // handles its exception. Do not rethrow a previous iteration's value.
        $code .= $exVar . ' = php::null;' . PHP_EOL . 'try {';
        $catches = $v->catches;

        $code .= PHP_EOL;
        $code .= $this->parseBlockStmts($v->stmts);
        $code .= $this->getIndent() . '}';

        $code .= ' catch (zend_object *_ex) {' . PHP_EOL;
        $code .= $this->withIndentedBlock(function () use ($exVar, $catches): string {
            $code = $this->getIndent() . $exVar . ' = php::catchException();' . PHP_EOL;
            if ($catches) {
                $catchMatched = $this->genTmpVarName();
                $code .= $this->getIndent() . 'bool ' . $catchMatched . ' = false;' . PHP_EOL;
                foreach ($catches as $catch) {
                    $code .= $this->parseCatch($catch, $exVar, $catchMatched) . PHP_EOL;
                }
            }
            return $code;
        });
        $code .= $this->getIndent() . '}' . PHP_EOL;

        $rethrow = $this->inGeneratorBody
            ? 'typephp_fiber_rethrow(' . $exVar . ');'
            : 'php::throwException(php::Object(' . $exVar . '));';
        $code .= 'if (' . $exVar . ') {' . PHP_EOL;
        $code .= $this->withIndentedBlock(fn() => $this->getIndent() . $rethrow . PHP_EOL);
        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseCatch(Node\Stmt\Catch_ $catch, string $exVar, string $catchMatched): string
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
                $conditions[] = Symbol::instanceOf_() . '(' . $exVar . ', ' . $ce . ')';
            } else {
                $this->fatalError($type, 'Unsupported catch type');
            }
        }

        $code .= '(' . implode(' || ', $conditions) . ')) {' . PHP_EOL;
        $code .= $this->withIndentedBlock(function () use ($catchMatched, $exVar, $var, $catch): string {
            $code = $this->getIndent() . $catchMatched . ' = true;' . PHP_EOL;
            if ($var !== '') {
                $code .= $this->getIndent() . $var . ' = ' . $exVar . ';' . PHP_EOL;
            }
            $code .= $this->getIndent() . "{$exVar} = php::null;" . PHP_EOL;
            $code .= $this->parseStmts($catch->stmts);
            return $code;
        });
        $code .= $this->getIndent() . '}';

        return $code;
    }

}
