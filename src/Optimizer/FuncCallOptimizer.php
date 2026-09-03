<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Optimizer;

use TypePhp\Type;

use TypePhp\Resolver\Reflection;
use PhpParser\Node;

/**
 * Config-driven optimizer for built-in function calls.
 *
 * Used as a trait by CompilerBase. All method calls are direct (no __call/reflection).
 */
trait FuncCallOptimizer
{
    protected const string ARG_TYPE_VAR = 'v';
    protected const string ARG_TYPE_STR = 's';
    protected const string ARG_TYPE_INT = 'i';
    protected const string ARG_TYPE_FLOAT = 'f';
    protected const string ARG_TYPE_BOOL = 'b';
    protected const string ARG_TYPE_REF = 'R';
    protected const string ARG_TYPE_ARRAY = 'A';
    protected const string ARG_OPTIONAL = '?';

    protected const int FOLD_STRING_LEN = 1;
    protected const int FOLD_STRING_CASE = 2;
    protected const int FOLD_CMP2 = 3;
    protected const int FOLD_CMP3 = 4;
    protected const int FOLD_COUNT_LITERAL = 5;
    protected const int FOLD_KNOWN_CLASS = 6;
    protected const int FOLD_KNOWN_CONSTANT = 7;
    protected const int FOLD_SSA_TYPE = 8;

    /** @var array<string,string|array>|null */
    protected ?array $_funcCallConfig = null;

    /** @var array<string,array> Cache for auto-detected arg reflection info */
    protected array $_autoArgTypes = [];

    // =========================================================================
    // Config
    // =========================================================================

    protected function getFuncCallConfig(): array
    {
        if ($this->_funcCallConfig !== null) {
            return $this->_funcCallConfig;
        }
        return $this->_funcCallConfig = $this->buildFuncCallConfig();
    }

