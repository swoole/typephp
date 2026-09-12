--TEST--
Static initializer temporaries do not reuse outer native condition snapshots
--FILE--
<?php
function conditionBeforeStatic(array $args): void {
    if ($args === []) { return; }
    static $table = [[[7, 'value']]];
    echo $table[0][0][1], "\n";
}
function main(): void { conditionBeforeStatic([1]); conditionBeforeStatic([2]); }
?>
--EXPECT--
value
value
