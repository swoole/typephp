--TEST--
Closure parameters accept string literals, concatenation and string variables
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    $bang = fn($x) => $x . '!';

    var_dump($bang('ab'));
    var_dump($bang('a' . 'b'));

    $value = 'xy';
    var_dump($bang($value));

    // Declared string parameter.
    $declared = fn(string $x) => $x . '?';
    var_dump($declared('ab'));
    var_dump($declared('a' . 'b'));
}
?>
--EXPECT--
string(3) "ab!"
string(3) "ab!"
string(3) "xy!"
string(3) "ab?"
string(3) "ab?"
