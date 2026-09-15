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
        // An array literal is materialized into a php::Var temporary, so the
        // parameter stays php::Var: narrowing it to php::Array would only add an
        // implicit conversion at every call without changing the ABI.
        self::assertMatchesRegularExpression('/php_callsitearray\(.*?\n\tauto fn = \[\]\(php::Var s4\)/s', $code);
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
        self::assertMatchesRegularExpression('/php_emptyarray\(.*?\n\tauto fn = \[\]\(php::Var ea3\)/s', $code);
        self::assertMatchesRegularExpression('/php_nullcoalesce\(.*?\n\tauto fn = \[\]\(php::Var nc1\)/s', $code);
    }

    // ===== Unique scenario tests (each verifies a distinct behavior) =====

    public function testGotoInvalidatesAllCandidates(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // Scoped to the goto function on purpose: a whole-file assertion passes
        // even when the candidate is invalidated, because other closures in the
        // same fixture already emit newClosureWithParameters.
        $body = $this->extractFunction($code, 'php_gotoinvalidates');
        self::assertNotSame('', $body, 'php_gotoinvalidates() not found in generated code');
        self::assertStringContainsString('newClosureWithParameters', $body);
        self::assertStringNotContainsString('auto fn = [](php::Int', $body);
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
        self::assertStringNotContainsString('(php::Int ni1)', $code);
        // The runtime check now runs at the call site so that parameters are
        // validated in PHP's binding order, therefore it is emitted against the
        // argument temporary rather than the lambda parameter name.
        $body = $this->extractFunction($code, 'php_nullableinttypedecl');
        self::assertStringContainsString('.isNull() || ', $body);
        self::assertStringContainsString('.isInt()', $body);
    }

    public function testNullableIntWithNullBothCallSites(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_nullableintwithnull\(.*?\n\tauto fn = \[\]\(php::Var ni2\)/s', $code);
        self::assertStringNotContainsString('(php::Int ni2)', $code);
        $body = $this->extractFunction($code, 'php_nullableintwithnull');
        self::assertStringContainsString('.isNull() || ', $body);
        self::assertStringContainsString('.isInt()', $body);
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

    public function testInferredArrayLiteralStaysVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // The array literal is materialized into a php::Var temporary, so php::Var
        // is the C++ ABI type actually produced at the call boundary.
        self::assertMatchesRegularExpression('/php_inferredarraynodecl\(.*?\n\tauto fn = \[\]\(php::Var x\)/s', $code);
    }

    public function testNoDecimalOrBigIntInLambdaParam(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertStringNotContainsString('php::BigInt', $code);
        self::assertStringNotContainsString('php::Decimal', $code);
        self::assertStringNotContainsString('php::BigFloat', $code);
    }

    // --- varint_types mode: closure params use php::Var for inferred ints ---

    public function testVarintModeTypeDeclStillUsesNativeType(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        self::assertMatchesRegularExpression('/php_varinttypedeclint\(.*?\n\tauto fn = \[\]\(php::Int p\)/s', $code);
        self::assertMatchesRegularExpression('/php_varinttypedeclfloat\(.*?\n\tauto fn = \[\]\(php::Float p\)/s', $code);
    }

    public function testVarintModeInferredIntUsesVar(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        // Lambda parameter is php::Int (type declaration), call site uses toIntArgExact
        self::assertMatchesRegularExpression('/php_varintinferredint\(.*?\n\tauto fn = \[\]\(php::Int vi1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(((a) + (b)), "{closure}", 1, "vi1")', $code);
        self::assertMatchesRegularExpression('/php_varintinferredintsub\(.*?\n\tauto fn = \[\]\(php::Int vis1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(((a) - (b)), "{closure}", 1, "vis1")', $code);
        self::assertMatchesRegularExpression('/php_varintinferredintmul\(.*?\n\tauto fn = \[\]\(php::Int vim1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(((a) * (b)), "{closure}", 1, "vim1")', $code);
    }

    public function testVarintModeModUsesVar(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        self::assertMatchesRegularExpression('/php_varintinferredintmod\(.*?\n\tauto fn = \[\]\(php::Int vimod1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(php::fn::mod(a, b), "{closure}", 1, "vimod1")', $code);
    }

    public function testVarintModeShiftUsesVar(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        self::assertMatchesRegularExpression('/php_varintinferredintshiftleft\(.*?\n\tauto fn = \[\]\(php::Int visl1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(((a) << (b)), "{closure}", 1, "visl1")', $code);
        self::assertMatchesRegularExpression('/php_varintinferredintshiftright\(.*?\n\tauto fn = \[\]\(php::Int visr1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(((a) >> (b)), "{closure}", 1, "visr1")', $code);
    }

    public function testVarintModePowUsesVar(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        self::assertMatchesRegularExpression('/php_varintbinarypow\(.*?\n\tauto fn = \[\]\(php::Int vbp1\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(php::fn::pow(a, b), "{closure}", 1, "vbp1")', $code);
    }

    // --- Modulo with float operands → php::fn::mod() → VAR ---

    public function testBinaryModFloatInfersVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertMatchesRegularExpression('/php_binarymodfloat\(.*?\n\tauto fn = \[\]\(php::Var bmf1\)/s', $code);
    }

    // --- varint_types mode: float division → Variant ---

    public function testVarintModeFloatDivUsesVar(): void
    {
        $code = $this->compileFixture('closure-param-type-varint.php');
        self::assertMatchesRegularExpression('/php_varintfloatdiv\(.*?\n\tauto fn = \[\]\(php::Var vfdiv1\)/s', $code);
    }

    // --- P1 fix: PropertyFetch always returns php::Var ---

    public function testPropertyFetchIntNarrowsToVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // $box->value is PropertyFetch → php::Var. Lambda param is php::Int (from
        // type declaration), call site must wrap in toIntArgExact for conversion.
        self::assertMatchesRegularExpression('/php_propertyfetchinttypedecl\(.*?\n\tauto fn = \[\]\(php::Int p\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(php::deindirect(', $code);
    }

    // --- PropertyFetch with untyped closure param → should NOT narrow ---

    public function testPropertyFetchUntypedParamProducesVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // $box->value is PropertyFetch → php::Var. Closure param $x has NO type
        // declaration, so lambda param should be php::Var (not narrowed to Int).
        // No toIntArgExact wrapper needed at call site.
        self::assertMatchesRegularExpression('/php_propertyfetchuntypedparam\(.*?\n\tauto fn = \[\]\(php::Var x\)/s', $code);
        // No type cast wrapper for the untyped param
        self::assertStringNotContainsString('toIntArgExact', $this->extractFunction($code, 'php_propertyfetchuntypedparam'));
    }

    private function extractFunction(string $code, string $funcName): string
    {
        $pattern = '/void ' . preg_quote($funcName) . '\(\)[^{]*\{(.*?)(?=\nvoid |\n[a-z]|\z)/s';
        return preg_match($pattern, $code, $m) ? $m[1] : '';
    }

    // --- P1 fix: multi-arg cast materialized to temp vars ---

    public function testMultiArgEvalOrderMaterializes(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // Two PropertyFetch args → both need toIntArgExact/toFloatArgExact.
        // C++17 evaluation order: cast results materialized to temp vars.
        self::assertMatchesRegularExpression('/php_multiargevalorder\(/s', $code);
        self::assertStringContainsString('auto tmp_var_', $code);
        self::assertStringContainsString('php::toIntArgExact(php::deindirect(', $code);
        self::assertStringContainsString('php::toFloatArgExact(php::deindirect(', $code);
    }

    // --- StaticPropertyFetch → always php::Var ---

    public function testStaticPropertyFetchNarrowsToVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // StaticBox::$value is StaticPropertyFetch → php::Var. Lambda param is
        // php::Int (from type decl), call site must wrap in toIntArgExact.
        self::assertMatchesRegularExpression('/php_staticpropertyfetch\(.*?\n\tauto fn = \[\]\(php::Int p\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(', $this->extractFunction($code, 'php_staticpropertyfetch'));
    }

    // --- NullsafePropertyFetch → always php::Var ---

    public function testNullsafePropertyFetchNarrowsToVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // $box?->value is NullsafePropertyFetch → php::Var. Lambda param is
        // php::Int (from type decl), call site must wrap in toIntArgExact.
        self::assertMatchesRegularExpression('/php_nullsafepropertyfetch\(.*?\n\tauto fn = \[\]\(php::Int p\)/s', $code);
        self::assertStringContainsString('php::toIntArgExact(', $this->extractFunction($code, 'php_nullsafepropertyfetch'));
    }

    // --- use() capture: captured int ---

    public function testUseCaptureInt(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // Captured $i should be accessible inside the closure body.
        self::assertMatchesRegularExpression('/php_usecaptureint\(/s', $code);
    }

    // --- Property write as argument ---

    public function testPropertyWriteAsArg(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // $box->value = 10 is an Assign wrapping PropertyFetch.
        // Lambda param is php::Int (from type decl), call site must wrap.
        self::assertMatchesRegularExpression('/php_propertywriteasarg\(.*?\n\tauto fn = \[\]\(php::Int v\)/s', $code);
    }

    // --- Box type declarations (decimal / bigint / bigfloat) ---

    public function testBoxTypeDeclStaysVar(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // php::Var has no conversion to a Box in either direction, so a declared
        // Box parameter must stay php::Var; narrowing it to php::Decimal and
        // friends makes the call boundary reject every non-native argument and
        // also breaks the lambda's own php::Var return type.
        self::assertMatchesRegularExpression('/php_boxtypedecldecimal\(.*?\n\tauto fn = \[\]\(php::Var bd1\)/s', $code);
        self::assertMatchesRegularExpression('/php_boxtypedeclbigint\(.*?\n\tauto fn = \[\]\(php::Var bb1\)/s', $code);
        self::assertMatchesRegularExpression('/php_boxtypedeclbigfloat\(.*?\n\tauto fn = \[\]\(php::Var bf1\)/s', $code);
    }

    public function testBoxTypeDeclDoesNotNarrow(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        self::assertStringNotContainsString('(php::Decimal bd1)', $code);
        self::assertStringNotContainsString('(php::BigInt bb1)', $code);
        self::assertStringNotContainsString('(php::BigFloat bf1)', $code);
    }

    // --- Match expression as argument ---

    public function testMatchAsArg(): void
    {
        $code = $this->compileFixture('closure-param-type.php');
        // match() produces php::Var. Lambda param is php::Int (from type decl).
        self::assertMatchesRegularExpression('/php_matchasarg\(/s', $code);
    }

    // --- A parameter written by the body must not be narrowed ---

    public function testReassignedParamStaysVar(): void
    {
        $code = $this->compileFixture('closure-param-reassign.php');
        // PHP allows re-assigning a parameter to any other type, so a narrowed
        // parameter would truncate the new value or fail to compile.
        self::assertMatchesRegularExpression('/php_reassigntostring\(.*?\n\tauto fn = \[\]\(php::Var rwStr\)/s', $code);
        self::assertMatchesRegularExpression('/php_reassigntofloat\(.*?\n\tauto fn = \[\]\(php::Var rwFloat\)/s', $code);
        self::assertMatchesRegularExpression('/php_incrementparam\(.*?\n\tauto fn = \[\]\(php::Var rwInc\)/s', $code);
        self::assertMatchesRegularExpression('/php_writethrougharraydim\(.*?\n\tauto fn = \[\]\(php::Var rwDim\)/s', $code);
    }

    public function testUntouchedParamStillNarrows(): void
    {
        $code = $this->compileFixture('closure-param-reassign.php');
        // The write check must not disable narrowing for read-only parameters.
        self::assertMatchesRegularExpression('/php_untouchedparamstillnarrows\(.*?\n\tauto fn = \[\]\(php::Int roKeep\)/s', $code);
    }
}
