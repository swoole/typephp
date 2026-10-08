--TEST--
Typed scalar property compound assignment computes the Zend result before checking the write
--FILE--
<?php
declare(strict_types=1);
class NativeScalarAssignOpVarBox
{
    public int $intValue = 1;
    public float $floatValue = 1.5;

    public function addInside($intDelta, $floatDelta): void
    {
        $this->intValue += $intDelta;
        $this->floatValue += $floatDelta;
    }
}

function main(): void
{
    $box = new NativeScalarAssignOpVarBox();

    $intDelta = std::any(2);
    $box->intValue += $intDelta;

    $floatDelta = std::any(2.25);
    $box->floatValue += $floatDelta;

    var_dump($box->intValue);
    var_dump($box->floatValue);

    $methodIntDelta = std::any(3);
    $methodFloatDelta = std::any(0.25);
    $box->addInside($methodIntDelta, $methodFloatDelta);
    var_dump($box->intValue);
    var_dump($box->floatValue);

    $numericIntDelta = std::any("4");
    $box->intValue += $numericIntDelta;
    var_dump($box->intValue);

    $numericFloatDelta = std::any("1.25");
    $box->floatValue += $numericFloatDelta;
    var_dump($box->floatValue);

    try {
        $badIntDelta = std::any("4.5");
        $box->intValue += $badIntDelta;
    } catch (TypeError $e) {
        var_dump($e->getMessage());
    }
    var_dump($box->intValue);

    try {
        $nonNumericDelta = std::any("oops");
        $box->intValue += $nonNumericDelta;
    } catch (TypeError $e) {
        var_dump($e->getMessage());
    }
    var_dump($box->intValue);
}
?>
--EXPECT--
int(3)
float(3.75)
int(6)
float(4)
int(10)
float(5.25)
string(81) "Cannot assign float to property NativeScalarAssignOpVarBox::$intValue of type int"
int(10)
string(39) "Unsupported operand types: int + string"
int(10)
