<?php
/**
 * Test fixture for closure parameter type narrowing.
 *
 * Each function isolates one scenario. Parameter names are unique to avoid
 * substring-match ambiguity in assertions.
 */

// --- Type declaration narrowing (type decl takes priority) ---

function typeDeclVarInt(int $v): int
{
    $fn = fn(int $p) => $p + 1;
    $i = 42;
    return $fn($i);
}

function typeDeclVarFloat(float $v): float
{
    $fn = fn(float $p) => $p * 2.0;
    $f = 3.14;
    return $fn($f);
}

function typeDeclVarString(string $v): int
{
    $fn = fn(string $p) => strlen($p);
    $s = "hello";
    return $fn($s);
}

function typeDeclVarBool(bool $v): bool
{
    $fn = fn(bool $p) => !$p;
    $b = true;
    return $fn($b);
}

// --- Type declaration + literal (no conversion needed) ---

function typeDeclLitInt(): int
{
    $fn = fn(int $q) => $q + 1;
    return $fn(42);
}

function typeDeclLitFloat(): float
{
    $fn = fn(float $q) => $q * 2.0;
    return $fn(3.14);
}

function typeDeclLitString(): int
{
    $fn = fn(string $q) => strlen($q);
    return $fn("hello");
}

function typeDeclLitBool(): bool
{
    $fn = fn(bool $q) => !$q;
    return $fn(true);
}

// --- Type declaration wins over call-site inference ---

function typeDeclWinsOverInfer(): int
{
    $fn = fn(int $r) => $r + 1;
    $s = "not an int";
    return $fn($s);
}

// --- Call-site literal inference (no type declaration) ---

function callSiteInt(): int
{
    $fn = fn($s1) => $s1 + 1;
    return $fn(42);
}

function callSiteFloat(): float
{
    $fn = fn($s2) => $s2 * 2.0;
    return $fn(3.14);
}

function callSiteBool(): bool
{
    $fn = fn($s3) => !$s3;
    return $fn(true);
}

function callSiteArray(): int
{
    $fn = fn($s4) => count($s4);
    return $fn([1, 2, 3]);
}

// --- Multi-call fallback (all call sites disagree) ---

function multiCallFallback(): void
{
    $fn = fn($m1) => $m1 + 1;
    var_dump($fn(42));
    var_dump($fn(3.14));
}

// --- Unary expressions ---

function unaryNegInt(): int
{
    $fn = fn($u1) => $u1 + 1;
    return $fn(-42);
}

function unaryNegFloat(): float
{
    $fn = fn($u2) => $u2 * 2.0;
    return $fn(-3.14);
}

function unaryPlus(): int
{
    $fn = fn($u3) => $u3 + 1;
    return $fn(+42);
}

// --- Cast expressions ---

function castInt(): int
{
    $fn = fn($c1) => $c1 + 1;
    return $fn((int)"42");
}

function castString(): string
{
    $fn = fn($c2) => $c2;
    return $fn((string)42);
}

function castFloat(): float
{
    $fn = fn($c3) => $c3 * 2.0;
    return $fn((float)"3.14");
}

function castBool(): bool
{
    $fn = fn($c4) => !$c4;
    return $fn((bool)1);
}

// --- goto invalidates candidates ---

function gotoInvalidates(): void
{
    $fn = fn($g1) => $g1 + 1;
    var_dump($fn(1));
    goto end;
    end:
}

// --- Spaceship operator (returns int, not bool) ---

function spaceshipReturnsVar(): void
{
    $fn = fn($sp1) => $sp1;
    var_dump($fn(1 <=> 2));
}

// --- Unary on bool operand (should stay VAR, not bool) ---

function unaryNegBool(): void
{
    $fn = fn($un1) => $un1;
    var_dump($fn(-true));
}

// --- High-precision decimal literal ---

function decimalLiteralInfersDecimal(): void
{
    $fn = fn($dl1) => $dl1;
    var_dump($fn(3.14159265358979323846));
}

// --- Expression arguments (not just literals/variables) ---

function exprArithAdd(): int
{
    $fn = fn($ea1) => $ea1 + 1;
    return $fn(1 + 2);
}

function exprArithMulFloat(): float
{
    $fn = fn($ea2) => $ea2 * 2.0;
    return $fn(3.14 * 2.0);
}

function exprLogicalOr(): void
{
    $fn = fn($eo1) => $eo1;
    var_dump($fn(true || false));
}

function exprConcatString(): void
{
    $fn = fn($ec1) => $ec1;
    var_dump($fn("hello" . "world"));
}

function exprComparisonReturnsBool(): void
{
    $fn = fn($ev1) => $ev1;
    var_dump($fn(1 === 2));
}

function exprTernary(): void
{
    $fn = fn($et1) => $et1;
    var_dump($fn(1 ? 42 : 0));
}

function exprFuncCallReturnsInt(): void
{
    $fn = fn($ef1) => $ef1;
    var_dump($fn(strlen("hello")));
}

// --- Multiple same-type call sites (should narrow) ---

