<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

use MJS\TopSort\Implementations\StringSort;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ArrayInitPlan;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Entity\InterfacePropertyDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\PropertyHookLowering;
use TypePhp\Transform\CompileTimeAttribute;
use TypePhp\Transform\NativeClassAttributeLowering;
use TypePhp\Transform\PrinterLowering;
use TypePhp\Transform\ArrayableLowering;
use TypePhp\Transform\ClassFieldSelection;
use TypePhp\Transform\FunctionAttributeLowering;
use TypePhp\Transform\ConstantExpressionValidationVisitor;
use TypePhp\Transform\RuntimeAttributeFactoryLowering;
use TypePhp\Transform\Visitor;
use TypePhp\Transform\VoidCastValidationVisitor;
use TypePhp\NativeClass\NativeGlobalDiscovery;
use TypePhp\NativeClass\NativeGlobalTypeResolver;
use PhpParser\Modifiers;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

class Preprocessor extends CompilerBase
{
    protected string $targetName = 'app';

    /**
     * Discover Native class names before parsing any signatures or fields.
     *
     * PHP permits forward class references across both declaration and file
     * order. Native fields need the same property while choosing a concrete
     * C++ pointer type, so waiting for prepareClass() would be order-dependent.
     * Only files which mention both an attribute and "Native" are parsed in
     * this lightweight pass; ordinary projects pay no second parse cost.
     *
     * @param list<string> $files
     */
    public function discoverNativeClassDeclarations(array $files): void
    {
        foreach ($files as $file) {
            if (!$this->isPhpFileForNativeDiscovery($file)) {
                continue;
            }
            $source = file_get_contents($file);
            if (!is_string($source)
                || !str_contains($source, '#[')
                || stripos($source, 'native') === false
            ) {
                continue;
            }
            try {
                $ast = $this->parser->parse($source);
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
                $ast = $this->requireStatementList($traverser->traverse($ast));
            } catch (\PhpParser\Error) {
                // prepareFile() owns the normal source diagnostic, including
                // the filename and compiler formatting. Avoid reporting a
                // syntax error twice from this declaration-only pass.
                continue;
            }
            $this->discoverNativeClassDeclarationsInAst($ast);
        }
    }

    private function isPhpFileForNativeDiscovery(string $file): bool
    {
        return str_ends_with(strtolower($file), '.php');
    }

