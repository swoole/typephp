--TEST--
Trait aliases materialize coalesce and shorthand ternary in independent contexts
--FILE--
<?php
trait ValueSelector
{
    public function coalesce(array $values): string
    {
        return $values['key'] ?? 'fallback';
    }

    public function shorthand(array $values): string
    {
        return $values['key'] ?: 'fallback';
    }

    public function localClosure(array $values): string
    {
        $select = fn(array $items): string => $items['key'] ?? 'fallback';
        return $select($values);
    }

    public function initialized(): string
    {
        static $value = null ?? 'fallback';
        return $value;
    }

    public function generated(array $values)
    {
        yield $values['key'] ?? 'fallback';
    }
}

class SelectorConsumer
{
    use ValueSelector {
        coalesce as coalesceAlias;
        shorthand as shorthandAlias;
        localClosure as localClosureAlias;
        initialized as initializedAlias;
        generated as generatedAlias;
    }
}

function main()
{
    $c = new SelectorConsumer();
    var_dump($c->coalesce(['key' => 'present']), $c->coalesceAlias(['key' => 'present']), $c->coalesceAlias([]));
    var_dump($c->shorthand(['key' => 'present']), $c->shorthandAlias(['key' => 'present']), $c->shorthandAlias(['key' => '']));
    var_dump($c->localClosure(['key' => 'present']), $c->localClosureAlias(['key' => 'present']), $c->localClosureAlias([]));
    var_dump($c->initialized(), $c->initializedAlias());
    foreach ($c->generated(['key' => 'present']) as $value) {
        var_dump($value);
    }
    foreach ($c->generatedAlias([]) as $value) {
        var_dump($value);
    }
}
?>
--EXPECT--
string(7) "present"
string(7) "present"
string(8) "fallback"
string(7) "present"
string(7) "present"
string(8) "fallback"
string(7) "present"
string(7) "present"
string(8) "fallback"
string(8) "fallback"
string(8) "fallback"
string(7) "present"
string(8) "fallback"
