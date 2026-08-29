<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

use TypePhp\Type;

use TypePhp\Metadata\Constants;

trait Utils
{
    protected function genIntegerLiteral(int $value): string
    {
        if ($value === PHP_INT_MIN) {
            return 'ZEND_LONG_MIN';
        }
        if ($value === PHP_INT_MAX) {
            return 'ZEND_LONG_MAX';
        }
        return $value . $this->getPlatform()->getIntegerLiteralSuffix();
    }

    protected function genCValue(mixed $value): string
    {
        if (is_int($value)) {
            return $this->genIntegerLiteral($value);
        }
        if (is_float($value)) {
            // A string cast formats with the precision ini, which defaults to
            // 14 and would bake a truncated constant into the binary.
            return $this->genFloatLiteral($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_string($value)) {
            return $this->genCharPtr($value);
        } else {
            $this->error('Unsupported constant type: ' . gettype($value));
        }
    }

    public function genCharPtr(string $str, bool $escape = false): string
    {
        return '"' . ($escape ? $this->escapeString($str) : $str) . '"';
    }

    protected function genArray(array $elements): string
    {
        return Type::ARRAY . '{' . implode(', ', $elements) . ' }';
    }

    public function escapeString(string $str): string
    {
        $str = addcslashes($str, "\\\"\n\r\t\v\f\0\x01..\x1f\x7f..\xff");
        // C++ trigraph
        return str_replace('??', '\?\?', $str);
    }

    protected function escapeBool(bool $bool): string
    {
        return $bool ? 'true' : 'false';
    }

    protected function escapeAttrMode(bool $update): string
    {
        return $update ? 'php::AttrMode::Update' : 'php::AttrMode::Get';
    }

    protected function escapeVarName(string $name): string
    {
        if ($name === 'this') {
            return 'this_';
        }
        if (in_array($name, Constants::CPP_RESERVED_NAMES, true)) {
            return '_php__var__' . $name;
        }
        return $name;
    }

    protected function escapeStaticVar(string $name): string
    {
        $prefix = '';
        if ($this->namespace) {
            $prefix .= $this->escapeNamespace($this->namespace) . '_';
        }
        if ($this->class) {
            $prefix .= $this->escapeClass($this->class) . '_';
            if ($this->method) {
                $prefix .= $this->method . '_';
            }
        } else {
            if ($this->function) {
                $prefix .= $this->function . '_';
            }
        }
        return $prefix . $name;
    }

    protected function escapeGlobalVar(string $name): string
    {
        return self::GLOBAL_VAR . $name;
    }

    protected function escapeConstVar(string $name): string
    {
        return self::CONST_VAR . str_replace('\\', self::NAMESPACE_SEPARATOR, $name);
    }

    protected function escapeNamespace(string $ns): string
    {
        return str_replace('\\', self::NAMESPACE_SEPARATOR, strtolower($ns));
    }

    protected function escapeZendFnName(string $fn, bool $lower = true): string
    {
        return str_replace('\\', '_', $lower ? strtolower($fn) : $fn);
    }

    protected function escapeCeName(string $name): string
    {
        return $this->escapeZendFnName($name, false);
    }

    protected function escapeName(string $name): string
    {
        return strtolower($name);
    }

    protected function escapeClass(string $class): string
    {
        return str_replace('\\', '_', trim(strtolower($class), '\\'));
    }

    protected function escapeFunction(string $func): string
    {
        return $this->escapeClass($func);
    }

    protected function escapeFileName(string $file): string
    {
        return str_replace('-', '_', $file);
    }

    protected function unescapeVarName(string $name): string
    {
        return str_replace('_php__var__', '', $name);
    }

    protected function isClosedExpr($expr, $call): bool
    {
        if ($call === '') {
            if (!str_starts_with($expr, '(')) {
                return false;
            }
            $startPos = 0;
        } else {
            if (!str_starts_with($expr, $call . '(')) {
                return false;
            }
            $startPos = strlen($call);
        }

        $bracketCount = 0;
        $length       = strlen($expr);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $expr[$i];
            if ($char === '(') {
                $bracketCount++;
            } elseif ($char === ')') {
                $bracketCount--;
                if ($bracketCount === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    /**
     * Strip PHP numeric literal underscores (e.g. 1_000_000 → 1000000).
     */
    protected function stripNumericUnderscores(string $rawValue): string
    {
        return str_replace('_', '', $rawValue);
    }

    protected function getNamespaceOfClass(string $class): string
    {
        $lastPos = strrpos($class, '\\');
        if ($lastPos !== false) {
            $namespace = substr($class, 0, $lastPos);
        } else {
            $namespace = '';
        }
        return $namespace;
    }
}
