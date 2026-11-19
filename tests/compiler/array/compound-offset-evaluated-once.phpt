--TEST--
Array compound assignment evaluates a side-effecting offset once
--FILE--
<?php

function nextCompoundOffset(int &$index): int
{
    return $index++;
}

function changeCompoundOffsetPart(string &$part): int
{
    $part = 'b';
    return 5;
}

function main(): void
{
    $statementIndex = 0;
    $statementValues = [10];
    $statementValues[$statementIndex++] += 5;

    var_dump($statementIndex, $statementValues);

    $resultIndex = 0;
    $resultValues = [20];
    $result = ($resultValues[$resultIndex++] *= 2);

    var_dump($resultIndex, $resultValues, $result);

    $globalIndex = 0;
    $GLOBALS['typephp_compound_once_0'] = 3;
    $GLOBALS['typephp_compound_once_' . $globalIndex++] += 4;

    var_dump($globalIndex, $GLOBALS['typephp_compound_once_0']);

    $nestedIndex = 0;
    $nestedValues = [[10, 20], [30, 40]];
    $nestedResult = ($nestedValues[$nestedIndex++][$nestedIndex++] += 5);

    var_dump($nestedIndex, $nestedValues[0][1], $nestedValues[1][0], $nestedResult);

    $callIndex = std::any(0);
    $callValues = [[10, 20], [30, 40]];
    $callResult = ($callValues[nextCompoundOffset($callIndex)][nextCompoundOffset($callIndex)] *= 2);

    var_dump($callIndex, $callValues[0][1], $callValues[1][0], $callResult);

    $rhsIndex = 0;
    $rhsValues = [10, 20];
    // PHP reads a plain variable offset after the RHS has incremented it.
    $rhsResult = ($rhsValues[$rhsIndex] += ++$rhsIndex);

    var_dump($rhsIndex, $rhsValues[0], $rhsValues[1], $rhsResult);

    $offsetPart = std::any('a');
    $interpolatedValues = ['a0' => 10, 'b0' => 20];
    $interpolatedResult = ($interpolatedValues["{$offsetPart}0"] += changeCompoundOffsetPart($offsetPart));

    var_dump($offsetPart, $interpolatedValues['a0'], $interpolatedValues['b0'], $interpolatedResult);
}
?>
--EXPECT--
int(1)
array(1) {
  [0]=>
  int(15)
}
int(1)
array(1) {
  [0]=>
  int(40)
}
int(40)
int(1)
int(7)
int(2)
int(25)
int(30)
int(25)
int(2)
int(40)
int(30)
int(40)
int(1)
int(10)
int(21)
int(21)
string(1) "b"
int(15)
int(20)
int(15)
