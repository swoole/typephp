--TEST--
Nested std array writes preserve native storage, boxed aliases and value copies
--FILE--
<?php
function update_cube(#[StdArray(Type::Int, [2, 2, 2])] box $cube): void
{
    $cube[1][0][1] = 42;
    $cube[0][1][0] += 3;
}

function main() {
    $cube = std::array(std::array(std::array(Type::Int, 2), 2), 2);
    $cube[0][1][0] = 7;
    $cube[1][0][1] = 11;
    var_dump($cube[0][1][0], $cube[1][0][1]);

    update_cube($cube);
    var_dump($cube[0][1][0], $cube[1][0][1]);

    // Copying into a matching std array preserves native element storage;
    // assigning it back must replace both elements of the original row.
    $row = std::array(Type::Int, 2);
    $row = $cube[1][0];
    $row[0] = 20;
    $row[1] = 30;
    var_dump($cube[1][0][0], $cube[1][0][1]);
    $cube[1][0] = $row;
    $row[1] = 99;
    var_dump($cube[1][0][0], $cube[1][0][1], $row[1]);

    $words = std::array(std::array(Type::String, 2), 2);
    $words[1][0] = 'native';
    $words[0][1] = 'storage';
    echo $words[1][0], ':', $words[0][1], "\n";

    $values = std::array(std::array(Type::Float, 2), 2);
    $values[1][1] = 1.5;
    $values[1][1] *= 2;
    var_dump($values[1][1]);
}
?>
--EXPECT--
int(7)
int(11)
int(10)
int(42)
int(0)
int(42)
int(20)
int(30)
int(99)
native:storage
float(3)
