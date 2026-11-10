<?php
/**
 * This file is part of TypePHP.
 *
 * Maintains constant symbols and namespace use/group-use declarations.
 */

namespace TypePhp\Resolver;

use TypePhp\Type;
use TypePhp\Entity\GlobalConstantDef;

use PhpParser\Node;

trait DeclarationSymbolTrait
{
    protected function parseConstDef(mixed $v2): void
    {
        foreach ($v2->consts as $const) {
            $name  = $this->parseIdentifier($const->name);
            if ($this->namespace) {
                $name = $this->namespace . '\\' . $name;
            }
            // Declaration finalization owns whole-program initialization.
            // Re-parsing a dirty file must not replace that metadata with
            // temporaries allocated in the function-body context.
            if ($this->compilerPhase === self::PHASE_CONVERT
                && ($this->constants[$this->escapeConstVar($name)]->codegenFinalized ?? false)) {
                continue;
            }
            $this->addConstant($name, '', $const->value);
            if ($this->compilerPhase === self::PHASE_CONVERT) {
                $this->finalizeGlobalConstantValue($this->constants[$this->escapeConstVar($name)], $const->value);
            }
        }
    }

    protected function finalizeGlobalConstantValue(GlobalConstantDef $constant, Node\Expr $expression): void
    {
        $this->resetFunction();
        $constant->value = $this->parseIdentifier($expression);
        $constant->initializationCode = $this->context->localVars ? $this->genScopeVarDecl() : '';
        $constant->initializationCode .= $this->parseBeforeStmtLines();
        $constant->afterInitializationCode = $this->parseAfterStmtLines();
        // Clean files are not re-parsed by convert(): finalize their scalar
        // storage types here too, keeping cold/warm declarations identical.
        $constant->type = $this->getConstantScalarStorageType($expression);
        $constant->codegenFinalized = true;
    }

    protected function addConstant(string $name, string $value, ?Node\Expr $valueExpr = null): void
    {
        $constInfo = new GlobalConstantDef(
            name: $name,
            value: $value,
            valueExpr: $valueExpr,
            namespace: $this->namespace,
            sourceFile: $this->file,
            type: $valueExpr !== null ? $this->getConstantScalarStorageType($valueExpr) : Type::VAR,
            codegenFinalized: $this->compilerPhase === self::PHASE_CONVERT,
        );
        $this->constants[$this->escapeConstVar($name)] = $constInfo;
        $this->registerSymbolDeclaration($this->file, $this->getConstantDependencySymbol($name));
    }

    protected function hasConstant(string $name): bool
    {
        return isset($this->constants[$this->escapeConstVar($name)]);
    }

    protected function getConstant(string $name): string
    {
        return $this->escapeConstVar($name);
    }

    protected function getConstantType(string $name): string
    {
        return $this->constants[$this->escapeConstVar($name)]->type;
    }

    /** Infer only literal representations, without lowering declaration ASTs. */
    protected function detectLiteralConstantType(Node\Expr $expression): string
    {
        if ($expression instanceof Node\Scalar\Int_
            || $expression instanceof Node\Scalar\Float_
            || $expression instanceof Node\Scalar\String_) {
            $type = $this->detectTypeOfExpr($expression);
            return in_array($type, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR], true) ? $type : Type::VAR;
        }
        if ($expression instanceof Node\Expr\ConstFetch
            && in_array(strtolower($expression->name->toString()), ['true', 'false'], true)) {
            return Type::BOOL;
        }
        if ($expression instanceof Node\Expr\UnaryMinus || $expression instanceof Node\Expr\UnaryPlus) {
            $operand = $expression->expr;
            if ($operand instanceof Node\Scalar\Int_ || $operand instanceof Node\Scalar\Float_) {
                $type = $this->detectLiteralConstantType($operand);
                if ($type === Type::INT || $type === Type::FLOAT) {
                    return $type;
                }
            }
        }
        return Type::VAR;
    }

    /** A native slot is safe only when the emitted initializer has that ABI. */
    protected function getConstantScalarStorageType(Node\Expr $expression): string
    {
        $type = $this->getLoweredExpressionType($expression) ?? $this->detectLiteralConstantType($expression);
        return in_array($type, [Type::INT, Type::FLOAT, Type::BOOL, Type::STR], true) ? $type : Type::VAR;
    }

    protected function parseUse(Node\Stmt\Use_ $v2): void
    {
        foreach ($v2->uses as $use) {
            $id = $this->parseIdentifier($use->name);
            $type = $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $v2->type;
            $alias = $this->registerUseImportAlias($use, $type, $id);
            if ($type === Node\Stmt\Use_::TYPE_FUNCTION) {
                $this->useFunctions[strtolower($alias)] = $id;
            } elseif ($type === Node\Stmt\Use_::TYPE_CONSTANT) {
                // $id is already the fully qualified constant name. Splitting
                // and re-joining it on `\` corrupted single-segment imports:
                // `use const PHP_EOL;` resolved to `\HP_EOL` because
                // strrpos() returns false when there is no separator.
                $this->useConstants[$alias] = $id;
            } else {
                $idLower = strtolower($id);
                if ($idLower === 'native_types') {
                    $this->fatalError(
                        $use,
                        '`use native_types` has been removed; native scalar types are now the default',
                    );
                } elseif ($idLower === 'varint_types') {
                    $this->varIntTypes = true;
                } elseif ($idLower === 'decimal_types') {
                    $this->decimalTypes = true;
                } elseif ($idLower === 'bigint_types') {
                    $this->bigintTypes = true;
                } else {
                    if ($use->alias) {
                        // Class and namespace import aliases are case-insensitive.
                        // An explicit alias replaces the implicit short name; it
                        // must not also make the target's final segment available.
                        $this->useAliases[strtolower($alias)] = $id;
                    } else {
                        $this->useNamespaces[] = $id;
                    }
                }
            }
        }
    }

    private function registerUseImportAlias(Node\UseItem $use, int $type, string $id): string
    {
        $alias = $use->getAlias()->toString();
        // PHP class and function names are case-insensitive, while constant
        // aliases are case-sensitive. The three import kinds have independent
        // symbol tables, so the same alias may be used once in each domain.
        $key = $type === Node\Stmt\Use_::TYPE_CONSTANT ? $alias : strtolower($alias);
        if (isset($this->useImportAliases[$type][$key])) {
            $kind = match ($type) {
                Node\Stmt\Use_::TYPE_FUNCTION => 'function ',
                Node\Stmt\Use_::TYPE_CONSTANT => 'const ',
                default => '',
            };
            $this->fatalError(
                $use,
                "Cannot use {$kind}{$id} as {$alias} because the name is already in use",
            );
        }
        $this->useImportAliases[$type][$key] = $id;

        return $alias;
    }

    protected function parseGroupUse(Node\Stmt\GroupUse $node): void
    {
        $prefix = $node->prefix;
        $uses = [];
        foreach ($node->uses as $use) {
            $fullName = Node\Name::concat($prefix, $use->name);
            $uses[] = new Node\UseItem($fullName, $use->alias, $use->type, $use->getAttributes());
        }
        $syntheticUse = new Node\Stmt\Use_($uses, $node->type, $node->getAttributes());
        $this->parseUse($syntheticUse);
    }

}
