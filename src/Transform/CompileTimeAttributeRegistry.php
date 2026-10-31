<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

final class CompileTimeAttributeRegistry
{
    public const TARGET_CLASS = 'class';
    public const TARGET_NAMED_CLASS = 'named_class';
    public const TARGET_CLASS_LIKE = 'class_like';
    public const TARGET_FUNCTION = 'function';
    public const TARGET_METHOD = 'method';
    public const TARGET_PROPERTY_HOOK = 'property_hook';
    public const TARGET_PROPERTY = 'property';
    public const TARGET_DECLARED_PROPERTY = 'declared_property';
    public const TARGET_PARAMETER = 'parameter';
    public const TARGET_TRAIT = 'trait';
    public const TARGET_ENUM = 'enum';

    public const ARGUMENTS_NONE = 'none';
    public const ARGUMENTS_METHODS_FOR = 'methods_for';
    public const ARGUMENTS_FIELDS = 'fields';
    public const ARGUMENTS_VALIDATE = 'validate';
    public const ARGUMENTS_WASM_EXPORT = 'wasm_export';
    public const ARGUMENTS_STD_CONTAINER = 'std_container';
    public const ARGUMENTS_PROVIDER = 'provider';

    public const PHASE_PREPROCESS = 'preprocess';
    public const PHASE_ENTER = 'enter';
    public const PHASE_FUNCTION_LEAVE = 'function_leave';
    public const PHASE_CLASS_LEAVE = 'class_leave';
    public const PHASE_PROVIDER = 'provider';

    /** @var array<string, array<string, mixed>> */
    private static array $providers = [];

    /** @var ?array<string, array<string, mixed>> */
    private static ?array $merged = null;

    public static function all(): array
    {
        if (self::$providers === []) {
            return self::buildBuiltins();
        }

        return self::$merged ??= array_replace(self::buildBuiltins(), self::$providers);
    }

    public static function registerProvider(array $definition): void
    {
        self::$providers[strtolower(ltrim($definition['name'], '\\'))] = $definition;
        self::$merged = null;
    }

    public static function resetProviders(): void
    {
        self::$providers = [];
        self::$merged = null;
    }

    public static function hasBuiltin(string $name): bool
    {
        return isset(self::buildBuiltins()[strtolower(ltrim($name, '\\'))]);
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     targets: list<string>,
     *     target_error: string,
     *     repeatable: bool,
     *     argument_parser: string,
     *     conflicts: list<string>,
     *     phase: string,
     *     preserve_in_library_stub: bool
     * }>
     */
    private static function buildBuiltins(): array
    {
        static $definitions = null;
        if ($definitions !== null) {
            return $definitions;
        }

        $definitions = [];
        $add = static function (
            string $name,
            array $targets,
            string $targetError,
            string $argumentParser,
            string $phase,
            bool $preserveInLibraryStub = true,
            array $conflicts = [],
            bool $repeatable = false,
        ) use (&$definitions): void {
            $definitions[strtolower($name)] = [
                'name' => $name,
                'targets' => $targets,
                'target_error' => $targetError,
                'repeatable' => $repeatable,
                'argument_parser' => $argumentParser,
                'conflicts' => $conflicts,
                'phase' => $phase,
                'preserve_in_library_stub' => $preserveInLibraryStub,
            ];
        };

        $add('Native', [self::TARGET_NAMED_CLASS], 'Native can only be applied to named classes', self::ARGUMENTS_NONE, self::PHASE_PREPROCESS, false);
        $add('MethodsFor', [self::TARGET_NAMED_CLASS], 'MethodsFor can only be applied to classes', self::ARGUMENTS_METHODS_FOR, self::PHASE_PREPROCESS);
        $add('NoExport', [self::TARGET_CLASS_LIKE, self::TARGET_FUNCTION, self::TARGET_METHOD], 'NoExport can only be applied to classes, functions, or methods', self::ARGUMENTS_NONE, self::PHASE_PREPROCESS, false);
        $add('WasmExport', [self::TARGET_FUNCTION], 'WasmExport can only be applied to named functions', self::ARGUMENTS_WASM_EXPORT, self::PHASE_PREPROCESS, false);
        foreach (['Getter', 'Setter', 'With'] as $name) {
            $add($name, [self::TARGET_PROPERTY], $name . ' can only be applied to instance properties', self::ARGUMENTS_NONE, self::PHASE_CLASS_LEAVE);
        }
        foreach (['Printer', 'Arrayable'] as $name) {
            $add($name, [self::TARGET_NAMED_CLASS], $name . ' can only be applied to named classes', self::ARGUMENTS_FIELDS, self::PHASE_CLASS_LEAVE);
        }
        foreach (['NotNull', 'NotEmpty'] as $name) {
            $add($name, [self::TARGET_PARAMETER], $name . ' can only be applied to function or method parameters', self::ARGUMENTS_NONE, self::PHASE_FUNCTION_LEAVE);
        }
        $add('Validate', [self::TARGET_PARAMETER], 'Validate can only be applied to function or method parameters', self::ARGUMENTS_VALIDATE, self::PHASE_FUNCTION_LEAVE);
        $add(
            'Override',
            [self::TARGET_METHOD, self::TARGET_PROPERTY],
            'Override can only be applied to methods or properties',
            self::ARGUMENTS_NONE,
            self::PHASE_ENTER,
        );
        $add('MustUse', [self::TARGET_FUNCTION, self::TARGET_METHOD], 'MustUse can only be applied to functions or methods', self::ARGUMENTS_NONE, self::PHASE_ENTER);
        $add('Immutable', [self::TARGET_METHOD, self::TARGET_PROPERTY_HOOK, self::TARGET_PARAMETER], 'Immutable can only be applied to methods, property hooks, or function parameters', self::ARGUMENTS_NONE, self::PHASE_ENTER);
        $add('Hot', [self::TARGET_FUNCTION, self::TARGET_METHOD], 'Hot can only be applied to functions or methods', self::ARGUMENTS_NONE, self::PHASE_ENTER, true, ['Cold']);
        $add('Cold', [self::TARGET_FUNCTION, self::TARGET_METHOD], 'Cold can only be applied to functions or methods', self::ARGUMENTS_NONE, self::PHASE_ENTER, true, ['Hot']);
        $add('Constructor', [self::TARGET_DECLARED_PROPERTY], 'Constructor can only be applied to instance properties', self::ARGUMENTS_NONE, self::PHASE_CLASS_LEAVE);
        $containerAttributes = ['StdArray', 'StdVector', 'StdMap', 'StdOrderedMap', 'StdList', 'StdDict'];
        foreach ($containerAttributes as $name) {
            $add($name, [self::TARGET_PARAMETER, self::TARGET_DECLARED_PROPERTY], $name . ' can only be applied to function or method parameters or properties', self::ARGUMENTS_STD_CONTAINER, self::PHASE_PREPROCESS, true, array_values(array_diff($containerAttributes, [$name])));
        }

        return $definitions;
    }

    public static function get(string $name): ?array
    {
        return self::all()[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /** @return list<string> */
    public static function names(bool $preservedInLibraryStubOnly = false): array
    {
        $names = [];
        foreach (self::all() as $definition) {
            if (!$preservedInLibraryStubOnly || $definition['preserve_in_library_stub']) {
                $names[] = $definition['name'];
            }
        }
        return $names;
    }

    /** @return list<string> */
    public static function namesForPhase(string $phase): array
    {
        $names = [];
        foreach (self::all() as $definition) {
            if ($definition['phase'] === $phase) {
                $names[] = $definition['name'];
            }
        }
        return $names;
    }
}
