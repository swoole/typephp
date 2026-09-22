<?php
/**
 * This file is part of TypePHP.
 *
 * Generates C++ helpers for defaults that require runtime initialization.
 */

namespace TypePhp\Generator;

use TypePhp\Type;

use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ArrayInitPlan;
use TypePhp\Entity\FunctionDef;

trait DefaultArgumentGenerator
{
    protected function getDefaultArgumentType(ArgInfo $argInfo): string
    {
        $type = $argInfo->type;
        if ($type === Type::STREAM || $type === Type::BOX) {
            return Type::VAR;
        }
        return $type;
    }

    protected function getDefaultArgumentHelperType(ArgInfo $argInfo): string
    {
        return $argInfo->variadic ? Type::ARRAY : $this->getDefaultArgumentType($argInfo);
    }

    protected function getDefaultArgumentHelperName(string $nativeName, int $argumentIndex): string
    {
        return self::PREFIX . $nativeName . '_arg_' . $argumentIndex . '_default_value';
    }

    protected function genDefaultArgumentExpr(string $nativeName, int $argumentIndex): string
    {
        return $this->getDefaultArgumentHelperName($nativeName, $argumentIndex) . '()';
    }

    protected function wrapArrayInitPlan(ArrayInitPlan $plan, string $body): string
    {
        return "do {\n" . $plan->init . $body . $plan->clean . "} while (0);\n";
    }

    /** @param array<string, FunctionDef> $functions */
    protected function genDefaultArgumentHelperDeclarations(array $functions): string
    {
        $code = '';
        foreach ($functions as $nativeName => $func) {
            foreach ($func->argInfoList as $argumentIndex => $argInfo) {
                if (!$this->shouldGenerateDefaultArgumentHelper($argInfo)) {
                    continue;
                }

                $type = $this->getDefaultArgumentHelperType($argInfo);
                $helper = $this->getDefaultArgumentHelperName($nativeName, $argumentIndex);
                $code .= $this->getFunctionDeclarationPrefix($func) . $type . ' ' . $helper . '();' . PHP_EOL;
            }
        }

        return $code ? $code . PHP_EOL : '';
    }

    protected function genDefaultArgumentHelperDefinitions(): string
    {
        $code = '';
        foreach ($this->symbols->functions() as $nativeName => $func) {
            if ($this->isImportedFunction($func)) {
                continue;
            }
            foreach ($func->argInfoList as $argumentIndex => $argInfo) {
                if (!$this->shouldGenerateDefaultArgumentHelper($argInfo)) {
                    continue;
                }

                $type = $this->getDefaultArgumentHelperType($argInfo);
                $helper = $this->getDefaultArgumentHelperName($nativeName, $argumentIndex);
                $code .= $type . ' ' . $helper . "() {\n";

                $plan = $argInfo->arrayInitPlan;
                if ($plan && $plan->requiresRuntimeInit()) {
                    $code .= $plan->init;
                    if ($plan->clean) {
                        $code .= $type . ' retval = ' . $plan->expr . ';' . PHP_EOL;
                        $code .= $plan->clean;
                        $code .= 'return retval;' . PHP_EOL;
                    } else {
                        $code .= 'return ' . $plan->expr . ';' . PHP_EOL;
                    }
                } else {
                    $default = $this->convertRuntimeConstantDefault($type, $argInfo->default);
                    $code .= 'return ' . $default . ';' . PHP_EOL;
                }

                $code .= '}' . PHP_EOL . PHP_EOL;
            }
        }

        return $code;
    }

    /**
     * Runtime constant lookup returns Variant, but a typed default helper must
     * return its native C++ type explicitly. Convert the complete expression so
     * constants nested in expressions are covered as well.
     */
    private function convertRuntimeConstantDefault(string $type, string $default): string
    {
        if (!str_contains($default, 'php::constant(')) {
            return $default;
        }

        return match ($type) {
            Type::INT => 'php::toInt(' . $default . ')',
            Type::FLOAT => 'php::toFloat(' . $default . ')',
            Type::BOOL => 'php::toBool(' . $default . ')',
            Type::STR => 'php::toString(' . $default . ')',
            Type::ARRAY => 'php::toArray(' . $default . ')',
            Type::OBJECT => 'php::toObject(' . $default . ')',
            default => $default,
        };
    }

    private function shouldGenerateDefaultArgumentHelper(ArgInfo $argInfo): bool
    {
        if ($argInfo->variadic) {
            return true;
        }

        return $argInfo->hasDefaultValue();
    }
}
