--TEST--
Universal methods whose PHP function may return false throw a TypeError
--FILE--
<?php
function run(string $ok): void
{
    echo $ok->base64Decode(), "\n";
}

function runArray(array $a): void
{
    $bad = ["a" => NAN];
    try {
        $bad->jsonEncode();
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
    echo $a->jsonEncode(), "\n";
}

function main(): void
{
    run("aGk=");
    runArray([1]);
}
?>
--EXPECT--
hi
Return value must be of type string, bool returned
[1]