function multiSameTypeNarrows(): void
{
    $fn = fn($ms1) => $ms1 + 1;
    var_dump($fn(10));
    var_dump($fn(20));
    var_dump($fn(30));
}

// --- Binary operators (not just +) ---

function binarySub(): int
{
    $fn = fn($bs1) => $bs1;
    return $fn(1 - 2);
}

function binaryDivFloat(): float
{
    $fn = fn($bd1) => $bd1;
    return $fn(6.0 / 2);
}

function binaryMod(): int
{
    $fn = fn($bm1) => $bm1;
    return $fn(10 % 3);
}

function binaryPow(): int
{
    $fn = fn($bp1) => $bp1;
    return $fn(2 ** 3);
}

// --- Bitwise / boolean operators ---

function bitwiseNot(): int
{
    $fn = fn($bn1) => $bn1;
    return $fn(~1);
}

function booleanNot(): bool
{
    $fn = fn($bt1) => $bt1;
    return $fn(!true);
}

function booleanAnd(): bool
{
    $fn = fn($ba1) => $ba1;
    return $fn(true && false);
}

function logicalXor(): bool
{
    $fn = fn($bx1) => $bx1;
    return $fn(true xor false);
}

// --- Null and empty array edge cases ---

function nullLiteral(): void
{
    $fn = fn($nl1) => $nl1;
    var_dump($fn(null));
}

function emptyArray(): array
{
    $fn = fn($ea3) => $ea3;
    return $fn([]);
}

// --- Multi-param closures ---

function multiParamAllInt(): void
{
    $fn = fn($mp1, $mp2) => $mp1 + $mp2;
    var_dump($fn(10, 20));
}

function multiParamAllFloat(): void
{
    $fn = fn($mp3, $mp4) => $mp3 + $mp4;
    var_dump($fn(1.0, 2.0));
}

function multiParamMixedTypes(): void
{
    $fn = fn($mp5, $mp6) => [$mp5, $mp6];
    var_dump($fn(1, "hello"));
}

// --- Multi-call same non-int types ---

function multiCallAllFloat(): void
{
    $fn = fn($mf1) => $mf1;
    var_dump($fn(1.0));
    var_dump($fn(2.0));
    var_dump($fn(3.0));
}

function multiCallAllString(): void
{
    $fn = fn($ms2) => $ms2;
    var_dump($fn("a"));
    var_dump($fn("b"));
    var_dump($fn("c"));
}

function multiCallAllBool(): void
{
    $fn = fn($mb1) => $mb1;
    var_dump($fn(true));
    var_dump($fn(false));
    var_dump($fn(true));
}

// --- Multi-call 2 same + 1 disagree → VAR ---

function multiCallTwoSameOneDiff(): void
{
    $fn = fn($md1) => $md1;
    var_dump($fn(1));
    var_dump($fn(2));
    var_dump($fn(3.0));
}

// --- Ternary mixed branches → VAR ---

function ternaryMixedBranches(): void
{
    $fn = fn($tm1) => $tm1;
    var_dump($fn(1 ? 42 : "str"));
}

// --- Const fetch ---

function constFetchInt(): void
{
    $fn = fn($cf1) => $cf1;
    var_dump($fn(PHP_INT_MAX));
}

// --- Nested functions (still narrowed) ---

function nestedFnStillNarrowed(): int
{
    $fn = fn($n1) => $n1 + 1;
    return $fn(42);
}

// --- Call-site string literal ---

function callSiteString(): int
{
    $fn = fn($cs1) => strlen($cs1);
    return $fn("hello");
}

// --- Binary shift ---

function binaryShiftLeft(): int
{
    $fn = fn($sl1) => $sl1;
    return $fn(1 << 3);
}

// --- Binary bitwise or ---

function binaryBitwiseOr(): int
{
    $fn = fn($bo1) => $bo1;
    return $fn(0b1010 | 0b1100);
}

// --- Null coalesce (not in detectTypeOfExpr switch → VAR) ---

function nullCoalesce(): void
{
    $nc_var = 1;
    $fn = fn($nc1) => $nc1;
    var_dump($fn($nc_var ?? 0));
}

// --- Multi-param with type declarations + mismatched args ---

function multiParamTypeDeclMismatch(): void
{
    $fn = fn(int $mt1, string $mt2) => [$mt1, $mt2];
    var_dump($fn("hello", 42));
}

// --- Binary shift right ---

function binaryShiftRight(): int
{
    $fn = fn($sr1) => $sr1;
    return $fn(8 >> 1);
}

// --- Binary bitwise and ---

function binaryBitwiseAnd(): int
{
    $fn = fn($ba2) => $ba2;
    return $fn(0b1010 & 0b1100);
}

// --- Binary bitwise xor ---

function binaryBitwiseXor(): int
{
    $fn = fn($bx2) => $bx2;
    return $fn(0b1010 ^ 0b1100);
}

// --- Comparison not equal ---

function comparisonNotEqual(): bool
{
    $fn = fn($ne1) => $ne1;
    return $fn(1 != 2);
}

// --- Cast array ---

