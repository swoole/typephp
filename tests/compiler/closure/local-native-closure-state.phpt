--TEST--
Local lambda storage and Zend Closure fallback remain distinct for escaped names and global captures
--FILE--
<?php

function local_closure_state(int $input): array
{
    global $local_closure_shared;

    $class = static fn (int $value): int => $value + 1;
    $fallback = static function () use ($local_closure_shared): int {
        return $local_closure_shared;
    };
    $local_closure_shared = 90;
    $escaping = static fn (int $value): int => $value * 2;

    return [$class($input), $fallback(), array_map($escaping, [3])];
}

function main(): void
{
    global $local_closure_shared;
    $local_closure_shared = 7;
    var_dump(local_closure_state(2), $local_closure_shared);
}
?>
--EXPECT--
array(3) {
  [0]=>
  int(3)
  [1]=>
  int(7)
  [2]=>
  array(1) {
    [0]=>
    int(6)
  }
}
int(90)
