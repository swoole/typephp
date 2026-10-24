--TEST--
Typed scalar property copies convert Zend-backed reads before writing native slots
--FILE--
<?php
declare(strict_types=1);

final class PropertyCopySource
{
    public int $number = 42;
    public float $amount = 1.25;
}

final class PropertyCopyDestination
{
    public int $number = 0;
    public float $amount = 0.0;

    public function copy(PropertyCopySource $source): void
    {
        $this->number = $source->number;
        $this->amount = $source->amount;
    }
}

function main(): void
{
    $source = new PropertyCopySource();
    $destination = new PropertyCopyDestination();
    $destination->copy($source);
    var_dump($destination->number, $destination->amount);
    $source->number = -7;
    $source->amount = 2.5;
    $destination->copy($source);
    var_dump($destination->number, $destination->amount);
}
?>
--EXPECT--
int(42)
float(1.25)
int(-7)
float(2.5)
