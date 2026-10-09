--TEST--
Std container scalar elements retain arithmetic and PHP arrays retain union and dynamic runtime errors
--FILE--
<?php
function multiply_dynamic($value): void {
    try {
        var_dump($value * 2);
    } catch (TypeError $error) {
        echo "dynamic runtime error\n";
    }
}

function main() {
    $matrix = std::array(std::array(Type::Int, 2), 2);
    $matrix[1][0] = 4;
    $matrix[1][1] = 3;
    var_dump($matrix[1][0] * $matrix[1][1]);
    $matrix[1][0] *= $matrix[1][1];
    var_dump($matrix[1][0]);

    $vector = std::vector([2, 3]);
    $vector[0] += $vector[1];
    var_dump($vector[0]);

    $map = std::map([0 => 2]);
    $map[0] *= 3;
    var_dump($map[0]);

    $ordered = std::orderedMap([0 => 9]);
    $ordered[0] -= 2;
    var_dump($ordered[0] + 1);

    $left = [0 => 1];
    $right = [0 => 99, 1 => 2];
    $union = $left + $right;
    var_dump($union[0], $union[1]);

    $list = std::list([1, 2]);
    $other = std::list([3, 4, 5]);
    $typedUnion = $list + $other;
    var_dump($typedUnion[0], $typedUnion[2]);

    try {
        var_dump(std::any($left) * 2);
    } catch (TypeError $error) {
        echo "PHP array runtime error\n";
    }

    // An untyped parameter still requires runtime validation.
    multiply_dynamic($vector);
}
?>
--EXPECT--
int(12)
int(12)
int(5)
int(6)
int(8)
int(1)
int(2)
int(1)
int(5)
PHP array runtime error
dynamic runtime error
