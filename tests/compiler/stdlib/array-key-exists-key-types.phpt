--TEST--
array_key_exists preserves key conversion, diagnostics and invalid-key errors
--FILE--
<?php
namespace KeyExistsKeys {

    function lookup(mixed $key, array $array): void
    {
        try {
            var_dump(array_key_exists($key, $array));
        } catch (\Throwable $error) {
            echo get_class($error), "\n";
        }
    }

    function run(): void
    {
        $diagnostics = [];
        set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$diagnostics): bool {
            $diagnostics[] = $severity;
            return true;
        });

        lookup(false, [0 => null]);
        lookup(false, ['' => true]);
        lookup(true, [1 => null]);
        lookup('1', [1 => null]);
        lookup('01', [1 => null]);
        var_dump(array_key_exists(1, [1 => null]), array_key_exists('1', [1 => null]));
        var_dump(array_key_exists(false, [0 => null]));

        lookup(1.5, [1 => null]);
        var_dump(array_key_exists(1.5, [1 => null]));
        var_dump($diagnostics === [E_DEPRECATED, E_DEPRECATED]);
        $diagnostics = [];

        lookup(null, ['' => null]);
        var_dump(array_key_exists(null, ['' => null]));
        var_dump(count($diagnostics) === (PHP_VERSION_ID >= 80500 ? 2 : 0));
        $diagnostics = [];

        $resource = fopen(__FILE__, 'r');
        lookup($resource, [(int) $resource => null]);
        var_dump($diagnostics === [E_WARNING]);
        fclose($resource);
        $diagnostics = [];

        lookup([], ['Array' => null]);
        lookup(new \stdClass(), []);
        var_dump($diagnostics === []);

        $array = [0 => null];
        $key = \std::any(false);
        var_dump(key_exists($key, $array), $array->keyExists($key));
        var_dump(array_key_exists(array: $array, key: $key));
        var_dump(array_key_exists(...[$key, $array]));
        restore_error_handler();
    }
}
namespace {
    function main(): void
    {
        \KeyExistsKeys\run();
    }
}
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
TypeError
TypeError
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
