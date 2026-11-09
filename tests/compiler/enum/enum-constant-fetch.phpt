--TEST--
Enum cases and ordinary enum constants retain their values
--FILE--
<?php
enum try_type
{
    case t_int;
    public const LABEL = 'integer';
    public const ALIAS = self::t_int;
}

function trying(?try_type $type = null): void
{
    var_dump($type?->name);
}

function main(int $argc, array $argv): void
{
    trying();
    trying(try_type::t_int);
    trying(try_type::ALIAS);
    var_dump(try_type::LABEL);
    var_dump(try_type::ALIAS === try_type::t_int);
}
?>
--EXPECT--
NULL
string(5) "t_int"
string(5) "t_int"
string(7) "integer"
bool(true)
