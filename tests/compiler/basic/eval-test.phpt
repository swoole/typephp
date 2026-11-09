--TEST--
eval() language construct
--FILE--
<?php

function main() {
    // eval with return value
    $result = eval('return 10 + 5;');
    var_dump($result);

    // eval returning string
    $msg = eval('return "hello world";');
    var_dump($msg);

    // The dynamic program owns its variables and returns its result.
    $sum = eval('$total = 0; for ($i = 1; $i <= 3; $i++) { $total += $i; } return $total;');
    var_dump($sum);

    echo "done\n";
}

?>
--EXPECT--
int(15)
string(11) "hello world"
int(6)
done
