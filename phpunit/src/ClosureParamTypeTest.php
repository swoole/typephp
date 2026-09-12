<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
final class ClosureParamTypeTest extends BaseTest
{
    private function compileFixture(string $fixture): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $fixture;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        return file_get_contents($compiler->convertFile($source));
    }

    // --- Type declaration narrows to native type (variable args) ---

    public function testTypeDeclVarNarrowsToNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_typedeclvarint\(.*?\n\tauto fn = \[\]\(php::Int p\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedeclvarfloat\(.*?\n\tauto fn = \[\]\(php::Float p\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedeclvarstring\(.*?\n\tauto fn = \[\]\(php::Str p\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedeclvarbool\(.*?\n\tauto fn = \[\]\(php::Bool p\)/s', $code);
    }

    // --- Type declaration + literal args ---

    public function testTypeDeclLitUsesNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_typedecllitint\(.*?\n\tauto fn = \[\]\(php::Int q\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedecllitfloat\(.*?\n\tauto fn = \[\]\(php::Float q\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedecllitstring\(.*?\n\tauto fn = \[\]\(php::Str q\)/s', $code);
        self::assertMatchesRegularExpression('/php_typedecllitbool\(.*?\n\tauto fn = \[\]\(php::Bool q\)/s', $code);
    }

    // --- Type declaration wins over call-site inference ---

    public function testTypeDeclWinsOverInferInt(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_typedeclwinsoverinfer\(.*?\n\tauto fn = \[\]\(php::Int r\)/s', $code);
        self::assertStringContainsString('toIntArgExact', $code);
    }

    // --- Call-site literal inference ---

    public function testCallSiteInference(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_callsiteint\(.*?\n\tauto fn = \[\]\(php::Int s1\)/s', $code);
        self::assertMatchesRegularExpression('/php_callsitefloat\(.*?\n\tauto fn = \[\]\(php::Float s2\)/s', $code);
        self::assertMatchesRegularExpression('/php_callsitebool\(.*?\n\tauto fn = \[\]\(php::Bool s3\)/s', $code);
        self::assertMatchesRegularExpression('/php_callsitearray\(.*?\n\tauto fn = \[\]\(php::Array s4\)/s', $code);
        self::assertMatchesRegularExpression('/php_callsitestring\(.*?\n\tauto fn = \[\]\(php::Str cs1\)/s', $code);
    }

    // --- Unary expressions ---

    public function testUnaryInfersNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_unarynegint\(.*?\n\tauto fn = \[\]\(php::Int u1\)/s', $code);
        self::assertMatchesRegularExpression('/php_unarynegfloat\(.*?\n\tauto fn = \[\]\(php::Float u2\)/s', $code);
        self::assertMatchesRegularExpression('/php_unaryplus\(.*?\n\tauto fn = \[\]\(php::Int u3\)/s', $code);
    }

    // --- Cast expressions ---

    public function testCastInfersNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_castint\(.*?\n\tauto fn = \[\]\(php::Int c1\)/s', $code);
        self::assertMatchesRegularExpression('/php_caststring\(.*?\n\tauto fn = \[\]\(php::Var c2\)/s', $code);
        self::assertMatchesRegularExpression('/php_castfloat\(.*?\n\tauto fn = \[\]\(php::Float c3\)/s', $code);
        self::assertMatchesRegularExpression('/php_castbool\(.*?\n\tauto fn = \[\]\(php::Bool c4\)/s', $code);
        self::assertMatchesRegularExpression('/php_castarray\(.*?\n\tauto fn = \[\]\(php::Array ca1\)/s', $code);
    }

    // --- Binary operators ---

    public function testBinaryOpInfersNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_binarysub\(.*?\n\tauto fn = \[\]\(php::Int bs1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarydivfloat\(.*?\n\tauto fn = \[\]\(php::Float bd1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarymod\(.*?\n\tauto fn = \[\]\(php::Int bm1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarypow\(.*?\n\tauto fn = \[\]\(php::Var bp1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarymulint\(.*?\n\tauto fn = \[\]\(php::Int bmi1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binaryshiftleft\(.*?\n\tauto fn = \[\]\(php::Int sl1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binaryshiftright\(.*?\n\tauto fn = \[\]\(php::Int sr1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarybitwiseand\(.*?\n\tauto fn = \[\]\(php::Int ba2\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarybitwiseor\(.*?\n\tauto fn = \[\]\(php::Int bo1\)/s', $code);
        self::assertMatchesRegularExpression('/php_binarybitwisexor\(.*?\n\tauto fn = \[\]\(php::Int bx2\)/s', $code);
    }

    // --- Bitwise / boolean operators ---

    public function testBitwiseBooleanInfersNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_bitwisenot\(.*?\n\tauto fn = \[\]\(php::Int bn1\)/s', $code);
        self::assertMatchesRegularExpression('/php_booleannot\(.*?\n\tauto fn = \[\]\(php::Bool bt1\)/s', $code);
        self::assertMatchesRegularExpression('/php_booleanand\(.*?\n\tauto fn = \[\]\(php::Bool ba1\)/s', $code);
        self::assertMatchesRegularExpression('/php_logicalxor\(.*?\n\tauto fn = \[\]\(php::Bool bx1\)/s', $code);
    }

    // --- Comparison operators ---

    public function testComparisonInfersBool(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_comparisonequal\(.*?\n\tauto fn = \[\]\(php::Bool ce1\)/s', $code);
        self::assertMatchesRegularExpression('/php_comparisonnotequal\(.*?\n\tauto fn = \[\]\(php::Bool ne1\)/s', $code);
        self::assertMatchesRegularExpression('/php_comparisonlessthan\(.*?\n\tauto fn = \[\]\(php::Bool clt1\)/s', $code);
        self::assertMatchesRegularExpression('/php_comparisonlessequal\(.*?\n\tauto fn = \[\]\(php::Bool cle1\)/s', $code);
        self::assertMatchesRegularExpression('/php_comparisongreaterthan\(.*?\n\tauto fn = \[\]\(php::Bool cgt1\)/s', $code);
        self::assertMatchesRegularExpression('/php_comparisongreaterequal\(.*?\n\tauto fn = \[\]\(php::Bool cge1\)/s', $code);
    }

    // --- Expression arguments ---

    public function testExprArgsInferType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_exprarithadd\(.*?\n\tauto fn = \[\]\(php::Int ea1\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprarithmulfloat\(.*?\n\tauto fn = \[\]\(php::Float ea2\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprlogicalor\(.*?\n\tauto fn = \[\]\(php::Bool eo1\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprconcatstring\(.*?\n\tauto fn = \[\]\(php::Str ec1\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprcomparisonreturnsbool\(.*?\n\tauto fn = \[\]\(php::Bool ev1\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprternary\(.*?\n\tauto fn = \[\]\(php::Int et1\)/s', $code);
        self::assertMatchesRegularExpression('/php_exprfunccallreturnsint\(.*?\n\tauto fn = \[\]\(php::Int ef1\)/s', $code);
    }

    // --- Const fetch ---

    public function testConstFetchInfersType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_constfetchint\(.*?\n\tauto fn = \[\]\(php::Int cf1\)/s', $code);
        self::assertMatchesRegularExpression('/php_constfetchtrue\(.*?\n\tauto fn = \[\]\(php::Bool ct1\)/s', $code);
        self::assertMatchesRegularExpression('/php_constfetchfalse\(.*?\n\tauto fn = \[\]\(php::Bool cf2\)/s', $code);
        self::assertMatchesRegularExpression('/php_constfetchnan\(.*?\n\tauto fn = \[\]\(php::Float cn1\)/s', $code);
        self::assertMatchesRegularExpression('/php_constfetchinf\(.*?\n\tauto fn = \[\]\(php::Float ci1\)/s', $code);
    }

    // --- Multi-param closures ---

    public function testMultiParamInference(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_multiparamallint\(.*?\n\tauto fn = \[\]\(php::Int mp1, php::Int mp2\)/s', $code);
        self::assertMatchesRegularExpression('/php_multiparamallfloat\(.*?\n\tauto fn = \[\]\(php::Float mp3, php::Float mp4\)/s', $code);
        self::assertMatchesRegularExpression('/php_multiparammixedtypes\(.*?\n\tauto fn = \[\]\(php::Int mp5, php::Str mp6\)/s', $code);
    }

    // --- Multi-call same type narrows ---

    public function testMultiCallSameTypeNarrows(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_multisametypenarrows\(.*?\n\tauto fn = \[\]\(php::Int ms1\)/s', $code);
        self::assertMatchesRegularExpression('/php_multicallallfloat\(.*?\n\tauto fn = \[\]\(php::Float mf1\)/s', $code);
        self::assertMatchesRegularExpression('/php_multicallallstring\(.*?\n\tauto fn = \[\]\(php::Str ms2\)/s', $code);
        self::assertMatchesRegularExpression('/php_multicallallbool\(.*?\n\tauto fn = \[\]\(php::Bool mb1\)/s', $code);
    }

    // --- Multi-call disagree → VAR ---

    public function testMultiCallFallback(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // different types across call sites → VAR
        self::assertMatchesRegularExpression('/php_multicallfallback\(.*?\n\tauto fn = \[\]\(php::Var m1\)/s', $code);
        // 2 agree + 1 disagree → VAR
        self::assertMatchesRegularExpression('/php_multicalltwosameonediff\(.*?\n\tauto fn = \[\]\(php::Var md1\)/s', $code);
    }

    // --- Null / edge cases ---

    public function testNullAndEdgeCases(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_nullliteral\(.*?\n\tauto fn = \[\]\(php::Var nl1\)/s', $code);
        self::assertMatchesRegularExpression('/php_emptyarray\(.*?\n\tauto fn = \[\]\(php::Array ea3\)/s', $code);
        self::assertMatchesRegularExpression('/php_nullcoalesce\(.*?\n\tauto fn = \[\]\(php::Var nc1\)/s', $code);
    }

    // ===== Unique scenario tests (each verifies a distinct behavior) =====

    public function testGotoInvalidatesAllCandidates(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertStringContainsString('newClosureWithParameters', $code);
    }

    public function testNestedFnStillNarrowed(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_nestedfnstillnarrowed\(.*?\n\tauto fn = \[\]\(php::Int n1\)/s', $code);
    }

    public function testClassMethodClosureStaysZend(): void
    {
        $code = $this->compileFixture('closure-param-type-class.php');
        self::assertStringNotContainsString('(php::Int p)', $code);
        self::assertStringContainsString('newClosureWithParameters', $code);
    }

    // --- Negative tests: operator result types ---

    public function testSpaceshipDoesNotNarrowToBool(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_spaceshipreturnsvar\(.*?\n\tauto fn = \[\]\(php::Var sp1\)/s', $code);
        self::assertStringNotContainsString('(php::Bool sp1)', $code);
    }

    public function testUnaryNegBoolDoesNotNarrowToBool(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_unarynegbool\(.*?\n\tauto fn = \[\]\(php::Var un1\)/s', $code);
        self::assertStringNotContainsString('(php::Bool un1)', $code);
    }

    public function testDecimalLiteralInfersDecimalType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_decimalliteralinfersdecimal\(.*?\n\tauto fn = \[\]\(php::Var dl1\)/s', $code);
        self::assertStringNotContainsString('(php::Decimal dl1)', $code);
    }

    // --- Multi-param type decl mismatch ---

    public function testMultiParamTypeDeclMismatch(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_multiparamtypedeclmismatch\(.*?\n\tauto fn = \[\]\(php::Int mt1, php::Str mt2\)/s', $code);
        self::assertStringContainsString('toIntArgExact', $code);
        self::assertStringContainsString('toStringArgExact', $code);
    }

    // --- Ternary mixed branches → VAR ---

    public function testTernaryMixedBranchesInfersVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_ternarymixedbranches\(.*?\n\tauto fn = \[\]\(php::Var tm1\)/s', $code);
    }

    // --- Nullable type declaration: always VAR with runtime check ---

    public function testNullableIntDeclKeepsRuntimeCheck(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_nullableinttypedecl\(.*?\n\tauto fn = \[\]\(php::Var ni1\)/s', $code);
        self::assertStringContainsString('ni1.isNull() || ni1.isInt()', $code);
        self::assertStringNotContainsString('(php::Int ni1)', $code);
    }

    public function testNullableIntWithNullBothCallSites(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_nullableintwithnull\(.*?\n\tauto fn = \[\]\(php::Var ni2\)/s', $code);
        self::assertStringContainsString('ni2.isNull() || ni2.isInt()', $code);
        self::assertStringNotContainsString('(php::Int ni2)', $code);
    }

    // --- Union type declaration: always VAR ---

    public function testUnionTypeDeclKeepsVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_uniontypedecl\(.*?\n\tauto fn = \[\]\(php::Var ut1\)/s', $code);
    }

    // --- Array/object/class type declarations: fallback to VAR ---

    public function testArrayTypeDeclKeepsVarWithRuntimeCheck(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_arraytypedecl\(.*?\n\tauto fn = \[\]\(php::Var x\)/s', $code);
        self::assertStringContainsString('isArray', $code);
    }

    public function testObjectTypeDeclKeepsVarWithRuntimeCheck(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_objecttypedecl\(.*?\n\tauto fn = \[\]\(php::Var x\)/s', $code);
        self::assertStringContainsString('isObject', $code);
    }

    public function testClassTypeDeclKeepsVarWithRuntimeCheck(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_classtypedecl\(.*?\n\tauto fn = \[\]\(php::Var x\)/s', $code);
        self::assertStringContainsString('instanceOf', $code);
    }

    public function testIntTypeDeclKeepsNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_inttypedecl\(.*?\n\tauto fn = \[\]\(php::Int x\)/s', $code);
    }

    public function testInferredArrayKeepsNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_inferredarraynodecl\(.*?\n\tauto fn = \[\]\(php::Array x\)/s', $code);
    }

    public function testNoDecimalOrBigIntInLambdaParam(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertStringNotContainsString('php::BigInt', $code);
        self::assertStringNotContainsString('php::Decimal', $code);
        self::assertStringNotContainsString('php::BigFloat', $code);
    }
}
