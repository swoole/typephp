--TEST--
object values with a statically unknown class keep the typed property is-a check
--FILE--
<?php
class ParamTargetExpected
{
    public function value(): string
    {
        return 'expected';
    }
}

class ParamTargetChild extends ParamTargetExpected
{
}

class ParamTargetOther
{
}

class ParamHolder
{
    public ParamTargetExpected $p;
    public ?ParamTargetExpected $nullable;
    public object $generic;
}

function assignObject(object $value, ParamHolder $holder): void
{
    $holder->p = $value;
}

function assignNullableObject(object $value, ParamHolder $holder): void
{
    $holder->nullable = $value;
}

function assignGenericObject(object $value, ParamHolder $holder): void
{
    $holder->generic = $value;
}

function main()
{
    $holder = new ParamHolder();

    try {
        assignObject(new ParamTargetExpected(), $holder);
        echo "valid assignment accepted\n";
        var_dump($holder->p->value());
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    try {
        assignObject(new ParamTargetChild(), $holder);
        echo "subclass assignment accepted\n";
        var_dump($holder->p->value());
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    try {
        assignObject(new ParamTargetOther(), $holder);
        echo "invalid assignment accepted\n";
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    try {
        assignNullableObject(new ParamTargetOther(), $holder);
        echo "invalid nullable assignment accepted\n";
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    try {
        assignGenericObject(new ParamTargetOther(), $holder);
        echo "generic object assignment accepted\n";
        var_dump($holder->generic::class);
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }
}
?>
--EXPECT--
valid assignment accepted
string(8) "expected"
subclass assignment accepted
string(8) "expected"
TypeError
TypeError
generic object assignment accepted
string(16) "ParamTargetOther"
