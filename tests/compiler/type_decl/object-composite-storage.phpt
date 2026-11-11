--TEST--
Object-only unions and intersections preserve object storage and complete PHP type boundaries
--FILE--
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

function main(): void
{
    $both = new ObjectCompositeBoth();
    $other = new ObjectCompositeOther();
    var_dump(objectCompositeUnion($both) === $both, objectCompositeUnion(), objectCompositeUnion($other) === $other);
    var_dump(objectCompositeIntersection($both) === $both, objectCompositeDnf($other) === $other);
    var_dump(objectCompositeLocalClosure() instanceof ObjectCompositeBoth, objectCompositeMixed('text'));
    $box = new ObjectCompositeBox();
    var_dump($box->value);
    $box->value = $both;
    var_dump($box->value === $both);
    $box->value = null;
    var_dump($box->value);
    $callback = objectCompositeClosure();
    var_dump($callback(null), $callback($both) === $both);
    $value = std::any($both);
    objectCompositeRef($value);
    var_dump($value instanceof ObjectCompositeOther);
    try { objectCompositeStdClass(json_decode('3')); }
    catch (TypeError $error) { echo "scalar parameter checked\n"; }
    try { objectCompositeUnion(std::any(new stdClass())); }
    catch (TypeError $error) { echo "union parameter checked\n"; }
    try { objectCompositeIntersection(std::any($other)); }
    catch (TypeError $error) { echo "intersection parameter checked\n"; }
    try { objectCompositeDynamic(json_decode('3')); }
    catch (TypeError $error) { echo "scalar return checked\n"; }
    try { objectCompositeDynamic(std::any(new stdClass())); }
    catch (TypeError $error) { echo "union return checked\n"; }
    try { $box->value = std::any('invalid'); }
    catch (TypeError $error) { echo "property checked\n"; }
    try { $callback(std::any(new stdClass())); }
    catch (TypeError $error) { echo "closure checked\n"; }
    var_dump(objectCompositeRebind($both), objectCompositeLocalRebind(), objectCompositeCaptureRebind($both));
    $rebind = objectCompositeClosureRebind();
    var_dump($rebind($both));
    // A call through Zend must retain the same TypeError boundary.
    try { call_user_func('objectCompositeStdClass', json_decode('3')); }
    catch (TypeError $error) { echo "Zend parameter checked\n"; }
}
?>
--EXPECT--
bool(true)
NULL
bool(true)
bool(true)
bool(true)
bool(true)
string(4) "text"
NULL
bool(true)
NULL
NULL
bool(true)
bool(true)
scalar parameter checked
union parameter checked
intersection parameter checked
scalar return checked
union return checked
property checked
closure checked
int(3)
int(4)
int(5)
int(6)
Zend parameter checked
