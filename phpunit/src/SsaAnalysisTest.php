<?php

namespace TypePhp\Tests;

use TypePhp\Type;

use PHPUnit\Framework\TestCase;
use TypePhp\Analysis\SsaBuilder;
use TypePhp\Analysis\SsaFlags;
use TypePhp\Analysis\SsaVar;
use TypePhp\Analysis\SsaBlock;
use TypePhp\Analysis\VarState;
use TypePhp\Analysis\PiConstraint;
use TypePhp\CompilerTest;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar;
use PhpParser\ParserFactory;

class SsaAnalysisTest extends TestCase
{
    private CompilerTest $compiler;
    private \ReflectionClass $ref;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        // SsaFlags, SsaVar, SsaBlock, etc. are defined in SsaBuilder.php
        // but PSR-4 expects each class in its own file. Load them early.
        require_once __DIR__ . '/../../src/Analysis/SsaBuilder.php';
        $this->tmpDir = sys_get_temp_dir() . '/ssa_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->compiler = CompilerTest::create($this->tmpDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function invoke(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    private function setProperty(string $name, mixed $value): void
    {
        if ($name === 'classes') {
            $prop = $this->ref->getProperty('symbols');
            $prop->setAccessible(true);
            $prop->getValue($this->compiler)->replaceClasses($value);
            return;
        }
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function getContextProperty(string $name): mixed
    {
        $ctxProp = $this->ref->getProperty('context');
        $ctxProp->setAccessible(true);
        $context = $ctxProp->getValue($this->compiler);
        $prop = new \ReflectionProperty($context, $name);
        $prop->setAccessible(true);
        return $prop->getValue($context);
    }

    private function setContextProperty(string $name, mixed $value): void
    {
        $ctxProp = $this->ref->getProperty('context');
        $ctxProp->setAccessible(true);
        $context = $ctxProp->getValue($this->compiler);
        $prop = new \ReflectionProperty($context, $name);
        $prop->setAccessible(true);
        $prop->setValue($context, $value);
    }

    private function optimizeLoopVarsForCode(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse('<?php function f($s = "") { ' . $code . ' }');
        $this->assertNotNull($stmts);

        $fn = $stmts[0];
        $this->assertInstanceOf(FunctionLike::class, $fn);

        $this->invoke('resetFunction');
        $builder = new SsaBuilder($fn->getStmts() ?: [], []);
        $builder->build();
        $this->setContextProperty('ssaBuilder', $builder);

        $this->invoke('optimizeLoopVars', $builder);

        return $this->getContextProperty('localVars');
    }

    // ========================================================================
    // SsaFlags: constant values
    // ========================================================================

    public function testSsaFlagsAreDistinct(): void
    {
        $flags = [
            SsaFlags::UNDEFINED,
            SsaFlags::REFERENCE,
            SsaFlags::ESCAPED,
            SsaFlags::PHI,
            SsaFlags::PARAM,
            SsaFlags::KILLED,
        ];
        $this->assertCount(6, array_unique($flags), 'All SSA flags must be distinct powers of 2');
    }

    public function testSsaFlagsArePowersOfTwo(): void
    {
        $flags = [
            SsaFlags::UNDEFINED,
            SsaFlags::REFERENCE,
            SsaFlags::ESCAPED,
            SsaFlags::PHI,
            SsaFlags::PARAM,
            SsaFlags::KILLED,
        ];
        foreach ($flags as $flag) {
            $this->assertGreaterThan(0, $flag);
            $this->assertEquals(0, $flag & ($flag - 1), "Flag {$flag} must be a power of 2");
        }
    }

    public function testSsaFlagValues(): void
    {
        $this->assertEquals(1, SsaFlags::UNDEFINED);
        $this->assertEquals(2, SsaFlags::REFERENCE);
        $this->assertEquals(4, SsaFlags::ESCAPED);
        $this->assertEquals(8, SsaFlags::PHI);
        $this->assertEquals(16, SsaFlags::PARAM);
        $this->assertEquals(32, SsaFlags::KILLED);
    }

    // ========================================================================
    // SsaVar
    // ========================================================================

    public function testSsaVarCreation(): void
    {
        $var = new SsaVar(42, 'myVar', SsaFlags::PARAM);
        $this->assertEquals(42, $var->id);
        $this->assertEquals('myVar', $var->origName);
        $this->assertEquals(SsaFlags::PARAM, $var->flags);
        $this->assertNull($var->definition);
        $this->assertNull($var->pi);
        $this->assertEmpty($var->phiSources);
        $this->assertFalse($var->isConstant);
        $this->assertNull($var->constantValue);
    }

    public function testSsaVarDefaults(): void
    {
        $var = new SsaVar(1, 'x');
        $this->assertEquals(0, $var->flags);
        $this->assertNull($var->definition);
    }

    public function testSsaVarFlagsCombinable(): void
    {
        $flags = SsaFlags::REFERENCE | SsaFlags::ESCAPED;
        $var = new SsaVar(1, 'x', $flags);
        $this->assertTrue(($var->flags & SsaFlags::REFERENCE) !== 0);
        $this->assertTrue(($var->flags & SsaFlags::ESCAPED) !== 0);
        $this->assertFalse(($var->flags & SsaFlags::PHI) !== 0);
    }

    // ========================================================================
    // PiConstraint
    // ========================================================================

    public function testPiConstraintDefaults(): void
    {
        $pi = new PiConstraint();
        $this->assertEquals('', $pi->narrowedType);
        $this->assertNull($pi->condition);
        $this->assertTrue($pi->isInstanceof);
        $this->assertFalse($pi->hasRange);
        $this->assertEquals(0, $pi->rangeMin);
        $this->assertEquals(0, $pi->rangeMax);
    }

    public function testPiConstraintCustom(): void
    {
        $pi = new PiConstraint();
        $pi->narrowedType = 'MyClass';
        $pi->isInstanceof = false;
        $pi->hasRange = true;
        $pi->rangeMin = 1;
        $pi->rangeMax = 10;

        $this->assertEquals('MyClass', $pi->narrowedType);
        $this->assertFalse($pi->isInstanceof);
        $this->assertTrue($pi->hasRange);
        $this->assertEquals(1, $pi->rangeMin);
        $this->assertEquals(10, $pi->rangeMax);
    }

    // ========================================================================
    // SsaBlock
    // ========================================================================

    public function testSsaBlockDefaults(): void
    {
        $block = new SsaBlock();
        $this->assertEmpty($block->stmts);
        $this->assertEmpty($block->predecessors);
        $this->assertEmpty($block->successors);
        $this->assertFalse($block->isGotoTarget);
        $this->assertNull($block->labelName);
        $this->assertFalse($block->endsWithGoto);
        $this->assertNull($block->gotoLabel);
        $this->assertEquals(-1, $block->branchTrueBlock);
        $this->assertEquals(-1, $block->branchFalseBlock);
        $this->assertEquals(-1, $block->branchJoinBlock);
        $this->assertFalse($block->isJoinPoint);
        $this->assertEquals(-1, $block->forceJumpTo);
        $this->assertEmpty($block->phi);
        $this->assertEquals(-1, $block->dominator);
        $this->assertEmpty($block->dominatedChildren);
        $this->assertEmpty($block->dominanceFrontier);
    }

    // ========================================================================
    // VarState
    // ========================================================================

    public function testVarStateDefaults(): void
    {
        $state = new VarState();
        $this->assertEmpty($state->stack);
        $this->assertEquals(0, $state->counter);
    }

    // ========================================================================
    // SsaBuilder: basic construction
    // ========================================================================

    public function testSsaBuilderEmptyFunction(): void
    {
        $builder = new SsaBuilder([]);
        $builder->build();

        $this->assertGreaterThanOrEqual(2, count($builder->blocks), 'Should have at least entry + exit blocks');
        $this->assertEmpty($builder->ssaVars);
    }

    public function testSsaBuilderSimpleAssignment(): void
    {
        $var = new Expr\Variable('x');
        $val = new Scalar\LNumber(42);
        $assign = new Stmt\Expression(new Expr\Assign($var, $val));

        $builder = new SsaBuilder([$assign]);
        $builder->build();

        $this->assertGreaterThan(0, count($builder->ssaVars), 'Should create SSA var for assignment');
    }

    public function testSsaBuilderWithParams(): void
    {
        $argInfoList = [
            (object) ['name' => 'a', 'byRef' => false],
            (object) ['name' => 'b', 'byRef' => true],
        ];

        $builder = new SsaBuilder([], $argInfoList);
        $builder->build();

        $paramVars = array_filter($builder->ssaVars, fn($v) => ($v->flags & SsaFlags::PARAM) !== 0);
        $this->assertCount(2, $paramVars, 'Two parameters should create 2 PARAM SSA vars');

        $bVars = array_filter($paramVars, fn($v) => $v->origName === 'b');
        $bVar = reset($bVars);
        $this->assertNotFalse($bVar);
        $this->assertTrue(($bVar->flags & SsaFlags::REFERENCE) !== 0, 'byRef param should have REFERENCE flag');
    }

    public function testSsaBuilderGetStmts(): void
    {
        $stmts = [new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(1)))];
        $builder = new SsaBuilder($stmts);
        $this->assertSame($stmts, $builder->getStmts());
    }

    // ========================================================================
    // SsaBuilder: CFG with control flow
    // ========================================================================

    public function testSsaBuilderIfElseCreatesBlocks(): void
    {
        $cond = new Expr\Variable('cond');
        $trueAssign = new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(1)));
        $falseAssign = new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(2)));
        $ifStmt = new Stmt\If_($cond, [
            'stmts' => [$trueAssign],
            'elseifs' => [],
            'else' => new Stmt\Else_([$falseAssign]),
        ]);

        $builder = new SsaBuilder([$ifStmt]);
        $builder->build();

        $this->assertGreaterThan(4, count($builder->blocks), 'If-else should create multiple blocks');
    }

    // ========================================================================
    // SsaBuilder: goto and labels
    // ========================================================================

    public function testSsaBuilderGotoLabel(): void
    {
        $label = new Stmt\Label('target');
        $label->name = new Node\Identifier('target');
        $gotoStmt = new Stmt\Goto_('target');
        $gotoStmt->name = new Node\Identifier('target');
        $assign = new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(1)));

        $builder = new SsaBuilder([$assign, $gotoStmt, $label, $assign]);
        $builder->build();

        $gotoBlocks = array_filter($builder->blocks, fn($b) => $b->endsWithGoto);
        $labelBlocks = array_filter($builder->blocks, fn($b) => $b->isGotoTarget);

        $this->assertCount(1, $gotoBlocks, 'Should have one block ending with goto');
        $this->assertCount(1, $labelBlocks, 'Should have one label block');
    }

    // ========================================================================
    // SsaBuilder: getDefBlocks
    // ========================================================================

    public function testSsaBuilderGetDefBlocks(): void
    {
        $assign1 = new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(1)));
        $assign2 = new Stmt\Expression(new Expr\Assign(new Expr\Variable('y'), new Scalar\LNumber(2)));

        $builder = new SsaBuilder([$assign1, $assign2]);
        $builder->build();

        $xBlocks = $builder->getDefBlocks('x');
        $yBlocks = $builder->getDefBlocks('y');

        $this->assertNotEmpty($xBlocks, 'x should have def blocks');
        $this->assertNotEmpty($yBlocks, 'y should have def blocks');
    }

    // ========================================================================
    // SsaBuilder: buildPiConstraints
    // ========================================================================

    public function testBuildPiConstraintsInstanceof(): void
    {
        $var = new Expr\Variable('obj');
        $class = new Node\Name('MyClass');
        $instanceof = new Expr\Instanceof_($var, $class);
        $ifStmt = new Stmt\If_($instanceof, ['stmts' => [], 'elseifs' => [], 'else' => null]);

        $builder = new SsaBuilder([]);
        $result = $builder->buildPiConstraints($ifStmt);

        $this->assertArrayHasKey('obj', $result['trueVars']);
        $this->assertArrayHasKey('obj', $result['falseVars']);

        $this->assertEquals('MyClass', $result['trueVars']['obj']->narrowedType);
        $this->assertTrue($result['trueVars']['obj']->isInstanceof);

        $this->assertEquals('!MyClass', $result['falseVars']['obj']->narrowedType);
        $this->assertFalse($result['falseVars']['obj']->isInstanceof);
    }

    public function testBuildPiConstraintsIsInt(): void
    {
        $arg = new Arg(new Expr\Variable('x'));
        $funcCall = new Expr\FuncCall(new Node\Name('is_int'), [$arg]);
        $ifStmt = new Stmt\If_($funcCall, ['stmts' => [], 'elseifs' => [], 'else' => null]);

        $builder = new SsaBuilder([]);
        $result = $builder->buildPiConstraints($ifStmt);

        $this->assertArrayHasKey('x', $result['trueVars']);
        $this->assertEquals('int', $result['trueVars']['x']->narrowedType);
        $this->assertFalse($result['trueVars']['x']->isInstanceof);
    }

    public function testBuildPiConstraintsNegation(): void
    {
        $var = new Expr\Variable('obj');
        $class = new Node\Name('MyClass');
        $instanceof = new Expr\Instanceof_($var, $class);
        $negated = new Expr\BooleanNot($instanceof);
        $ifStmt = new Stmt\If_($negated, ['stmts' => [], 'elseifs' => [], 'else' => null]);

        $builder = new SsaBuilder([]);
        $result = $builder->buildPiConstraints($ifStmt);

        $this->assertArrayHasKey('obj', $result['trueVars']);
        $this->assertEquals('!MyClass', $result['trueVars']['obj']->narrowedType);
    }

    public function testBuildPiConstraintsPlainVariable(): void
    {
        $cond = new Expr\Variable('flag');
        $ifStmt = new Stmt\If_($cond, ['stmts' => [], 'elseifs' => [], 'else' => null]);

        $builder = new SsaBuilder([]);
        $result = $builder->buildPiConstraints($ifStmt);

        $this->assertEmpty($result['trueVars']);
        $this->assertEmpty($result['falseVars']);
    }

    // ========================================================================
    // SsaBuilder: dump
    // ========================================================================

    public function testSsaBuilderDump(): void
    {
        $assign = new Stmt\Expression(new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(42)));
        $builder = new SsaBuilder([$assign]);
        $builder->build();

        $dump = $builder->dump();
        $this->assertStringContainsString('SSA Builder Dump', $dump);
        $this->assertStringContainsString('Blocks:', $dump);
        $this->assertStringContainsString('SSA Vars:', $dump);
        $this->assertStringContainsString('$x', $dump);
    }

    // ========================================================================
    // SsaPropOptimizer: resolveNewExprClass
    // ========================================================================

    public function testResolveNewExprClassFullyQualified(): void
    {
        $name = new Node\Name\FullyQualified('App\\Service\\User');
        $newExpr = new Expr\New_($name);

        $result = $this->invoke('resolveNewExprClass', $newExpr);
        $this->assertEquals('App\\Service\\User', $result);
    }

    public function testResolveNewExprClassUnqualified(): void
    {
        $name = new Node\Name('MyClass');
        $newExpr = new Expr\New_($name);

        $this->setProperty('namespace', 'App\\Service');

        $result = $this->invoke('resolveNewExprClass', $newExpr);
        $this->assertEquals('App\\Service\\MyClass', $result);
    }

    public function testResolveNewExprClassSelf(): void
    {
        $name = new Node\Name('self');
        $newExpr = new Expr\New_($name);

        $classDef = new ClassDef('MyService', 0, 'App\\Service');
        $this->setProperty('classDef', $classDef);
        $this->setProperty('namespace', 'App\\Service');
        $this->setProperty('class', 'MyService');

        $result = $this->invoke('resolveNewExprClass', $newExpr);
        $this->assertEquals('App\\Service\\MyService', $result);
    }

    public function testResolveNewExprClassStatic(): void
    {
        $name = new Node\Name('static');
        $newExpr = new Expr\New_($name);

        $classDef = new ClassDef('MyService', 0, 'App\\Service');
        $this->setProperty('classDef', $classDef);
        $this->setProperty('namespace', 'App\\Service');
        $this->setProperty('class', 'MyService');

        $result = $this->invoke('resolveNewExprClass', $newExpr);
        $this->assertNull($result, 'static cannot be resolved at compile time (late static binding)');
    }

    public function testResolveNewExprClassSelfNoClassDef(): void
    {
        $name = new Node\Name('self');
        $newExpr = new Expr\New_($name);

        $result = $this->invoke('resolveNewExprClass', $newExpr);
        $this->assertNull($result, 'Should return null when classDef is not set');
    }

    public function testResolveNewExprClassNotNew(): void
    {
        $var = new Expr\Variable('obj');
        $result = $this->invoke('resolveNewExprClass', $var);
        $this->assertNull($result, 'Non-New_ expressions should return null');
    }

    // ========================================================================
    // SsaPropOptimizer: isPropOfObj
    // ========================================================================

    public function testIsPropOfObjTrue(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'propName');

        $result = $this->invoke('isPropOfObj', $propFetch, 'obj');
        $this->assertTrue($result);
    }

    public function testIsPropOfObjDifferentVar(): void
    {
        $objVar = new Expr\Variable('otherObj');
        $propFetch = new Expr\PropertyFetch($objVar, 'propName');

        $result = $this->invoke('isPropOfObj', $propFetch, 'obj');
        $this->assertFalse($result);
    }

    public function testIsPropOfObjNotPropertyFetch(): void
    {
        $var = new Expr\Variable('obj');
        $result = $this->invoke('isPropOfObj', $var, 'obj');
        $this->assertFalse($result);
    }

    public function testIsPropOfObjNotVariable(): void
    {
        $methodCall = new Expr\MethodCall(new Expr\Variable('obj'), 'method');
        $propFetch = new Expr\PropertyFetch($methodCall, 'propName');

        $result = $this->invoke('isPropOfObj', $propFetch, 'obj');
        $this->assertFalse($result, 'Property fetch on method call result should not match');
    }

    // ========================================================================
    // SsaPropOptimizer: isObjectDefinition
    // ========================================================================

    public function testIsObjectDefinitionNew(): void
    {
        $ssaVar = new SsaVar(1, 'obj');
        $newExpr = new Expr\New_(new Node\Name('MyClass'));
        $assign = new Expr\Assign(new Expr\Variable('obj'), $newExpr);
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('isObjectDefinition', $ssaVar);
        $this->assertTrue($result);
    }

    public function testIsObjectDefinitionNull(): void
    {
        $ssaVar = new SsaVar(1, 'obj');
        $result = $this->invoke('isObjectDefinition', $ssaVar);
        $this->assertFalse($result);
    }

    public function testIsObjectDefinitionNotAssign(): void
    {
        $ssaVar = new SsaVar(1, 'obj');
        $ssaVar->definition = new Stmt\Foreach_(
            new Expr\Variable('arr'),
            new Expr\Variable('value')
        );

        $result = $this->invoke('isObjectDefinition', $ssaVar);
        $this->assertFalse($result, 'Foreach is not an object definition');
    }

    // ========================================================================
    // SsaPropOptimizer: isClassSafeForPropHoisting
    // ========================================================================

    public function testIsClassSafeForPropHoistingClean(): void
    {
        $classDef = new ClassDef('SafeClass', 0, 'App');
        $this->setProperty('classes', ['app_safeclass' => $classDef]);

        $result = $this->invoke('isClassSafeForPropHoisting', 'App\\SafeClass');
        $this->assertTrue($result, 'Class without __get/__set should be safe');
    }

    public function testIsClassSafeForPropHoistingWithGet(): void
    {
        $classDef = new ClassDef('MagicClass', 0, 'App');
        $classDef->methods = ['__get' => new MethodDef(0, '__get')];
        $this->setProperty('classes', ['app_magicclass' => $classDef]);

        $result = $this->invoke('isClassSafeForPropHoisting', 'App\\MagicClass');
        $this->assertFalse($result, 'Class with __get should NOT be safe');
    }

    public function testIsClassSafeForPropHoistingWithSet(): void
    {
        $classDef = new ClassDef('MagicClass', 0, 'App');
        $classDef->methods = ['__set' => new MethodDef(0, '__set')];
        $this->setProperty('classes', ['app_magicclass' => $classDef]);

        $result = $this->invoke('isClassSafeForPropHoisting', 'App\\MagicClass');
        $this->assertFalse($result, 'Class with __set should NOT be safe');
    }

    public function testIsClassSafeForPropHoistingUnknown(): void
    {
        $this->setProperty('classes', []);

        $result = $this->invoke('isClassSafeForPropHoisting', 'Unknown\\Class');
        $this->assertFalse($result, 'Unknown class should NOT be safe');
    }

    // ========================================================================
    // SsaPropOptimizer: hasDangerousPropOps
    // ========================================================================

    public function testHasDangerousPropOpsUnset(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $unset = new Stmt\Unset_([$propFetch]);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$unset, $read]);
        $this->assertTrue($result, 'unset($obj->prop) must disable property slot hoisting');
    }

    public function testHasDangerousPropOpsUnsetDifferentObj(): void
    {
        $objVar = new Expr\Variable('other');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $unset = new Stmt\Unset_([$propFetch]);

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$unset]);
        $this->assertFalse($result, 'unset on different object should not match');
    }

    public function testHasDangerousPropOpsUnsetObject(): void
    {
        $unset = new Stmt\Unset_([new Expr\Variable('obj')]);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$unset]);
        $this->assertSame(['*' => true], $result, 'unset($obj) must disable every property slot');
    }

    public function testUnsetObjectInElseifDisablesSlotsForAllBranches(): void
    {
        $if = new Stmt\If_(new Expr\Variable('first'), [
            'stmts' => [new Stmt\Expression(new Expr\PropertyFetch(new Expr\Variable('obj'), 'a'))],
            'elseifs' => [new Stmt\ElseIf_(new Expr\Variable('second'), [
                new Stmt\Unset_([new Expr\Variable('obj')]),
            ])],
            'else' => new Stmt\Else_([
                new Stmt\Expression(new Expr\PropertyFetch(new Expr\Variable('obj'), 'b')),
            ]),
        ]);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$if]);
        $this->assertSame(['*' => true], $result);
    }

    public function testHasDangerousPropOpsAssignRef(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $refVar = new Expr\Variable('ref');
        $assignRef = new Expr\AssignRef($refVar, $propFetch);
        $stmt = new Stmt\Expression($assignRef);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertTrue($result, '&$obj->prop before a later access should be detected');
    }

    public function testHasDangerousPropOpsAssignRefToProperty(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop');
        $assignRef = new Expr\AssignRef($propFetch, new Expr\Variable('ref'));
        $stmt = new Stmt\Expression($assignRef);

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt]);
        $this->assertTrue($result, '$obj->prop =& $ref should be detected');
    }

    public function testHasDangerousPropOpsByRefArg(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $arg = new Arg($propFetch, true); // byRef = true
        $funcCall = new Expr\FuncCall(new Node\Name('someFunc'), [$arg]);
        $stmt = new Stmt\Expression($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertTrue($result, 'func(&$obj->prop) before a later access should be detected');
    }

    public function testHasDangerousPropOpsStdRef(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $stdRefArg = new Arg($propFetch);
        $stdRefCall = new Expr\StaticCall(new Node\Name('std'), new Node\Identifier('ref'), [$stdRefArg]);
        $arg = new Arg($stdRefCall);
        $funcCall = new Expr\FuncCall(new Node\Name('someFunc'), [$arg]);
        $stmt = new Stmt\Expression($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertTrue($result, 'func(std::ref($obj->prop)) before a later access should be detected');
    }

    public function testHasDangerousPropOpsClean(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $assign = new Expr\Assign($propFetch, new Scalar\LNumber(42));
        $stmt = new Stmt\Expression($assign);

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt]);
        $this->assertFalse($result, 'Regular property assignment should be safe');
    }

    public function testHasDangerousPropOpsNestedInIf(): void
    {
        $objVar = new Expr\Variable('obj');
        $propFetch = new Expr\PropertyFetch($objVar, 'prop');
        $assignRef = new Expr\AssignRef(new Expr\Variable('ref'), $propFetch);
        $ifStmt = new Stmt\If_(new Expr\ConstFetch(new Node\Name('true')), [
            'stmts' => [new Stmt\Expression($assignRef)],
            'elseifs' => [],
            'else' => null,
        ]);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$ifStmt, $read]);
        $this->assertTrue($result, '&$obj->prop inside if before a later access should be detected');
    }

    public function testHasDangerousPropOpsNestedStdRefInAssignment(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop');
        $stdRefCall = new Expr\StaticCall(
            new Node\Name('std'),
            new Node\Identifier('ref'),
            [new Arg($propFetch)],
        );
        $funcCall = new Expr\FuncCall(new Node\Name('someFunc'), [new Arg($stdRefCall)]);
        $stmt = new Stmt\Expression(new Expr\Assign(new Expr\Variable('result'), $funcCall));
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertTrue($result, 'std::ref($obj->prop) nested in an assignment RHS before a later access should be detected');
    }

    public function testHasDangerousPropOpsNestedByRefInReturn(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop');
        $funcCall = new Expr\FuncCall(new Node\Name('someFunc'), [new Arg($propFetch, true)]);
        $stmt = new Stmt\Return_($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'prop')
        ));

        $result = $this->invoke('hasDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertTrue($result, 'By-ref property argument nested in return before a later access should be detected');
    }

    public function testCollectDangerousPropOpsTracksPropertyNames(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'b');
        $assignRef = new Expr\AssignRef(new Expr\Variable('ref'), $propFetch);
        $stmt = new Stmt\Expression($assignRef);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'b')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame(['b' => true], $result);
    }

    public function testCollectDangerousPropOpsDynamicPropertyWildcard(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), new Expr\Variable('prop'));
        $write = new Stmt\Expression(new Expr\Assign($propFetch, new Scalar\LNumber(5)));
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$write, $read]);
        $this->assertSame(['a' => true], $result);
    }

    public function testCollectDangerousPropOpsObjectArgumentWildcard(): void
    {
        $funcCall = new Expr\FuncCall(new Node\Name('mutate'), [new Arg(new Expr\Variable('obj'))]);
        $stmt = new Stmt\Expression($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame(['a' => true], $result, 'Passing the object to dynamic code may turn a property slot into a reference');
    }

    public function testCollectDangerousPropOpsObjectMethodReceiverWildcard(): void
    {
        $methodCall = new Expr\MethodCall(new Expr\Variable('obj'), 'mutate');
        $stmt = new Stmt\Expression($methodCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame(['a' => true], $result);
    }

    public function testCollectDangerousPropOpsPropertyArgumentDoesNotExposeObject(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'a');
        $funcCall = new Expr\FuncCall(new Node\Name('mutate'), [new Arg($propFetch)]);
        $stmt = new Stmt\Expression($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame([], $result, 'Passing a property value by value does not expose the owning object');
    }

    public function testCollectDangerousPropOpsInternalFunctionObjectArgumentIsSafe(): void
    {
        $funcCall = new Expr\FuncCall(new Node\Name('gettype'), [new Arg(new Expr\Variable('obj'))]);
        $stmt = new Stmt\Expression($funcCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame([], $result);
    }

    public function testCollectDangerousPropOpsInternalStaticMethodObjectArgumentIsSafe(): void
    {
        $staticCall = new Expr\StaticCall(
            new Node\Name('DateTimeImmutable'),
            'createFromMutable',
            [new Arg(new Expr\Variable('obj'))]
        );
        $stmt = new Stmt\Expression($staticCall);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame([], $result);
    }

    public function testCollectDangerousPropOpsEvalDoesNotCaptureObject(): void
    {
        $eval = new Expr\Eval_(new Scalar\String_('return 42;'));
        $stmt = new Stmt\Expression($eval);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame([], $result);
    }

    public function testCollectDangerousPropOpsIncludeDoesNotCaptureObject(): void
    {
        $include = new Expr\Include_(new Scalar\String_('unknown.php'), Expr\Include_::TYPE_INCLUDE);
        $stmt = new Stmt\Expression($include);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame([], $result);
    }

    public function testDynamicSourceOperandsStillExposeExplicitObjectArguments(): void
    {
        foreach (['eval', 'include'] as $kind) {
            $operand = new Expr\FuncCall(new Node\Name('buildSourceOrPath'), [new Arg(new Expr\Variable('obj'))]);
            $dynamicCode = $kind === 'eval' ? new Expr\Eval_($operand)
                : new Expr\Include_($operand, Expr\Include_::TYPE_INCLUDE);
            $read = new Stmt\Expression(new Expr\Assign(
                new Expr\Variable('value'),
                new Expr\PropertyFetch(new Expr\Variable('obj'), 'a'),
            ));
            $result = $this->invoke('collectDangerousPropOps', 'obj', [new Stmt\Expression($dynamicCode), $read]);
            $this->assertSame(['a' => true], $result, $kind);
        }
    }

    public function testCollectDangerousPropOpsObjectAliasWildcard(): void
    {
        $assign = new Expr\Assign(new Expr\Variable('alias'), new Expr\Variable('obj'));
        $stmt = new Stmt\Expression($assign);
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt, $read]);
        $this->assertSame(['a' => true], $result);
    }

    public function testCollectDangerousPropOpsPropertyReadDoesNotExposeObject(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'a');
        $assign = new Expr\Assign(new Expr\Variable('value'), $propFetch);
        $stmt = new Stmt\Expression($assign);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt]);
        $this->assertSame([], $result);
    }

    public function testCollectDangerousPropOpsUnsetAfterLastAccessIsAlwaysUnsafe(): void
    {
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));
        $unset = new Stmt\Unset_([new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')]);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$read, $unset]);
        $this->assertSame(['a' => true], $result);
    }

    public function testCollectDangerousPropOpsAssignRefToPropertyIsAlwaysUnsafe(): void
    {
        $propFetch = new Expr\PropertyFetch(new Expr\Variable('obj'), 'a');
        $assignRef = new Expr\AssignRef($propFetch, new Expr\Variable('ref'));
        $stmt = new Stmt\Expression($assignRef);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$stmt]);
        $this->assertSame(['a' => true], $result);
    }

    public function testCollectDangerousPropOpsObjectArgumentAfterLastAccessIsSafe(): void
    {
        $read = new Stmt\Expression(new Expr\Assign(
            new Expr\Variable('value'),
            new Expr\PropertyFetch(new Expr\Variable('obj'), 'a')
        ));
        $funcCall = new Expr\FuncCall(new Node\Name('mutate'), [new Arg(new Expr\Variable('obj'))]);
        $stmt = new Stmt\Expression($funcCall);

        $result = $this->invoke('collectDangerousPropOps', 'obj', [$read, $stmt]);
        $this->assertSame([], $result);
    }

    // ========================================================================
    // SsaPropOptimizer: isObjectSsaStable
    // ========================================================================

    public function testIsObjectSsaStableSingleDef(): void
    {
        $builder = new SsaBuilder([]);
        $ssaVar = new SsaVar(0, 'obj');
        $newExpr = new Expr\New_(new Node\Name('MyClass'));
        $assign = new Expr\Assign(new Expr\Variable('obj'), $newExpr);
        $ssaVar->definition = new Stmt\Expression($assign);
        $builder->ssaVars[0] = $ssaVar;

        $result = $this->invoke('isObjectSsaStable', $builder, 'obj');
        $this->assertTrue($result, 'Single SSA def from new Expr should be stable');
    }

    public function testIsObjectSsaStableWithReference(): void
    {
        $builder = new SsaBuilder([]);
        $ssaVar = new SsaVar(0, 'obj', SsaFlags::REFERENCE);
        $builder->ssaVars[0] = $ssaVar;

        $result = $this->invoke('isObjectSsaStable', $builder, 'obj');
        $this->assertFalse($result, 'REFERENCE flag should make object unstable');
    }

    public function testIsObjectSsaStableWithEscaped(): void
    {
        $builder = new SsaBuilder([]);
        $ssaVar = new SsaVar(0, 'obj', SsaFlags::ESCAPED);
        $builder->ssaVars[0] = $ssaVar;

        $result = $this->invoke('isObjectSsaStable', $builder, 'obj');
        $this->assertFalse($result, 'ESCAPED flag should make object unstable');
    }

    public function testIsObjectSsaStableWithKilled(): void
    {
        $builder = new SsaBuilder([]);
        $ssaVar = new SsaVar(0, 'obj', SsaFlags::KILLED);
        $builder->ssaVars[0] = $ssaVar;

        $result = $this->invoke('isObjectSsaStable', $builder, 'obj');
        $this->assertFalse($result, 'KILLED flag should make object unstable');
    }

    public function testIsObjectSsaStableWithPhi(): void
    {
        $builder = new SsaBuilder([]);
        $ssaVar = new SsaVar(0, 'obj', SsaFlags::PHI);
        $builder->ssaVars[0] = $ssaVar;

        $result = $this->invoke('isObjectSsaStable', $builder, 'obj');
        $this->assertFalse($result, 'PHI should be skipped, no stable def found');
    }

    public function testIsObjectSsaStableNotFound(): void
    {
        $builder = new SsaBuilder([]);
        $result = $this->invoke('isObjectSsaStable', $builder, 'nonexistent');
        $this->assertFalse($result, 'Non-existent variable should not be stable');
    }

    // ========================================================================
    // SsaPropOptimizer: isStableObject (public method)
    // ========================================================================

    public function testIsStableObject(): void
    {
        $this->invoke('resetFunction');
        $this->setContextProperty('stableObjects', ['myObj' => 'App\\MyClass']);

        $this->assertTrue($this->compiler->isStableObject('myObj'));
        $this->assertFalse($this->compiler->isStableObject('unknown'));
    }

    public function testCanHoistStableObjectPropAllowsCleanProperty(): void
    {
        $this->invoke('resetFunction');
        $this->setContextProperty('stableObjects', ['obj' => 'App\\MyClass']);
        $this->setContextProperty('unsafeObjectProps', ['obj' => ['b' => true]]);

        $propertyA = new PropertyDef('a', 0, Type::INT);
        $propertyB = new PropertyDef('b', 0, Type::INT);
        $this->assertTrue($this->compiler->canHoistStableObjectProp('obj', 'a', $propertyA));
        $this->assertFalse($this->compiler->canHoistStableObjectProp('obj', 'b', $propertyB));
    }

    public function testCanHoistStableObjectPropRejectsWildcard(): void
    {
        $this->invoke('resetFunction');
        $this->setContextProperty('stableObjects', ['obj' => 'App\\MyClass']);
        $this->setContextProperty('unsafeObjectProps', ['obj' => ['*' => true]]);

        $property = new PropertyDef('a', 0, Type::INT);
        $this->assertFalse($this->compiler->canHoistStableObjectProp('obj', 'a', $property));
    }

    // ========================================================================
    // LoopVarOptimizer: range-proven counters
    // ========================================================================

    public function testLoopVarOptimizerNarrowsWhilePostDecFromConstant(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 1000;
            while ($n--) {
                echo $n;
            }
        ');

        $this->assertSame(Type::INT, $locals['n'] ?? null);
    }

    public function testLoopVarOptimizerNarrowsForCounterAndConstantBoundVar(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 20000;
            for ($i = 0; $i < $n; $i++) {
                echo $i;
            }
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
        $this->assertSame(Type::INT, $locals['n'] ?? null);
    }

    public function testLoopVarOptimizerNarrowsForCounterWithStrlenBound(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            for ($i = 0; $i <= strlen($s); $i++) {
                echo $i;
            }
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
    }

    public function testLoopVarOptimizerNarrowsForCounterWithGenericIntFunctionBound(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            for ($i = 0; $i < time(); $i++) {
                echo $i;
            }
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
    }

    public function testLoopVarOptimizerRejectsInclusiveGenericIntFunctionBound(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            for ($i = 0; $i <= time(); $i++) {
                echo $i;
            }
        ');

        $this->assertArrayNotHasKey('i', $locals);
    }

    public function testLoopVarOptimizerNarrowsDescendingCounterFromIntFunction(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            for ($i = time(); $i > 0; $i--) {
                echo $i;
            }
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
    }

    public function testLoopVarOptimizerNarrowsDescendingCounterFromIntMethod(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            for ($i = $v->toInt(); $i > 0; --$i) {
                echo $i;
            }
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
    }

    public function testLoopVarOptimizerRejectsBodyCounterMutation(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 10;
            for ($i = 0; $i < $n; $i++) {
                $i += 2;
            }
        ');

        $this->assertArrayNotHasKey('i', $locals);
    }

    public function testLoopVarOptimizerRejectsNonUnitStep(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 10;
            for ($i = 0; $i < $n; $i += 2) {
                echo $i;
            }
        ');

        $this->assertArrayNotHasKey('i', $locals);
    }

    public function testLoopVarOptimizerAllowsCheckedArithmeticAfterLoop(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 10;
            for ($i = 0; $i < $n; $i++) {
                echo $i;
            }
            $x = $i + PHP_INT_MAX;
        ');

        $this->assertSame(Type::INT, $locals['i'] ?? null);
    }

    public function testLoopVarOptimizerRejectsMutatingUseAfterLoop(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 10;
            for ($i = 0; $i < $n; $i++) {
                echo $i;
            }
            $i += PHP_INT_MAX;
        ');

        $this->assertArrayNotHasKey('i', $locals);
    }

    public function testLoopVarOptimizerRejectsCounterWhenBoundVarCanChangeBeforeLoop(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            $n = 10;
            if (time()) {
                $n = "not-int";
            }
            for ($i = 0; $i < $n; $i++) {
                echo $i;
            }
        ');

        $this->assertArrayNotHasKey('i', $locals);
        $this->assertArrayNotHasKey('n', $locals);
    }

    public function testLoopVarOptimizerRejectsCounterReusedAsForeachKey(): void
    {
        $locals = $this->optimizeLoopVarsForCode('
            foreach (["name" => 1] as $i => $value) {
                echo $value;
            }
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        ');

        $this->assertArrayNotHasKey('i', $locals);
    }

    public function testLoopVarOptimizerRejectsCounterReusedAsForeachValueOrDestructuringTarget(): void
    {
        $valueLocals = $this->optimizeLoopVarsForCode('
            foreach (["value"] as $i) {
                echo $i;
            }
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        ');
        $listLocals = $this->optimizeLoopVarsForCode('
            foreach ([["value"]] as [$i]) {
                echo $i;
            }
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        ');

        $this->assertArrayNotHasKey('i', $valueLocals);
        $this->assertArrayNotHasKey('i', $listLocals);
    }

    // ========================================================================
    // SsaTypeOptimizer: detectSsaDefType
    // ========================================================================

    public function testDetectSsaDefTypeIntLiteral(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assign = new Expr\Assign(new Expr\Variable('x'), new Scalar\LNumber(42));
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::INT, $result);
    }

    public function testDetectSsaDefTypeFloatLiteral(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assign = new Expr\Assign(new Expr\Variable('x'), new Scalar\DNumber(3.14));
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::FLOAT, $result);
    }

    public function testDetectSsaDefTypeStringLiteral(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assign = new Expr\Assign(new Expr\Variable('x'), new Scalar\String_('hello'));
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::STR, $result);
    }

    public function testDetectSsaDefTypeBoolLiteral(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assign = new Expr\Assign(new Expr\Variable('x'), new Expr\ConstFetch(new Node\Name('true')));
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::BOOL, $result);
    }

    public function testDetectTypeOfExplicitStdNativeCalls(): void
    {
        $cases = [
            'int' => Type::INT,
            'float' => Type::FLOAT,
            'bool' => Type::BOOL,
        ];

        foreach ($cases as $method => $expectedType) {
            $call = new Expr\StaticCall(
                new Node\Name('std'),
                new Node\Identifier($method),
                [new Arg(new Scalar\LNumber(1))]
            );

            $this->assertSame($expectedType, $this->invoke('detectTypeOfExpr', $call));
        }
    }

    public function testDetectTypeOfFirstClassCallableBeforeBuiltinReturnTypeOptimizations(): void
    {
        foreach (['round', 'fopen'] as $function) {
            $call = new Expr\FuncCall(
                new Node\Name($function),
                [new Node\VariadicPlaceholder()]
            );

            $this->assertSame(Type::OBJECT, $this->invoke('detectTypeOfExpr', $call));
        }
    }

    public function testDetectTypeOfConcatExpressions(): void
    {
        $concat = new Expr\BinaryOp\Concat(new Scalar\LNumber(1), new Scalar\String_(''));
        $concatAssign = new Expr\AssignOp\Concat(new Expr\Variable('value'), new Scalar\LNumber(2));

        $this->assertSame(Type::STR, $this->invoke('detectTypeOfExpr', $concat));
        $this->assertSame(Type::STR, $this->invoke('detectTypeOfExpr', $concatAssign));
    }

    public function testDetectSsaDefTypeNull(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertNull($result, 'SSA var without definition should return null');
    }

    public function testDetectSsaDefTypeForeach(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $ssaVar->definition = new Stmt\Foreach_(
            new Expr\Variable('arr'),
            new Expr\Variable('x')
        );

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertNull($result, 'Foreach variable definition type is unknown');
    }

    public function testDetectSsaDefTypeAssignOpModIsUnknown(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assignOp = new Expr\AssignOp\Mod(new Expr\Variable('x'), new Scalar\LNumber(10));
        $ssaVar->definition = new Stmt\Expression($assignOp);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertNull($result, 'Compound assignment should not prove a narrow int type');
    }

    public function testDetectSsaDefTypeAssignOpDiv(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assignOp = new Expr\AssignOp\Div(new Expr\Variable('x'), new Scalar\LNumber(2));
        $ssaVar->definition = new Stmt\Expression($assignOp);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::FLOAT, $result);
    }

    public function testDetectSsaDefTypeAssignOpPlusIsUnknownForIntRhs(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $assignOp = new Expr\AssignOp\Plus(new Expr\Variable('x'), new Scalar\LNumber(1));
        $ssaVar->definition = new Stmt\Expression($assignOp);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertNull($result, 'Int += can overflow at runtime, so SSA should not infer a narrow int type');
    }

    public function testDetectSsaDefTypeArithmeticIntExprIsUnknown(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $expr = new Expr\BinaryOp\Plus(new Scalar\LNumber(1), new Scalar\LNumber(2));
        $assign = new Expr\Assign(new Expr\Variable('x'), $expr);
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertNull($result, 'Even constant + is not used for SSA int narrowing without a range-proven fold');
    }

    public function testDetectSsaDefTypeLiteralBitwiseIntExpr(): void
    {
        $ssaVar = new SsaVar(1, 'x');
        $expr = new Expr\BinaryOp\BitwiseAnd(new Scalar\LNumber(7), new Scalar\LNumber(3));
        $assign = new Expr\Assign(new Expr\Variable('x'), $expr);
        $ssaVar->definition = new Stmt\Expression($assign);

        $result = $this->invoke('detectSsaDefType', $ssaVar);
        $this->assertEquals(Type::INT, $result);
    }

    // ========================================================================
    // SsaTypeOptimizer: exprCanOverflowInt
    // ========================================================================

    public function testExprCanOverflowIntDivision(): void
    {
        $div = new Expr\BinaryOp\Div(
            new Scalar\LNumber(10),
            new Scalar\LNumber(3)
        );
        $this->assertTrue($this->invoke('exprCanOverflowInt', $div), 'Division always can overflow');
    }

    public function testExprCanOverflowIntBitwiseSafe(): void
    {
        $and = new Expr\BinaryOp\BitwiseAnd(
            new Scalar\LNumber(0xFF),
            new Scalar\LNumber(0x0F)
        );
        $this->assertFalse($this->invoke('exprCanOverflowInt', $and), 'Bitwise AND is safe');
    }

    public function testExprCanOverflowIntConstantArithmetic(): void
    {
        $plus = new Expr\BinaryOp\Plus(
            new Scalar\LNumber(1),
            new Scalar\LNumber(2)
        );
        $this->assertTrue($this->invoke('exprCanOverflowInt', $plus), 'Arithmetic is treated as unsafe without range proof');
    }

    public function testExprCanOverflowIntPow(): void
    {
        $pow = new Expr\BinaryOp\Pow(
            new Scalar\LNumber(2),
            new Scalar\LNumber(63)
        );
        $this->assertTrue($this->invoke('exprCanOverflowInt', $pow), 'Pow can overflow');
    }

    public function testExprCanOverflowIntPhpIntMax(): void
    {
        $plus = new Expr\BinaryOp\Plus(
            new Expr\ConstFetch(new Node\Name('PHP_INT_MAX')),
            new Scalar\LNumber(1)
        );
        $this->assertTrue($this->invoke('exprCanOverflowInt', $plus), 'PHP_INT_MAX + 1 can overflow');
    }

    // ========================================================================
    // SsaTypeOptimizer: isBoundaryConstant
    // ========================================================================

    public function testIsBoundaryConstantPhpIntMax(): void
    {
        $const = new Expr\ConstFetch(new Node\Name('PHP_INT_MAX'));
        $this->assertTrue($this->invoke('isBoundaryConstant', $const));
    }

    public function testIsBoundaryConstantPhpIntMin(): void
    {
        $const = new Expr\ConstFetch(new Node\Name('PHP_INT_MIN'));
        $this->assertTrue($this->invoke('isBoundaryConstant', $const));
    }

    public function testIsBoundaryConstantOther(): void
    {
        $const = new Expr\ConstFetch(new Node\Name('FOO'));
        $this->assertFalse($this->invoke('isBoundaryConstant', $const));
    }

    public function testIsBoundaryConstantNonConst(): void
    {
        $var = new Expr\Variable('x');
        $this->assertFalse($this->invoke('isBoundaryConstant', $var));
    }

    // ========================================================================
    // SsaTypeOptimizer: hasDangerousIntOps
    // ========================================================================

    public function testHasDangerousIntOpsArithmeticPlus(): void
    {
        $var = new Expr\Variable('x');
        $plus = new Expr\BinaryOp\Plus($var, new Scalar\LNumber(1));
        $stmt = new Stmt\Expression($plus);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertTrue($result, 'Arithmetic + with variable should be dangerous');
    }

    public function testHasDangerousIntOpsBitwiseAnd(): void
    {
        $var = new Expr\Variable('x');
        $and = new Expr\BinaryOp\BitwiseAnd($var, new Scalar\LNumber(0xFF));
        $stmt = new Stmt\Expression($and);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertFalse($result, 'Bitwise AND should be safe');
    }

    public function testHasDangerousIntOpsComparison(): void
    {
        $var = new Expr\Variable('x');
        $comparison = new Expr\BinaryOp\Greater($var, new Scalar\LNumber(0));
        $stmt = new Stmt\Expression($comparison);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertFalse($result, 'Comparison reads an int but cannot overflow it');
    }

    public function testHasDangerousIntOpsAssignOpPlus(): void
    {
        $var = new Expr\Variable('x');
        $plusEq = new Expr\AssignOp\Plus($var, new Scalar\LNumber(1));
        $stmt = new Stmt\Expression($plusEq);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertTrue($result, 'AssignOp\Plus should be dangerous');
    }

    public function testHasDangerousIntOpsAssignOpBitwiseAnd(): void
    {
        $var = new Expr\Variable('x');
        $andEq = new Expr\AssignOp\BitwiseAnd($var, new Scalar\LNumber(0x0F));
        $stmt = new Stmt\Expression($andEq);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertTrue($result, 'Compound assignment should be treated as dangerous for int narrowing');
    }

    public function testHasDangerousIntOpsIncrement(): void
    {
        $var = new Expr\Variable('x');
        $postInc = new Expr\PostInc($var);
        $stmt = new Stmt\Expression($postInc);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertTrue($result, 'PostInc should be dangerous');
    }

    public function testHasDangerousIntOpsBitwiseNot(): void
    {
        $var = new Expr\Variable('x');
        $bitNot = new Expr\BitwiseNot($var);
        $stmt = new Stmt\Expression($bitNot);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertFalse($result, 'BitwiseNot should be safe');
    }

    public function testHasDangerousIntOpsUnaryMinus(): void
    {
        $var = new Expr\Variable('x');
        $unaryMinus = new Expr\UnaryMinus($var);
        $stmt = new Stmt\Expression($unaryMinus);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertTrue($result, 'UnaryMinus should be dangerous');
    }

    public function testHasDangerousIntOpsDifferentVariable(): void
    {
        $y = new Expr\Variable('y');
        $plus = new Expr\BinaryOp\Plus($y, new Scalar\LNumber(1));
        $stmt = new Stmt\Expression($plus);

        $result = $this->invoke('hasDangerousIntOps', 'x', [$stmt]);
        $this->assertFalse($result, 'Operation on different variable should be safe for x');
    }

    // ========================================================================
    // SsaTypeOptimizer: exprHasIntHazard
    // ========================================================================

    public function testExprHasIntHazardAdd(): void
    {
        $var = new Expr\Variable('x');
        $plus = new Expr\BinaryOp\Plus($var, new Scalar\LNumber(1));

        $this->assertTrue($this->invoke('exprHasIntHazard', $plus, 'x'));
    }

    public function testExprHasIntHazardBitwiseAnd(): void
    {
        $var = new Expr\Variable('x');
        $and = new Expr\BinaryOp\BitwiseAnd($var, new Scalar\LNumber(0xFF));

        $this->assertFalse($this->invoke('exprHasIntHazard', $and, 'x'));
    }

    public function testExprHasIntHazardBitwiseOr(): void
    {
        $var = new Expr\Variable('x');
        $or = new Expr\BinaryOp\BitwiseOr($var, new Scalar\LNumber(1));

        $this->assertFalse($this->invoke('exprHasIntHazard', $or, 'x'));
    }

    public function testExprHasIntHazardMod(): void
    {
        $var = new Expr\Variable('x');
        $mod = new Expr\BinaryOp\Mod($var, new Scalar\LNumber(10));

        $this->assertFalse($this->invoke('exprHasIntHazard', $mod, 'x'));
    }

    public function testExprHasIntHazardShiftLeft(): void
    {
        $var = new Expr\Variable('x');
        $shl = new Expr\BinaryOp\ShiftLeft($var, new Scalar\LNumber(2));

        $this->assertFalse($this->invoke('exprHasIntHazard', $shl, 'x'));
    }

    public function testExprHasIntHazardCastInt(): void
    {
        $var = new Expr\Variable('x');
        $cast = new Expr\Cast\Int_($var);

        $this->assertFalse($this->invoke('exprHasIntHazard', $cast, 'x'));
    }

    // ========================================================================
    // SsaTypeOptimizer: hasDangerousFloatOps
    // ========================================================================

    public function testHasDangerousFloatOpsBitwiseAssign(): void
    {
        $var = new Expr\Variable('x');
        $andEq = new Expr\AssignOp\BitwiseAnd($var, new Scalar\LNumber(1));
        $stmt = new Stmt\Expression($andEq);

        $result = $this->invoke('hasDangerousFloatOps', 'x', [$stmt]);
        $this->assertTrue($result, 'Bitwise assign-op on float should be dangerous');
    }

    public function testHasDangerousFloatOpsNestedBitwiseBinary(): void
    {
        $var = new Expr\Variable('x');
        $bitwise = new Expr\BinaryOp\BitwiseAnd($var, new Scalar\LNumber(1));
        $stmt = new Stmt\Expression(new Expr\Assign(new Expr\Variable('y'), $bitwise));

        $result = $this->invoke('hasDangerousFloatOps', 'x', [$stmt]);
        $this->assertTrue($result, 'Bitwise binary op nested in an assignment should be dangerous for float');
    }

    public function testHasDangerousFloatOpsReturnMod(): void
    {
        $var = new Expr\Variable('x');
        $mod = new Expr\BinaryOp\Mod($var, new Scalar\LNumber(2));
        $stmt = new Stmt\Return_($mod);

        $result = $this->invoke('hasDangerousFloatOps', 'x', [$stmt]);
        $this->assertTrue($result, 'Modulo in return should be dangerous for float');
    }

    public function testHasDangerousFloatOpsArithmetic(): void
    {
        $var = new Expr\Variable('x');
        $plusEq = new Expr\AssignOp\Plus($var, new Scalar\DNumber(1.5));
        $stmt = new Stmt\Expression($plusEq);

        $result = $this->invoke('hasDangerousFloatOps', 'x', [$stmt]);
        $this->assertFalse($result, 'Arithmetic assign-op on float should be safe');
    }
}