    /** @param list<Node> $ast */
    private function discoverNativeClassDeclarationsInAst(array $ast): void
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->name === null
                || (!NativeClassAttributeLowering::isNative($class)
                    && CompileTimeAttribute::find($class, 'Native') === null)
            ) {
                continue;
            }
            $name = isset($class->namespacedName)
                ? $class->namespacedName->toString()
                : $class->name->toString();
            $name = ltrim($name, '\\');
            $this->nativeClassDeclarations[strtolower($name)] = $name;
        }
    }

    /**
     * Discover Native Object globals after all declarations have been prepared
     * but before the first translation unit is emitted.
     *
     * @param list<string> $files
     */
    public function discoverNativeGlobalObjects(array $files): void
    {
        $hasNativeClass = false;
        foreach ($this->symbols->classes() as $class) {
            if ($class->nativeObject) {
                $hasNativeClass = true;
                break;
            }
        }
        if (!$hasNativeClass) {
            return;
        }

        $candidateSources = [];
        foreach ($files as $file) {
            if (!$this->isPhpFileForNativeDiscovery($file)) {
                continue;
            }
            $source = file_get_contents($file);
            if (is_string($source)
                && (str_contains($source, 'global') || str_contains($source, '$GLOBALS'))
            ) {
                $candidateSources[$file] = $source;
            }
        }
        if ($candidateSources === []) {
            return;
        }

        $functionReturns = [];
        foreach ($this->symbols->functions() as $function) {
            if (!$function->method && $this->hasClass($function->returnClass)) {
                $functionReturns[strtolower(ltrim($function->getNamespacedName(), '\\'))]
                    = $this->getClass($function->returnClass)->getNamespacedName(false);
            }
        }

        $resolver = new NativeGlobalTypeResolver($this->symbols->classes(), $this->constants);
        $this->nativeGlobalTypeResolver = $resolver;
        $discovery = new NativeGlobalDiscovery($resolver, $functionReturns);

        foreach ($candidateSources as $source) {
            try {
                $ast = $this->parser->parse($source);
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
                $ast = $this->requireStatementList($traverser->traverse($ast));
            } catch (\PhpParser\Error) {
                // prepareFile() has already emitted the authoritative syntax
                // diagnostic. This pass must not report it a second time.
                continue;
            }
            foreach ($discovery->discover($ast) as $slot) {
                $this->registerNativeGlobalObject($slot['name'], $slot['class'], $slot['node']);
            }
        }
    }

    public function getSortedFiles(array $list): array
    {
        $sorter = new StringSort();
        $fileDeps = [];

        // Build the dependency graph
        foreach ($this->symbolCallInFile as $file => $symbols) {
            $deps = [];
            foreach ($symbols as $symbol) {
                if (isset($this->symbolDeclInFile[$symbol])) {
                    $depFile = $this->symbolDeclInFile[$symbol];
                    if ($depFile !== $file) {
                        $deps[] = $depFile;
                    }
                }
            }
            $deps = array_unique($deps);
            $fileDeps[$file] = $deps;
            $sorter->add($file, $deps);
        }

        $sortedFiles = $sorter->sort();

        // Append files that do not participate in dependency management (non-stub files not present in the sorted list)
        foreach ($list as $file) {
            if (!$this->isStubFile($file) and !in_array($file, $sortedFiles)) {
                $sortedFiles[] = $file;
            }
        }

        $this->climate->lightBlue('prepare completed: ' . count($sortedFiles) . ' source files in total');
        return $sortedFiles;
    }

    protected function genArgumentDeclaration(ArgInfo $argInfo): string
    {
        $nativeObjectType = $this->getNativeObjectArgumentType($argInfo);
        if ($nativeObjectType !== null) {
            return $nativeObjectType . $argInfo->name;
        }
        $type = $argInfo->type;
        if ($type === Type::STREAM || $type === Type::BOX) {
            $type = Type::VAR;
        }
        return $type . ' ' . $argInfo->name;
    }

    public function getCppFile(string $file): string
    {
        $info = pathinfo($file);

        $separator = $this->getPlatform()->getPathSeparator();
        $relativePath = $this->removeCommonPrefix($this->buildDir, $info['dirname']);

        return $this->buildDir . $separator . $relativePath . $separator . $info['filename'] . '.cc';
    }

    public function getObjectFile(string $cppFile): string
    {
        $info = pathinfo($cppFile);
        $ext = $this->getPlatform()->getObjectExtension();

        // Keep the same path separator as cppFile
        $normalizedFile = str_replace('\\', '/', $cppFile);
        $normalizedMiscDir = str_replace('\\', '/', $this->getPhpxDir() . '/src/misc/');
        if (str_starts_with($normalizedFile, $normalizedMiscDir)) {
            $separator = $this->getPlatform()->getPathSeparator();
            // Only typephp_main.cc contains project-specific symbols. All
            // other PHPX misc sources are target-independent and share their
            // cached object files within the build directory.
            $cacheScope = $this->isProjectRuntimeEntryFile($cppFile) ? $this->targetName : 'shared';
            $objectDir = $this->buildDir . $separator . 'phpx-misc' . $separator . $cacheScope;
            if (!is_dir($objectDir)) {
                mkdir($objectDir, 0777, true);
            }
            return $objectDir . $separator . $info['filename'] . $ext;
        }

        return $info['dirname'] . $this->getPlatform()->getPathSeparator() . $info['filename'] . $ext;
    }

    protected function isProjectRuntimeEntryFile(string $file): bool
    {
        $normalizedFile = str_replace('\\', '/', $file);
        $runtimeEntry = str_replace('\\', '/', $this->getPhpxDir() . '/src/misc/typephp_main.cc');
        return $normalizedFile === $runtimeEntry;
    }

    public function prepareFile(string $file): void
    {
        $previousPhase = $this->enterCompilerPhase(self::PHASE_PREPARE);
        try {
            $phpCode = $this->loadFile($file);
            $this->symbolCallInFile[$this->file] = [];
            $this->resetFile();
            $this->resetFunction();
            $this->resetMethod();
            $this->resetClass();
            $this->resetNamespace();

            $this->climate->info('prepare: ' . $this->getRelativePath($this->file));
            try {
                $ast = $this->parser->parse($phpCode);
            } catch (\PhpParser\Error $e) {
                $this->climate->red("Fatal error: {$e->getMessage()} in {$this->file}");
                throw new SyntaxError($e->getMessage(), $e->getCode());
            }

            $this->stubImportLibrary = $this->stubFile && $this->hasLibraryImportAnnotation($ast)
                ? $this->getExternalImportLibraryName($this->file)
                : '';
            if ($this->stubImportLibrary !== '') {
                $this->externalImportStubFiles[$this->file] = true;
            }
            if ($this->stubImportLibrary !== '' && !in_array($this->stubImportLibrary, $this->linkLibs, true)) {
                $this->linkLibs[] = $this->stubImportLibrary;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $traverser->addVisitor(new VoidCastValidationVisitor(
                fn (Node $node, string $message) => $this->fatalError($node, $message),
            ));
            $traverser->addVisitor(new Visitor(
                fn (Node $node, string $message) => $this->warning($node, $message),
                $this->file,
            ));
            $traverser->addVisitor(new ConstantExpressionValidationVisitor($this->phpVersion));
            $traverser->addVisitor(new RuntimeAttributeFactoryLowering(
                $this->file,
                fn (string $class, string $case): bool => $this->isDeclaredEnumCase($class, $case),
            ));
            try {
                $stmts = $this->requireStatementList($traverser->traverse($ast));
            } catch (\PhpParser\Error $error) {
                $this->fatalPhpParserError($error);
            }
            // Keep the resolved declaration AST until convert. Defaults and
            // constants are validated here, but their C++ expressions are not
            // generated until the complete symbol table is available.
            $this->preparedFileAsts[$this->file] = $stmts;
            $this->declarationExpressionsFinalized = false;
            // The prepared class graph changed; override flags must be
            // re-finalized before the next conversion.
            $this->methodOverrideFlagsFinalized = false;
            // CompilerTest and embedding users may invoke prepareFile()
            // directly instead of the project pipeline. Preserve same-file
            // forward Native references for that public entry path as well.
            $this->discoverNativeClassDeclarationsInAst($stmts);

            foreach ($stmts as $v) {
                if ($v instanceof Node\Stmt\Namespace_) {
                    $this->prepareNamespace($v);
                } elseif ($v instanceof Node\Stmt\Class_ || $v instanceof Node\Stmt\Enum_ || $v instanceof Node\Stmt\Trait_) {
                    $this->prepareClass($v);
                } elseif ($v instanceof Node\Stmt\Interface_) {
                    $this->parseInterface($v);
                } elseif ($v instanceof Node\Stmt\Function_) {
                    $this->prepareFunction($v);
                } elseif ($v instanceof Node\Stmt\Use_) {
                    $this->parseUse($v);
                } elseif ($v instanceof Node\Stmt\GroupUse) {
                    $this->parseGroupUse($v);
                } elseif ($v instanceof Node\Stmt\Const_) {
                    $this->parseConstDef($v);
                } elseif ($v instanceof Node\Stmt\Expression) {
                    $this->foundStrayCode($v);
                } elseif (!$v instanceof Node\Stmt\Declare_ && !$v instanceof Node\Stmt\Nop) {
                    $this->fatalError($v, 'Unsupported statement: ' . $v->getType());
                }
            }
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }

    protected function fatalPhpParserError(\PhpParser\Error $error): never
    {
        $location = $this->file;
        if ($error->getStartLine() > 0) {
            $location .= ':' . $error->getStartLine();
        }
        $this->error($error->getRawMessage() . ' in ' . $location);
    }

    /**
     * Root parser output must remain a statement list after declaration
     * visitors have run. Validate that invariant before storing the AST.
     *
     * @param list<Node> $nodes
     * @return list<Node\Stmt>
     */
    private function requireStatementList(array $nodes): array
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node\Stmt) {
                throw new \LogicException('Root AST traversal produced a non-statement node: ' . $node->getType());
            }
        }
        return $nodes;
    }

    /**
     * Lower declaration-only constant expressions after every symbol is known.
     *
     * @param list<string> $files
     */
    public function finalizeDeclarationExpressions(array $files): void
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'declaration expression finalization');
        if ($this->declarationExpressionsFinalized) {
            return;
        }
        foreach ($files as $file) {
            $path = realpath($file);
            if ($path === false || !isset($this->preparedFileAsts[$path])) {
                continue;
            }
            $this->loadFile($path);
            $this->resetFile();
            $this->resetFunction();
            $this->resetMethod();
            $this->resetClass();
            $this->resetNamespace();
            $this->finalizeDeclarationStatementList($this->preparedFileAsts[$path]);
        }
        $this->declarationExpressionsFinalized = true;
    }

    /** @param array<Node\Stmt> $statements */
    private function finalizeDeclarationStatementList(array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->resetClass();
                $this->resetMethod();
                $this->resetFunction();
                $this->resetNamespace();
                $this->namespace = $statement->name ? $this->parseIdentifier($statement->name) : '';
                $this->finalizeDeclarationStatementList($statement->stmts);
                continue;
            }
            if ($statement instanceof Node\Stmt\Use_) {
                $this->parseUse($statement);
                continue;
            }
            if ($statement instanceof Node\Stmt\GroupUse) {
                $this->parseGroupUse($statement);
                continue;
            }
            if ($statement instanceof Node\Stmt\Class_
                || $statement instanceof Node\Stmt\Trait_
                || $statement instanceof Node\Stmt\Enum_
            ) {
                $this->finalizeClassDeclarationExpressions($statement);
                continue;
            }
            if ($statement instanceof Node\Stmt\Interface_) {
                $this->finalizeInterfaceDeclarationExpressions($statement);
                continue;
            }
            if ($statement instanceof Node\Stmt\Function_) {
                $this->resetClass();
                $this->resetMethod();
                $this->finalizePreparedFunctionDefaults(
                    $statement,
                    $this->getFunction($this->getFunctionName($statement)),
                );
                continue;
            }
            if ($statement instanceof Node\Stmt\Const_) {
                $this->finalizeGlobalConstantExpressions($statement);
            }
        }
    }

    private function finalizeClassDeclarationExpressions(
        Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class,
    ): void {
        $this->resetClass();
        $this->class = $this->parseIdentifier($class->name);
        $this->classDef = $this->getClass($this->getFullClassName());
        foreach ($class->stmts as $statement) {
            if ($statement instanceof Node\Stmt\ClassConst) {
                foreach ($statement->consts as $constant) {
                    $name = $this->parseIdentifier($constant->name);
                    $this->finalizePreparedConstant(
                        $this->classDef->getConstant($name),
                        $constant->value,
                    );
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Property) {
                foreach ($statement->props as $property) {
                    $name = $this->parseIdentifier($property->name);
                    if ($property->default !== null && $this->classDef->hasProperty($name)) {
                        $this->finalizePreparedProperty(
                            $this->classDef->getProperty($name),
                            $property->default,
                        );
                    }
                }
                continue;
            }
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $name = $this->getMethodName($statement);
            $this->resetMethod();
            $this->method = $name;
            $this->methodDef = $this->classDef->hasMethod($name)
                ? $this->classDef->getMethod($name)
                : ($this->classDef->hasAbstractMethod($name)
                    ? $this->classDef->getAbstractMethod($name)
                    : null);
            if ($this->methodDef !== null && $this->methodDef->functionDef !== null) {
                $this->finalizePreparedFunctionDefaults($statement, $this->methodDef->functionDef);
            }
        }
    }

    private function finalizeInterfaceDeclarationExpressions(Node\Stmt\Interface_ $interface): void
    {
        $this->resetClass();
        $this->interface = $this->parseIdentifier($interface->name);
        $this->interfaceDef = $this->getInterface($this->getFullClassLikeName());
        foreach ($interface->stmts as $statement) {
            if ($statement instanceof Node\Stmt\ClassConst) {
                foreach ($statement->consts as $constant) {
                    $name = $this->parseIdentifier($constant->name);
                    $this->finalizePreparedConstant(
                        $this->interfaceDef->constants[$name],
                        $constant->value,
                    );
                }
                continue;
            }
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $name = $this->getMethodName($statement);
            $this->resetMethod();
            $this->method = $name;
            $this->methodDef = $this->interfaceDef->methods[strtolower($name)] ?? null;
            if ($this->methodDef !== null && $this->methodDef->functionDef !== null) {
                $this->finalizePreparedFunctionDefaults($statement, $this->methodDef->functionDef);
            }
        }
        $this->interface = '';
        $this->interfaceDef = null;
    }

    private function finalizePreparedFunctionDefaults(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $function,
        FunctionDef $functionDef,
    ): void {
        $this->resetFunction();
        $this->function = $this->parseIdentifier($function->name);
        $this->functionDef = $functionDef;
        foreach ($function->params as $index => $parameter) {
            if ($parameter->default === null || !isset($functionDef->argInfoList[$index])) {
                continue;
            }
            $argument = $functionDef->argInfoList[$index];
            $argument->default = '';
            $argument->arrayInitPlan = null;
            $this->lowerArgumentDefault($parameter, $argument);
        }
    }

    private function finalizePreparedProperty(PropertyDef $property, Node\Expr $expression): void
    {
        $this->resetFunction();
        $property->arrayInitPlan = null;
        if ($expression instanceof Node\Expr\Array_) {
            $property->arrayInitPlan = $this->buildLiteralArrayInitPlan($expression);
            $property->default = $property->arrayInitPlan->expr;
        } else {
            $property->default = $this->parseIdentifier($expression);
        }
    }

    private function finalizePreparedConstant(ConstantDef $constant, Node\Expr $expression): void
    {
        $this->resetFunction();
        $constant->arrayExpr = '';
        $constant->value = $this->parseIdentifier($expression);
        if ($this->context->beforeStmtLines) {
            if ($this->context->localVars) {
                $constant->arrayExpr .= $this->genScopeVarDecl();
            }
            $constant->arrayExpr .= $this->parseBeforeStmtLines();
        }
        $constant->codegenFinalized = true;
    }

    private function finalizeGlobalConstantExpressions(Node\Stmt\Const_ $statement): void
    {
        foreach ($statement->consts as $constant) {
            $name = $this->parseIdentifier($constant->name);
            if ($this->namespace !== '') {
                $name = $this->namespace . '\\' . $name;
            }
            $key = $this->escapeConstVar($name);
            if (!isset($this->constants[$key])) {
                continue;
            }
            $this->resetFunction();
            $this->constants[$key]->value = $this->parseIdentifier($constant->value);
            $this->constants[$key]->codegenFinalized = true;
        }
    }

    /** @param array<Node> $stmts */
    private function hasLibraryImportAnnotation(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            foreach ($stmt->getComments() as $comment) {
                if (preg_match('/@import-library\b/', $comment->getText()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNoExportAttribute(NodeAbstract $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->isRootCompileTimeAttribute($attribute, 'NoExport')) {
                    continue;
                }
                if ($attribute->args !== []) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'NoExport',
                        'NoExport does not accept arguments',
                        $attribute,
                    );
                }
                return true;
            }
        }

        return false;
    }

    private function parseWasmExportAttribute(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): ?string
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->isRootCompileTimeAttribute($attribute, 'WasmExport')) {
                    continue;
                }
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'WasmExport',
                        'WasmExport can only be applied to named functions',
                        $attribute,
                    );
                }
                if (count($attribute->args) > 1) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'WasmExport',
                        'WasmExport accepts at most one string name',
                        $attribute,
                    );
                }
                if ($attribute->args === []) {
                    return '';
                }
                $argument = $attribute->args[0];
                if ($argument->name !== null && strcasecmp($argument->name->toString(), 'name') !== 0) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'WasmExport',
                        'WasmExport only accepts the named argument `name`',
                        $attribute,
                    );
                }
                if (!$argument->value instanceof Node\Scalar\String_) {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'WasmExport',
                        'WasmExport name must be a constant string',
                        $attribute,
                    );
                }
                $name = $argument->value->value;
                if ($name === '') {
                    $this->fatalCompileTimeAttribute(
                        $node,
                        'WasmExport',
                        'WasmExport name must not be empty',
                        $attribute,
                    );
                }
                return $name;
            }
        }

        return null;
    }

    private function isRootCompileTimeAttribute(Node\Attribute $attribute, string $name): bool
    {
        return strcasecmp($this->getResolvedPhpName($attribute->name), $name) === 0;
    }

    private function getResolvedPhpName(Node\Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName')
            ?? $name->getAttribute('namespacedName')
            ?? $name;

        return ltrim($resolvedName->toString(), '\\');
    }

    private function getExternalImportLibraryName(string $stubFile): string
    {
        $name = basename($stubFile, '.stub.php');
        $name = str_replace(['-', '*'], '_', $name);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new SyntaxError('Invalid external import stub filename `' . basename($stubFile) . '`');
        }

        return $name;
    }

    /**
     * Collect per-file symbol dependencies for the incremental compilation cache.
     *
     * The cache does not consume this graph yet, but this collector is retained
     * intentionally so cache invalidation can later be based on symbol usage.
     */
    protected function findSymbolUsing(NodeAbstract $ast): void
    {
        $nodeFinder = new NodeFinder();
        $functionCalls = $nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall::class);

        foreach ($functionCalls as $call) {
            if ($call->name instanceof Node\Name) {
                // Internal functions do not participate in dependency management
                $funcName = strtolower($call->name->toString());
                if (!$this->isInternalFunction($funcName)) {
                    $this->symbolCallInFile[$this->file][] = $funcName;
                }
            }
        }

        $depClasses = [];
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\StaticPropertyFetch::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\ClassConstFetch::class));
        $depClasses = array_merge($depClasses, $nodeFinder->findInstanceOf($ast, Node\Expr\New_::class));
        foreach ($depClasses as $call) {
            if ($call->class instanceof Node\Name) {
                $className = $this->parseIdentifier($call->class);
                if ($className !== 'self' && $className !== 'static') {
                    $fullClassName = $this->getNamespacedClassName($className);
                    $this->symbolCallInFile[$this->file][] = strtolower($fullClassName);
                }
            }
        }
        // Deduplicate dependencies
        $this->symbolCallInFile[$this->file] = array_unique($this->symbolCallInFile[$this->file]);
    }

    protected function prepareNamespace(Node\Stmt\Namespace_ $node): void
    {
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();
        $this->resetNamespace();

        $this->namespace = $node->name ? $this->parseIdentifier($node->name) : '';
        foreach ($node->stmts as $v2) {
            if ($v2 instanceof Node\Stmt\Class_ || $v2 instanceof Node\Stmt\Enum_ || $v2 instanceof Node\Stmt\Trait_) {
                $this->prepareClass($v2);
            } elseif ($v2 instanceof Node\Stmt\Function_) {
                $this->prepareFunction($v2);
            } elseif ($v2 instanceof Node\Stmt\Use_) {
                $this->parseUse($v2);
            } elseif ($v2 instanceof Node\Stmt\GroupUse) {
                $this->parseGroupUse($v2);
            } elseif ($v2 instanceof Node\Stmt\Const_) {
                $this->parseConstDef($v2);
            } elseif ($v2 instanceof Node\Stmt\Interface_) {
                $this->parseInterface($v2);
            } elseif (!$v2 instanceof Node\Stmt\Nop) {
                $this->foundStrayCode($v2);
            }
        }
    }

    protected function parseParameterType(Node\Param $param, ArgInfo $argInfo, string $var): string
    {
        $this->markLateBoundTypeNodes($param->type);
        // Capture the late-bound parameter type keyword *before* resolveTypeDecl
        // runs, because resolveTypeDecl mutates the `self`/`static`/`parent` node
        // name to the declaring class when the method belongs to a trait.
        $typeKeyword = '';
        if ($param->type instanceof Node\Name) {
            $ptLower = strtolower($param->type->toString());
            if ($ptLower === 'self' || $ptLower === 'static' || $ptLower === 'parent') {
                $typeKeyword = $ptLower;
            }
        }
        [$type, $class] = $this->resolveTypeDecl($param->type, self::DECL_TYPE_OF_PARAM);
        $this->assertSupportedNativeObjectTypeNode($param->type, self::DECL_TYPE_OF_PARAM, $param);
        $nullableNative = $this->resolveNullableNativeObjectType($param->type, self::DECL_TYPE_OF_PARAM);
        if ($nullableNative !== null) {
            [$type, $class] = $nullableNative;
            $argInfo->nullable = true;
        }
        $argInfo->undeclared = $param->type === null;
        if (
            $param->type !== null
            && !$param->type instanceof NullableType
            && !$param->type instanceof UnionType
            && !$param->type instanceof IntersectionType
        ) {
            $argInfo->explicitMixed = in_array(strtolower($this->parseIdentifier($param->type)), ['mixed', 'any'], true);
        }
        if ($class) {
            $argInfo->declaredClass = $class;
        }
        if ($class and !$this->hasInterface($class)) {
            $argInfo->class = $class;
        }
        // Record late-bound parameter type keywords so they can be re-resolved
        // to the consuming class when a trait method is flattened into a class.
        $argInfo->typeKeyword = $typeKeyword;
        // Ordinary PHP references use php::Ref at the native ABI. Native
        // object references are rejected after the complete signature has
        // been parsed: a typed pointer already shares object identity, while
        // PHP & would additionally expose caller-slot rebinding.
        return $param->byRef ? Type::REF : $type;
    }

    /**
     * @param $params array<Node\Param>
     */
    protected function parseParams(array $params, FunctionDef $functionDef): void
    {
        $list                          = [];
        $functionDef->argCountRequired = count($params);
        $lastRequiredIndex = -1;
        $lastRequiredName = '';
        $last = array_key_last($params);
        foreach ($params as $i => $param) {
            if (!$param->default && !$param->variadic) {
                $lastRequiredIndex = $i;
                if (is_string($param->var->name)) {
                    $lastRequiredName = $param->var->name;
                }
            }
        }

        foreach ($params as $i => $param) {
            if (!is_string($param->var->name)) {
                $this->fatalError($param, 'Parameter name must be a string');
            }
            $phpName = $param->var->name;
            $name = $this->escapeVarName($phpName);
            // Local stubs define C++ native functions and require explicit ABI types.
            // Generated external stubs may preserve an untyped PHP declaration as php::Var.
            if ($this->stubFile && $this->stubImportLibrary === '' && !$param->type) {
                throw new \RuntimeException('No type for ' . $phpName);
            }
            // Constructor property promotion syntax
            if ($param->isPromoted()) {
                if (!$this->classDef or !$this->methodDef or $this->methodDef->name !== '__construct') {
                    $this->fatalError($param, 'Promoted properties are not supported');
                }
                // A variadic parameter collects arguments into an array, so no
                // single value exists to promote into the property.
                if ($param->variadic) {
                    $this->fatalError($param, 'Cannot declare variadic promoted property');
                }
                $nullable = $param->type instanceof NullableType;
                // Promoted property defaults belong to the constructor parameter,
                // not to the property default table. The property itself must stay
                // uninitialized until __construct assigns it.
                $promotedProperty = $this->addClassProperty($phpName, $param->flags, $param->type, null, $nullable, $param, true);
                $promotedProperty->arrayDef = $this->parseArrayDefinition($param);
            }
            if ($param->variadic) {
                if ($i !== $last) {
                    $this->fatalError($param, 'Variadic parameters must be the last parameter');
                }
            }
            if ($param->default && $i < $lastRequiredIndex) {
                $this->fatalError(
                    $param,
                    $this->getFunctionDisplayName($functionDef)
                    . '(): optional parameter `$' . $phpName . '` cannot be declared before required parameter `$'
                    . $lastRequiredName . '`'
                );
            }
            if ($this->method and $name === 'this_') {
                $this->fatalError($param, 'Cannot use `$this` as parameter of class method');
            }
            $argInfo = new ArgInfo();
            $type = $this->parseParameterType($param, $argInfo, $name);
            $argInfo->name = $name;
            $argInfo->phpName = $phpName;
            $argInfo->type = $type;
            $argInfo->byRef = $param->byRef;
            $argInfo->variadic = $param->variadic;
            $argInfo->property = $param->isPromoted();
            $argInfo->immutable = \TypePhp\Transform\CompileTimeAttribute::consume($param, 'Immutable');
            if ($param->type === null || $param->type instanceof NullableType) {
                $argInfo->nullable = true;
            }
            if (($param->byRef && $param->type !== null)
                || $param->type instanceof NullableType
                || $param->type instanceof UnionType
                || $param->type instanceof IntersectionType
            ) {
                $typeInfo = $this->buildTypeCheckFromNode($param->type, $param->byRef);
                if (!empty($typeInfo['check']) && !$this->isNativeObjectClass($argInfo->declaredClass)) {
                    $argInfo->typeCheck = $typeInfo['check'];
                    $argInfo->typeStr = $typeInfo['typeStr'];
                    $argInfo->typeNode = $param->type;
                }
            }
            if ($param->variadic) {
                $list[] = Type::ARRAY . ' ' . $name;
            } else {
                $list[] = $this->genArgumentDeclaration($argInfo);
            }
            if ($param->default) {
                $argInfo->defaultExpr = $param->default;
                $argInfo->defaultValue = $param->default;
                if ($this->compilerPhase === self::PHASE_CONVERT) {
                    $this->lowerArgumentDefault($param, $argInfo);
                }
            } elseif ($param->variadic) {
                // A variadic parameter can be treated as an empty-array default value
                $argInfo->default = '{}';
                $argInfo->defaultValue = new Node\Expr\Array_();
            }
            $functionDef->argInfoList[] = $argInfo;
        }
        $functionDef->params = implode(', ', $list);
        $functionDef->argCountRequired = $lastRequiredIndex + 1;
    }

    protected function lowerArgumentDefault(Node\Param $param, ArgInfo $argInfo): void
    {
        if ($param->default === null) {
            return;
        }
        $arrayInitPlan = $param->default instanceof Node\Expr\Array_
            ? $this->withoutLocalClassEntryHoisting(
                fn (): ArrayInitPlan => $this->buildLiteralArrayInitPlan($param->default),
            )
            : null;
        $argInfo->arrayInitPlan = $arrayInitPlan;
        if ($param->byRef) {
            if ($this->isEmptyArray($param->default)) {
                $argInfo->default = 'php::getEmptyArrayRef()';
                return;
            }
            if ($this->isNull($param->default)) {
                $argInfo->default = 'nullptr';
                return;
            }
            $value = $arrayInitPlan?->expr ?? $this->parseParamDefaultValue($param->default);
            $argInfo->default = 'php::newReference(' . $value . ')';
            return;
        }
        $argInfo->default = $arrayInitPlan?->expr ?? $this->parseParamDefaultValue($param->default);
    }

    protected function getFunctionDisplayName(FunctionDef $functionDef): string
    {
        if ($this->class) {
            return $this->class . '::' . $functionDef->name;
        }
        return $functionDef->getNamespacedName();
    }

    protected function parseFunctionDecl(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): FunctionDef
    {
        // Local stubs define C++ native functions and require an explicit ABI return type.
        // Generated external stubs may preserve an untyped PHP declaration as php::Var.
        if ($this->stubFile && $this->stubImportLibrary === '' && !$v->returnType) {
            // The following magic methods must not declare a return type: __construct()/__destruct()/__clone()
            if (($this->method and !in_array($this->method, ['__construct', '__destruct', '__clone'])) or !$this->method) {
                $name = $this->class ? $this->class . '::' . $v->name : $v->name;
                $this->fatalError($v, 'The return type of the function `' . $name . '` must be specified');
            }
        }
        if ($this->method and $v->returnType !== null) {
            $methodName = $this->class . '::' . $this->method;
            if (in_array($this->method, ['__construct', '__destruct'], true)) {
                $this->fatalError($v, 'Method `' . $methodName . '()` cannot declare a return type');
            }
            if ($this->method === '__clone'
                and (!$v->returnType instanceof Node\Identifier or strtolower($v->returnType->name) !== 'void')) {
                $this->fatalError($v, 'Method `' . $methodName . '()` return type must be void when declared');
            }
        }

        $fnName = $this->parseIdentifier($v->name);
        $this->markLateBoundTypeNodes($v->returnType);
        // Capture the late-bound return type keyword *before* resolveTypeDecl runs,
        // because resolveTypeDecl mutates the `self`/`static`/`parent` node name to
        // the declaring class when the method belongs to a trait.
        $returnTypeKeyword = '';
        if ($v->returnType instanceof Node\Name) {
            $rtLower = strtolower($v->returnType->toString());
            if ($rtLower === 'self' || $rtLower === 'static' || $rtLower === 'parent') {
                $returnTypeKeyword = $rtLower;
            }
        }
        [$returnType, $class] = $this->resolveTypeDecl($v->returnType, self::DECL_TYPE_OF_RETURN);
        $this->assertSupportedNativeObjectTypeNode($v->returnType, self::DECL_TYPE_OF_RETURN, $v);
        $nullableNativeReturn = $this->resolveNullableNativeObjectType(
            $v->returnType,
            self::DECL_TYPE_OF_RETURN,
        );
        if ($nullableNativeReturn !== null) {
            [$returnType, $class] = $nullableNativeReturn;
        }
        // Constructor, destructor, and clone methods cannot have a return value
        if ($this->method and in_array($this->method, ['__construct', '__destruct', '__clone'])) {
            $returnType = Type::VOID;
        }

        $functionDef = new FunctionDef($fnName, $returnType, $this->namespace);
        $functionDef->mustUse = (bool) $v->getAttribute(FunctionAttributeLowering::MUST_USE_ATTRIBUTE, false);
        $functionDef->immutable = (bool) $v->getAttribute(FunctionAttributeLowering::IMMUTABLE_ATTRIBUTE, false);
        $functionDef->overrideRequired = (bool) $v->getAttribute(FunctionAttributeLowering::OVERRIDE_ATTRIBUTE, false);
        $functionDef->hot = (bool) $v->getAttribute(FunctionAttributeLowering::HOT_ATTRIBUTE, false);
        $functionDef->cold = (bool) $v->getAttribute(FunctionAttributeLowering::COLD_ATTRIBUTE, false);
        $wasmExportName = $this->parseWasmExportAttribute($v);
        $functionDef->wasmExport = $wasmExportName !== null;
        $functionDef->wasmExportName = $wasmExportName ?? '';
        if ($functionDef->mustUse && $returnType === Type::VOID) {
            $this->fatalCompileTimeAttribute(
                $v,
                'MustUse',
                'MustUse cannot be applied to a function or method returning void',
            );
        }
        $functionDef->exported = !($this->classDef?->exported === false || $this->hasNoExportAttribute($v));
        $functionDef->returnClass = $class;
        $functionDef->returnNullable = $nullableNativeReturn !== null;
        $functionDef->returnTypeStr = $v->returnType === null
            ? ''
            : $this->typeCheckNodeToString($v->returnType);
        // Record late-bound return type keywords so they can be re-resolved to
        // the consuming class when a trait method is flattened into a class.
        $functionDef->returnTypeKeyword = $returnTypeKeyword;
        $functionDef->stub = $this->stubFile;
        $functionDef->importLibrary = $this->stubImportLibrary;
        $functionDef->returnTypeUndeclared = $v->returnType === null;
        $functionDef->returnsByRef = $v->byRef;
        if ($this->containsYield($v)) {
            $this->prepareGeneratorFunction($v, $functionDef);
        }

        if (!$functionDef->generator
            && !$this->isNativeObjectClass($functionDef->returnClass)
            && ($v->returnType instanceof NullableType
                || $v->returnType instanceof UnionType
                || $v->returnType instanceof IntersectionType)) {
            $typeInfo = $this->buildTypeCheckFromNode($v->returnType);
            if (!empty($typeInfo['check'])) {
                $functionDef->returnTypeCheck = $typeInfo['check'];
                $functionDef->returnTypeNode = $v->returnType;
            }
        }

        if (!$this->method && $this->canOptimizeMultiReturn($v, $functionDef)) {
            $functionDef->multiReturnCount = count($v->stmts[array_key_last($v->stmts)]->expr->items);
            // The fixed tuple is an internal ABI detail. PHP and ordinary native
            // callers continue to observe an array return value.
            $functionDef->returnType = Type::ARRAY;
        }

        $this->parseParams($v->params, $functionDef);
        $this->assertNativeObjectFunctionSignature($v, $functionDef);
        if ($functionDef->generator
            && ($this->classDef?->nativeObject || $this->functionUsesNativeObject($functionDef))
        ) {
            // FiberGenerator lowers the body to a Zend Closure and captures all
            // parameters through php::Args. Native pointers deliberately have
            // no zval representation, and a Native method's `this` cannot be
            // bound to that Closure either.
            $this->fatalError(
                $v,
                'Generator functions cannot accept, capture, or return Native objects',
            );
        }

        if ($this->classDef !== null
            && !$this->classDef->nativeObject
            && strtolower($fnName) === '__construct'
            && $this->functionUsesNativeObject($functionDef)
        ) {
            $this->fatalError($v, 'Zend-backed constructors cannot accept or return native objects');
        }

        // The main function must return void and take either no parameters or the two parameters argc and argv
        if (!$this->class and !$this->namespace and $fnName === self::ENTRY_FUNCTION) {
            if (count($v->params) > 0) {
                if (count($v->params) != 2) {
                    $this->fatalError($v, 'The parameters of the main function must be `(int $argc, array $argv)`.');
                }
                if ($returnType !== Type::VOID) {
                    $this->fatalError($v, 'main function must return void');
                }
                if (!$this->checkArgType($functionDef->argInfoList[0]->type, Type::INT)) {
                    $this->fatalError($v, 'The first parameter of the main function must be of type `int`.');
                }
                if (!$this->checkArgType($functionDef->argInfoList[1]->type, Type::ARRAY)) {
                    $this->fatalError($v, 'The second parameter of the main function must be of type `array`.');
                }
            }
        }

        return $functionDef;
    }

    private function canOptimizeMultiReturn(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, FunctionDef $functionDef): bool
    {
        if ($functionDef->stub || $functionDef->generator || $functionDef->returnsByRef
            || ($functionDef->returnType !== Type::ARRAY && !$functionDef->returnTypeUndeclared)
            || !$function->stmts) {
            return false;
        }

        $return = $function->stmts[array_key_last($function->stmts)] ?? null;
        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof Node\Expr\Array_
            || count($return->expr->items) < 2) {
            return false;
        }

        $returns = (new NodeFinder())->findInstanceOf($function->stmts, Node\Stmt\Return_::class);
        if (count($returns) !== 1) {
            return false;
        }

        foreach ($return->expr->items as $item) {
            if ($item === null || $item->key !== null || $item->unpack || $item->byRef) {
                return false;
            }
            $value = $item->value;
            if (($value instanceof Node\Expr\Variable && is_string($value->name))
                || $value instanceof Node\Scalar
                || $value instanceof Node\Expr\ConstFetch) {
                continue;
            }
            return false;
        }
        return true;
    }

    protected function prepareFunction(Node\Stmt\ClassMethod|Node\Stmt\Function_ $v): void
    {
        $this->resetFunction();
        $this->function = $this->parseIdentifier($v->name);
        $name = $this->getFunctionName($v);
        unset($this->traitMethodFunctions[$name], $this->traitMethodFunctions[$this->escapeFunction($name)]);
        if ($this->hasFunction($name)) {
            $existing = $this->getFunction($name);
            $currentIsMethod = $this->methodDef !== null;
            if ($existing->method !== $currentIsMethod) {
                $currentDisplayName = $currentIsMethod
                    ? $this->classDef->getNamespacedName(false) . '::' . $this->function
                    : ltrim($this->namespace . '\\' . $this->function, '\\');
                $existingDisplayName = $existing->displayName !== ''
                    ? $existing->displayName
                    : $existing->getNamespacedName();
                $existingLocation = $existing->sourceFile !== ''
                    ? " (previously declared in {$existing->sourceFile}:{$existing->startLine})"
                    : '';
                $this->fatalError(
                    $v,
                    "C++ symbol collision: `{$existingDisplayName}()` and `{$currentDisplayName}()` "
                    . "both map to `" . self::PREFIX . "{$name}`{$existingLocation}; rename one of them",
                );
            }
            $this->fatalError($v, "Duplicate function `{$name}`");
        }
        // Forbid redefining built-in functions
        if (!$this->methodDef and $this->isInternalFunction($name)) {
            $this->fatalError($v, "The function `{$name}` is a built-in function and cannot be redefined");
        }
        $functionDef = $this->parseFunctionDecl($v);
        $functionDef->attributeFactory = (bool) $v->getAttribute(
            RuntimeAttributeFactoryLowering::FACTORY_FUNCTION_ATTRIBUTE,
            false,
        );
        if ($functionDef->attributeFactory) {
            $functionDef->exported = false;
            $functionDef->attributeFactoryScope = (string) $v->getAttribute(
                RuntimeAttributeFactoryLowering::FACTORY_SCOPE_ATTRIBUTE,
                '',
            );
        }
        $functionDef->sourceFile = $this->file;
        $functionDef->startLine = $v->getStartLine();
        $functionDef->method = $this->methodDef !== null;
        $functionDef->declaringClass = $functionDef->method
            ? $this->classDef->getNamespacedName(false)
            : '';
        $functionDef->displayName = $functionDef->method
            ? $this->classDef->getNamespacedName(false) . '::' . $functionDef->name
            : $functionDef->getNamespacedName();
        $this->addFunction($name, $functionDef);
        if ($this->methodDef) {
            $this->methodDef->functionDef = $functionDef;
        }
    }

    protected function prepareClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->resetClass();
        $this->class = $this->parseIdentifier($class->name);
        $fullClassName = $this->getFullClassName();
        $fullClassNameLower = strtolower($fullClassName);

        if ($class instanceof Node\Stmt\Class_) {
            $flags = $class->flags;
        } else {
            $flags = Modifiers::PUBLIC;
        }
        if (isset($this->symbolDeclInFile[$fullClassNameLower])) {
            $this->fatalError($class, "Duplicate class `{$fullClassName}`");
        }
        // Dynamic properties and readonly semantics are mutually exclusive:
        // every property of a readonly class is readonly and declared, so
        // Zend rejects the attribute at compile time.
        if ($class instanceof Node\Stmt\Class_ && ($flags & Modifiers::READONLY)) {
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if (strcasecmp($this->getResolvedPhpName($attribute->name), 'AllowDynamicProperties') === 0) {
                        $this->fatalError(
                            $attribute,
                            "Cannot apply #[AllowDynamicProperties] to readonly class `{$fullClassName}`",
                        );
                    }
                }
            }
        }

        $this->classDef = new ClassDef($this->class, $flags, $this->namespace);
        $this->classDef->nativeObject = NativeClassAttributeLowering::isNative($class);
        $this->classDef->exported = !$this->hasNoExportAttribute($class);
        if ($this->classDef->nativeObject && $this->stubFile) {
            $this->fatalCompileTimeAttribute(
                $class,
                'Native',
                '#[Native] cannot be used in .stub.php; Native class layout must be owned by the TypePHP compiler',
            );
        }
        if ($this->classDef->nativeObject
            && $this->classDef->exported
            && $this->isBuildModeLib()
            && !$this->isWasiTarget()
        ) {
            $this->fatalCompileTimeAttribute(
                $class,
                'Native',
                "Native class `{$fullClassName}` cannot be exported through a library stub; mark it with #[NoExport]",
            );
        }
        $this->classDef->methodsForTarget = $this->parseMethodsForTarget($class);
        $this->addClass($fullClassName, $this->classDef);

        if (!empty($class->extends)) {
            $this->parentClass = $this->getNamespacedClassName($this->parseIdentifier($class->extends));
            $parentClassLower = strtolower($this->parentClass);
            if ($parentClassLower === $fullClassNameLower) {
                $this->fatalError($class, "Class {$fullClassName} cannot extend itself");
            }
            $this->symbols->setParent($fullClassNameLower, $parentClassLower);
            $this->classSubClasses[$parentClassLower][] = $fullClassNameLower;
            if (!$this->isInternalClass($parentClassLower)) {
                $this->symbolCallInFile[$this->file][] = $parentClassLower;
            }
            $this->classDef->extends = $this->parentClass;
            // Whether it inherits from an internal class
            $this->classDef->inheritedFromInternalClass = $this->isInternalClass($parentClassLower);
        }

        if ($class instanceof Node\Stmt\Enum_) {
            $this->classDef->enum = true;
            if ($class->scalarType !== null) {
                $this->classDef->enumBackingType = $class->scalarType->name;
            }
        }
        if (!$class instanceof Node\Stmt\Trait_) {
            $this->classDef->implements = $this->parseImplements($class->implements);
        } else {
            $this->classDef->trait = $class;
            // Trait members are compiled later in the consuming class, but
            // names inside them retain the lexical imports of the trait
            // declaration.
            $this->classDef->traitUseNamespaces = $this->useNamespaces;
            $this->classDef->traitUseAliases = $this->useAliases;
            $this->classDef->traitUseFunctions = $this->useFunctions;
            $this->classDef->traitUseConstants = $this->useConstants;
        }
        $this->symbolDeclInFile[$fullClassNameLower] = $this->file;

        if ($class instanceof Node\Stmt\Class_) {
            $generatedPrinter = null;
            $generatedArrayable = null;
            foreach ($class->getMethods() as $method) {
                if ($method->getAttribute(PrinterLowering::GENERATED_ATTRIBUTE)) {
                    $generatedPrinter = $method;
                }
                if ($method->getAttribute(ArrayableLowering::GENERATED_ATTRIBUTE)) {
                    $generatedArrayable = $method;
                }
            }
            if ($generatedPrinter !== null) {
                $this->classDef->printerGenerated = true;
                $this->classDef->printerFields = $generatedPrinter->getAttribute(PrinterLowering::FIELDS_ATTRIBUTE);
                $properties = $this->classDef->printerFields
                    ?? [...$this->parentPublicProperties($this->classDef->extends), ...ClassFieldSelection::ownPublicProperties($class)];
                PrinterLowering::rebuildGeneratedMethod(
                    $class,
                    $properties,
                    $this->classDef->printerFields,
                    $this->classStringProperties($this->classDef),
                );
            }
            if ($generatedArrayable !== null) {
                $this->classDef->arrayableGenerated = true;
                $this->classDef->arrayableFields = $generatedArrayable->getAttribute(ArrayableLowering::FIELDS_ATTRIBUTE);
                $properties = $this->classDef->arrayableFields
                    ?? [...$this->parentPublicProperties($this->classDef->extends), ...ClassFieldSelection::ownPublicProperties($class)];
                ArrayableLowering::rebuildGeneratedMethod(
                    $class,
                    $properties,
                    $this->classDef->arrayableFields,
                );
            }
        }

        // Property defaults may reference class constants declared later in the
        // class body. Collect every constant first so default-value validation
        // is independent of declaration order, matching PHP's class semantics.
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                $this->parseClassConstDef($stmt);
            }
        }

        $code = '';
        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                    break;
                case 'Stmt_Property':
                    $this->parseClassPropertyDef($v);
                    break;
                case 'Stmt_TraitUse':
                    $this->prepareTraitUse($v);
                    break;
                case 'Stmt_Nop':
                    break;
                case 'Stmt_EnumCase':
                    $caseName = $this->parseIdentifier($v->name);
                    // Only literal backing values are recorded here; an
                    // expression-valued case (`case A = 1 + 1;`) cannot be
                    // evaluated while declarations are still being collected,
                    // and no compile-time consumer needs the scalar: case
                    // identity flows as EnumCaseRef and gen_stub evaluates
                    // the registration value from the AST itself.
                    $this->classDef->enumCases[$caseName] =
                        $v->expr instanceof Node\Scalar\Int_ || $v->expr instanceof Node\Scalar\String_
                            ? $v->expr->value
                            : null;
                    break;
                case 'Stmt_ClassMethod':
                    $this->prepareClassMethod($v, $class);
                    break;
                case 'Stmt_Expression':
                    $this->foundStrayCode($v);
                    break;
                default:
                    $this->unsupportedSyntax($v);
                    break;
            }
        }

        // Trait members are later injected into the consuming class for stub
        // generation. Fully qualify every declared class type while the
        // trait's own namespace/import context is still active.
        if ($class instanceof Node\Stmt\Trait_) {
            foreach ($class->stmts as $v) {
                if ($v instanceof Node\Stmt\ClassMethod) {
                    $v->returnType = $this->upgradeToFullyQualifiedName($v->returnType);
                    foreach ($v->params as $param) {
                        $param->type = $this->upgradeToFullyQualifiedName($param->type);
                    }
                } elseif ($v instanceof Node\Stmt\Property || $v instanceof Node\Stmt\ClassConst) {
                    $v->type = $this->upgradeToFullyQualifiedName($v->type);
                }
            }
        }

        $this->resetClass();

        return $code;
    }

    /** @return list<string> */
    protected function parentPublicProperties(string $parent): array
    {
        if ($parent === '') {
            return [];
        }
        $classDef = $this->getClassDef($parent);
        if ($classDef === null) {
            return [];
        }
        $properties = $this->parentPublicProperties($classDef->extends);
        foreach ($classDef->properties as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $properties[] = $property->name;
            }
        }
        return array_values(array_unique($properties));
    }

    /** @return list<string> */
    protected function selectableProperties(ClassDef $classDef): array
    {
        $properties = [];
        $parent = $classDef->extends;
        while ($parent !== '') {
            $parentDef = $this->getClassDef($parent);
            if ($parentDef === null) {
                break;
            }
            foreach ($parentDef->properties as $property) {
                if (!$property->isStatic() && !$property->isPrivate()) {
                    $properties[] = $property->name;
                }
            }
            $parent = $parentDef->extends;
        }
        foreach ($classDef->properties as $property) {
            if (!$property->isStatic()) {
                $properties[] = $property->name;
            }
        }
        return array_values(array_unique($properties));
    }

    /** @return list<string> */
    protected function classStringProperties(ClassDef $classDef): array
    {
        $types = [];
        if ($classDef->extends !== '') {
            $parent = $this->getClassDef($classDef->extends);
            if ($parent !== null) {
                foreach ($this->classStringProperties($parent) as $property) {
                    $types[$property] = true;
                }
            }
        }
        foreach ($classDef->properties as $property) {
            if (!$property->isStatic()) {
                $types[$property->name] = $property->type === Type::STR;
            }
        }
        return array_keys(array_filter($types));
    }

    protected function parseMethodsForTarget(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): ?string
    {
        foreach ($class->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                if (!$this->isRootCompileTimeAttribute($attribute, 'MethodsFor')) {
                    continue;
                }
                if (!$class instanceof Node\Stmt\Class_) {
                    $this->fatalCompileTimeAttribute(
                        $class,
                        'MethodsFor',
                        'MethodsFor can only be applied to classes',
                        $attribute,
                    );
                }
                if (count($attribute->args) !== 1) {
                    $this->fatalCompileTimeAttribute(
                        $class,
                        'MethodsFor',
                        'MethodsFor expects exactly one target',
                        $attribute,
                    );
                }
                $target = $this->parseMethodsForTargetValue($attribute->args[0]->value, $attribute, $class);
                unset($group->attrs[$attributeIndex]);
                $group->attrs = array_values($group->attrs);
                if (empty($group->attrs)) {
                    unset($class->attrGroups[$groupIndex]);
                    $class->attrGroups = array_values($class->attrGroups);
                }
                return $target;
            }
        }
        return null;
    }

    private function parseMethodsForTargetValue(
        Node\Expr $value,
        NodeAbstract $errorNode,
        Node\Stmt\Class_ $class,
    ): string
    {
        if ($value instanceof Node\Scalar\String_ && $value->value === '*') {
            return '*';
        }
        if ($value instanceof Node\Expr\ClassConstFetch
            && $this->isNameExpr($value->class)
            && $this->isIdExpr($value->name)) {
            $targetClass = $this->getResolvedPhpName($value->class);
            $constant = $value->name->toString();
            if (strtolower($constant) === 'class') {
                return $targetClass;
            }
            $targets = [
                'Int' => Type::INT,
                'Float' => Type::FLOAT,
                'Bool' => Type::BOOL,
                'BigInt' => Type::BIGINT,
                'BigFloat' => Type::BIGFLOAT,
                'Decimal' => Type::DECIMAL,
                'String' => Type::STR,
                'Array' => Type::ARRAY,
                'Object' => Type::OBJECT,
                'Any' => Type::VAR,
                'Stream' => Type::STREAM,
                'Box' => Type::BOX,
            ];
            if (strcasecmp($targetClass, 'Type') === 0 && isset($targets[$constant])) {
                return $targets[$constant];
            }
        }
        $this->fatalCompileTimeAttribute(
            $class,
            'MethodsFor',
            "MethodsFor target must be '*', Type::*, or ClassName::class",
            $errorNode,
        );
    }

    protected function buildLiteralArrayInitPlan(Node\Expr\Array_ $defaultNode): ArrayInitPlan
    {
        $localVarCount = count($this->context->localVars);
        $beforeStmtCount = count($this->context->beforeStmtLines);
        $afterStmtCount = count($this->context->afterStmtLines);
        $expr = $this->parseIdentifier($defaultNode);

        $init = '';
        $clean = '';
        $newLocalVars = array_slice($this->context->localVars, $localVarCount, null, true);
        $newBeforeStmtLines = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
        $newAfterStmtLines = array_slice($this->context->afterStmtLines, $afterStmtCount);

        if ($newLocalVars) {
            $init .= $this->genLocalVarDecl($newLocalVars);
            $this->context->localVars = array_slice($this->context->localVars, 0, $localVarCount, true);
        }
        if ($newBeforeStmtLines) {
            $init .= implode(PHP_EOL, $newBeforeStmtLines) . PHP_EOL;
            $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
        }
        if ($newAfterStmtLines) {
            $clean .= implode(PHP_EOL, $newAfterStmtLines) . PHP_EOL;
            $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
        }

        return new ArrayInitPlan($expr, $init, $clean);
    }

    protected function getMethodName(Node\Stmt\ClassMethod $v): string
    {
        return $this->parseIdentifier($v->name);
    }

    protected function parseClassConstDef(Node\Stmt\ClassConst $v): void
    {
        $this->resetFunction();
        $flags = $this->parseModifiers($v->flags);
        [$declaredType, $class] = $v->type
            ? $this->resolveTypeDecl($v->type, self::DECL_TYPE_OF_CONST)
            : [null, ''];
        if ($v->type !== null && $this->typeDeclContainsCallable($v->type)) {
            $constName = $v->consts !== [] ? $this->parseIdentifier($v->consts[0]->name) : '';
            $this->fatalError(
                $v,
                "Class constant `{$this->classDef->getNamespacedName(false)}::{$constName}` cannot have type `{$this->typeCheckNodeToString($v->type)}`",
            );
        }

        foreach ($v->consts as $const) {
            $type = $declaredType;
            if ($type === null) {
                $type = match ($const->value->getType()) {
                    'Expr_Array' => Type::ARRAY,
                    'Scalar_String' => Type::STR,
                    default => Type::VAR,
                };
                // `::class` is a compile-time magic constant that always yields a string,
                // so a constant declared as `X = self::class` (or `Foo::class`) must be
                // typed as a string rather than a generic variant.
                if ($type === Type::VAR
                    && $const->value instanceof Node\Expr\ClassConstFetch
                    && strtolower((string) $const->value->name) === 'class') {
                    $type = Type::STR;
                }
                // A constant whose value references another class constant
                // (e.g. `X = ParentClass::Y` or `X = self::Y`) must take the referenced
                // constant's type. This keeps override compatibility checks and the C++
                // declaration correct, mirroring PHP where overriding an untyped constant
                // with a value of any (compatible) type is allowed.
                if ($type === Type::VAR
                    && $const->value instanceof Node\Expr\ClassConstFetch
                    && $const->value->class instanceof Node\Name) {
                    $refType = $this->resolveReferencedConstantType($const->value, $this->getFullClassName());
                    if ($refType !== null) {
                        $type = $refType;
                    }
                }
            }
            $constName = $this->parseIdentifier($const->name);
            if ($this->classDef->hasConstant($constName)) {
                $this->fatalError($v, "Duplicate constant `{$constName}`");
            }
            $constInfo = $this->parseClassLikeConstant($const, $flags, $type, $class, $declaredType);
            $constInfo->class = $class;
            $this->classDef->constants[$constInfo->name] = $constInfo;
        }
    }

    /**
     * Resolve the compile-time type of a class constant whose value is a
     * `ClassConstFetch` referencing another constant (e.g. `X = ParentClass::Y`
     * or `X = self::Y`). Returns the referenced constant's type, or null when
     * the reference cannot be resolved yet (for instance when the referenced
     * class has not been prepared). `::class` always resolves to a string.
     */
    protected function resolveReferencedConstantType(Node\Expr\ClassConstFetch $fetch, string $currentClass): ?string
    {
        $constName = $fetch->name->toString();
        if (strcasecmp($constName, 'class') === 0) {
            return Type::STR;
        }
        if (!($fetch->class instanceof Node\Name)) {
            return null;
        }
        $className = $fetch->class->toString();
        if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
            $targetClass = $currentClass;
        } elseif (strcasecmp($className, 'parent') === 0) {
            $targetClass = $this->getParentClass($currentClass);
        } else {
            $targetClass = $this->getNamespacedClassName($className);
        }
        if ($targetClass === '' || !$this->hasClass($targetClass)) {
            return null;
        }
        $def = $this->getClass($targetClass);
        if (!$def->hasConstant($constName)) {
            return null;
        }
        $refConst = $def->getConstant($constName);
        // Follow the chain in case the referenced constant is itself an
        // expression that resolves to another constant.
        if ($refConst->type !== Type::VAR) {
            return $refConst->type;
        }
        if ($refConst->valueExpr instanceof Node\Expr\ClassConstFetch) {
            return $this->resolveReferencedConstantType($refConst->valueExpr, $targetClass);
        }
        return null;
    }

    private function parseClassLikeConstant(Node\Const_ $const, int $flags, string $type, string $class = '', ?string $declaredType = null): ConstantDef
    {
        $constName = $this->parseIdentifier($const->name);
        $constValue = $this->compilerPhase === self::PHASE_CONVERT
            ? $this->parseIdentifier($const->value)
            : '';

        $constInfo = new ConstantDef($constName, $flags, $type, $constValue);
        $constInfo->valueExpr = $const->value;
        $constInfo->declaredType = $declaredType;
        $constInfo->codegenFinalized = $this->compilerPhase === self::PHASE_CONVERT;

        if ($constInfo->codegenFinalized && $this->context->beforeStmtLines) {
            $arrayExpr = '';
            if ($this->context->localVars) {
                $arrayExpr .= $this->genScopeVarDecl();
            }
            $arrayExpr .= $this->parseBeforeStmtLines();
            $constInfo->arrayExpr = $arrayExpr;
        }
        $constInfo->class = $class;
        return $constInfo;
    }

    /**
     * Create and register a class property with type normalization, shared by
     * regular property declarations and constructor property promotion.
     */
    protected function addClassProperty(string $name, int $flags, ?NodeAbstract $typeNode, $defaultNode, bool $nullable, NodeAbstract $errorNode, bool $promoted = false): PropertyDef
    {
        if ($promoted
            && ($flags & Modifiers::FINAL)
            && !($flags & Modifiers::VISIBILITY_MASK)
        ) {
            $this->fatalError(
                $errorNode,
                'Final promoted property must explicitly declare public, protected, or private visibility',
            );
        }
        $flags = $this->parseModifiers($flags);
        // A `readonly class` marks every property readonly, so the class-level
        // flag participates in the same Zend declaration rules as an explicit
        // per-property `readonly` modifier.
        if (($flags | $this->classDef->flags) & Modifiers::READONLY) {
            $className = $this->classDef->getNamespacedName(false);
            if ($flags & Modifiers::STATIC) {
                $this->fatalError($errorNode, "Static property `{$className}::\${$name}` cannot be readonly");
            }
            if ($typeNode === null) {
                $this->fatalError($errorNode, "Readonly property `{$className}::\${$name}` must have type");
            }
            if ($defaultNode !== null) {
                $this->fatalError($errorNode, "Readonly property `{$className}::\${$name}` cannot have default value");
            }
        }
        $this->validateAsymmetricPropertyDeclaration($name, $flags, $typeNode, $errorNode);
        // Resolving the declaration also runs the common compound-type
        // validation (callable as an intersection/DNF member is rejected
        // there, ahead of the property-specific rule, matching Zend).
        [$type, $class] = $this->resolveTypeDecl($typeNode, self::DECL_TYPE_OF_PROPERTY);
        // `callable` is a runtime-context type (a string or array may or may
        // not be callable depending on scope), so Zend forbids it in property
        // types entirely - bare, nullable, or as a union member.
        if ($typeNode !== null && $this->typeDeclContainsCallable($typeNode)) {
            $this->fatalError(
                $errorNode,
                "Property `{$this->classDef->getNamespacedName(false)}::\${$name}` cannot have type `{$this->typeCheckNodeToString($typeNode)}`",
            );
        }
        $this->assertSupportedNativeObjectTypeNode($typeNode, self::DECL_TYPE_OF_PROPERTY, $errorNode);
        $nullableNative = $this->resolveNullableNativeObjectType(
            $typeNode,
            self::DECL_TYPE_OF_PROPERTY,
        );
        if ($nullableNative !== null) {
            [$type, $class] = $nullableNative;
            $nullable = true;
        }
        if ($this->isNativeObjectClass($class) && !$this->classDef->nativeObject) {
            $this->fatalError(
                $errorNode,
                'Native object types can only be used as properties of native classes',
            );
        }

        $default = null;
        $arrayInitPlan = null;
        if ($defaultNode !== null) {
            $this->checkPropertyDefaultType($name, $typeNode, $defaultNode, $errorNode);
            if ($defaultNode instanceof Node\Expr\Array_) {
                if ($this->compilerPhase === self::PHASE_CONVERT) {
                    $arrayInitPlan = $this->buildLiteralArrayInitPlan($defaultNode);
                    $default = $arrayInitPlan->expr;
                }
                // Only narrow the property type to `array` when the declared type
                // cannot already hold an array. `mixed`/`iterable`/union/nullable
                // types are represented as php::Var and can legally store an array,
                // so forcing `array` here would wrongly reject non-array assignments
                // (e.g. `mixed $value = []` followed by `$this->value = 123`).
                if ($type !== Type::VAR) {
                    $type = Type::ARRAY;
                }
            } elseif ($this->compilerPhase === self::PHASE_CONVERT) {
                $default = $this->parseIdentifier($defaultNode);
            }
        }

        if ($this->classDef->hasProperty($name)) {
            $this->fatalError($errorNode, "Duplicate property `{$name}`");
        }

        $propDef = new PropertyDef($name, $flags, $type, $default, $nullable);
        $propDef->overrideRequired = (bool) $errorNode->getAttribute(
            FunctionAttributeLowering::OVERRIDE_ATTRIBUTE,
            false,
        );
        $propDef->node = $errorNode;
        if ($typeNode !== null
            && !$typeNode instanceof NullableType
            && !$typeNode instanceof UnionType
            && !$typeNode instanceof IntersectionType
        ) {
            $propDef->explicitAny = strtolower($this->parseIdentifier($typeNode)) === 'any';
        }
        $propDef->readonly = (bool) (($flags | $this->classDef->flags) & Modifiers::READONLY);
        $propDef->class = $class;
        $propDef->defaultExpr = $defaultNode;
        $propDef->arrayInitPlan = $arrayInitPlan;
        $propDef->requiresRuntimeDefaultInit = $this->propertyDefaultRequiresRuntimeInit($defaultNode);
        $propDef->promoted = $promoted;
        if ($typeNode instanceof NullableType || $typeNode instanceof UnionType || $typeNode instanceof IntersectionType) {
            $typeInfo = $this->buildTypeCheckFromNode($typeNode);
            $propDef->typeCheck = $typeInfo['check'];
            $propDef->typeStr = $typeInfo['typeStr'];
        }
        $this->classDef->properties[$name] = $propDef;
        return $propDef;
    }

    /**
     * Whether a declared type mentions `callable` outside an intersection.
     * Zend forbids callable in property and class-constant types; callable
     * inside an intersection is rejected first, with its own diagnostic, by
     * the common declaration validation in parseTypeDecl().
     */
    private function typeDeclContainsCallable(NodeAbstract $typeNode): bool
    {
        if ($typeNode instanceof NullableType) {
            return $this->typeDeclContainsCallable($typeNode->type);
        }
        if ($typeNode instanceof UnionType) {
            foreach ($typeNode->types as $member) {
                if ($this->typeDeclContainsCallable($member)) {
                    return true;
                }
            }
            return false;
        }
        if ($typeNode instanceof IntersectionType) {
            return false;
        }
        return strtolower($this->parseIdentifier($typeNode)) === 'callable';
    }

    private function validateAsymmetricPropertyDeclaration(
        string $name,
        int $flags,
        ?NodeAbstract $typeNode,
        NodeAbstract $errorNode,
    ): void {
        if (!($flags & (Modifiers::PRIVATE_SET | Modifiers::PROTECTED_SET))) {
            return;
        }

        $className = $this->classDef->getNamespacedName(false);
        if ($typeNode === null) {
            $this->fatalError(
                $errorNode,
                "Property with asymmetric visibility {$className}::\${$name} must have type",
            );
        }

        $readVisibility = $flags & Modifiers::PRIVATE
            ? 1
            : ($flags & Modifiers::PROTECTED ? 2 : 3);
        $setVisibility = $flags & Modifiers::PRIVATE_SET ? 1 : 2;
        if ($readVisibility < $setVisibility) {
            $this->fatalError(
                $errorNode,
                "Visibility of property {$className}::\${$name} must not be weaker than set visibility",
            );
        }
    }

    /**
     * gen_stub emits compile-time scalar values and empty arrays exactly into
     * the internal class default-property table. Non-empty arrays are emitted
     * there as an empty-array placeholder, while enum cases need a live object;
     * both must therefore be restored by create_object. Keep an unresolved
     * expression on that conservative runtime path as well.
     */
    private function propertyDefaultRequiresRuntimeInit(?NodeAbstract $default): bool
    {
        if ($default === null) {
            return false;
        }
        if ($default instanceof Node\Expr\Array_) {
            return $default->items !== [];
        }

        // A null result includes enum cases and constants which cannot be
        // resolved safely during preprocessing. Their runtime initialization
        // must not be removed merely because their source syntax resembles a
        // scalar constant expression.
        $type = $this->detectDefaultValueType($default);
        return $type === null || $type === 'array';
    }

    /**
     * Diagnose, during preprocessing, whether a property's default value is
     * compatible with its declared type.
     *
     * TypePHP rejects obvious mismatches such as `int $a = []` at compile time
     * instead of silently coercing the declared type or deferring to a runtime
     * TypeError, matching the static-compilation principles in CLAUDE.md.
     */
    protected function checkPropertyDefaultType(string $name, ?NodeAbstract $typeNode, NodeAbstract $defaultNode, NodeAbstract $errorNode): void
    {
        if ($typeNode === null) {
            // Untyped property accepts any default value.
            return;
        }

        $valueType = $this->detectDefaultValueType($defaultNode);
        if ($valueType === null) {
            // The value type is not statically decidable (e.g. user or class
            // constant references); leave it to later stages.
            return;
        }

        $allowed = $this->collectAllowedDefaultTypes($typeNode);
        if ($allowed === null) {
            // mixed / callable / otherwise unconstrained type declaration.
            return;
        }

        if (in_array($valueType, $allowed, true)) {
            return;
        }

        $className = $this->getFullClassName();
        $typeStr   = $this->propertyTypeDeclToString($typeNode);
        $this->fatalError(
            $errorNode,
            "Cannot use {$valueType} as default value for property {$className}::\${$name} of type {$typeStr}"
        );
    }

    /**
     * Determine the PHP value type of a constant expression used as a default
     * value. Returns one of int/float/string/true/false/array/null, or null when
     * the type cannot be decided statically.
     */
    protected function detectDefaultValueType(NodeAbstract $node, ?string $scopeClass = null, int $depth = 0): ?string
    {
        if ($depth > 16) {
            return null;
        }
        $scopeClass ??= $this->getFullClassName();

        switch ($node->getType()) {
            case 'Scalar_Int':
                return 'int';
            case 'Scalar_Float':
                return 'float';
            case 'Scalar_String':
            case 'Scalar_InterpolatedString':
            case 'Expr_BinaryOp_Concat':
                return 'string';
            case 'Expr_Array':
                return 'array';
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                return $this->detectDefaultValueType($node->expr, $scopeClass, $depth + 1);
            case 'Expr_ConstFetch':
                return match (strtolower($node->name->toString())) {
                    'true'          => 'true',
                    'false'         => 'false',
                    'null'          => 'null',
                    default         => null,
                };
            case 'Expr_ClassConstFetch':
                if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
                    return null;
                }
                $constName = $node->name->toString();
                if (strcasecmp($constName, 'class') === 0) {
                    return 'string';
                }
                $className = $node->class->toString();
                if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
                    $targetClass = $scopeClass;
                } elseif (strcasecmp($className, 'parent') === 0) {
                    $targetClass = $this->getParentClass($scopeClass);
                } else {
                    $targetClass = $this->getNamespacedClassName($className);
                }
                if ($targetClass === '' || !$this->hasClass($targetClass)) {
                    return null;
                }
                $targetDef = $this->getClass($targetClass);
                if (!$targetDef->hasConstant($constName)) {
                    return null;
                }
                return $this->detectDefaultValueType(
                    $targetDef->getConstant($constName)->valueExpr,
                    $targetClass,
                    $depth + 1
                );
            default:
                try {
                    $value = (new ConstExprEvaluator(
                        static function (Node\Expr $expr): never {
                            throw new \RuntimeException('Unresolved constant expression');
                        }
                    ))->evaluateDirectly($node);
                } catch (\Throwable) {
                    return null;
                }
                return match (true) {
                    is_int($value) => 'int',
                    is_float($value) => 'float',
                    is_string($value) => 'string',
                    $value === true => 'true',
                    $value === false => 'false',
                    is_array($value) => 'array',
                    $value === null => 'null',
                    default => null,
                };
        }
    }

    /**
     * Collect the set of value types accepted as a default for a declared type
     * node. Returns null when the type imposes no statically-checkable
     * constraint (mixed / callable / unknown).
     *
     * @return array<int, string>|null
     */
    protected function collectAllowedDefaultTypes(NodeAbstract $typeNode): ?array
    {
        if ($typeNode instanceof NullableType) {
            $inner = $this->collectAllowedDefaultTypes($typeNode->type);
            if ($inner === null) {
                return null;
            }
            return array_values(array_unique(array_merge($inner, ['null'])));
        }

        if ($typeNode instanceof UnionType) {
            $all = [];
            foreach ($typeNode->types as $sub) {
                $part = $this->collectAllowedDefaultTypes($sub);
                if ($part === null) {
                    // A mixed-like member accepts any default value.
                    return null;
                }
                $all = array_merge($all, $part);
            }
            return array_values(array_unique($all));
        }

        if ($typeNode instanceof IntersectionType) {
            // Intersection types are object-only; no scalar/array default valid.
            return [];
        }

        return match (strtolower($this->parseIdentifier($typeNode))) {
            'int'                      => ['int'],
            'float', 'double'          => ['float', 'int'], // int coerces to float
            'string'                   => ['string'],
            'bool'                     => ['true', 'false'],
            'true'                     => ['true'],
            'false'                    => ['false'],
            'array'                    => ['array'],
            'iterable'                 => ['array'],
            'null'                     => ['null'],
            'object'                   => [], // no literal object default exists
            'self', 'parent', 'static' => [],
            'mixed', 'any'             => null,
            'callable'                 => null, // string/array/closure — not checkable
            default                    => [],   // class type: only null via ?Type
        };
    }

    protected function propertyTypeDeclToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof NullableType) {
            return '?' . $this->propertyTypeDeclToString($typeNode->type);
        }
        if ($typeNode instanceof UnionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->propertyTypeDeclToString($t);
            }
            return implode('|', $parts);
        }
        if ($typeNode instanceof IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->propertyTypeDeclToString($t);
            }
            return implode('&', $parts);
        }
        return $this->parseIdentifier($typeNode);
    }

    protected function parseClassPropertyDef(Node\Stmt\Property $v): void
    {
        $this->validateClassPropertyHookPlacement($v);
        $arrayDef = $this->parseArrayDefinition($v);
        if ($this->classDef->nativeObject) {
            if ($v->type === null) {
                $this->fatalError($v, 'Native class properties must declare a type');
            }
            if ($v->isStatic()) {
                $this->fatalError($v, 'Native class static properties are not supported');
            }
            // A Zend readonly property carries runtime initialization state.
            // Native fields deliberately have no Zend property slot, so a raw
            // C++ assignment would silently bypass the readonly contract.
            // Reject it until Native objects have an equally explicit state
            // representation instead of emitting behavior that only appears
            // readonly at compile time.
            if ($v->isReadonly() || ($this->classDef->flags & Modifiers::READONLY)) {
                $this->fatalError($v, 'Native class readonly properties are not supported');
            }
        }
        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $nullable = $v->type instanceof NullableType;

        foreach ($v->props as $prop) {
            $propName = $this->parseIdentifier($prop->name);
            $propDef = $this->addClassProperty($propName, $v->flags, $v->type, $prop->default, $nullable, $v);
            $propDef->arrayDef = $arrayDef;
            if ($this->classDef->nativeObject && $this->isNativeObjectForbiddenPropertyType($propDef)) {
                $message = $propDef->type === Type::BOX
                    ? 'Native class properties cannot use Box types'
                    : 'Native class properties cannot use Std Container types';
                $this->fatalError($v, $message);
            }
            $hookMetadata = $v->getAttribute(PropertyHookLowering::PROPERTY_ATTRIBUTE, []);
            $propDef->virtual = (bool) ($hookMetadata['virtual'] ?? false);
            foreach ($v->hooks as $hook) {
                $kind = strtolower($hook->name->toString());
                if ($kind === 'get') {
                    if ($hook->byRef) {
                        $this->fatalError($hook, 'Property get hooks returning by reference are not supported');
                    }
                    $propDef->getter = PropertyHookLowering::getterName($propName);
                } elseif ($kind === 'set') {
                    $propDef->setter = PropertyHookLowering::setterName($propName);
                }
            }
        }

        $this->context = $oriCtx;
    }

    /**
     * Mirror Zend's compile-time placement rules for property hooks on class
     * (and trait) properties; the interface path enforces its own subset in
     * prepareInterfaceProperty(). Check order follows Zend 8.4 precedence:
     * static, readonly, then the abstract-property rules.
     */
    private function validateClassPropertyHookPlacement(Node\Stmt\Property $v): void
    {
        $abstract = (bool) ($v->flags & Modifiers::ABSTRACT);
        if ($v->hooks === [] && !$abstract) {
            return;
        }

        $className = $this->classDef->getNamespacedName(false);
        $propName = $v->props !== [] ? $this->parseIdentifier($v->props[0]->name) : '';
        if ($v->hooks !== []) {
            if ($v->flags & Modifiers::STATIC) {
                $this->fatalError($v, 'Cannot declare hooks for static property');
            }
            // A readonly class marks every property readonly, exactly like an
            // explicit per-property modifier.
            if (($v->flags | $this->classDef->flags) & Modifiers::READONLY) {
                $this->fatalError($v, 'Hooked properties cannot be readonly');
            }
            // Zend checks hook-level modifier conflicts right after the
            // property-level placement rules, before any abstract-property
            // rule: a final hook on a private property is rejected first even
            // when the hook is also bodiless (probed:
            // `abstract private int $x { final get; }` reports final+private).
            // A private property cannot be overridden, so a final hook on it
            // is meaningless; the rule applies in traits as well.
            foreach ($v->hooks as $hook) {
                if (($hook->flags & Modifiers::FINAL) && ($v->flags & Modifiers::PRIVATE)) {
                    $this->fatalError($hook, 'Property hook cannot be both final and private');
                }
            }
        }

        if ($abstract) {
            if ($v->hooks === []) {
                $this->fatalError($v, 'Only hooked properties may be declared abstract');
            }
            // A bodiless hook of an abstract property is itself abstract. An
            // abstract hook must be implementable by a subclass, which a
            // private property forbids, and must be overridable, which final
            // forbids. Zend reports these per hook, before the default-value
            // and abstract-hook-presence rules (probed on 8.4.13), and —
            // unlike abstract private trait METHODS — does not exempt traits.
            foreach ($v->hooks as $hook) {
                if ($hook->body !== null) {
                    continue;
                }
                if ($v->flags & Modifiers::PRIVATE) {
                    $this->fatalError($hook, 'Property hook cannot be both abstract and private');
                }
                if ($hook->flags & Modifiers::FINAL) {
                    $this->fatalError($hook, 'Property hook cannot be both abstract and final');
                }
            }
            foreach ($v->props as $prop) {
                if ($prop->default !== null) {
                    $this->fatalError(
                        $v,
                        "Cannot specify default value for virtual hooked property {$className}::\${$propName}",
                    );
                }
            }
            $hasAbstractHook = false;
            foreach ($v->hooks as $hook) {
                if ($hook->body === null) {
                    $hasAbstractHook = true;
                    break;
                }
            }
            if (!$hasAbstractHook) {
                $this->fatalError(
                    $v,
                    "Abstract property `{$className}::\${$propName}` must specify at least one abstract hook",
                );
            }
            if (!$this->classDef->trait && !($this->classDef->flags & Modifiers::ABSTRACT)) {
                $this->fatalError(
                    $v,
                    "Non-abstract class `{$className}` contains abstract hooked property `\${$propName}`",
                );
            }
            return;
        }

        // Without the abstract modifier every declared hook needs a body.
        foreach ($v->hooks as $hook) {
            if ($hook->body === null) {
                $this->fatalError($hook, 'Non-abstract property hook must have a body');
            }
        }
    }

    protected function prepareClassMethod(Node\Stmt\ClassMethod $v, Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): void
    {
        $this->resetMethod();
        $name = $this->getMethodName($v);
        $this->method = $name;
        $this->assertKeywordMethodMayBeDeclared($v, $name, $this->classDef->nativeObject);
        $this->assertNativeMagicMethodSupported($v, $name);
        $flags = $this->parseModifiers($v->flags);
        $abstract = $flags & Modifiers::ABSTRACT;
        if ($this->classDef->nativeObject && ($flags & Modifiers::STATIC)) {
            $this->fatalError($v, 'Native class static methods are not supported');
        }

        if (!$abstract) {
            $this->methodDef = new MethodDef($flags, $name);
            $this->methodDef->node = $v;
            $traitOrigin = $v->getAttribute('typephp_trait_origin');
            if (is_string($traitOrigin)) {
                $this->methodDef->traitOrigin = $traitOrigin;
            }
            if ($this->classDef->hasMethod($name)) {
                $generatedBy = $v->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_BY);
                $generatedTarget = $v->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_TARGET);
                if (is_string($generatedBy) && $generatedTarget instanceof Node) {
                    $this->fatalCompileTimeAttribute(
                        $generatedTarget,
                        $generatedBy,
                        "Duplicate method `{$this->method}`",
                        $generatedTarget,
                        null,
                        $this->classDef->getMethod($name)->node,
                    );
                }
                $this->fatalError($v, "Duplicate method `{$this->method}`");
            }
            $this->prepareFunction($v);
            if ($class instanceof Node\Stmt\Trait_) {
                // Trait functions are templates, not native symbols. The
                // FunctionDef remains owned by MethodDef and is cloned into
                // every class that composes the trait.
                $this->symbols->removeFunction($this->getFunctionName($v));
            }
            $this->checkRequiredArgNum($name, $this->methodDef, $v);
            $this->classDef->addMethod($this->methodDef);
        } else {
            if ($this->classDef->hasMethod($name) || $this->classDef->hasAbstractMethod($name)) {
                $this->fatalError($v, "Duplicate method `{$this->method}`");
            }
            // A private method cannot be overridden, so an abstract private
            // method could never be implemented. Traits are exempt since PHP
            // 8.0: the consuming class provides the private implementation.
            if (!$class instanceof Node\Stmt\Trait_ && ($flags & Modifiers::PRIVATE)) {
                $this->fatalError($v, "Abstract function `{$this->class}::{$name}()` cannot be declared private");
            }
            // An abstract method declares a signature only; Zend rejects a body
            // instead of silently discarding it.
            if ($v->stmts !== null) {
                $this->fatalError($v, "Abstract function `{$this->class}::{$name}()` cannot contain body");
            }
            if (!$class instanceof Node\Stmt\Trait_ && isset($class->flags) && !($class->flags & Modifiers::ABSTRACT)) {
                $this->fatalError($v, "Non-abstract class {$this->class} contains abstract method {$v->name}");
            }
            $this->methodDef = new MethodDef($flags, $name);
            $this->methodDef->node = $v;
            $traitOrigin = $v->getAttribute('typephp_trait_origin');
            if (is_string($traitOrigin)) {
                $this->methodDef->traitOrigin = $traitOrigin;
            }
            // Keep abstract method metadata in the symbol repository as well.
            // Native virtual calls need the same argument/default lowering as
            // concrete calls, even though no free-function body is emitted.
            $this->prepareFunction($v);
            $this->methodDef->functionDef->abstractMethod = true;
            $this->checkRequiredArgNum($name, $this->methodDef, $v);
            if ($this->method === '__construct') {
                foreach ($v->params as $param) {
                    if ($param->isPromoted()) {
                        $this->fatalError($v, 'Cannot declare promoted property in an abstract constructor');
                    }
                }
            }
            $this->classDef->addAbstractMethod($name, $flags, $this->methodDef);
        }

        $normalizedMethod = strtolower($name);
        if ($this->classDef->nativeObject && $normalizedMethod === 'toany') {
            $this->assertKeywordConversionMethodSignature(
                $v,
                $this->classDef->getNamespacedName(false),
                $name,
                $this->methodDef->functionDef,
                Type::VAR,
                true,
            );
        } elseif ($normalizedMethod === 'toarray') {
            $this->assertKeywordConversionMethodSignature(
                $v,
                $this->classDef->getNamespacedName(false),
                $name,
                $this->methodDef->functionDef,
                Type::ARRAY,
                $this->classDef->nativeObject,
            );
        }

        $fullClassName = $this->getFullClassName();

        $fullMethodName = $fullClassName . '::' . $this->method;
        $fullMethodNameLower = strtolower($fullMethodName);
        $fullClassNameLower = strtolower($fullClassName);

        // Check whether a subclass already overrides this method (when the subclass is preprocessed before the parent)
        $isOverridden = $this->isMethodOverriddenInSubClasses($fullClassNameLower, $this->method);
        $this->classMethodOverride[$fullMethodNameLower] = $isOverridden;

        // Find whether a parent class has a method with the same name, and recursively mark the parent method as overridden
        while (($parentClass = $this->symbols->parent($fullClassNameLower)) !== '') {
            $parentMethodLower = strtolower($parentClass . '::' . $this->method);
            if (isset($this->classMethodOverride[$parentMethodLower])) {
                $this->classMethodOverride[$parentMethodLower] = true;
            }
            $fullClassNameLower = strtolower($parentClass);
        }

        $this->resetMethod();
    }

    /**
     * Finalize the classMethodOverride flags once the complete class graph is
     * known.
     *
     * The incremental registration in prepareClassMethod() depends on file
     * preprocessing order: with a "sandwich" order (ancestor first, leaf
     * second, intermediate class last), the ancestor method's override flag is
     * missed, causing MethodCallTrait::findNativeMethod() to devirtualize a
     * call that should be dynamically dispatched.
     *
     * Runs once per class-graph change, before conversion starts. For every
     * declared method it walks the complete parent chain and marks each
     * ancestor method of the same name as overridden, following the existing
     * upward-marking semantics. This is order-independent and costs roughly
     * method count x inheritance depth.
     */
    protected function finalizeMethodOverrideFlags(): void
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'method override flag finalization');
        if ($this->methodOverrideFlagsFinalized) {
            return;
        }
        $this->methodOverrideFlagsFinalized = true;

        // Trait composition introduces real methods into the consuming class.
        // They participate in virtual dispatch exactly like methods declared
        // in the class body, so mark them before any method body is lowered.
        // This is deliberately conservative: an extra mark only disables a
        // native direct-call optimization, while a missing mark bypasses the
        // trait override at runtime.
        foreach ($this->symbols->classes() as $classDef) {
            if ($classDef->trait !== null || $classDef->usedTraits === []) {
                continue;
            }
            $traitMethods = [];
            $visitedTraits = [];
            $this->collectComposedTraitMethodNames($classDef, $traitMethods, $visitedTraits);
            $className = strtolower($classDef->getNamespacedName(false));
            foreach (array_keys($traitMethods) as $method) {
                $this->classMethodOverride[$className . '::' . $method] ??= false;
            }
        }

        foreach (array_keys($this->classMethodOverride) as $fullMethodNameLower) {
            $pos = strrpos($fullMethodNameLower, '::');
            if ($pos === false) {
                continue;
            }
            $methodLower = substr($fullMethodNameLower, $pos + 2);
            $classLower = substr($fullMethodNameLower, 0, $pos);
            while (($parentClass = $this->symbols->parent($classLower)) !== '') {
                $parentMethodLower = strtolower($parentClass) . '::' . $methodLower;
                if (isset($this->classMethodOverride[$parentMethodLower])) {
                    $this->classMethodOverride[$parentMethodLower] = true;
                }
                $classLower = strtolower($parentClass);
            }
        }
    }

    /**
     * Collect every concrete method a class may receive through direct or
     * nested trait composition. Conflict suppression may make this set larger
     * than the final method table; those false positives safely retain Zend
     * dynamic dispatch.
     *
     * @param array<string, true> $methods
     * @param array<string, true> $visitedTraits
     */
    private function collectComposedTraitMethodNames(
        ClassDef $owner,
        array &$methods,
        array &$visitedTraits,
    ): void {
        foreach ($owner->usedTraits as $traitName) {
            $traitKey = strtolower($traitName);
            if (isset($visitedTraits[$traitKey]) || !$this->hasClass($traitName)) {
                continue;
            }
            $visitedTraits[$traitKey] = true;
            $traitDef = $this->getClass($traitName);
            if ($traitDef->trait === null) {
                continue;
            }
            foreach ($traitDef->methods as $method) {
                if (!($method->flags & Modifiers::ABSTRACT)) {
                    $methods[strtolower($method->name)] = true;
                }
            }
            foreach ($owner->traitAliases as $aliases) {
                foreach ($aliases as $alias) {
                    $methods[strtolower($alias['newName'])] = true;
                }
            }
            $this->collectComposedTraitMethodNames($traitDef, $methods, $visitedTraits);
        }
    }

    private function assertKeywordMethodMayBeDeclared(
        Node\Stmt\ClassMethod $method,
        string $name,
        bool $nativeClass,
    ): void {
        $normalized = strtolower($name);
        if ($normalized !== 'toany' && $normalized !== 'toref') {
            return;
        }
        if ($nativeClass && $normalized === 'toany') {
            return;
        }

        $this->fatalError(
            $method,
            "Method name `{$name}()` is reserved for a TypePHP keyword method and cannot be declared here",
        );
    }

    /**
     * Recursively check whether any subclass (and its subclasses) has defined a method with the same name; handles the case where a subclass is preprocessed before its parent.
     */
    private function isMethodOverriddenInSubClasses(string $classNameLower, string $method): bool
    {
        if (!isset($this->classSubClasses[$classNameLower])) {
            return false;
        }
        $stack = $this->classSubClasses[$classNameLower];
        while (!empty($stack)) {
            $subClass = array_shift($stack);
            $subMethodLower = $subClass . '::' . strtolower($method);
            if (isset($this->classMethodOverride[$subMethodLower])) {
                return true;
            }
            if (isset($this->classSubClasses[$subClass])) {
                foreach ($this->classSubClasses[$subClass] as $grandChild) {
                    $stack[] = $grandChild;
                }
            }
        }
        return false;
    }

    protected function parseInterface(Node\Stmt\Interface_ $v): void
    {
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();
        $name = $this->parseIdentifier($v->name);
        $this->interface = $name;
        $this->interfaceDef = new InterfaceDef($name, $this->namespace);
        $interfaceName = $this->interfaceDef->getNamespacedName(false);
        $interfaceNameLower = strtolower($interfaceName);

        $extendedInterfaces = [];
        foreach ($v->extends as $parent) {
            $parentName = $this->getNamespacedClassName($this->parseIdentifier($parent));
            // An interface may only extend interfaces. The parent's kind is
            // only known once its declaration has been prepared; a parent
            // declared later is validated by the Translator instead.
            if ($this->hasClass($parentName) || $this->isInternalClass($parentName)) {
                $this->fatalError($parent, "`{$interfaceName}` cannot implement `{$parentName}` - it is not an interface");
            }
            $parentNameLower = strtolower($parentName);
            if (isset($extendedInterfaces[$parentNameLower])) {
                $this->fatalError(
                    $parent,
                    "Interface `{$interfaceName}` cannot implement previously implemented interface `{$parentName}`",
                );
            }
            $extendedInterfaces[$parentNameLower] = true;
            $this->interfaceDef->extendsList[] = $parentName;
            if ($this->interfaceDef->extends === '') {
                $this->interfaceDef->extends = $parentName;
            }
            if (!$this->isInternalInterface($parentName)) {
                $this->symbolCallInFile[$this->file][] = strtolower($parentName);
            }
        }

        if (isset($this->symbolDeclInFile[$interfaceNameLower])) {
            $this->fatalError($v, "Duplicate interface `{$interfaceName}`");
        }

        $this->symbolDeclInFile[$interfaceNameLower] = $this->file;
        $this->symbols->putInterface($this->escapeClass($interfaceName), $this->interfaceDef);
        $this->interfacesDefineInFile[$interfaceName] = $this->interfaceDef;

        foreach ($v->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constName = $this->parseIdentifier($const->name);
                    if ($stmt->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) {
                        $this->fatalError(
                            $stmt,
                            "Access type for interface constant `{$interfaceName}::{$constName}` must be public",
                        );
                    }
                    if ($stmt->type) {
                        [$type, $class] = $this->resolveTypeDecl($stmt->type, self::DECL_TYPE_OF_CONST);
                        if ($this->typeDeclContainsCallable($stmt->type)) {
                            $this->fatalError(
                                $stmt,
                                "Class constant `{$interfaceName}::{$constName}` cannot have type `{$this->typeCheckNodeToString($stmt->type)}`",
                            );
                        }
                    } else {
                        $class = '';
                        $type = match ($const->value->getType()) {
                            'Expr_Array' => Type::ARRAY,
                            'Scalar_String' => Type::STR,
                            default => Type::VAR,
                        };
                    }
                    if ($this->interfaceDef->hasConstant($constName)) {
                        $this->fatalError($stmt, "Duplicate constant `{$constName}`");
                    }
                    $constInfo = $this->parseClassLikeConstant($const, $this->parseModifiers($stmt->flags), $type, $class, $stmt->type ? $type : null);
                    $this->interfaceDef->constants[$constName] = $constInfo;
                }
                continue;
            }

            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methodName = $this->getMethodName($stmt);
                $this->assertKeywordMethodMayBeDeclared($stmt, $methodName, false);
                // Interface methods are implicitly public and abstract; Zend
                // rejects the modifiers below in this exact precedence order.
                if ($stmt->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) {
                    $this->fatalError($stmt, "Access type for interface method `{$interfaceName}::{$methodName}()` must be public");
                }
                if ($stmt->flags & Modifiers::ABSTRACT) {
                    $this->fatalError($stmt, "Interface method `{$interfaceName}::{$methodName}()` must not be abstract");
                }
                if ($stmt->flags & Modifiers::FINAL) {
                    $this->fatalError($stmt, "Interface method `{$interfaceName}::{$methodName}()` must not be final");
                }
                if ($stmt->stmts !== null) {
                    $this->fatalError($stmt, "Interface function `{$interfaceName}::{$methodName}()` cannot contain body");
                }
                if ($this->interfaceDef->hasMethod($methodName)) {
                    $this->fatalError($stmt, "Duplicate method `{$methodName}`");
                }
                $this->method = $methodName;
                $methodDef = new MethodDef($this->parseModifiers($stmt->flags), $methodName);
                $methodDef->node = $stmt;
                $methodDef->functionDef = $this->parseFunctionDecl($stmt);
                $methodDef->functionDef->method = true;
                if (strtolower($methodName) === 'toarray') {
                    $this->assertKeywordConversionMethodSignature(
                        $stmt,
                        $this->interfaceDef->getNamespacedName(false),
                        $methodName,
                        $methodDef->functionDef,
                        Type::ARRAY,
                        false,
                    );
                }
                $this->interfaceDef->addMethod($methodDef);
                $this->resetMethod();
                $this->resetFunction();
                continue;
            }

            if ($stmt instanceof Node\Stmt\Property) {
                $this->prepareInterfaceProperty($stmt);
                continue;
            }

            if (!$stmt instanceof Node\Stmt\Nop) {
                $this->fatalError($stmt, 'Unsupported interface statement: ' . $stmt->getType());
            }
        }

        $this->resetMethod();
        $this->resetFunction();
        $this->interface = '';
        $this->interfaceDef = null;
    }

    private function prepareInterfaceProperty(Node\Stmt\Property $property): void
    {
        if ($property->hooks === []) {
            $this->fatalError($property, 'Interfaces may only include hooked properties');
        }
        if ($property->flags & Modifiers::ABSTRACT) {
            $this->fatalError(
                $property,
                'Property in interface cannot be explicitly abstract. All interface members are implicitly abstract',
            );
        }
        if ($property->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) {
            $this->fatalError($property, 'Property in interface cannot be protected or private');
        }
        if ($property->flags & Modifiers::FINAL) {
            $this->fatalError($property, 'Property in interface cannot be final');
        }
        if ($property->flags & Modifiers::STATIC) {
            $this->fatalError($property, 'Cannot declare hooks for static property');
        }
        if ($property->flags & Modifiers::READONLY) {
            $this->fatalError($property, 'Hooked properties cannot be readonly');
        }

        $readable = false;
        $writable = false;
        foreach ($property->hooks as $hook) {
            if ($hook->body !== null) {
                $this->fatalError($hook, 'Abstract property hook cannot have body');
            }
            $kind = strtolower($hook->name->toString());
            if ($kind === 'get') {
                if ($hook->byRef) {
                    $this->fatalError($hook, 'Property get hooks returning by reference are not supported');
                }
                if ($readable) {
                    $this->fatalError($hook, 'Cannot redeclare property hook "get"');
                }
                $readable = true;
            } elseif ($kind === 'set') {
                if ($writable) {
                    $this->fatalError($hook, 'Cannot redeclare property hook "set"');
                }
                if ($hook->params !== []) {
                    $this->fatalError(
                        $hook,
                        'Explicit setter parameters in interface property hooks are not supported yet',
                    );
                }
                $writable = true;
            } else {
                $this->fatalError($hook, "Unknown hook `{$kind}`, expected `get` or `set`");
            }
        }

        [$type, $class] = $this->resolveTypeDecl($property->type, self::DECL_TYPE_OF_PROPERTY);
        $nullable = $property->type instanceof NullableType;
        foreach ($property->props as $prop) {
            $name = $this->parseIdentifier($prop->name);
            if ($property->type !== null && $this->typeDeclContainsCallable($property->type)) {
                $this->fatalError(
                    $property,
                    "Property `{$this->interfaceDef->getNamespacedName(false)}::\${$name}` cannot have type `{$this->typeCheckNodeToString($property->type)}`",
                );
            }
            if ($property->getAttribute(FunctionAttributeLowering::OVERRIDE_ATTRIBUTE, false)) {
                $this->fatalCompileTimeAttribute(
                    $property,
                    'Override',
                    "{$this->interfaceDef->getNamespacedName(false)}::\${$name} has #[\\Override] attribute, "
                        . 'but no matching parent class property exists',
                );
            }
            if ($this->interfaceDef->hasProperty($name)) {
                $this->fatalError($property, "Duplicate property `{$name}`");
            }
            if ($prop->default !== null) {
                $this->fatalError($property, "Cannot specify default value for virtual hooked property {$this->interfaceDef->getNamespacedName(false)}::\${$name}");
            }

            $definition = new InterfacePropertyDef(
                $name,
                $this->parseModifiers($property->flags),
                $type,
                $nullable,
                $readable,
                $writable,
                $property,
            );
            $definition->class = $class;
            if ($property->type instanceof NullableType
                || $property->type instanceof UnionType
                || $property->type instanceof IntersectionType
            ) {
                $typeInfo = $this->buildTypeCheckFromNode($property->type);
                $definition->typeCheck = $typeInfo['check'];
                $definition->typeStr = $typeInfo['typeStr'];
            }
            $this->interfaceDef->properties[$name] = $definition;
        }
    }

    protected function parseTraitUseOptions(Node\Stmt\TraitUse $traitUse, array &$aliases, array &$ignored): void
    {
        foreach ($traitUse->adaptations as $adaptation) {
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                $traits = [];
                if (!$adaptation->trait) {
                    // use THello1, THello2 {
                    //    hello as hello3;
                    // }
                    // No trait specified: add alias mappings for all traits, since the trait's method list is unavailable during preprocessing
                    $traits = $traitUse->traits;
                } else {
                    $traits[] = $adaptation->trait;
                }
                foreach ($traits as $trait) {
                    $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait));
                    $methodName = $adaptation->method->toString();
                    /*
                     * For example:
                     * use TraitA { TraitA::method as newMethod}
                     * This means TraitA::method() is renamed to TraitA::newMethod()
                     */
                    $aliases[$this->getFullMethodName($traitName, $methodName)][] = [
                        'newName' => $adaptation->newName ? $adaptation->newName->toString() : $methodName,
                        'newModifier' => $adaptation->newModifier ?: 0,
                    ];
                }
            }
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                if (!$adaptation->trait) {
                    $this->fatalError($traitUse, 'Trait precedence cannot be used without a trait');
                }
                $methodName = $adaptation->method->toString();
                /*
                 * For example:
                 * use TraitA { TraitA::method insteadof TraitB}
                 * This means TraitB::method() is ignored, and TraitA::method() is actually executed
                 */
                foreach ($adaptation->insteadof as $trait2) {
                    $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait2));
                    $ignored[$this->getFullMethodName($traitName, $methodName)] = true;
                }
            }
        }
    }

    protected function prepareTraitUse(Node\Stmt\TraitUse $v): void
    {
        $aliases = [];
        $ignored = [];
        if ($v->adaptations) {
            $this->parseTraitUseOptions($v, $aliases, $ignored);
        }
        foreach ($v->traits as $trait) {
            $traitName = $this->getNamespacedClassName($this->parseIdentifier($trait));
            $this->classDef->usedTraits[] = $traitName;
            if (!$this->isInternalClass($traitName)) {
                $this->symbolCallInFile[$this->file][] = strtolower($traitName);
            }
        }
        foreach ($aliases as $fullMethodName => $aliasList) {
            foreach ($aliasList as $alias) {
                $this->classDef->traitAliases[$fullMethodName][] = $alias;
            }
        }
        $this->classDef->traitIgnored = array_merge($this->classDef->traitIgnored, $ignored);
    }
}