function castArray(): array
{
    $fn = fn($ca1) => $ca1;
    return $fn((array)42);
}

// --- Const fetch true ---

function constFetchTrue(): bool
{
    $fn = fn($ct1) => $ct1;
    return $fn(true);
}

// --- Nullable type declaration ---

function nullableIntTypeDecl(): void
{
    $fn = fn(?int $ni1) => $ni1;
    var_dump($fn(42));
}

function nullableIntWithNull(): void
{
    $fn = fn(?int $ni2) => $ni2;
    var_dump($fn(42));
    var_dump($fn(null));
}

// --- Comparison operators (==, <, <=, >, >=) ---

function comparisonEqual(): bool
{
    $fn = fn($ce1) => $ce1;
    return $fn(1 == 2);
}

function comparisonLessThan(): bool
{
    $fn = fn($clt1) => $clt1;
    return $fn(1 < 2);
}

function comparisonLessEqual(): bool
{
    $fn = fn($cle1) => $cle1;
    return $fn(1 <= 2);
}

function comparisonGreaterThan(): bool
{
    $fn = fn($cgt1) => $cgt1;
    return $fn(2 > 1);
}

function comparisonGreaterEqual(): bool
{
    $fn = fn($cge1) => $cge1;
    return $fn(2 >= 1);
}

// --- ConstFetch false, NAN, INF ---

function constFetchFalse(): bool
{
    $fn = fn($cf2) => $cf2;
    return $fn(false);
}

function constFetchNan(): float
{
    $fn = fn($cn1) => $cn1;
    return $fn(NAN);
}

function constFetchInf(): float
{
    $fn = fn($ci1) => $ci1;
    return $fn(INF);
}

// --- Union type declaration (always VAR) ---

function unionTypeDecl(): void
{
    $fn = fn(int|string $ut1) => $ut1;
    var_dump($fn(42));
}

// --- Binary mul int ---

function binaryMulInt(): int
{
    $fn = fn($bmi1) => $bmi1;
    return $fn(2 * 3);
}

// --- Type declaration scenarios: fallback to VAR + runtime check ---

function arrayTypeDecl(): void
{
    $fn = fn(array $x) => count($x);
    var_dump($fn([1, 2, 3]));
}

function objectTypeDecl(): void
{
    $fn = fn(object $x) => $x;
    var_dump($fn(new \stdClass()));
}

function classTypeDecl(): void
{
    $fn = fn(\DateTime $x) => $x->format('Y');
    var_dump($fn(new \DateTime()));
}

function intTypeDecl(): void
{
    $fn = fn(int $x) => $x + 1;
    var_dump($fn(42));
}

function inferredArrayNoDecl(): int
{
    $fn = fn($x) => count($x);
    return $fn([1, 2, 3]);
}

// --- Entry point ---
function main(): void
{
    typeDeclVarInt(10);
    typeDeclVarFloat(1.5);
    typeDeclVarString("test");
    typeDeclVarBool(false);
    typeDeclLitInt();
    typeDeclLitFloat();
    typeDeclLitString();
    typeDeclLitBool();
    typeDeclWinsOverInfer();
    callSiteInt();
    callSiteFloat();
    callSiteBool();
    callSiteArray();
    callSiteString();
    multiCallFallback();
    unaryNegInt();
    unaryNegFloat();
    unaryPlus();
    castInt();
    castString();
    castFloat();
    castBool();
    gotoInvalidates();
    exprArithAdd();
    exprArithMulFloat();
    exprLogicalOr();
    exprConcatString();
    exprComparisonReturnsBool();
    exprTernary();
    exprFuncCallReturnsInt();
    spaceshipReturnsVar();
    unaryNegBool();
    decimalLiteralInfersDecimal();
    multiSameTypeNarrows();
    nestedFnStillNarrowed();
    binarySub();
    binaryDivFloat();
    binaryMod();
    binaryPow();
    bitwiseNot();
    booleanNot();
    booleanAnd();
    logicalXor();
    nullLiteral();
    emptyArray();
    multiParamAllInt();
    multiParamAllFloat();
    multiParamMixedTypes();
    multiCallAllFloat();
    multiCallAllString();
    multiCallAllBool();
    multiCallTwoSameOneDiff();
    ternaryMixedBranches();
    constFetchInt();
    binaryShiftLeft();
    binaryBitwiseOr();
    nullCoalesce();
    multiParamTypeDeclMismatch();
    binaryShiftRight();
    binaryBitwiseAnd();
    binaryBitwiseXor();
    comparisonNotEqual();
    castArray();
    constFetchTrue();
    nullableIntTypeDecl();
    nullableIntWithNull();
    comparisonEqual();
    comparisonLessThan();
    comparisonLessEqual();
    comparisonGreaterThan();
    comparisonGreaterEqual();
    constFetchFalse();
    constFetchNan();
    constFetchInf();
    unionTypeDecl();
    binaryMulInt();
    arrayTypeDecl();
    objectTypeDecl();
    classTypeDecl();
    intTypeDecl();
    inferredArrayNoDecl();
}
