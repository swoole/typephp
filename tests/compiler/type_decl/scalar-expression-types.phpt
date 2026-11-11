--TEST--
String and boolean expressions retain fixed types while builtin unions preserve their values
--FILE--
<?php

function scalarStringCast(mixed $value): string
{
    $text = (string) $value;
    return $text;
}

function scalarInterpolated(int $value): string
{
    $text = "n=$value";
    return $text;
}

function scalarInstanceCheck(object $value): bool
{
    $matches = $value instanceof stdClass;
    return $matches;
}

function scalarIssetCheck(array $values): bool
{
    $present = isset($values['key']);
    return $present;
}

function scalarEmptyCheck(mixed $value): bool
{
    $empty = empty($value);
    return $empty;
}

function scalarFixedBuiltin(string $value): int
{
    $length = strlen($value);
    $part = substr($value, 1);
    return $length + strlen($part);
}

function scalarUnionBuiltins(array $values, string $text): array
{
    $found = strpos($text, 'x');
    $last = array_pop($values);
    $sum = array_sum($values);
    $power = pow(2, 63);
    $decoded = json_decode($text);
    $results = [$found, $last, $sum, $power, $decoded];
    return $results;
}

final class ScalarExpressionStringable
{
    public static int $calls = 0;
    public function __toString(): string { self::$calls++; return 'label'; }
}
function main(): void
{
    var_dump(scalarStringCast(null), scalarStringCast(12));
    var_dump(scalarStringCast(new ScalarExpressionStringable()), ScalarExpressionStringable::$calls);
    var_dump(scalarInterpolated(4));
    var_dump(scalarInstanceCheck(new stdClass()), scalarInstanceCheck(new ScalarExpressionStringable()));
    var_dump(scalarIssetCheck([]), scalarIssetCheck(['key' => null]), scalarIssetCheck(['key' => 0]));
    var_dump(scalarEmptyCheck(0), scalarEmptyCheck(''), scalarEmptyCheck([1]));
    var_dump(scalarFixedBuiltin('abcd'));
    $first = scalarUnionBuiltins([1, 2.5, 'last'], 'x');
    var_dump($first[0], $first[1], $first[2], is_float($first[3]), $first[4]);
    $second = scalarUnionBuiltins([], 'null');
    var_dump($second[0], $second[1], $second[2], $second[4]);
    $third = scalarUnionBuiltins([], '7');
    var_dump($third[4]);
}
?>
--EXPECT--
string(0) ""
string(2) "12"
string(5) "label"
int(1)
string(3) "n=4"
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
int(7)
int(0)
string(4) "last"
float(3.5)
bool(true)
NULL
bool(false)
NULL
int(0)
NULL
int(7)
