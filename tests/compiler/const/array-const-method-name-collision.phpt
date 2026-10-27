--TEST--
array class const symbol stays disjoint from same-name method (C2872)
--FILE--
<?php
class C2872FontData
{
    private const LATIN = [65, 66];

    public static function latin(): int
    {
        return self::LATIN[0];
    }
}

class C2872ChildFontData extends C2872FontData
{
}

class C2872CasePair
{
    const A = [1, 2];
    const a = [3, 4];
}

interface C2872Palette
{
    const COLORS = ['r', 'g'];
}

class C2872Canvas implements C2872Palette
{
}

// A lowercase array constant plus a root-namespace function whose mapped
// php_ symbol would collide with a php_-prefixed constant spelling.
class Box
{
    public const items = [1, 2];
}

function const__box__items(): int
{
    return 7;
}

function main()
{
    var_dump(C2872FontData::latin());
    var_dump(C2872ChildFontData::latin());
    var_dump(C2872CasePair::A);
    var_dump(C2872CasePair::a);
    var_dump(C2872Canvas::COLORS);
    var_dump(Box::items, const__box__items());
}
?>
--EXPECT--
int(65)
int(65)
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
array(2) {
  [0]=>
  string(1) "r"
  [1]=>
  string(1) "g"
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
int(7)
