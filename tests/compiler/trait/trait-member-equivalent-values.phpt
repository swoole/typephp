--TEST--
Trait members compare evaluated values instead of source spelling
--FILE--
<?php
enum Status: string
{
    case Active = 'active';
}

trait FirstValues
{
    public const int SCORE = 1 + 1;
    public const UNTYPED = 1 + 1;
    public array $items = [1, 2];
    public Status $status = Status::Active;
}

trait SecondValues
{
    public const int SCORE = 2;
    public const UNTYPED = 2;
    public array $items = array(1, 2);
    public Status $status = Status::Active;
}

class Values
{
    use FirstValues, SecondValues;
}

function main(): void
{
    $values = new Values();
    var_dump(Values::SCORE);
    var_dump(Values::UNTYPED);
    var_dump($values->items);
    var_dump($values->status === Status::Active);
}
?>
--EXPECT--
int(2)
int(2)
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
bool(true)
