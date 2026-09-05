--TEST--
Constant folding uses resolved function names for imported aliases
--FILE--
<?php
namespace FunctionAliasFolding {
    use function strtoupper as upper;
    use function strtolower as strtoupper;
    use function strcasecmp as compare;
    use function strcmp as strcasecmp;
    use function strncasecmp as compare_prefix;
    use function strncmp as strncasecmp;

    function run(): void
    {
        var_dump(upper('MiXeD'));
        var_dump(strtoupper('MiXeD'));
        var_dump(compare('A', 'a'));
        var_dump(strcasecmp('A', 'a') !== 0);
        var_dump(compare_prefix('A', 'a', 1));
        var_dump(strncasecmp('A', 'a', 1) !== 0);
    }
}

namespace {
    function main(): void
    {
        FunctionAliasFolding\run();
    }
}
?>
--EXPECT--
string(5) "MIXED"
string(5) "mixed"
int(0)
bool(true)
int(0)
bool(true)