    protected function buildFuncCallConfig(): array
    {
        $simple = [
            'method_exists', 'property_exists',
            'number_format',
            'implode',
            'array_key_first', 'array_key_last',
            'array_values',
            'version_compare', 'gettype',
            'is_array', 'is_string', 'is_object', 'is_resource',
            'is_scalar', 'is_numeric', 'is_countable', 'is_iterable',
            'array_is_list', 'is_dir', 'is_file', 'file_exists', 'realpath', 'time',
            'in_array', 'array_search',
            'date', 'strtotime', 'md5', 'sha1', 'hash', 'print_r',
            'base64_encode', 'base64_decode',
            'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
            'json_encode', 'json_decode', 'serialize', 'unserialize',
            'random_int', 'random_bytes', 'mt_rand', 'rand',
            'strstr', 'strrpos', 'is_a', 'is_subclass_of',
            'uniqid',
            'dirname', 'basename',
            // Math: trig, hyperbolic, exp/log, misc
            'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2',
            'sinh', 'cosh', 'tanh', 'asinh', 'acosh', 'atanh',
            'pi', 'exp', 'expm1', 'log', 'log10', 'log1p',
            'hypot', 'deg2rad', 'rad2deg', 'fmod', 'fdiv', 'fpow', 'intdiv',
            // Math: is_* checks
            'is_finite', 'is_infinite', 'is_nan',
            // Math: base conversion
            'decbin', 'decoct', 'dechex', 'bindec', 'hexdec', 'octdec', 'base_convert',
        ];

        $extra = [
            // Aliases (PHP function name → C++ target name)
            'join'             => 'implode',
            'stristr'          => 'stristr',

            'strlen'            => ['constFold' => self::FOLD_STRING_LEN],
            'ord'               => [],
            'ucfirst'           => [],
            'lcfirst'           => [],
            'strtolower'        => ['constFold' => self::FOLD_STRING_CASE],
            'strtoupper'        => ['constFold' => self::FOLD_STRING_CASE],
            'crc32'             => [],
            'chr'               => [],
            'strcmp'            => ['constFold' => self::FOLD_CMP2],
            'strcasecmp'        => ['constFold' => self::FOLD_CMP2],
            'str_starts_with'   => [],
            'str_ends_with'     => [],
            'str_contains'      => [],

            'strncmp'           => ['constFold' => self::FOLD_CMP3],
            'strncasecmp'       => ['constFold' => self::FOLD_CMP3],
            'explode'           => [],
            'strpos'            => [],
            'stripos'           => [],
            'substr'            => [],
            'str_repeat'        => [],
            'array_fill'        => [],

            // Variadic
            'array_merge'        => ['variadic' => true],

            // Compile-time fold with defaults
            'class_exists'       => ['constFold' => self::FOLD_KNOWN_CLASS, 'defaults' => [1 => 'true']],
            'interface_exists'   => ['defaults' => [1 => 'true']],
            'trait_exists'       => ['defaults' => [1 => 'true']],
            'enum_exists'        => ['defaults' => [1 => 'true']],
            'defined'            => ['constFold' => self::FOLD_KNOWN_CONSTANT],

            // Big* dispatch
            'abs' => [
                'bigDispatch' => [
                    Type::BIGINT => 'php::BigInt::abs',
                    Type::BIGFLOAT => 'php::BigFloat::abs',
                    Type::DECIMAL => 'php::Decimal::abs',
                    'fallback' => 'php::fn::abs',
                ],
                'fallbackArgTypes' => [[Type::INT, Type::FLOAT]],
            ],
            'pow' => ['bigDispatch' => [
                Type::BIGINT => 'php::BigInt::pow',
                Type::DECIMAL => 'php::Decimal::pow',
                'fallback' => 'php::fn::pow',
            ]],
            'sqrt' => [
                'bigDispatch' => [
                    Type::BIGINT => 'php::BigInt::sqrt',
                    Type::DECIMAL => 'php::Decimal::sqrt',
                    Type::BIGFLOAT => 'php::BigFloat::sqrt',
                    'fallback' => 'php::fn::sqrt',
                ],
                'fallbackArgTypes' => [[Type::INT, Type::FLOAT]],
            ],
            'floor' => [
                'bigDispatch' => [
                    Type::DECIMAL => 'php::Decimal::floor',
                    'fallback' => 'php::fn::floor',
                ],
                'fallbackArgTypes' => [[Type::INT, Type::FLOAT]],
            ],
            'ceil' => [
                'bigDispatch' => [
                    Type::DECIMAL => 'php::Decimal::ceil',
                    'fallback' => 'php::fn::ceil',
                ],
                'fallbackArgTypes' => [[Type::INT, Type::FLOAT]],
            ],

            // Type conversions
            'strval'   => ['conversion' => self::ARG_TYPE_STR],
            'intval'   => ['conversion' => self::ARG_TYPE_INT],
            'floatval' => ['conversion' => self::ARG_TYPE_FLOAT],
            'boolval'  => ['conversion' => self::ARG_TYPE_BOOL],

            // SSA compile-time type checks
            'is_int'    => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => Type::INT],
            'is_float'  => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => Type::FLOAT],
            'is_bool'   => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => Type::BOOL],

            // Custom handlers
            'is_null'            => ['handler' => 'genIsNull', 'nativeReceiver' => true],
            'get_class'          => ['handler' => 'genGetClassOptimized', 'nativeReceiver' => true],
            'get_parent_class'   => ['handler' => 'genGetParentClass', 'nativeReceiver' => true],
            'function_exists'    => ['handler' => 'genFunctionExists'],
            'func_get_arg'       => ['handler' => 'genFuncGetArg'],
            'func_get_args'      => ['handler' => 'genFuncGetArgs'],
            'func_num_args'      => ['handler' => 'genFuncNumArgs'],
            'compact'            => ['handler' => 'genCompact'],

            'array_keys'         => ['handler' => 'genArrayKeys'],
            'array_key_exists'   => ['handler' => 'genArrayKeyExists'],
            'round'              => ['handler' => 'genRound'],
            // count() is also a language-level Native operation when its
            // concrete receiver implements Countable.
            'count'              => ['handler' => 'genCount', 'nativeReceiver' => true],
            'define'             => ['handler' => 'genDefine'],
            'is_callable'        => ['handler' => 'genIsCallable'],
        ];

        $config = $extra;
        foreach ($simple as $name) {
            if (!isset($config[$name])) {
                $config[$name] = [];
            }
        }
        return $config;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    protected function parseFuncCallWithOptimizer(string $name, Node\Expr\FuncCall $expr): string|false
    {
        foreach ($expr->args as $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                return false;
            }
            // Custom handlers, big-type dispatch and scalar conversions work
            // with the syntactic argument list. Named arguments and unpacking
            // require Zend's runtime binding/expansion semantics, so reject
            // them before any optimizer-specific handler can consume them.
            if ($arg instanceof Node\Arg && ($arg->name !== null || $arg->unpack)) {
                return false;
            }
        }

        $config = $this->getFuncCallConfig()[$name] ?? null;
        if ($config === null) {
            return false;
        }

        // Optimized php::fn::* calls must obey the same ZendVM escape boundary
        // as the generic call generator. The four scalar conversions are
        // language-level Native keyword aliases and are lowered to an exact
        // Native method; every other PHP function rejects Native pointers.
        if (!isset($config['conversion'])) {
            foreach ($expr->args as $index => $arg) {
                if ($arg instanceof Node\Arg
                    && $this->isNativeObjectClass($this->detectClassOfExpr($arg->value))
                ) {
                    if (($config['nativeReceiver'] ?? false) && $index === 0) {
                        continue;
                    }
                    $this->fatalError(
                        $arg,
                        'Native objects cannot cross a dynamic PHP/ZendVM call boundary',
                    );
                }
            }
        }

        // Check whether the variables used in the arguments are defined; if a variable
        // does not exist, fall back to the dynamic call path, where parseCallArgs()
        // produces a clear error message.
        foreach ($expr->args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            if ($this->isVarExpr($arg->value) && is_string($arg->value->name) && !$this->hasVar($arg->value->name)) {
                return false;
            }
        }

        if (is_string($config)) {
            $targetName = $config;
            $config = ['target' => $targetName];
            $name = $targetName;
        }

        if (isset($config['handler'])) {
            return $this->{$config['handler']}($name, $expr, $config);
        }
        if (isset($config['bigDispatch'])) {
            return $this->dispatchBigType(
                $expr,
                $config['bigDispatch'],
                $config['fallbackArgTypes'] ?? [],
            );
        }
        if (isset($config['conversion'])) {
            return $this->dispatchConversion($expr, $config['conversion']);
        }

        return $this->dispatchFuncCall($name, $expr, $config);
    }

    // =========================================================================
    // Generic dispatcher
    // =========================================================================

    protected function dispatchFuncCall(string $name, Node\Expr\FuncCall $expr, array $config): string|false
    {
        // Named arguments and unpack (...) expansion require runtime handling; fall back to the dynamic call path.
        foreach ($expr->args as $arg) {
            if ($arg->name !== null || $arg->unpack) {
                return false;
            }
        }

        $target = $config['target'] ?? null;
        if ($target === null) {
            $target = 'php::fn::' . $name;
        } elseif (!str_starts_with($target, 'php::')) {
            $target = 'php::fn::' . $target;
        }

        $refInfo = $this->getArgReflectionInfo($name);
        $argTypeStr = $config['args'] ?? ($refInfo['args'] ?? '');
        $defaults = $config['defaults'] ?? [];
        $variadicType = $config['variadicType'] ?? ($refInfo['variadicType'] ?? '');
        $nullables = $refInfo['nullables'] ?? [];

        if (!$this->hasOptimizerSafeTypedArguments(
            $expr,
            $argTypeStr,
            $variadicType,
            $nullables,
        )) {
            return false;
        }

        if (!empty($config['variadic']) || ($refInfo['variadic'] ?? false)) {
            return $this->genVariadicCall($target, $expr, $variadicType);
        }

        if (isset($config['constFold'])) {
            $folded = $this->tryConstFold($config['constFold'], $config['constFoldExtra'] ?? null, $expr);
            if ($folded !== false) {
                return $folded;
            }
        }

        $args = $this->buildArgList($expr, $argTypeStr, $defaults, $nullables);
        return $target . '(' . implode(', ', $args) . ')';
    }

    protected function hasOptimizerSafeTypedArguments(
        Node\Expr\FuncCall $expr,
        string $argTypeStr,
        string $variadicType,
        array $nullables,
    ): bool
    {
        // The optimized ABI conversions are safe for exact types and for
        // strict PHP's int-to-float widening. Every other conversion would
        // erase the runtime zval type before Zend can validate the parameter,
        // so keep those calls on php::call().
        $types = $argTypeStr === '' ? [] : explode('_', $argTypeStr);
        foreach ($expr->args as $index => $arg) {
            // Custom handlers call this helper too. They cannot lower an
            // unpacked list as a fixed C++ ABI argument sequence.
            if ($arg->unpack) {
                return false;
            }
            $type = $types[$index] ?? $variadicType;
            $base = ($type[0] ?? '') === self::ARG_OPTIONAL ? substr($type, 1) : $type;
            if (!in_array($base, [
                self::ARG_TYPE_STR,
                self::ARG_TYPE_INT,
                self::ARG_TYPE_FLOAT,
                self::ARG_TYPE_BOOL,
                self::ARG_TYPE_ARRAY,
            ], true)) {
                continue;
            }
            if ($this->isNull($arg->value)) {
                if ($nullables[$index] ?? false) {
                    continue;
                }
                return false;
            }
            $expected = match ($base) {
                self::ARG_TYPE_STR => Type::STR,
                self::ARG_TYPE_INT => Type::INT,
                self::ARG_TYPE_FLOAT => Type::FLOAT,
                self::ARG_TYPE_BOOL => Type::BOOL,
                self::ARG_TYPE_ARRAY => Type::ARRAY,
            };
            $actual = $this->detectTypeOfExpr($arg->value);
            if ($actual === $expected) {
                continue;
            }
            if ($expected === Type::FLOAT && $actual === Type::INT) {
                continue;
            }
            return false;
        }

        return true;
    }

    protected function hasOptimizerSafeReflectedArguments(
        string $name,
        Node\Expr\FuncCall $expr,
        array $config,
    ): bool
    {
        $refInfo = $this->getArgReflectionInfo($name);
        return $this->hasOptimizerSafeTypedArguments(
            $expr,
            $config['args'] ?? ($refInfo['args'] ?? ''),
            $config['variadicType'] ?? ($refInfo['variadicType'] ?? ''),
            $refInfo['nullables'] ?? [],
        );
    }

    // =========================================================================
    // Auto-detect argument types from PHP reflection
    // =========================================================================

    protected function getArgReflectionInfo(string $funcName): array
    {
        if (isset($this->_autoArgTypes[$funcName])) {
            return $this->_autoArgTypes[$funcName];
        }

        $ref = Reflection::getFunction($funcName);
        if (!$ref) {
            return $this->_autoArgTypes[$funcName] = ['args' => '', 'variadic' => false, 'variadicType' => '', 'minArgs' => 0, 'maxArgs' => 0, 'nullables' => []];
        }

        $types = [];
        $nullables = [];
        $variadic = false;
        $variadicType = '';
        foreach ($ref->getParameters() as $param) {
            if ($param->isVariadic()) {
                $variadic = true;
                $variadicType = $this->phpParamToArgChar($param);
                continue;
            }
            $char = $this->phpParamToArgChar($param);
            if ($param->isOptional()) {
                $char = self::ARG_OPTIONAL . $char;
            }
            $types[] = $char;
            $nullables[] = $param->allowsNull();
        }

        return $this->_autoArgTypes[$funcName] = [
            'args' => implode('_', $types),
            'variadic' => $variadic,
            'variadicType' => $variadicType,
            'minArgs' => $ref->getNumberOfRequiredParameters(),
            'maxArgs' => $ref->getNumberOfParameters(),
            'nullables' => $nullables,
        ];
    }

    protected function phpParamToArgChar(\ReflectionParameter $param): string
    {
        if ($param->isPassedByReference()) {
            return self::ARG_TYPE_REF;
        }
        $type = $param->getType();
        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'string' => self::ARG_TYPE_STR,
                'int' => self::ARG_TYPE_INT,
                'float' => self::ARG_TYPE_FLOAT,
                'bool' => self::ARG_TYPE_BOOL,
                'array' => self::ARG_TYPE_ARRAY,
                default => self::ARG_TYPE_VAR,
            };
        }
        return self::ARG_TYPE_VAR;
    }

    // =========================================================================
    // Arg helpers
    // =========================================================================

    protected function getArg(Node\Expr\FuncCall $expr, int $i): string
    {
        $arg = $expr->args[$i]->value;
        if ($this->isVarExpr($arg) and $arg->name === 'GLOBALS') {
            return 'php::globalsArray()';
        }
        return $this->parseOrderedOperand($arg, false);
    }

    protected function getRefArg(Node\Expr\FuncCall $expr, int $i): string
    {
        $arg = $expr->args[$i]->value;
        if ($this->isArrayDimFetch($arg) and $this->isVarExpr($arg->var)) {
            $array = $this->parseIdentifier($arg->var);
            if ($arg->dim !== null) {
                $tmpRef = $this->genTmpVarName();
                $this->context->beforeStmtLines[] = 'auto&& ' . $tmpRef . ' = ' . $array . '.item(' . $this->identifierToStr($arg->dim) . ', true);';
                return $tmpRef;
            }
        }
        if ($this->isPropertyFetch($arg) and $this->isVarExpr($arg->var)) {
            $obj = $this->parseIdentifier($arg->var);
            $tmpRef = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = 'auto&& ' . $tmpRef . ' = ' . $obj . '.attr(' . $this->propertyNameToStr($arg->name) . ', php::AttrMode::Update);';
            return $tmpRef;
        }
        return $this->getArg($expr, $i);
    }

    protected function resolveArg(Node\Expr\FuncCall $expr, int $index, string $type): string
    {
        $base = ($type[0] ?? '') === self::ARG_OPTIONAL ? substr($type, 1) : $type;
        $arg = $expr->args[$index]->value;
        $raw = ($base === self::ARG_TYPE_REF) ? $this->getRefArg($expr, $index) : $this->getArg($expr, $index);

        if ($base === self::ARG_TYPE_ARRAY) {
            if ($this->argumentAlreadyHasExactType($arg, Type::ARRAY)) {
                return $raw;
            }
            return $this->convertStdContainerArrayExpr($expr, $index, $raw);
        }

        $exactType = match ($base) {
            self::ARG_TYPE_STR => Type::STR,
            self::ARG_TYPE_INT => Type::INT,
            self::ARG_TYPE_FLOAT => Type::FLOAT,
            self::ARG_TYPE_BOOL => Type::BOOL,
            default => null,
        };
        if ($exactType !== null && $this->argumentAlreadyHasExactType($arg, $exactType)) {
            return $raw;
        }

        return $this->applyArgConversion($raw, $type);
    }

    protected function argumentAlreadyHasExactType(Node\Expr $arg, string $type): bool
    {
        if ($this->isVarExpr($arg)) {
            return !$this->isStdContainer($arg->name)
                && $this->hasVar($arg->name)
                && $this->getVarType($arg->name) === $type;
        }

        // Semantic type information is not enough here: a dynamically read
        // typed property, for example, is still represented by php::Variant.
        // Limit the shortcut to expressions whose generated C++ value has a
        // fixed representation without an implicit PHPX conversion.
        $fixedRepresentation = $arg instanceof Node\Scalar
            || $arg instanceof Node\Expr\Cast
            || $arg instanceof Node\Expr\Array_
            || $arg instanceof Node\Expr\BinaryOp\Concat
            || $arg instanceof Node\Expr\ConstFetch;

        return $fixedRepresentation && $this->detectTypeOfExpr($arg) === $type;
    }

    protected function applyArgConversion(string $cxxExpr, string $type): string
    {
        $base = ($type[0] ?? '') === self::ARG_OPTIONAL ? substr($type, 1) : $type;

        return match ($base) {
            self::ARG_TYPE_STR => $this->convertStringExpr($cxxExpr),
            self::ARG_TYPE_INT => $this->convertIntExpr($cxxExpr),
            self::ARG_TYPE_FLOAT => $this->convertFloatExpr($cxxExpr),
            self::ARG_TYPE_BOOL => $this->convertBoolExpr($cxxExpr),
            self::ARG_TYPE_ARRAY => $this->convertArrayExpr($cxxExpr),
            default => $cxxExpr,
        };
    }

    protected function convertStdContainerArrayExpr(Node\Expr\FuncCall $expr, int $index, string $raw): string
    {
        $arg = $expr->args[$index]->value;
        if ($this->isVarExpr($arg) and $this->isStdContainer($arg->name)) {
            return $this->convertArrayExpr($raw . '_ref');
        }
        return $this->convertArrayExpr($raw);
    }

    protected function buildArgList(Node\Expr\FuncCall $expr, string $argTypeStr, array $defaults = [], array $nullables = []): array
    {
        if ($argTypeStr === '') {
            return [];
        }

        $types = explode('_', $argTypeStr);
        $argCount = count($expr->args);
        $args = [];

        foreach ($types as $i => $type) {
            $optional = ($type[0] ?? '') === self::ARG_OPTIONAL;
            $nullable = $nullables[$i] ?? false;

            // Missing optional arg — use configured default or skip (C++ default handles it)
            if ($optional && $argCount <= $i) {
                if (isset($defaults[$i])) {
                    $args[] = $defaults[$i];
                }
                continue;
            }

            // Nullable param — pass raw Variant; C++ function checks isNull() at runtime
            if ($nullable) {
                $args[] = $this->getArg($expr, $i);
                continue;
            }

            $args[] = $this->resolveArg($expr, $i, $type);
        }

        return $args;
    }

    // =========================================================================
    // Variadic, conversion, Big* dispatch
    // =========================================================================

    protected function genVariadicCall(string $target, Node\Expr\FuncCall $expr, string $variadicType = ''): string
    {
        $base = ($variadicType !== '' && ($variadicType[0] ?? '') === self::ARG_OPTIONAL) ? substr($variadicType, 1) : $variadicType;
        $args = [];
        foreach ($expr->args as $index => $arg) {
            $raw = $this->parseOrderedOperand($arg->value, false);
            $args[] = match ($base) {
                self::ARG_TYPE_STR => $this->convertStringExpr($raw),
                self::ARG_TYPE_INT => $this->convertIntExpr($raw),
                self::ARG_TYPE_FLOAT => $this->convertFloatExpr($raw),
                self::ARG_TYPE_BOOL => $this->convertBoolExpr($raw),
                self::ARG_TYPE_ARRAY => $this->convertStdContainerArrayExpr($expr, $index, $raw),
                default => $raw,
            };
        }
        return $target . '(' . implode(', ', $args) . ')';
    }

    protected function dispatchConversion(Node\Expr\FuncCall $expr, string $convType): string|false
    {
        // These four are lowered as single-argument Native casts, which cannot
        // carry intval()'s $base. Any other arity must reach the runtime
        // function instead of silently dropping the extra argument.
        //
        // An unpacked or named argument is a single Node\Arg whatever its
        // runtime arity turns out to be, so neither may be read as the value
        // being converted; both stay on the dynamic path like dispatchFuncCall()
        // already does for every other builtin.
        if (count($expr->args) !== 1
            || !($expr->args[0] instanceof Node\Arg)
            || $expr->args[0]->unpack
            || $expr->args[0]->name !== null
        ) {
            return false;
        }
        $arg = $expr->args[0]->value;
        $type = $this->detectTypeOfExpr($arg);
        $nativeClass = $this->detectClassOfExpr($arg);
        if ($this->isNativeObjectClass($nativeClass)) {
            $method = match ($convType) {
                self::ARG_TYPE_STR => 'toString',
                self::ARG_TYPE_INT => 'toInt',
                self::ARG_TYPE_FLOAT => 'toFloat',
                self::ARG_TYPE_BOOL => 'toBool',
                default => null,
            };
            if ($method !== null) {
                return $this->parseMethodCall(new Node\Expr\MethodCall(
                    $arg,
                    new Node\Identifier($method),
                ));
            }
        }
        $parsed = $this->parseExpr($arg);

        if ($convType === self::ARG_TYPE_STR) {
            return match ($type) {
                Type::BIGINT => 'php::BigInt::toString(' . $parsed . ')',
                Type::BIGFLOAT => 'php::BigFloat::toString(' . $parsed . ')',
                Type::DECIMAL => 'php::Decimal::toString(' . $parsed . ')',
                default => $this->convertStringExpr($parsed),
            };
        }

        return match ($convType) {
            self::ARG_TYPE_INT => $this->convertIntExpr($parsed, $type),
            self::ARG_TYPE_FLOAT => $this->convertFloatExpr($parsed, $type),
            self::ARG_TYPE_BOOL => $this->convertBoolExpr($parsed, $type),
            default => $parsed,
        };
    }

    protected function dispatchBigType(
        Node\Expr\FuncCall $expr,
        array $dispatch,
        array $fallbackArgTypes = [],
    ): string|false
    {
        $type = $this->detectTypeOfExpr($expr->args[0]->value);
        $target = $dispatch[$type] ?? null;
        if ($target === null) {
            foreach ($fallbackArgTypes as $index => $acceptedTypes) {
                $arg = $expr->args[$index] ?? null;
                if (!$arg instanceof Node\Arg
                    || !in_array($this->detectTypeOfExpr($arg->value), $acceptedTypes, true)
                ) {
                    return false;
                }
            }
            $target = $dispatch['fallback'] ?? null;
        }
        if (!$target) {
            return false;
        }

        $args = [$this->parseOrderedOperand($expr->args[0]->value, false)];
        if (count($expr->args) >= 2) {
            $args[] = $this->parseOrderedOperand($expr->args[1]->value, false);
        }

        return $target . '(' . implode(', ', $args) . ')';
    }

    // =========================================================================
    // Constant folding
    // =========================================================================

    protected function tryConstFold(int $rule, mixed $extra, Node\Expr\FuncCall $expr): string|false
    {
        return match ($rule) {
            self::FOLD_STRING_LEN => $this->doFoldStringLen($expr),
            self::FOLD_STRING_CASE => $this->doFoldStringCase($expr),
            self::FOLD_CMP2 => $this->doFoldCmp2($expr),
            self::FOLD_CMP3 => $this->doFoldCmp3($expr),
            self::FOLD_COUNT_LITERAL => $this->doFoldCountLiteral($expr),
            self::FOLD_KNOWN_CLASS => $this->doFoldKnownClass($expr),
            self::FOLD_KNOWN_CONSTANT => $this->doFoldKnownConstant($expr),
            self::FOLD_SSA_TYPE => $this->doFoldSsaType($expr, $extra),
            default => false,
        };
    }

    protected function doFoldStringLen(Node\Expr\FuncCall $expr): string|false
    {
        $arg = $expr->args[0]->value;
        return ($arg instanceof Node\Scalar\String_)
            ? strlen($arg->value) . $this->getPlatform()->getIntegerLiteralSuffix()
            : false;
    }

    protected function doFoldStringCase(Node\Expr\FuncCall $expr): string|false
    {
        $arg = $expr->args[0]->value;
        if (!$this->isScalarString($arg)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $val = $func === 'strtoupper' ? strtoupper($arg->value) : strtolower($arg->value);
        return $this->getLiteralString($val);
    }

    protected function doFoldCmp2(Node\Expr\FuncCall $expr): string|false
    {
        $a0 = $expr->args[0]->value;
        $a1 = $expr->args[1]->value;
        if (!$this->isScalarString($a0) || !$this->isScalarString($a1)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $result = $func === 'strcasecmp'
            ? strcasecmp($a0->value, $a1->value)
            : strcmp($a0->value, $a1->value);
        return $result . $this->getPlatform()->getIntegerLiteralSuffix();
    }

    protected function doFoldCmp3(Node\Expr\FuncCall $expr): string|false
    {
        $a0 = $expr->args[0]->value;
        $a1 = $expr->args[1]->value;
        $a2 = $expr->args[2]->value;
        if (!$this->isScalarString($a0) || !$this->isScalarString($a1) || !$this->isScalarInt($a2)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $result = $func === 'strncasecmp'
            ? strncasecmp($a0->value, $a1->value, (int) $a2->value)
            : strncmp($a0->value, $a1->value, (int) $a2->value);
        return $result . $this->getPlatform()->getIntegerLiteralSuffix();
    }

    protected function doFoldCountLiteral(Node\Expr\FuncCall $expr): string|false
    {
        if (count($expr->args) !== 1 || !($expr->args[0] instanceof Node\Arg)) {
            return false;
        }
        $arg = $expr->args[0]->value;
        if ($arg instanceof Node\Expr\Array_) {
            if (!$this->isCountFoldableArray($arg)) {
                return false;
            }
            return count($arg->items) . $this->getPlatform()->getIntegerLiteralSuffix();
        }
        return $this->genStdContainerCount($arg);
    }

    /**
     * The number of AST items only equals the runtime element count when no
     * item spreads another array, no key can collide with another key, and
     * dropping the element expressions cannot lose an observable effect.
     * Anything else keeps the runtime php::fn::count() call.
     */
    protected function isCountFoldableArray(Node\Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            // [...$other] contributes an element count only known at runtime,
            // a key may collapse onto an earlier one (['a' => 1, 'a' => 2]
            // counts as one element, not two), and a by-reference item binds
            // its source variable instead of reading it.
            if ($item->unpack || $item->key !== null || $item->byRef) {
                return false;
            }
            if (!$this->isCountFoldableItem($item->value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Only expressions whose evaluation is provably free of observable effects
     * may be discarded. Variables, general constant and class constant
     * fetches, interpolated strings and every other expression stay on the
     * runtime path: they can be undefined, autoload, throw or call __get().
     */
    protected function isCountFoldableItem(Node\Expr $value): bool
    {
        // Node\Scalar\String_ is the literal string only; an interpolated
        // string is a distinct Node\Scalar\InterpolatedString node.
        if ($value instanceof Node\Scalar\Int_
            || $value instanceof Node\Scalar\Float_
            || $value instanceof Node\Scalar\String_
        ) {
            return true;
        }
        // The language constants only. Any other name may be undefined and
        // must still raise the same Error PHP raises.
        if ($value instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true);
        }
        if ($value instanceof Node\Expr\UnaryMinus || $value instanceof Node\Expr\UnaryPlus) {
            return $value->expr instanceof Node\Scalar\Int_ || $value->expr instanceof Node\Scalar\Float_;
        }
        if ($value instanceof Node\Expr\Array_) {
            return $this->isCountFoldableArray($value);
        }
        return false;
    }

    protected function doFoldKnownClass(Node\Expr\FuncCall $expr): string|false
    {
        // An explicit $autoload argument must still be evaluated, including
        // any side effects or exception it produces. Leave that form on the
        // normal call path instead of duplicating argument semantics here.
        if (count($expr->args) !== 1 || !($expr->args[0] instanceof Node\Arg)) {
            return false;
        }
        $cn = $expr->args[0]->value;
        if (!$this->isScalarString($cn) || !$this->hasClass($cn->value)) {
            return false;
        }
        // The class table also carries traits, but a trait is not a class to
        // class_exists(): PHP answers false for it and true for an enum.
        if ($this->getClassDef($cn->value)?->trait !== null) {
            return 'false';
        }
        return $this->isNativeObjectClass($cn->value) ? 'false' : 'true';
    }

    protected function doFoldKnownConstant(Node\Expr\FuncCall $expr): string|false
    {
        $cn = $expr->args[0]->value;
        return ($this->isScalarString($cn) && $this->hasConstant($cn->value)) ? 'true' : false;
    }

    protected function doFoldSsaType(Node\Expr\FuncCall $expr, mixed $expectType): string|false
    {
        if (count($expr->args) !== 1 || !($expr->args[0] instanceof Node\Arg)) {
            return false;
        }
        $value = $expr->args[0]->value;
        if ($this->detectTypeOfExpr($value) !== $expectType) {
            return false;
        }
        if ($value instanceof Node\Expr\Variable || $value instanceof Node\Scalar) {
            return 'true';
        }
        // The argument can carry side effects (a call, an increment). Keep
        // evaluating it, as genIsNull does for native scalar operands.
        return '((void) (' . $this->parseExprAsValue($value) . '), true)';
    }

    // =========================================================================
    // Custom handlers
    // =========================================================================

    protected function genIsNull(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $value = $e->args[0]->value;
        if ($this->isNativeObjectClass($this->detectClassOfExpr($value))) {
            return '(' . $this->parseExprAsValue($value) . ' == nullptr)';
        }

        $type = $this->detectTypeOfExpr($value);
        $valueCode = $this->parseExprAsValue($value);
        if ($this->isNativeType($type)) {
            // Fixed native scalars cannot contain null. Keep evaluating the
            // operand because a call or increment may still have side effects.
            return '((void) (' . $valueCode . '), false)';
        }

        return '(' . $valueCode . ').isNull()';
    }

    protected function genIsCallable(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        if (count($e->args) >= 3) {
            return false;
        }
        return $this->dispatchFuncCall('is_callable', $e, ['target' => 'php::fn::is_callable']);
    }

    protected function genGetClassOptimized(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        if ($e->args === []) {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $e,
                    'Native classes do not support runtime class introspection; use `self::class` or a concrete class name',
                );
            }
            return false;
        }
        $obj = $e->args[0]->value;
        if ($this->isNativeObjectClass($this->detectClassOfExpr($obj))) {
            $this->fatalError(
                $e,
                'Native classes do not support runtime class introspection; use `NativeClass::class`',
            );
        }
        if ($this->isVarExpr($obj) && $this->isStableObject($obj->name)) {
            return $this->getLiteralString($this->getObjectType($obj->name));
        }
        return 'php::fn::get_class(' . $this->parseIdentifier($obj) . ')';
    }

    protected function genGetParentClass(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        if (count($e->args) === 0) {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $e,
                    'Native classes do not support runtime class introspection; use `parent::class` or a concrete class name',
                );
            }
            if ($this->classDef && $this->classDef->extends) {
                return $this->getLiteralString($this->classDef->extends);
            }
            return 'false';
        }
        $arg = $e->args[0]->value;
        if ($this->isNativeObjectClass($this->detectClassOfExpr($arg))) {
            $this->fatalError(
                $e,
                'Native classes do not support runtime class introspection; use a concrete class name',
            );
        }
        if ($this->isScalarString($arg)) {
            $cls = $this->getClassDef($arg->value);
            if ($cls && $cls->extends) return $this->getLiteralString($cls->extends);
            if ($cls && !$cls->extends) return 'false';
        }
        return 'php::fn::get_parent_class(' . $this->parseIdentifier($arg) . ')';
    }

    protected function genArrayKeys(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        if (!$this->hasOptimizerSafeReflectedArguments($n, $e, $c)) {
            return false;
        }
        $cnt = count($e->args);
        if ($cnt >= 3) {
            if ($this->detectTypeOfExpr($e->args[2]->value) !== Type::BOOL) {
                return false;
            }
            return 'php::fn::array_keys_filter(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', '
                . $this->resolveArg($e, 2, self::ARG_TYPE_BOOL) . ')';
        }
        if ($cnt >= 2) {
            return 'php::fn::array_keys_filter(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', false)';
        }
        return 'php::fn::array_keys(' . $this->getArg($e, 0) . ')';
    }

    protected function genArrayKeyExists(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        if (!$this->hasOptimizerSafeReflectedArguments($n, $e, $c)) {
            return false;
        }
        // The C++ receiver is PHP's second argument, but PHP still evaluates
        // the key first. Resolve both in source order before rearranging them.
        $key = $this->getArg($e, 0);
        $array = $this->getArg($e, 1);
        return $array . '.offsetExists(' . $key . ')';
    }

    protected function genRound(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        // An unpacked or named argument is a single Node\Arg whatever its
        // runtime arity turns out to be, so the syntactic count below cannot
        // stand in for the real one and no position may be read directly.
        foreach ($e->args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name !== null) {
                return false;
            }
        }
        $args = count($e->args);
        if ($args >= 3) {
            // Neither optimized implementation preserves the full contract of
            // an explicit mode. The PHPX wrapper bypasses Zend's validation,
            // while Decimal::round() has no mode parameter at all.
            return false;
        }
        $type = $this->detectTypeOfExpr($e->args[0]->value);
        if ($type === Type::DECIMAL) {
            $a0 = $this->parseExpr($e->args[0]->value);
            if ($args >= 2) {
                return 'php::Decimal::round(' . $a0 . ', ' . $this->parseExpr($e->args[1]->value) . ')';
            }
            return 'php::Decimal::round(' . $a0 . ')';
        }
        // Reflection reports int|float as a union, which is represented by a
        // raw Variant in the generic ABI metadata. The direct round() wrapper
        // accepts that Variant and performs its own numeric conversion, so it
        // is only strict-compatible when the source type is already proven.
        if (!in_array($type, [Type::INT, Type::FLOAT], true)) {
            return false;
        }
        if (!$this->hasOptimizerSafeReflectedArguments($n, $e, $c)) {
            return false;
        }
        if ($args >= 2) {
            return 'php::fn::round(' . $this->getArg($e, 0) . ', ' . $this->convertIntExpr($this->getArg($e, 1)) . ')';
        }
        return 'php::fn::round(' . $this->getArg($e, 0) . ')';
    }

    protected function genCount(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        $receiver = $e->args[0] ?? null;
        $nativeClass = $receiver instanceof Node\Arg
            ? $this->detectClassOfExpr($receiver->value)
            : '';
        if ($this->isNativeObjectClass($nativeClass)) {
            if (count($e->args) !== 1) {
                $this->fatalError($e, 'count() accepts exactly one argument for native objects');
            }
            if (!$this->isObjectClassStaticallyAssignableTo($nativeClass, 'Countable')) {
                $this->fatalError($e, 'count() requires a native class implementing Countable');
            }

            // Native objects have no zend_class_entry/count_elements handler.
            // Countable gives us an exact compile-time target, so lower this
            // directly and retain the same zero-cost call path as $obj->count().
            return $this->parseMethodCall(new Node\Expr\MethodCall(
                $receiver->value,
                new Node\Identifier('count'),
                [],
                $e->getAttributes(),
            ));
        }

        if (!$this->hasOptimizerSafeReflectedArguments($n, $e, $c)) {
            return false;
        }

        $folded = $this->doFoldCountLiteral($e);
        if ($folded !== false) return $folded;
        if (count($e->args) >= 2) {
            return 'php::fn::count(' . $this->getArg($e, 0) . ', ' . $this->convertIntExpr($this->getArg($e, 1)) . ')';
        }
        return 'php::fn::count(' . $this->getArg($e, 0) . ')';
    }

    protected function genDefine(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        if (!$this->hasOptimizerSafeReflectedArguments($n, $e, $c)) {
            return false;
        }
        $arg = $e->args[0]->value;
        if ($this->isScalarString($arg) && str_contains($arg->value, '::')) {
            $this->fatalError($e, 'Invalid define name `' . $arg->value . '`');
        }
        $args = count($e->args) >= 3 ? 3 : 2;
        if ($args == 3) {
            return 'php::fn::define(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', ' . $this->getArg($e, 2) . ')';
        }
        return 'php::fn::define(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ')';
    }

    protected function genFuncGetArgs(string $name, Node\Expr\FuncCall $expr, array $config): string
    {
        $this->warningUndefinedBehavior($expr);
        $funcDef = $this->functionDef;
        $list = [];
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                $tmpVar = $this->addTmpVar(Type::ARRAY);
                $this->context->beforeStmtLines[] = $this->genArray($list) . ';';
                $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $argInfo->name . ');';
                return $tmpVar;
            }
            $list[] = $argInfo->name;
        }
        return $this->genArray($list);
    }

    protected function genFuncGetArg(string $name, Node\Expr\FuncCall $expr, array $config): string
    {
        $this->warningUndefinedBehavior($expr);
        $position = $expr->args[0]->value;
        if ($this->isScalarInt($position)) {
            $funcDef = $this->functionDef;
            $posInt = intval($position->value);
            foreach ($funcDef->argInfoList as $i => $argInfo) {
                if ($argInfo->variadic) {
                    return $argInfo->name . '.offsetGet(' . ($posInt - $i) . ')';
                }
                if ($i == $posInt) {
                    return $argInfo->name;
                }
            }
            $this->fatalError($expr, 'wrong parameter position `' . $posInt . '`');
        } else {
            $this->fatalError($expr, 'func_get_arg() only support scalar int');
        }
    }

    protected function genFuncNumArgs(string $name, Node\Expr\FuncCall $expr, array $config): string
    {
        $this->warningUndefinedBehavior($expr);
        $funcDef = $this->functionDef;
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                // Array::count() is size_t, while func_num_args() is a PHP
                // integer. Keep the folded expression in the exact native
                // type so a surrounding toInt() cannot hit ambiguous C++
                // scalar overloads.
                return '(static_cast<' . Type::INT . '>(' . $argInfo->name . '.count()) + '
                    . $this->genIntegerLiteral($i) . ')';
            }
        }
        return $this->genIntegerLiteral(count($funcDef->argInfoList));
    }

    protected function genFunctionExists(string $name, Node\Expr\FuncCall $expr, array $config): string|false
    {
        if (!$this->hasOptimizerSafeReflectedArguments($name, $expr, $config)) {
            return false;
        }
        $funcName = $expr->args[0]->value;
        if ($this->isScalarString($funcName)) {
            $nameLower = strtolower(trim($funcName->value, '\\'));
            $nativeFunction = $this->findNativeFunction($nameLower);
            if ($nativeFunction) {
                // A function whose ABI contains Native pointers is callable
                // only from generated TypePHP C++. It has no Zend wrapper and
                // therefore must remain invisible to function_exists().
                return $this->functionUsesNativeObject($this->getFunction($nativeFunction))
                    ? 'false'
                    : 'true';
            }
            $funcName = $this->getLiteralString($nameLower);
            return 'php::fn::function_exists(' . $funcName . ')';
        }
        return 'php::fn::function_exists(' . $this->parseIdentifier($funcName) . ')';
    }

    protected function genCompact(string $name, Node\Expr\FuncCall $expr, array $config): string
    {
        $list = [];

        foreach ($expr->args as $arg) {
            if (!$this->isScalarString($arg->value)) {
                $this->fatalError($expr, 'The argument of compact function can only be literal string');
            }
            $var = $arg->value->value;
            if (!$this->hasVar($var) && $var !== 'this') {
                $this->fatalError($arg->value, "Undefined variable `{$var}` in compact()");
            }
            if ($this->isSuperGlobal($var)) {
                $this->fatalError($expr, 'Cannot use super global variable `' . $var . '` in compact function');
            }

            $key = $this->getLiteralString($var);
            if ($var === 'this') {
                if (empty($this->class)) {
                    $this->fatalError($expr, 'Cannot use compact("this") outside of class method');
                }
            }
            $cVar = $this->escapeVarName($var);
            $list[] = '{' . $key . '.str(), php::Var(' . $cVar . ')}';
        }

        return 'php::Array{' . implode(', ', $list) . '}';
    }

    // =========================================================================
    // Utility
    // =========================================================================

}
