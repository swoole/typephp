--TEST--
Array key reference-returning calls run first and keep the reference until insertion
--FILE--
<?php
function &array_key_reference(): mixed {
    global $key_reference;
    echo "key\n";
    return $key_reference;
}
function array_key_reference_value(string $next): string {
    global $key_reference;
    echo "value\n";
    $key_reference = $next;
    return 'v';
}
function array_key_copy(): mixed {
    global $key_reference;
    echo "copy\n";
    return $key_reference;
}
class ArrayKeyReference {
    public function &key(): mixed { return array_key_reference(); }
    public static function &staticKey(): mixed { return array_key_reference(); }
}
function main(): void
{
    global $key_reference;
    $key_reference = 'old';
    $a = [array_key_reference() => array_key_reference_value('direct')];
    echo json_encode($a), "\n";
    $f = 'array_key_reference';
    $a = [$f() => array_key_reference_value('dynamic')];
    echo json_encode($a), "\n";
    $o = new ArrayKeyReference();
    $a = [$o->key() => array_key_reference_value('method')];
    echo json_encode($a), "\n";
    $method = 'key';
    $a = [$o->$method() => array_key_reference_value('dynamic-method')];
    echo json_encode($a), "\n";
    $a = [ArrayKeyReference::staticKey() => array_key_reference_value('static')];
    echo json_encode($a), "\n";
    for ($i = 0; $i < 2; $i++) {
        $a = [array_key_reference() => array_key_reference_value('loop')];
        echo json_encode($a), "\n";
    }
    $closure = array_key_reference(...);
    $a = [$closure() => array_key_reference_value('closure')];
    echo json_encode($a), "\n";
    $f = 'array_key_copy';
    $a = [$f() => array_key_reference_value('after-copy')];
    echo json_encode($a), "\n";
    var_dump($key_reference);
}
?>
--EXPECT--
key
value
{"direct":"v"}
key
value
{"dynamic":"v"}
key
value
{"method":"v"}
key
value
{"dynamic-method":"v"}
key
value
{"static":"v"}
key
value
{"loop":"v"}
key
value
{"loop":"v"}
key
value
{"closure":"v"}
copy
value
{"closure":"v"}
string(10) "after-copy"
