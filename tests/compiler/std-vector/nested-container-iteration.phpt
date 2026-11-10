--TEST--
Nested std container iterations release their guards before later structural writes
--FILE--
<?php
function main(): void
{
    $values = std::vector([1, 2]);
    $fixed = std::array([10, 20]);
    $map = std::map(['a' => 3]);
    $ordered = std::orderedMap([1 => 4]);
    $sum = 0;
    foreach ($values as $left) {
        foreach ($values as $right) {
            $sum += $left + $right;
        }
        foreach ($fixed as $fixedValue) {
            $sum += $fixedValue;
        }
        foreach ($map as $mapValue) {
            $sum += $mapValue;
        }
        foreach ($ordered as $orderedValue) {
            $sum += $orderedValue;
        }
    }
    echo 'sum=', $sum, "\n";
    $values[] = 3;
    $map['b'] = 5;
    unset($ordered[1]);
    $fixed[0] = 11;
    echo 'after=', count($values), ',', count($map), ',', count($ordered), ',', $fixed[0], "\n";
}
?>
--EXPECT--
sum=86
after=3,2,0,11
