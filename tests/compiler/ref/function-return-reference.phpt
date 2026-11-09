--TEST--
function returning by reference preserves aliases
--FILE--
<?php
function &value_ref()
{
    global $value;
    return $value;
}
function value_copy()
{
    global $value;
    return $value;
}
function main()
{
    global $value;
    $value = std::any(1);
    $alias =& value_ref();
    $alias = 42;
    var_dump(value_ref());

    $localAlias =& local_ref();
    $localAlias = 'kept alive';
    var_dump($localAlias);

    $update = require __DIR__ . '/function-return-reference-require.inc';
    $update('value_ref');
    var_dump(value_ref());

    $callback = 'value_ref';
    $dynamicAlias =& $callback();
    $dynamicAlias = 'from dynamic callback';
    var_dump(value_ref());

    $callback = 'value_copy';
    try {
        $badAlias =& $callback();
    } catch (TypeError $e) {
        echo "dynamic callback TypeError\n";
    }
}

function &local_ref()
{
    $value = std::any(1);
    return $value;
}
?>
--EXPECT--
int(42)
string(10) "kept alive"
string(12) "from require"
string(21) "from dynamic callback"
dynamic callback TypeError
