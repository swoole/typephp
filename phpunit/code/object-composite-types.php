<?php

interface ObjectCompositeLeft { public function left(): int; }
interface ObjectCompositeRight { public function right(): int; }
final class ObjectCompositeBoth implements ObjectCompositeLeft, ObjectCompositeRight
{
    public function left(): int { return 3; }
    public function right(): int { return 4; }
}
final class ObjectCompositeOther { public function value(): int { return 9; } }

function objectCompositeUnion(ObjectCompositeBoth|ObjectCompositeOther|null $value = null): ObjectCompositeBoth|ObjectCompositeOther|null
{
    $copy = $value;
    return $copy;
}
function objectCompositeIntersection(ObjectCompositeLeft&ObjectCompositeRight $value): ObjectCompositeLeft&ObjectCompositeRight
{
    $copy = $value;
    return $copy;
}
function objectCompositeDnf((ObjectCompositeLeft&ObjectCompositeRight)|ObjectCompositeOther $value): (ObjectCompositeLeft&ObjectCompositeRight)|ObjectCompositeOther
{
    return $value;
}
function objectCompositeMixed(ObjectCompositeOther|string $value): ObjectCompositeOther|string { return $value; }
function objectCompositeRef(ObjectCompositeBoth|ObjectCompositeOther|null &$value): void { $value = new ObjectCompositeOther(); }
function objectCompositeDynamic(mixed $value): ObjectCompositeBoth|ObjectCompositeOther|null { return $value; }
function objectCompositeStdClass(stdClass|ObjectCompositeOther $value): void {}
function objectCompositeClosure(): Closure
{
    return static fn(ObjectCompositeBoth|ObjectCompositeOther|null $value): ObjectCompositeBoth|ObjectCompositeOther|null => $value;
}
function objectCompositeLocalClosure(): object
{
    $identity = static fn(ObjectCompositeBoth|ObjectCompositeOther $value): ObjectCompositeBoth|ObjectCompositeOther => $value;
    $result = $identity(new ObjectCompositeBoth());
    return $result;
}
final class ObjectCompositeBox
{
    public ObjectCompositeBoth|ObjectCompositeOther|null $value = null;
    public function __construct(ObjectCompositeBoth|ObjectCompositeOther|null $value = null) { $this->value = $value; }
}

function objectCompositeRebind(ObjectCompositeBoth|ObjectCompositeOther $value): mixed
{
    $value = json_decode('3');
    return $value;
}
function objectCompositeLocalRebind(): mixed
{
    $value = objectCompositeUnion(new ObjectCompositeBoth());
    $value = json_decode('4');
    return $value;
}
function objectCompositeCaptureRebind(ObjectCompositeBoth|ObjectCompositeOther $value): mixed
{
    $callback = static function () use ($value): mixed { $value = json_decode('5'); return $value; };
    return $callback();
}
function objectCompositeClosureRebind(): Closure
{
    return static function (ObjectCompositeBoth|ObjectCompositeOther $value): mixed {
        $value = json_decode('6');
        return $value;
    };
}
