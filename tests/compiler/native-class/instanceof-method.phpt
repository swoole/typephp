--TEST--
Native object instanceOf keyword retains operator folding, null behavior and nullsafe laziness
--FILE--
<?php
interface NativeMethodMarker {}
#[Native]
class NativeMethodBase {}
#[Native]
final class NativeMethodChild extends NativeMethodBase implements NativeMethodMarker {}
#[Native]
class NativeMethodOther {}

function makeNativeMethodChild(): NativeMethodChild {
    echo "made\n";
    return new NativeMethodChild();
}
function nativeMethodNullsafe(?NativeMethodChild $object): ?bool {
    return $object?->instanceOf(NativeMethodMarker::class);
}
function main(): void {
    $object = new NativeMethodChild();
    var_dump($object->instanceOf(NativeMethodChild::class));
    var_dump($object->instanceOf(NativeMethodBase::class));
    var_dump($object->instanceOf(NativeMethodMarker::class));
    var_dump($object->instanceOf(NativeMethodOther::class));
    var_dump(makeNativeMethodChild()->instanceOf(NativeMethodBase::class));
    var_dump(nativeMethodNullsafe($object));
    $object = null;
    var_dump($object->instanceOf(NativeMethodChild::class));
    var_dump(nativeMethodNullsafe($object));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
made
bool(true)
bool(true)
bool(false)
NULL
