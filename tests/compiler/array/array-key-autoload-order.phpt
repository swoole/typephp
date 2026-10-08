--TEST--
Array key class autoloading finishes before value side effects
--FILE--
<?php
class ArrayKeyAutoloadSource { public const KEY = 'loaded'; }
function autoload_key_value(): string { echo "value\n"; return 'v'; }
function main(): void
{
    spl_autoload_register(function (string $class): void {
        echo 'autoload:', $class, "\n";
        if ($class === 'ArrayKeyAutoloadDynamic' || $class === 'ArrayKeyAutoloadNamed') {
            class_alias(ArrayKeyAutoloadSource::class, $class);
        }
    });
    $class = 'ArrayKeyAutoloadDynamic';
    $a = [$class::KEY => autoload_key_value()];
    echo json_encode($a), "\n";
    $a = [ArrayKeyAutoloadNamed::KEY => autoload_key_value()];
    echo json_encode($a), "\n";
}
?>
--EXPECT--
autoload:ArrayKeyAutoloadDynamic
value
{"loaded":"v"}
autoload:ArrayKeyAutoloadNamed
value
{"loaded":"v"}
