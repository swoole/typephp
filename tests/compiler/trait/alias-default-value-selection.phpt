--TEST--
Trait aliases materialize parameter defaults in independent helper contexts
--FILE--
<?php
trait DefaultValueSelector
{
    public function coalesce($value = null ?? 'fallback')
    {
        return $value;
    }

    public function shorthand($value = '' ?: 'fallback')
    {
        return $value;
    }

    public function reference(&$value = null ?? 'fallback')
    {
        return $value;
    }

    public function arrayDefault(array $values = [null ?? 'fallback'])
    {
        return $values[0];
    }
}

class DefaultSelectorConsumer
{
    use DefaultValueSelector {
        coalesce as coalesceAlias;
        shorthand as shorthandAlias;
        reference as referenceAlias;
        arrayDefault as arrayAlias;
    }
}

function main()
{
    $c = new DefaultSelectorConsumer();
    var_dump($c->coalesce(), $c->coalesceAlias(), $c->coalesceAlias('provided'));
    var_dump($c->shorthand(), $c->shorthandAlias());
    var_dump($c->reference(), $c->referenceAlias());
    var_dump($c->arrayDefault(), $c->arrayAlias());
}
?>
--EXPECT--
string(8) "fallback"
string(8) "fallback"
string(8) "provided"
string(8) "fallback"
string(8) "fallback"
string(8) "fallback"
string(8) "fallback"
string(8) "fallback"
string(8) "fallback"
