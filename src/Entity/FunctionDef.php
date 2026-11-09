<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

use TypePhp\Entity\ArgInfo;
use PhpParser\NodeAbstract;

class FunctionDef
{
    public string $name;
    public string $returnType;

    /**
     * @var array<ArgInfo>
     */
    public array $argInfoList = [];
    public int $argCountRequired = 0;
    public string $params = '';
    public string $namespace;
    public bool $method = false;
    /** Abstract method contract; it has metadata/default helpers but no C++ body symbol. */
    public bool $abstractMethod = false;
    /** Fully-qualified declaring class for methods; empty for free functions. */
    public string $declaringClass = '';
    public bool $stub = false;
    /** Whether this function is part of the public ABI of a library build. */
    public bool $exported = true;
    /** Hidden request-time factory used to materialize a runtime attribute value. */
    public bool $attributeFactory = false;
    /** Original lexical class scope of an attribute factory, if any. */
    public string $attributeFactoryScope = '';
    /** External library imported by the stub containing this function. */
    public string $importLibrary = '';
    public bool $returnTypeUndeclared = false;
    public bool $returnsByRef = false;
    public bool $generator = false;
    /**
     * eval() and include/require run in the caller's variable scope, so they
     * need the live `$this` from the Zend object frame. Such methods must keep
     * dynamic dispatch; a direct C++ call has no object frame.
     */
    public bool $usesObjectContext = false;
    /** The call result must not be discarded as a statement expression. */
    public bool $mustUse = false;
    /** This instance method may not mutate its receiver. */
    public bool $immutable = false;
    /** The method must override an inherited class or interface method. */
    public bool $overrideRequired = false;
    /** Prefer optimizing this function for frequently executed paths. */
    public bool $hot = false;
    /** Prefer optimizing this function for rarely executed paths. */
    public bool $cold = false;
    /** Whether this function is part of the public WIT component interface. */
    public bool $wasmExport = false;
    /** Explicit WIT export name, or an empty string when it is derived from the PHP name. */
    public string $wasmExportName = '';
    /** Number of fixed positional values returned through the internal tuple fast path. */
    public int $multiReturnCount = 0;
    /** Source file containing this function definition. */
    public string $sourceFile = '';
    /** First source line of this function definition. */
    public int $startLine = 1;
    /** PHP-level function or Class::method name used in diagnostics. */
    public string $displayName = '';

    /**
     * @var string Must be a fully qualified class name including the namespace
     */
    public string $returnClass = '';
    /** Whether a Native object return may be represented by nullptr. */
    public bool $returnNullable = false;

    /**
     * Late-bound return type keyword: 'self', 'static' or 'parent'.
     * Empty for ordinary class-name return types. When set, the effective class
     * depends on the consuming context (e.g. a trait method's `self` resolves to
     * the class that uses the trait), so it must be re-resolved when the method
     * is flattened into a class.
     */
    public string $returnTypeKeyword = '';

    /** Same format as ArgInfo::$typeCheck. Null means no runtime return type check. */
    public ?array $returnTypeCheck = null;

    /** Human-readable return type string for error messages. */
    public string $returnTypeStr = '';

    /** Original union/nullable return type AST node. */
    public ?NodeAbstract $returnTypeNode = null;

    /**
     * Source-level return type declared on a generator method, preserved after
     * `prepareGeneratorFunction()` neutralizes the runtime return type. A
     * generator actually returns a `\FiberGenerator` (which implements
     * `Iterator`), so the C++ return type and runtime type check are left
     * neutral; this copy is only used by interface/abstract return-type
     * covariance checks so a generator method can still satisfy a contract such
     * as `: \Generator`.
     */
    public ?string $declaredReturnType = null;
    public string $declaredReturnClass = '';
    public ?array $declaredReturnTypeCheck = null;
    public string $declaredReturnTypeStr = '';

    public function __construct(string $name, string $returnType, string $namespace)
    {
        $this->name = $name;
        $this->returnType = $returnType;
        $this->namespace = $namespace;
    }

    public function getNamespacedName(): string
    {
        return $this->namespace ? $this->namespace . '\\' . $this->name : $this->name;
    }

    public function hasVariadicArg(): bool
    {
        return $this->argInfoList && $this->argInfoList[count($this->argInfoList) - 1]->variadic;
    }

    public function hasMultiReturn(): bool
    {
        return $this->multiReturnCount > 0;
    }

    public function getMultiReturnCppType(): string
    {
        return 'std::tuple<' . implode(', ', array_fill(0, $this->multiReturnCount, 'php::Var')) . '>';
    }
}
