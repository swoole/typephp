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
    public static int $staticNumber = 0;
    public static float $staticAmount = 0.0;

    public function copy(PropertyCopySource $source): void
    {
        $this->number = $source->number;
        $this->amount = $source->amount;
    }

    public function add(PropertyCopySource $source): void
    {
        $this->number += $source->number;
        $this->amount += $source->amount;
        self::$staticNumber += $source->number;
        self::$staticAmount += $source->amount;
    }

    public function assignExpressions(PropertyCopySource $source, bool $cond): void
    {
        $this->number = $source->number + 10;
        $this->amount = $source->amount + 0.5;
        $this->number = -$source->number;
        $this->number = $cond ? $source->number : 0;
        $this->number = $source->number ?: 99;
    }

    public static function multiplyStatic(): void
    {
        self::$staticNumber *= 1.5;
    }

    public static function divideStatic(): void
    {
        self::$staticNumber /= 0.5;
    }

    public static function multiplyStaticByProperty(PropertyCopySource $source): void
    {
        self::$staticNumber *= $source->amount;
    }

    public static function divideStaticByProperty(PropertyCopySource $source): void
    {
        self::$staticNumber /= $source->amount;
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

    $destination->number = 10;
    $destination->amount = 1.5;
    $source->number = 5;
    $source->amount = 0.75;
    $destination->add($source);
    var_dump($destination->number, $destination->amount, PropertyCopyDestination::$staticNumber, PropertyCopyDestination::$staticAmount);

    $destination->assignExpressions($source, true);
    var_dump($destination->number, $destination->amount);

    PropertyCopyDestination::$staticNumber = 10;
    PropertyCopyDestination::multiplyStatic();
    var_dump(PropertyCopyDestination::$staticNumber);

    PropertyCopyDestination::$staticNumber = 10;
    PropertyCopyDestination::divideStatic();
    var_dump(PropertyCopyDestination::$staticNumber);

    $source->amount = 1.5;
    PropertyCopyDestination::$staticNumber = 10;
    PropertyCopyDestination::multiplyStaticByProperty($source);
    var_dump(PropertyCopyDestination::$staticNumber);

    $source->amount = 0.5;
    PropertyCopyDestination::$staticNumber = 10;
    PropertyCopyDestination::divideStaticByProperty($source);
    var_dump(PropertyCopyDestination::$staticNumber);
}
?>
--EXPECT--
int(42)
float(1.25)
int(-7)
float(2.5)
int(15)
float(2.25)
int(5)
float(0.75)
int(5)
float(1.25)
int(15)
int(20)
int(15)
int(20)
