--TEST--
Native arithmetic methods check var arguments at runtime without coercion
--FILE--
<?php
function run(int $a, float $f, $i, $x, $s): void
{
    var_dump($a->add($i), $f->mul($i), $f->add($x), $a->max($i));
    foreach ([fn() => $a->add($x), fn() => $a->add($s), fn() => $f->add($s)] as $call) {
        try {
            $call();
        } catch (TypeError $e) {
            echo $e->getMessage(), "\n";
        }
    }
}

function main(): void
{
    run(7, 2.5, 3, 1.5, "5");
}
?>
--EXPECT--
int(10)
float(7.5)
float(4)
int(7)
Return value must be of type int, float returned
Return value must be of type int, string returned
Return value must be of type float, string returned
