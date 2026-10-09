--TEST--
Dynamic array arithmetic retains PHP TypeErrors while dynamic union stays legal
--FILE--
<?php
function dynamic_add($left, $right): void {
    try {
        var_dump($left + $right);
    } catch (TypeError $error) {
        echo $error->getMessage(), "\n";
    }
}

function dynamic_add_into($left, $right): void {
    try {
        $left += $right;
        var_dump($left);
    } catch (TypeError $error) {
        echo $error->getMessage(), "\n";
    }
}

function known_array_dynamic_rhs(array $left, $right): void {
    try {
        var_dump($left + $right);
    } catch (TypeError $error) {
        echo $error->getMessage(), "\n";
    }
    try {
        $left += $right;
        var_dump($left);
    } catch (TypeError $error) {
        echo $error->getMessage(), "\n";
    }
}

function dynamic_multiply($left, $right): void {
    try {
        var_dump($left * $right);
    } catch (TypeError $error) {
        echo $error->getMessage(), "\n";
    }
}

function main(): void {
    dynamic_add([], 2);
    dynamic_add(2.5, []);
    dynamic_add([], false);
    dynamic_add('2', []);
    dynamic_add([], null);
    $stream = fopen('php://memory', 'r+');
    dynamic_add([], $stream);
    fclose($stream);
    dynamic_add([], new stdClass());
    dynamic_add_into([], 2);
    known_array_dynamic_rhs([], 2);
    dynamic_multiply([], []);
    dynamic_add([0 => 1], [0 => 9, 1 => 2]);
    dynamic_add_into([0 => 1], [0 => 9, 1 => 2]);
    known_array_dynamic_rhs([0 => 1], [0 => 9, 1 => 2]);
}
?>
--EXPECT--
Unsupported operand types: array + int
Unsupported operand types: float + array
Unsupported operand types: array + bool
Unsupported operand types: string + array
Unsupported operand types: array + null
Unsupported operand types: array + resource
Unsupported operand types: array + stdClass
Unsupported operand types: array + int
Unsupported operand types: array + int
Unsupported operand types: array + int
Unsupported operand types: array * array
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
