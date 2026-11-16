--TEST--
Readonly scalar property arithmetic preserves native operands, checked reads and evaluation order
--FILE--
<?php
final class ReadonlyArithmeticBox
{
    public function __construct(
        public readonly float $width,
        public readonly float $height,
        public readonly int $count,
        public readonly bool $enabled,
    ) {}

    public function ratio(): float { return $this->width / $this->height; }
}

final class UninitializedReadonlyArithmeticBox
{
    public readonly float $width;
}

function halfReadonlyBox(ReadonlyArithmeticBox $box): float
{
    return $box->width < $box->height ? $box->width / 2.0 : $box->height / 2.0;
}

function nextReadonlyCount(ReadonlyArithmeticBox $box): int { return $box->count + 1; }
function readonlyEnabled(ReadonlyArithmeticBox $box): bool { return $box->enabled === true; }
function readonlyReceiver(ReadonlyArithmeticBox $box): ReadonlyArithmeticBox { echo "receiver\n"; return $box; }
function readonlyDivisor(): float { echo "divisor\n"; return 2.0; }
function orderedReadonlyHalf(ReadonlyArithmeticBox $box): float
{
    return readonlyReceiver($box)->width / readonlyDivisor();
}
function uninitializedReadonlyHalf(UninitializedReadonlyArithmeticBox $box): float { return $box->width / 2.0; }
function nullableReadonlyHalf(?ReadonlyArithmeticBox $box): float { return $box->width / 2.0; }

function main(): void
{
    $box = new ReadonlyArithmeticBox(6.0, 12.0, 4, true);
    var_dump(halfReadonlyBox($box), $box->ratio(), nextReadonlyCount($box), readonlyEnabled($box));
    var_dump(halfReadonlyBox(new ReadonlyArithmeticBox(12.0, 6.0, 0, false)));
    var_dump(readonlyEnabled(new ReadonlyArithmeticBox(1.0, 2.0, 0, false)));
    var_dump(orderedReadonlyHalf($box));
    try {
        uninitializedReadonlyHalf(new UninitializedReadonlyArithmeticBox());
    } catch (Error $error) {
        echo "uninitialized: ", $error->getMessage(), "\n";
    }
    try {
        nullableReadonlyHalf(null);
    } catch (Error $error) {
        echo "null receiver\n";
    }
}
?>
--EXPECT--
float(3)
float(0.5)
int(5)
bool(true)
float(3)
bool(false)
receiver
divisor
float(3)
uninitialized: Typed property UninitializedReadonlyArithmeticBox::$width must not be accessed before initialization
null receiver
