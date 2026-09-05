<?php

/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

use TypePhp\Build\PhpxLocator;

use League\CLImate\CLImate;
use TypePhp\Backend\CompilerBackend;
use TypePhp\Backend\CompilerFactory;
use TypePhp\Build\NativeBuildConfigurationTrait;
use TypePhp\Entity\ArgInfo;
use TypePhp\Context\FunctionContext;
use TypePhp\Context\CompilationStateTrait;
use TypePhp\Diagnostics\CompilerDiagnosticTrait;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Diagnostics\CliDiagnosticReporter;
use TypePhp\Diagnostics\DiagnosticReporter;
use TypePhp\Diagnostics\ThrowingDiagnosticReporter;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Exception\DynamicCall;
use TypePhp\Exception\Redo;
use TypePhp\Exception\Unsupported;
use TypePhp\Generator\AnonClassGenerator;
use TypePhp\Generator\CallArgumentGenerator;
use TypePhp\Generator\ClosureGenerator;
use TypePhp\Generator\FiberGenerator;
use TypePhp\Generator\PlaceHolderGenerator;
use TypePhp\Generator\ParameterCountCheckGenerator;
use TypePhp\Generator\PropertyPromotion;
use TypePhp\Generator\Symbol;
use TypePhp\Generator\Utils;
use TypePhp\Generator\TypeCheckGenerator;
use TypePhp\Optimizer\SsaPropOptimizer;
use TypePhp\Optimizer\SsaTypeOptimizer;
use TypePhp\Optimizer\LoopVarOptimizer;
use TypePhp\Parser\StdContainerTrait;
use TypePhp\Parser\AssignOpTrait;
use TypePhp\Parser\ArrayExpressionTrait;
use TypePhp\Parser\AstNodeType;
use TypePhp\Parser\BinaryOpTrait;
use TypePhp\Parser\ClassConstantFetchTrait;
use TypePhp\Parser\ConditionalControlTrait;
use TypePhp\Parser\ConstantExpressionTrait;
use TypePhp\Parser\ExceptionControlFlowTrait;
use TypePhp\Parser\ForeachTrait;
use TypePhp\Parser\FunctionCallTrait;
use TypePhp\Parser\LoopControlTrait;
use TypePhp\Parser\MethodCallTrait;
use TypePhp\Parser\NullsafeAccessTrait;
use TypePhp\Parser\PropertyAccessTrait;
use TypePhp\Parser\SelectionExpressionTrait;
use TypePhp\Parser\SwitchTrait;
use TypePhp\Parser\TypeConversionTrait;
use TypePhp\Parser\TypeDetectionTrait;
use TypePhp\Parser\UnaryExpressionTrait;
use TypePhp\Parser\UniversalMethodCall;
use TypePhp\Optimizer\FuncCallOptimizer;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Macos;
use TypePhp\Platform\PlatformBase;
use TypePhp\Platform\PlatformFactory;
use TypePhp\Platform\Windows;
use TypePhp\Python\PythonModuleTrait;
use TypePhp\Resolver\DeclarationSymbolTrait;
use TypePhp\Resolver\MagicMethodDetector;
use TypePhp\Resolver\PropertyAccessContext;
use TypePhp\Resolver\NativePropertyAccess;
use TypePhp\Resolver\NameResolutionTrait;
use TypePhp\Resolver\PropertyAccessResult;
use TypePhp\Resolver\PropertyAccessResolver;
use TypePhp\Resolver\Reflection;
use TypePhp\Symbol\SymbolRepository;
use TypePhp\TypeSystem\CompositeTypeCheckerTrait;
use TypePhp\TypeSystem\CompoundTypeDeclarationValidationTrait;
use TypePhp\TypeSystem\NativeTypeCompatibilityTrait;
use TypePhp\NativeClass\NativeClassSupportTrait;
use TypePhp\NativeClass\NativeGlobalTypeResolver;
use TypePhp\Immutable\ImmutableSupportTrait;
use TypePhp\ArrayDef\ArrayDefSupportTrait;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\NodeAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;

class CompilerBase implements PropertyAccessContext
{
    use CompositeTypeCheckerTrait;
    use CompoundTypeDeclarationValidationTrait;
    use CompilerDiagnosticTrait;
    use CompilationStateTrait;
    use NativeTypeCompatibilityTrait;
    use NativeClassSupportTrait;
    use ImmutableSupportTrait;
    use ArrayDefSupportTrait;
    use NativeBuildConfigurationTrait;
    use PythonModuleTrait;
    use DeclarationSymbolTrait;
    use NameResolutionTrait;
    use AstNodeType;
    use FuncCallOptimizer;
    use AnonClassGenerator;
    use CallArgumentGenerator;
    use ClosureGenerator;
    use FiberGenerator;
    use PlaceHolderGenerator;
    use ParameterCountCheckGenerator;
    use PropertyPromotion;
    use MagicMethodDetector;
    use StdContainerTrait;
    use BinaryOpTrait;
    use ClassConstantFetchTrait;
    use ConditionalControlTrait;
    use ConstantExpressionTrait;
    use ExceptionControlFlowTrait;
    use ForeachTrait;
    use FunctionCallTrait;
    use LoopControlTrait;
    use MethodCallTrait;
    use NullsafeAccessTrait;
    use PropertyAccessTrait;
    use SelectionExpressionTrait;
    use SwitchTrait;
    use TypeConversionTrait;
    use TypeDetectionTrait;
    use UnaryExpressionTrait;
    use AssignOpTrait;
    use ArrayExpressionTrait;
    use UniversalMethodCall;
    use Utils;
    use TypeCheckGenerator;
    use SsaTypeOptimizer;
    use LoopVarOptimizer;
    use SsaPropOptimizer;

    public const string DEFAULT_PHP_VERSION = '8.5';
    protected const string NATIVE_PROPERTY_VALUE_VAR = 'var';
    protected const string NATIVE_PROPERTY_VALUE_DYNAMIC = 'dynamic';
    protected const int COMPOSITE_TYPE_MISMATCH = -1;
    protected const int COMPOSITE_TYPE_UNKNOWN = 0;
    protected const int COMPOSITE_TYPE_MATCH = 1;
    protected const string ATTR_ARRAY_DIM_FETCH_UPDATE = 'aotArrayDimFetchUpdate';
    protected const string ATTR_PROPERTY_FETCH_UPDATE = 'aotPropertyFetchUpdate';
    protected const string ATTR_STATEMENT_EXPRESSION = 'aotStatementExpression';
    protected const string ATTR_MULTI_RETURN_IMPL = 'aotMultiReturnImpl';
    protected const string ATTR_SCOPED_CALLBACK = 'aotScopedCallback';
    protected const string ATTR_FORCE_FLOAT_LITERAL = 'aotForceFloatLiteral';

    /**
     * Keyword methods (to* builtins) with mandated return types.
     * Use findKeywordMethod() for unified lookup including keyword extension methods.
     */
    public const array KEYWORD_METHOD_MAP = [
        'toInt'        => Type::INT,
        'toFloat'      => Type::FLOAT,
        'toString'     => Type::STR,
        'toBool'       => Type::BOOL,
        'toArray'      => Type::ARRAY,
        'toStream'     => Type::STREAM,
        'toBigInt'     => Type::BIGINT,
        'toBigFloat'   => Type::BIGFLOAT,
        'toDecimal'    => Type::DECIMAL,
        'toObject'     => Type::OBJECT,
        'toAny'        => Type::VAR,
        'toRef'        => Type::REF,
    ];

    /** Keyword methods not listed here accept no arguments. */
    public const array KEYWORD_METHOD_WITH_ARGUMENTS = [
        'toObject' => true,
    ];

    private const array STREAM_FUNCTIONS = [
        'fopen',
        'tmpfile',
        'fsockopen',
        'stream_socket_client',
        'stream_socket_accept',
        'popen',
    ];

    /**
     * APIs which cannot have the same semantics in Wasmtime and a browser.
     * Keep this list at the language boundary so a WASI build never degrades
     * into a link error or a browser-only implementation.
     */
    private const array WASI_UNSUPPORTED_FUNCTIONS = [
        'exec',
        'passthru',
        'popen',
        'proc_close',
        'proc_get_status',
        'proc_nice',
        'proc_open',
        'proc_terminate',
        'shell_exec',
        'system',
        'fsockopen',
        'pfsockopen',
        'stream_socket_accept',
        'stream_socket_client',
        'stream_socket_enable_crypto',
        'stream_socket_get_name',
        'stream_socket_pair',
        'stream_socket_recvfrom',
        'stream_socket_sendto',
        'stream_socket_server',
        'stream_socket_shutdown',
    ];

    private const array WASI_UNSUPPORTED_FUNCTION_PREFIXES = [
        'pcntl_',
        'posix_',
        'socket_',
    ];
    public const int DECL_TYPE_OF_RETURN = 1;
    public const int DECL_TYPE_OF_PROPERTY = 2;
    public const int DECL_TYPE_OF_CONST = 3;
    public const int DECL_TYPE_OF_PARAM = 4;

    public const string VALUE_NAN = 'std::numeric_limits<double>::quiet_NaN()';
    public const string VALUE_INF = 'std::numeric_limits<double>::infinity()';
    public const string VALUE_NULL = 'php::null';
    public const string VALUE_ZERO = 'php::zero';
    public const string VALUE_FALSE = 'php::false_';
    public const string VALUE_TRUE = 'php::true_';

    protected function getBoolValue(Expr\ConstFetch $expr): string
    {
        return strcasecmp($expr->name->toString(), 'true') === 0 ? self::VALUE_TRUE : self::VALUE_FALSE;
    }
    public const string LITERAL_STRINGS = '_literal_strings';
    public const string LITERAL_STRING_GETTER = 'get_str';
    public const string ANON_CLASS = '_anon_class_';
    public const string DYNAMIC_CALLED_CLASS = '__dynamic_called_class__';
    public const string STATIC_VAR = '_static_var_';
    public const string GLOBAL_VAR = '_global_var_';
    public const string CONST_VAR = '_const_var_';
    public const string OBJECT_PROP = '_object_prop_';
    public const string CLASS_MAP = 'class_map';
    public const string FUNC_MAP = 'func_map';
    public const string PERSISTENT_CLASS_MAP = 'persistent_class_map';
    public const string PERSISTENT_FUNC_MAP = 'persistent_func_map';
    public const string PERSISTENT_PROP_MAP = 'persistent_property_map';
    public const string NAMESPACE_SEPARATOR = '__';

    public const string PREFIX = 'php_';
    protected const string MULTI_RETURN_NAMESPACE = 'typephp::detail';
    public const string OP_ISSET = 'isset';
    public const string OP_EMPTY = 'empty';
    public const string OP_NOT_EMPTY = 'notEmpty';
    public const string OP_REFVAL = 'toReference';
    public const string OP_NOP = "if (0) {}\n";
    public const string BUILD_MODE_BIN = 'bin';
    public const string BUILD_MODE_EXT = 'ext';
    public const string BUILD_MODE_LIB = 'lib';
    public const string ENTRY_FUNCTION = 'main';
    protected const string PHASE_IDLE = 'idle';
    protected const string PHASE_PREPARE = 'prepare';
    protected const string PHASE_COMPOSE = 'compose';
    protected const string PHASE_CONVERT = 'convert';

    protected string $lang = 'PHP';
    protected bool $verbose = false;
    protected int $indentLevel = 0;
    protected string $indentStr = "\t";
    public string $mode = 'cli';
    protected string $osType = 'linux';
    protected string $compilerPhase = self::PHASE_IDLE;
    protected string $cppCompiler = '';
    protected array $literalStrings = [];
    protected int $literalStringIndex = 0;
    protected int $anonClassIndex = 0;
    protected int $classIndex = 0;

    /**
     * User-defined (request-lifetime) class name → ID. Backed by a THREAD_LOCAL
     * cache at runtime and cleared on RSHUTDOWN.
     * @var array<string, int>
     */
    protected array $classMap = [];
    /**
     * Built-in / compiled-output (module-lifetime) class name → ID. Lazily
     * populated after PHP startup and NOT cleared on RSHUTDOWN.
     * @var array<string, int>
     */
    protected array $persistentClassMap = [];
    protected int $persistentClassIndex = 0;
    /**
     * @var array<string, int>
     */
    protected array $stdTypeMap = [];
    protected int $funcIndex = 0;

    /**
     * User-defined (request-lifetime) function/method → ID. Backed by a
     * THREAD_LOCAL cache at runtime and cleared on RSHUTDOWN.
     * Key is a function name or `Class::method`.
     * @var array<string, int>
     */
    protected array $funcMap = [];
    /**
     * Built-in / compiled-output (module-lifetime) function/method → ID. Lazily
     * populated after PHP startup and NOT cleared on RSHUTDOWN.
     * Key is a function name or `Class::method`.
     * @var array<string, int>
     */
    protected array $persistentFuncMap = [];
    protected int $persistentFuncIndex = 0;
    /**
     * Declared-property offset cache for built-in / compiled-output classes.
     * Key is `Class::prop`; lazily populated and NOT cleared on RSHUTDOWN.
     * Property resolution only covers declared properties of compiled classes
     * and built-in classes (process-stable). User-class properties go through
     * the string path and never enter this cache.
     */
    protected array $persistentPropMap = [];
    protected int $persistentPropIndex = 0;
    /**
     * Per-generated-access-site Zend object-handler cache slots. Unlike the
     * declared-property offset cache above, these are request-local and may
     * cache a runtime class together with a dynamic/hooked-property sentinel.
     */
    protected int $propertyAccessCacheIndex = 0;
    protected int $methodCallCacheIndex = 0;
    protected int $functionCallCacheIndex = 0;
    /** @var array<string, array<Node\Stmt>> Prepared declaration ASTs keyed by real path. */
    protected array $preparedFileAsts = [];
    protected bool $traitDeclarationsComposed = false;
    protected bool $declarationExpressionsFinalized = false;
    protected bool $methodOverrideFlagsFinalized = false;
    protected const array PHP_RUNTIME_TYPE_MAP = [
        'integer' => Type::INT,
        'double' => Type::FLOAT,
        'boolean' => Type::BOOL,
    ];
    protected array $zendTypeMap = [
        'int' => Type::INT,
        'float' => Type::FLOAT,
        'bool' => Type::BOOL,
        'false' => Type::BOOL,
        'true' => Type::BOOL,
        'void' => Type::VOID,
        'never' => Type::VOID,
        'string' => Type::STR,
        'array' => Type::ARRAY,
        'object' => Type::OBJECT,
        'mixed' => Type::VAR,
        'null' => Type::VAR,
        'any' => Type::VAR,
        // The callable type can be a string, array, or object:
        // 1) 'foo' function-name string, 2) [ $obj, 'bar' ] object-method array,
        // 3) a Closure object, 4) [ 'class', 'staticMethod' ] class + static-method array.
        'callable' => Type::VAR,
        // The iterable type can be an array or an object.
        'iterable' => Type::VAR,
        'stream' => Type::STREAM,
        'bigint' => Type::BIGINT,
        'bigfloat' => Type::BIGFLOAT,
        'decimal' => Type::DECIMAL,
        'box' => Type::BOX,
    ];
    protected array $localHeaders = [];
    protected array $internalFunctions = [];
    protected array $internalConstants = [];

    /**
     * Stores the declaration of every function and class method. Key is the
     * symbol name; value is the file in which the function or method is declared.
     * @var array<string, string>
     */
    protected array $symbolDeclInFile = [];

    /**
     * Stores every function / class-method call. Key is the file name; value is
     * a list of the functions / class methods called within that file.
     * @var array<string, array<string>>
     */
    protected array $symbolCallInFile = [];
    protected array $redoAfterDeclare = [];
    protected array $constData = [];
    protected int $optimizeLevel = 0;
    protected int $maxJob = 4;
    protected string $buildMode = self::BUILD_MODE_BIN;
    protected string $cxxFlags = '';
    protected string $cxxStd = 'c++17';
    protected string $march = '';            // --march: target CPU instruction set (e.g. native, x86-64-v3)
    protected string $targetPlatform = '';   // --target-platform: cross-compilation target triple (e.g. aarch64-linux-gnu)
    protected string $ldflags = '';
    protected array $linkLibs = [];    // --link-lib / -l: user-specified libraries to link
    protected array $linkPaths = [];   // --link-path / -L: user-specified library search paths
    /** @var list<string> Required PHP modules recorded in zend_module_entry.deps. */
    protected array $extensionDependencies = [];
    protected bool $debug = false;
    protected bool $formatCode = false;   // --format: enable clang-format (disabled by default)
    protected bool $printBacktraceOnError = true;
    protected bool $noLiteralStrings = false;
    protected bool $noConsole = false;  // Windows: hide console window
    protected string $sanitize = '';    // Sanitizer type (address, undefined, etc.)
    protected bool $dryRun = false;     // Dry run: only generate C++ code, skip compile & link
    protected array $userIncludePaths = [];  // --include-path / -I: user-provided C++ include dirs
    protected array $userDefines = [];       // --define / -D: user-provided preprocessor macros
    protected bool $enableLto = false;       // --lto: enable Link Time Optimization (-flto)
    protected bool $fullStatic = false;      // --full-static: link against the bundled fully-static SDK
    protected string $file;
    protected string $dir;

    /**
     * The raw namespace value, which may contain `\\` multi-level separators.
     */
    protected string $namespace = '';
    protected string $method = '';
    protected string $function = '';
    protected array $useNamespaces = [];
    protected array $useAliases = [];
    protected array $useFunctions = [];
    protected array $useConstants = [];
    /** @var array<int, array<string, string>> Import aliases separated by class/function/constant domain. */
    protected array $useImportAliases = [];

    /**
     * The raw class name, without the namespace.
     */
    protected string $class = '';
    protected string $parentClass = '';
    protected string $interface = '';
    /**
     * @var array<string, ConstantDef>
     */
    protected array $constants = [];
    /**
     * @var array<string, ClassDef>
     */
    protected array $classesDefineInFile = [];
    /**
     * @var array<string, InterfaceDef>
     */
    protected array $interfacesDefineInFile = [];
    /**
     * @var array<string, FunctionDef>
     */
    protected array $functionDefineInFile = [];

    protected ?FunctionDef $functionDef = null;
    protected ?ClassDef $classDef = null;
    protected ?MethodDef $methodDef = null;
    protected ?InterfaceDef $interfaceDef = null;
    protected bool $inGeneratorBody = false;
    /** Parameter-default helpers have no ordinary function-entry declaration block. */
    protected bool $allowLocalClassEntryHoisting = true;
    private ?DiagnosticReporter $diagnosticReporter = null;
    protected FunctionContext $context;
    protected array $superGlobalVars = [
        '_GET'     => Type::ARRAY,
        '_POST'    => Type::ARRAY,
        '_COOKIE'  => Type::ARRAY,
        '_SERVER'  => Type::ARRAY,
        '_FILES'   => Type::ARRAY,
        '_SESSION' => Type::ARRAY,
        '_REQUEST' => Type::ARRAY,
        '_ENV'     => Type::ARRAY,
        'GLOBALS'  => Type::ARRAY,
    ];
    protected array $globalVars = [];
    /** @var array<string, string> Global/static Native pointer slot => class name. */
    protected array $nativeGlobalObjects = [];
    /** Immutable metadata shared by Native global pre-discovery and lowering. */
    protected ?NativeGlobalTypeResolver $nativeGlobalTypeResolver = null;
    /** @var array<string, string> Lowercase class name => declared Native class name. */
    protected array $nativeClassDeclarations = [];
    /** @var array<string, true> Request-reset initialization flags for Native static locals. */
    protected array $nativeStaticInitializers = [];
    protected bool $nativeTypes = false;
    protected bool $decimalTypes = false;
    protected bool $bigintTypes = false;
    protected string $rootPath;
    protected string $buildDir;
    protected string $outputDir = '';    // Output directory specified by the -o option
    protected int $debugLine = 0;
    protected CLImate $climate;
    protected bool $stubFile = false;
    protected string $stubImportLibrary = '';

    /** @var array<string, true> */
    protected array $externalImportStubFiles = [];
    protected bool $enableProfiler = false;
    protected bool $noProgress = false;
    protected bool $forTest = false;
    protected Parser $parser;
    protected string $phpVersion = self::DEFAULT_PHP_VERSION;
    protected PrettyPrinter $printer;
    protected bool $isPhpZts = false;  // Whether the PHP build is thread-safe (ZTS)

    // Windows platform: store the detected PHP lib file paths.
    protected string $windowsPhpEmbedLib = '';  // Path to php8embed.lib
    protected string $windowsPhpCoreLib = '';   // Path to php8ts.lib or php8.lib

    // New platform and compiler abstraction layers (optional to use).
    protected ?PlatformBase $platform = null;
    protected ?CompilerBackend $compilerBackend = null;

    /**
     * Records all class method names collected during the preprocessing phase.
     * Used to detect methods with the same name declared in both a child class
     * and its parent class, resolving dynamic method-binding calls such as
     * `static::methodCall()` and `$this->methodCall()` where a parent and a
     * child class both define the method.
     * @var array<string, bool>
     */
    protected array $classMethodOverride = [];

    /**
     * Stores all class inheritance relationships. Class names must be all lowercase.
     * @var array<string, string>
     */
    protected SymbolRepository $symbols;

    /**
     * Reverse class hierarchy: parent class (lowercase) => list of child classes (lowercase)
     * @var array<string, string[]>
     */
    protected array $classSubClasses = [];

    public function __construct(string $rootPath)
    {
        $this->osType = PHP_OS_FAMILY;
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $this->error('PHP 8.4.0 or later is required');
        }
        if (version_compare(PHP_VERSION, '8.6.0', '>=')) {
            $this->error('PHP 8.6.0 or later is not supported');
        }
        $this->rootPath = $rootPath;
        $this->symbols = new SymbolRepository();
        $this->setPhpVersion(self::DEFAULT_PHP_VERSION);
        $this->printer = new PrettyPrinter\Standard();
        $this->setBuildDir($rootPath . '/build');
        $climate = new CLImate();
        $this->climate = $climate;
    }

    public function setDiagnosticReporter(DiagnosticReporter $reporter): void
    {
        $this->diagnosticReporter = $reporter;
    }

    protected function getDiagnosticReporter(): DiagnosticReporter
    {
        if ($this->diagnosticReporter !== null) {
            return $this->diagnosticReporter;
        }
        return $this->forTest
            ? new ThrowingDiagnosticReporter()
            : new CliDiagnosticReporter($this->climate, $this->printBacktraceOnError);
    }

    public function setMode($mode): void
    {
        $this->mode = $mode;
    }

    /** Set the PHP language version accepted by the parser. */
    public function setPhpVersion(string $version): void
    {
        if (!preg_match('/^8\.(4|5)(?:\.0)?$/', $version, $matches)) {
            $this->error('Unsupported PHP language version: `' . $version . '`. Supported versions: 8.4, 8.5');
        }

        $this->phpVersion = '8.' . $matches[1] . '.0';
        // php-parser's emulative lexer permits the compiler runtime to be
        // older than the selected PHP language version.
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString($this->phpVersion));
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function setIndent(string $indent): void
    {
        $this->indentStr = $indent;
    }

    public function setIndentLevel(int $level): void
    {
        $this->indentLevel = $level;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    protected function unsupportedSyntax(Node $node): never
    {
        $message = 'Error: Unsupported ' . $this->getLang() . ' Syntax,';
        $message .= ' Line: ' . $this->getLine($node) . ', Type: ' . $this->getType($node) . PHP_EOL;
        if ($this->mode === 'cli') {
            if (defined('TYPEPHP_DEBUG') && TYPEPHP_DEBUG) {
                var_dump($node);
                debug_print_backtrace();
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode($node, JSON_PRETTY_PRINT);
        }
        throw new Unsupported($message);
    }

    /**
     * Return indentation relative to the current code-generation level.
     * Level 1 is the current level, level 2 is one nested level, and so on.
     */
    protected function getIndent(int $level = 1): string
    {
        return str_repeat($this->indentStr, $this->indentLevel + $level - 1);
    }

    protected function getPhpxDir(): string
    {
        try {
            return PhpxLocator::resolve($this->rootPath);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
        }
    }

    protected function getPlatform(): PlatformBase
    {
        if ($this->platform === null) {
            $this->platform = PlatformFactory::create();
        }

        return $this->platform;
    }

    protected function getCompilerBackend(): CompilerBackend
    {
        if ($this->compilerBackend === null) {
            $this->cppCompiler = CompilerFactory::detectCompilerName($this->getPlatform(), $this->cppCompiler);
            $this->compilerBackend = CompilerFactory::createByName($this->cppCompiler, $this->getPlatform());
        }

        return $this->compilerBackend;
    }

    public function isWindows(): bool
    {
        return $this->getPlatform() instanceof Windows;
    }

    public function isLinux(): bool
    {
        return $this->getPlatform() instanceof Linux;
    }

    public function isMacos(): bool
    {
        return $this->getPlatform() instanceof Macos;
    }

    public function isWasiTarget(): bool
    {
        $target = strtolower($this->targetPlatform);
        return $target === 'wasm32-unknown-wasip2' || $target === 'wasm32-wasip2';
    }

    protected function assertWasiFunctionSupported(NodeAbstract $expr, string $name): void
    {
        if (!$this->isWasiTarget()) {
            return;
        }

        $name = strtolower(ltrim($name, '\\'));
        if (in_array($name, self::WASI_UNSUPPORTED_FUNCTIONS, true)) {
            $this->fatalError($expr, "Function `{$name}` is not supported by the WASI target");
        }
        foreach (self::WASI_UNSUPPORTED_FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $this->fatalError($expr, "Function `{$name}` is not supported by the WASI target");
            }
        }
    }

    public function isBuildModeBin(): bool
    {
        return $this->buildMode === self::BUILD_MODE_BIN;
    }

    public function isBuildModeExt(): bool
    {
        return $this->buildMode === self::BUILD_MODE_EXT;
    }

    public function isBuildModeLib(): bool
    {
        return $this->buildMode === self::BUILD_MODE_LIB;
    }

    public function isBuildModeEmbed(): bool
    {
        return $this->isBuildModeBin() || $this->isBuildModeLib();
    }

    public function getPhpDir(): string
    {
        try {
            return $this->getPlatform()->getPhpDir();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
    }

    public function isScalarInt(Expr $expr): bool
    {
        return $expr instanceof Node\Scalar\LNumber;
    }

    public function getLine($node): int
    {
        return $node->getLine();
    }

    public function getType($node): string
    {
        return $node->getType();
    }

    public function getTypeFromZendType(string $type): string
    {
        return $this->zendTypeMap[$type] ?? self::PHP_RUNTIME_TYPE_MAP[$type] ?? Type::VAR;
    }

    public function getObjectType(string $object): string
    {
        if (isset($this->context->stableObjects[$object])) {
            return $this->context->stableObjects[$object];
        }
        return $this->context->objects[$object] ?? 'stdClass';
    }

    protected function getDeclaredObjectType(string $object): string
    {
        if (isset($this->context->declaredObjects[$object])) {
            return $this->context->declaredObjects[$object];
        }
        if (isset($this->context->objects[$object]) || isset($this->context->stableObjects[$object])) {
            return $this->getObjectType($object);
        }
        return '';
    }

    public function parseExpr(Node $expr): string
    {
        if ($expr->hasAttribute('replace')) {
            return $expr->getAttribute('replace');
        }
        $type = $expr->getType();
        $this->writeLog('Line ' . $this->getLine($expr) . ': ' . $type);
        if ($expr->getLine() === $this->debugLine) {
            dump($expr);
        }
        if ($expr instanceof Node\Expr\BinaryOp) {
            $this->assertNativeObjectBinaryOperatorSupported($expr);
        }
        if ($expr instanceof Expr\Variable) {
            $varName = $this->parseIdentifier($expr);
            $this->requireVar($expr, $varName);
            if ($this->isStdContainer($varName)) {
                return $varName . '_ref';
            }
            // $GLOBALS is an INDIRECT to &EG(symbol_table), whose refcount
            // MUST NOT be directly manipulated. Create a separated copy.
            return $varName === 'GLOBALS' ? 'php::globalsArray()' : $varName;
        }

        return match (true) {
            $expr instanceof Expr\Isset_ => $this->parseIsset($expr),
            $expr instanceof Expr\Empty_ => $this->parseEmpty($expr),
            $expr instanceof Expr\Assign => $this->parseAssign($expr),
            $expr instanceof Expr\AssignRef => $this->parseAssignRef($expr),
            $expr instanceof Expr\Print_ => $this->parsePrint($expr),
            $expr instanceof Expr\BinaryOp\Equal => $this->parseBinaryOpEqual($expr),
            $expr instanceof Expr\BinaryOp\NotEqual => $this->parseBinaryOpNotEqual($expr),
            $expr instanceof Expr\BinaryOp\Identical => $this->parseBinaryOpIdentical($expr),
            $expr instanceof Expr\BinaryOp\NotIdentical => $this->parseBinaryOpNotIdentical($expr),
            $expr instanceof Expr\BooleanNot => $this->parseBooleanNot($expr),
            $expr instanceof Expr\BinaryOp\Plus => $this->parseBinaryOpPlus($expr),
            $expr instanceof Expr\BinaryOp\Div => $this->parseBinaryOpDiv($expr),
            $expr instanceof Expr\BinaryOp\Smaller => $this->parseBinaryOpSmaller($expr),
            $expr instanceof Expr\BinaryOp\SmallerOrEqual => $this->parseBinaryOpSmallerOrEqual($expr),
            $expr instanceof Expr\BinaryOp\GreaterOrEqual => $this->parseBinaryOpGreaterOrEqual($expr),
            $expr instanceof Expr\BinaryOp\Spaceship => $this->parseBinaryOpSpaceship($expr),
            $expr instanceof Expr\BinaryOp\Coalesce => $this->parseBinaryOpCoalesce($expr),
            $expr instanceof Expr\PreInc => $this->parsePreInc($expr),
            $expr instanceof Expr\PostInc => $this->parsePostInc($expr),
            $expr instanceof Expr\PreDec => $this->parsePreDec($expr),
            $expr instanceof Expr\PostDec => $this->parsePostDec($expr),
            $expr instanceof Expr\AssignOp\Plus => $this->parseAssignOpPlus($expr),
            $expr instanceof Expr\AssignOp\Minus => $this->parseAssignOpMinus($expr),
            $expr instanceof Expr\AssignOp\Mul => $this->parseAssignOpMul($expr),
            $expr instanceof Expr\AssignOp\Div => $this->parseAssignOpDiv($expr),
            $expr instanceof Expr\AssignOp\Mod => $this->parseAssignOpMod($expr),
            $expr instanceof Expr\AssignOp\Concat => $this->parseAssignOpConcat($expr),
            $expr instanceof Expr\AssignOp\ShiftLeft => $this->parseAssignOpShiftLeft($expr),
            $expr instanceof Expr\AssignOp\ShiftRight => $this->parseAssignOpShiftRight($expr),
            $expr instanceof Expr\AssignOp\BitwiseAnd => $this->parseAssignOpBitwiseAnd($expr),
            $expr instanceof Expr\AssignOp\BitwiseOr => $this->parseAssignOpBitwiseOr($expr),
            $expr instanceof Expr\AssignOp\BitwiseXor => $this->parseAssignOpBitwiseXor($expr),
            $expr instanceof Expr\AssignOp\Pow => $this->parseAssignOpPow($expr),
            $expr instanceof Expr\AssignOp\Coalesce => $this->parseAssignOpCoalesce($expr),
            $expr instanceof Expr\BinaryOp\Mul => $this->parseBinaryOpMul($expr),
            $expr instanceof Expr\BinaryOp\Concat => $this->parseBinaryOpConcat($expr),
            $expr instanceof Expr\BinaryOp\Greater => $this->parseBinaryOpGreater($expr),
            $expr instanceof Expr\BinaryOp\LogicalAnd,
            $expr instanceof Expr\BinaryOp\BooleanAnd => $this->parseBinaryOpLogicalAnd($expr),
            $expr instanceof Expr\BinaryOp\LogicalOr,
            $expr instanceof Expr\BinaryOp\BooleanOr => $this->parseBinaryOpLogicalOr($expr),
            $expr instanceof Expr\BinaryOp\LogicalXor => $this->parseBinaryOpLogicalXor($expr),
            $expr instanceof Expr\BinaryOp\Minus => $this->parseBinaryOpMinus($expr),
            $expr instanceof Expr\Array_ => $this->parseArray($expr),
            $expr instanceof Expr\ArrayDimFetch => $this->parseArrayDimFetch($expr),
            $expr instanceof Expr\PropertyFetch => $this->parsePropertyFetch($expr),
            $expr instanceof Expr\NullsafePropertyFetch => $this->parseNullsafePropertyFetch($expr),
            $expr instanceof Expr\NullsafeMethodCall => $this->parseNullsafeMethodCall($expr),
            $expr instanceof Expr\BinaryOp\ShiftLeft => $this->parseBinaryOpShiftLeft($expr),
            $expr instanceof Expr\BinaryOp\ShiftRight => $this->parseBinaryOpShiftRight($expr),
            $expr instanceof Expr\BinaryOp\BitwiseAnd => $this->parseBinaryOpBitwiseAnd($expr),
            $expr instanceof Expr\BinaryOp\BitwiseOr => $this->parseBinaryOpBitwiseOr($expr),
            $expr instanceof Expr\BinaryOp\BitwiseXor => $this->parseBinaryOpBitwiseXor($expr),
            $expr instanceof Expr\BinaryOp\Pipe => $this->parsePipeOperator($expr),
            $expr instanceof Expr\BitwiseNot => $this->parseBitwiseNot($expr),
            $expr instanceof Expr\BinaryOp\Mod => $this->parseBinaryOpMod($expr),
            $expr instanceof Expr\BinaryOp\Pow => $this->parseBinaryOpPow($expr),
            $expr instanceof Expr\Ternary => $this->parseTernary($expr),
            $expr instanceof Expr\Match_ => $this->parseMatch($expr),
            $expr instanceof Expr\FuncCall => $this->parseFuncCall($expr),
            $expr instanceof Expr\MethodCall => $this->parseMethodCall($expr),
            $expr instanceof Expr\StaticCall => $this->parseStaticCall($expr),
            $expr instanceof Expr\StaticPropertyFetch => $this->parseStaticPropertyFetch($expr),
            $expr instanceof Expr\ClassConstFetch => $this->parseClassConstFetch($expr),
            $expr instanceof Expr\Include_ => $this->parseInclude($expr),
            $expr instanceof Expr\Eval_ => $this->parseEval($expr),
            $expr instanceof Expr\New_ => $this->parseNew($expr),
            $expr instanceof Expr\Clone_ => $this->parseClone($expr),
            $expr instanceof Expr\Instanceof_ => $this->parseInstanceof($expr),
            $expr instanceof Expr\Throw_ => $this->parseThrow($expr),
            $expr instanceof Expr\ShellExec => $this->parseShellExec($expr),
            $expr instanceof Expr\Closure => $this->parseClosure($expr),
            $expr instanceof Expr\ArrowFunction => $this->parseArrowFunction($expr),
            $expr instanceof Node\Name\FullyQualified => $this->parseFullyQualifiedName($expr),
            $expr instanceof Node\Scalar\Int_,
            $expr instanceof Node\Scalar\Float_,
            $expr instanceof Node\Scalar\String_ => $this->parseIdentifier($expr),
            $expr instanceof Node\Scalar\MagicConst => $this->parseMagicConst($expr),
            $expr instanceof Node\Scalar\InterpolatedString => $this->parseInterpolatedString($expr),
            $expr instanceof Expr\Cast\Int_ => $this->parseCastInt($expr),
            $expr instanceof Expr\Cast\Double => $this->parseCastDouble($expr),
            $expr instanceof Expr\Cast\Bool_ => $this->parseCastBool($expr),
            $expr instanceof Expr\Cast\String_ => $this->parseCastString($expr),
            $expr instanceof Expr\Cast\Array_ => $this->parseCastArray($expr),
            $expr instanceof Expr\Cast\Object_ => $this->parseCastObject($expr),
            $expr instanceof Expr\Cast\Void_ => $this->parseCastVoid($expr),
            $expr instanceof Expr\ConstFetch => $this->parseConstFetch($expr),
            $expr instanceof Expr\UnaryMinus => $this->parseUnaryMinus($expr),
            $expr instanceof Expr\UnaryPlus => $this->parseUnaryPlus($expr),
            $expr instanceof Node\InterpolatedStringPart => $this->parseInterpolatedStringPart($expr),
            $expr instanceof Expr\ErrorSuppress => $this->parseErrorSuppress($expr),
            $expr instanceof Expr\Exit_ => $this->parseExit($expr),
            $expr instanceof Expr\Yield_ => $this->parseYieldExpr($expr),
            $expr instanceof Expr\YieldFrom => $this->parseYieldFromExpr($expr),
            default => $this->unsupportedSyntax($expr),
        };
    }

    public function stop(string $string): never
    {
        $this->climate->red($string . "\n");
        exit(1);
    }

    public function genTmpVarName(): string
    {
        return 'tmp_var_' . $this->context->tmpVarIndex++;
    }

    protected function genExtraNamedVariadicArgs(string $var): string
    {
        return $this->getIndent() . 'php::appendCallExtraNamedArgs(' . $var . ');' . PHP_EOL;
    }

    public function writeFile(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (!file_put_contents($file, $content)) {
            throw new \RuntimeException('Can not write file: ' . $file);
        }
    }

    public function getIncludeDir(): string
    {
        return $this->getBuildDir() . '/include';
    }

    public function getBuildDir(): string
    {
        return $this->buildDir;
    }

    public function getUserIncludePaths(): array
    {
        return $this->userIncludePaths;
    }

    public function getUserDefines(): array
    {
        return $this->userDefines;
    }

    public function isLtoEnabled(): bool
    {
        return $this->enableLto;
    }

    public function getLinkLibs(): array
    {
        return $this->linkLibs;
    }

    public function getLinkPaths(): array
    {
        return $this->linkPaths;
    }

    /** @return list<string> */
    public function getExtensionDependencies(): array
    {
        return $this->extensionDependencies;
    }

    public function getMarch(): string
    {
        return $this->march;
    }

    public function getRelativePath($path, $cwd = ''): string
    {
        $cwd = $cwd ?: getcwd();
        return ltrim($this->removeCommonPrefix($cwd, $path), '/');
    }

    protected function removeCommonPrefix(string $short, string $long): string
    {
        return $this->getPlatform()->removeCommonPrefix($short, $long);
    }

    protected function getVarType(string $name): string
    {
        if ($this->hasLocalVar($name)) {
            return $this->context->localVars[$name];
        }
        if ($this->hasScopeGlobalVar($name)) {
            return $this->context->globalVars[$name];
        }

        return Type::VAR;
    }

    /**
     * Resolve the ClassDef for an object expression (variable or $this).
     */
    private function resolveObjectClassDef(Node\Expr $expr): ?ClassDef
    {
        if ($expr instanceof Expr\Variable) {
            $name = $this->parseIdentifier($expr);
            if ($name === 'this_' && $this->classDef) {
                return $this->classDef;
            }
            if ($this->isTypedObject($name)) {
                $className = $this->getObjectType($name);
                if ($this->hasClass($className)) {
                    return $this->getClass($className);
                }
            }
        }
        return null;
    }

    protected function resetFunction(): void
    {
        $this->context = new FunctionContext();
        $this->function = '';
        $this->functionDef = null;
    }

    protected function resetMethod(): void
    {
        $this->method = '';
        $this->methodDef = null;
    }

    protected function enterCompilerPhase(string $phase): string
    {
        $previous = $this->compilerPhase;
        $this->compilerPhase = $phase;
        return $previous;
    }

    protected function restoreCompilerPhase(string $phase): void
    {
        $this->compilerPhase = $phase;
    }

    protected function assertCompilerPhase(string $expected, string $feature): void
    {
        if ($this->compilerPhase !== $expected) {
            $this->error("Internal compiler error: {$feature} can only be used during {$expected} phase, current phase is {$this->compilerPhase}");
        }
    }

    protected function resetClass(): void
    {
        $this->class     = '';
        $this->interface = '';
        $this->classDef  = null;
    }

    protected function resetFile(): void
    {
        $this->indentLevel = 0;
        $this->nativeTypes = false;
        $this->decimalTypes = false;
        $this->bigintTypes = false;
        $this->classesDefineInFile = [];
        $this->interfacesDefineInFile = [];
        $this->functionDefineInFile = [];
        $this->stubImportLibrary = '';
    }

    protected function resetNamespace(): void
    {
        $this->useNamespaces = [];
        $this->useAliases = [];
        $this->useFunctions = [];
        $this->useConstants = [];
        $this->useImportAliases = [];
        $this->namespace = '';
    }

    protected function getFunctionName(FunctionLike $v): string
    {
        if ($this->methodDef !== null && $this->classDef !== null) {
            return $this->getNativeName(
                $this->parseIdentifier($v->name),
                $this->classDef->namespace,
                $this->classDef->name,
            );
        }
        return $this->getNativeName($this->parseIdentifier($v->name), $this->namespace, $this->class);
    }

    protected function getFullClassName(): string
    {
        if ($this->classDef !== null) {
            return $this->classDef->getNamespacedName(false);
        }
        return ltrim($this->namespace . '\\' . $this->class, '\\');
    }

    protected function getFullClassLikeName(): string
    {
        if ($this->classDef !== null) {
            return $this->classDef->getNamespacedName(false);
        }
        $name = $this->class !== '' ? $this->class : $this->interface;
        return ltrim($this->namespace . '\\' . $name, '\\');
    }

    protected function getFullMethodName(string $fullClassName, string $method): string
    {
        return strtolower($fullClassName . '::' . $method);
    }

    protected function isCurrentConstructor(): bool
    {
        return $this->method === '__construct';
    }

    protected function getCurrentMethodDisplayName(): string
    {
        return $this->getFullClassName() . '::' . $this->method;
    }

    protected function assertExprCanBeUsedAsValue(NodeAbstract $expr, string $context = 'value'): void
    {
        if ($this->isVarExpr($expr)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects(
                $expr,
                $this->parseIdentifier($expr),
            );
        }
        // PHP permits using a void/never call as an expression; the expression
        // result is null after the call side effect has run.
    }

    protected function assertExprCanBeUsedAsCondition(NodeAbstract $expr, string $context = 'condition'): void
    {
        // Conditions are value contexts in PHP. A void/never expression is
        // evaluated for side effects and then coerced from null.
    }

    protected function isVoidValueExpr(Node $expr): bool
    {
        return $this->detectTypeOfExpr($expr) === Type::VOID;
    }

    protected function wrapVoidExprAsNull(Node $expr, string $exprCode): string
    {
        if (!$this->isVoidValueExpr($expr)) {
            return $exprCode;
        }

        return '((void) (' . $exprCode . '), ' . self::VALUE_NULL . ')';
    }

    protected function parseExprAsValue(Node $expr): string
    {
        if ($expr instanceof Expr\Cast\Void_) {
            return $this->parseExpr($expr);
        }
        $value = $this->wrapVoidExprAsNull($expr, $this->parseExpr($expr));
        return $this->normalizeNativeObjectValueExpr($expr, $value);
    }

    /**
     * Snapshot a reference-returning call before a by-value container can retain
     * its php::Ref. Assigning to an existing Var detaches the reference, unlike
     * constructing a Variant directly from Ref. Keep the assignment inline so
     * earlier arguments or array elements retain PHP's evaluation order.
     */
    protected function materializeRefReturnAsValue(NodeAbstract $value, string $expr): string
    {
        if ($value instanceof Expr\CallLike && $this->resolveRefReturningCall($value) !== false) {
            $tmpVar = $this->addTmpVar(Type::VAR);
            return '(' . $tmpVar . ' = ' . $expr . ')';
        }
        return $expr;
    }

    protected function getObjectPropVarName(string $object, string $prop): string
    {
        return self::OBJECT_PROP . $object . self::NAMESPACE_SEPARATOR . $prop;
    }

    protected function getObjectPropInfoByVar(string $var): ?array
    {
        return $this->context->objectProps[$var] ?? null;
    }

    protected function registerObjectPropVar(string $var, array $info): void
    {
        if (isset($this->context->objectProps[$var])) {
            return;
        }
        $this->context->objectProps[$var] = $info;
    }

    protected function registerHoistedObjectPropVar(string $var, string $type, string $getter): void
    {
        $info = $this->getHoistedObjectPropInfo($type);
        $this->registerObjectPropVar($var, [
            'type' => $info['type'],
            'getter' => $getter,
            'kind' => $info['kind'],
        ]);
    }

    protected function getNativeName(string $fn, string $ns = '', string $class = ''): string
    {
        $names = [];
        if ($ns) {
            $names[] = $this->escapeNamespace($ns);
        }
        if ($class) {
            $names[] = $this->escapeClass($class);
        }
        if ($fn) {
            $names[] = $this->escapeName($fn);
        }
        return implode(self::NAMESPACE_SEPARATOR, $names);
    }

    /**
     * Determine whether a class's symbol pointer is stable across the PHP
     * module lifetime (registered at MINIT, safe to cache across requests).
     * Compiled output (classes/interfaces compiled in this unit) and PHP
     * built-in classes/interfaces both satisfy this condition.
     */
    protected function isProcessStableClass(string $className): bool
    {
        if ($this->hasClass($className) || $this->hasInterface($className)) {
            return true;
        }
        $ref = Reflection::getClass(ltrim($className, '\\'));
        return $ref !== null && $ref->isInternal();
    }

    /**
     * Determine whether a function/method symbol pointer is stable across the
     * PHP module lifetime. For a `Class::method` key, stability is determined
     * by the class it belongs to.
     */
    protected function isProcessStableFunction(string $funcName): bool
    {
        if (str_contains($funcName, '::')) {
            [$class] = explode('::', $funcName, 2);
            return $this->isProcessStableClass($class);
        }
        if ($this->hasFunction($funcName)) {
            return true;
        }
        $ref = Reflection::getFunction(ltrim($funcName, '\\'));
        return $ref !== null && $ref->isInternal();
    }

    protected function getClassId(string $className): int
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'class cache ID allocation');
        if (isset($this->classMap[$className])) {
            return $this->classMap[$className];
        }
        if (isset($this->persistentClassMap[$className])) {
            return $this->persistentClassMap[$className];
        }
        if ($this->isProcessStableClass($className)) {
            $id = $this->persistentClassIndex++;
            $this->persistentClassMap[$className] = $id;
        } else {
            $id = $this->classIndex++;
            $this->classMap[$className] = $id;
        }
        return $id;
    }

    protected function getFuncId(string $funcName): int
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'function cache ID allocation');
        if (isset($this->funcMap[$funcName])) {
            return $this->funcMap[$funcName];
        }
        if (isset($this->persistentFuncMap[$funcName])) {
            return $this->persistentFuncMap[$funcName];
        }
        if ($this->isProcessStableFunction($funcName)) {
            $id = $this->persistentFuncIndex++;
            $this->persistentFuncMap[$funcName] = $id;
        } else {
            $id = $this->funcIndex++;
            $this->funcMap[$funcName] = $id;
        }
        return $id;
    }

    /**
     * @param string $className Must be a fully-qualified class name (with namespace).
     *
     * Note: there is no dynamic propMap for user-defined classes (unlike
     * classMap/funcMap). The property-offset cache assumes declared properties
     * can be resolved at compile time (PropertyAccessResolver only accepts a
     * compiled ClassDef or the reflected declared properties of a built-in
     * class). User classes are not visible at compile time, so their property
     * accesses always go through the `.attr(name)` string path. As a result,
     * every entry is necessarily process-stable and goes into persistentPropMap.
     */
    protected function getPropertyId(string $className, string $propName): int
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'property cache ID allocation');
        $key = $className . '::' . $propName;
        if (isset($this->persistentPropMap[$key])) {
            return $this->persistentPropMap[$key];
        }
        $id = $this->persistentPropIndex++;
        $this->persistentPropMap[$key] = $id;
        return $id;
    }

    protected function getPropertyAccessCache(): string
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'property access cache ID allocation');
        $id = $this->propertyAccessCacheIndex++;
        return 'get_property_cache(PropertyCacheId{' . $id . '})';
    }

    protected function getMethodCallCache(): string
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'method call cache ID allocation');
        $id = $this->methodCallCacheIndex++;
        return 'typephp_get_method_call_cache(MethodCallCacheId{' . $id . '})';
    }

    protected function getFunctionCallCache(): string
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'function call cache ID allocation');
        $id = $this->functionCallCacheIndex++;
        return 'typephp_get_function_call_cache(FunctionCallCacheId{' . $id . '})';
    }

    /** Return the function-local late-static-bound class entry. */
    protected function getCalledCeExpr(): string
    {
        $this->context->needsCalledCe = true;
        return '_typephp_called_ce';
    }

    /** Return the function-local late-static-bound class name. */
    protected function getCalledClassExpr(): string
    {
        $this->context->needsCalledCe = true;
        $this->context->needsCalledClass = true;
        return '_typephp_called_class';
    }

    protected function getClassEntryPtr(string $className): string
    {
        $id = $this->getClassId($className);
        $persistent = isset($this->persistentClassMap[$className]);
        $helper = $persistent ? 'get_persistent_class' : 'get_class';
        $idType = $persistent ? 'PersistentClassId' : 'RequestClassId';
        return $helper . '(' . $idType . '{' . $id . '}, ' . $this->getLiteralString($className) . ')';
    }

    /**
     * Resolve a process-stable class once at function entry. This keeps class
     * table/cache lookup out of loops containing `new KnownClass()` while
     * leaving runtime-provided classes at their original expression site.
     */
    protected function getLocalClassEntryPtr(string $className): string
    {
        if (!$this->allowLocalClassEntryHoisting || !$this->isProcessStableClass($className)) {
            return $this->getClassEntryPtr($className);
        }
        if (isset($this->context->classEntryPtrs[$className])) {
            return $this->context->classEntryPtrs[$className];
        }

        $entry = $this->genTmpVarName();
        $this->context->classEntryPtrs[$className] = $entry;
        return $entry;
    }

    protected function withoutLocalClassEntryHoisting(callable $callback): mixed
    {
        $allowLocalClassEntryHoisting = $this->allowLocalClassEntryHoisting;
        $this->allowLocalClassEntryHoisting = false;
        try {
            return $callback();
        } finally {
            $this->allowLocalClassEntryHoisting = $allowLocalClassEntryHoisting;
        }
    }

    /** The declaring class controls visibility; the runtime called class does not. */
    protected function getCallableScopeExpr(): string
    {
        if (!$this->classDef || !$this->methodDef) {
            return 'php::CallableScope(nullptr, nullptr, nullptr)';
        }
        if ($this->context->callableScopeVar === null) {
            $this->context->callableScopeVar = $this->genTmpVarName();
        }
        return $this->context->callableScopeVar;
    }

    protected function getCeWrapper(string $className): string
    {
        if (isset($this->context->ceWrappers[$className])) {
            return $this->context->ceWrappers[$className];
        }
        $object = $this->addTmpVar(Type::OBJECT);
        $this->context->ceWrappers[$className] = $object;
        return $object;
    }

    protected function getFuncPtr(string $funcName): string
    {
        if (str_contains($funcName, '::')) {
            throw new \LogicException('Class methods must be resolved through getMethodPtr()');
        }
        $id = $this->getFuncId($funcName);
        $persistent = isset($this->persistentFuncMap[$funcName]);
        $helper = $persistent ? 'get_persistent_func' : 'get_func';
        $idType = $persistent ? 'PersistentFuncId' : 'RequestFuncId';
        return $helper . '(' . $idType . '{' . $id . '}, ' . $this->getLiteralString($funcName) . ')';
    }

    protected function getMethodPtr(string $class, string $method): string
    {
        $key = $class . '::' . $method;
        $funcId = $this->getFuncId($key);
        $classId = $this->getClassId($class);
        $persistentMethod = isset($this->persistentFuncMap[$key]);
        $persistentClass = isset($this->persistentClassMap[$class]);
        if ($persistentMethod !== $persistentClass) {
            throw new \LogicException(sprintf(
                'Cache lifetime mismatch for %s: method ID %d is %s, class ID %d is %s',
                $key,
                $funcId,
                $persistentMethod ? 'persistent' : 'request-local',
                $classId,
                $persistentClass ? 'persistent' : 'request-local',
            ));
        }
        $helper = $persistentMethod ? 'get_persistent_method' : 'get_method';
        $funcIdType = $persistentMethod ? 'PersistentFuncId' : 'RequestFuncId';
        $classIdType = $persistentClass ? 'PersistentClassId' : 'RequestClassId';
        return $helper . '(' . $funcIdType . '{' . $funcId . '}, ' . $this->getLiteralString($method)
            . ', ' . $classIdType . '{' . $classId . '}, ' . $this->getLiteralString($class) . ')';
    }

    protected function getPropertyOffset(string $class, string $prop): string
    {
        $propId = $this->getPropertyId($class, $prop);
        return 'get_persistent_prop(PersistentPropertyId{' . $propId . '}, '
            . $this->getLiteralString($prop) . ', ' . $this->getLiteralString($class) . ')';
    }

    protected function writeLog($msg): void
    {
        if ($this->verbose) {
            echo $msg . PHP_EOL;
        }
    }

    protected function getLiteralString(string $string): string
    {
        if ($this->noLiteralStrings) {
            return $this->getInlineString($string);
        }
        $index = $this->literalStrings[$string] ?? $this->addLiteralString($string);
        return self::LITERAL_STRING_GETTER . '(' . $index . ')';
    }

    /**
     * Generates a PHP string value without adding it to the literal-string table.
     *
     * A C++ string literal may contain an embedded NUL, but passing it as a
     * const char* would truncate it at that byte. ZEND_STRL preserves its length.
     */
    protected function getInlineString(string $string): string
    {
        return Type::STR . '{ZEND_STRL(' . $this->genCharPtr($string, true) . ')}';
    }

    protected function parseScalar(Node\Scalar $expr): string
    {
        $type = $expr->getType();
        switch ($type) {
            case 'Scalar_Int':
                if ($this->bigintTypes) {
                    return 'php::toBigInt(' . $expr->value . ')';
                }
                return $expr->value . $this->getPlatform()->getIntegerLiteralSuffix();
            case 'Scalar_Float':
                if ($this->isBigIntLiteral($expr)) {
                    return 'php::toBigInt(' . $this->getLiteralString($this->getBigIntLiteralString($expr)) . ')';
                }
                if ($this->isDecimalLiteral($expr) || $this->decimalTypes) {
                    $rawValue = $expr->getAttribute('rawValue');
                    $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string) $expr->value;
                    return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
                }
                return $this->parseScalarFloat($expr);
            case 'Scalar_String':
                return $expr->hasAttribute('noLiteralString') ? $this->getInlineString($expr->value) : $this->getLiteralString($expr->value);
            default:
                $this->unsupportedSyntax($expr);
                break;
        }
        return '';
    }

    /**
     * Check if a numeric literal's rawValue represents an integer that exceeds int64 range.
     * PHP's parser converts such literals to float (Scalar_Float) when they overflow.
     */
    /**
     * Check if a Scalar_Float literal should be treated as Decimal.
     * Only "long" floats (>= 16 significant digits) that would lose precision
     * as native PHP float (double) are auto-converted.
     */
    private function getBigIntLiteralString(Node\Scalar $expr): string
    {
        return $this->stripNumericUnderscores($expr->getAttribute('rawValue'));
    }

    protected function parseSuperGlobalVar(string $name): string
    {
        if (!$this->hasGlobalVar($name)) {
            $this->addGlobalVar($name, $this->superGlobalVars[$name]);
        }
        if (!$this->hasScopeGlobalVar($name)) {
            $this->addScopeGlobalVar($name, $this->superGlobalVars[$name]);
        }
        return $name;
    }

    protected function parseVariable(Variable $expr): string
    {
        if (!is_string($expr->name)) {
            $this->fatalError($expr, 'The `$$` syntax is not supported');
        }
        if ($this->isSuperGlobal($expr->name)) {
            return $this->parseSuperGlobalVar($expr->name);
        }
        return $this->escapeVarName($expr->name);
    }

    protected function parseImplements(array $implements): array
    {
        $list = [];
        $seen = [];
        foreach ($implements as $implement) {
            $interfaceName = $this->getNamespacedClassName($this->parseIdentifier($implement));
            $interfaceNameLower = strtolower($interfaceName);
            if (isset($seen[$interfaceNameLower])) {
                $kind = $this->classDef?->enum ? 'Enum' : 'Class';
                $className = $this->classDef?->getNamespacedName(false) ?? $this->class;
                $this->fatalError(
                    $implement,
                    "{$kind} {$className} cannot implement previously implemented interface {$interfaceName}",
                );
            }
            $seen[$interfaceNameLower] = true;
            $list[] = $interfaceName;
            if (!$this->isInternalInterface($interfaceName)) {
                $this->symbolCallInFile[$this->file][] = $interfaceNameLower;
            }
        }
        return $list;
    }

    protected function parseArrayKey(NodeAbstract $expr, bool $keepStringObject = false): string
    {
        $this->assertNotNativeObjectArrayKey($expr);
        $key = $this->parseIdentifier($expr);
        if (str_starts_with($key, self::LITERAL_STRING_GETTER . '(')) {
            // Array initializers and setters use zend_string* keys, while item()
            // uses php::String to avoid an ambiguous conversion to Variant.
            return $keepStringObject ? $key : "{$key}.str()";
        }
        if ($this->isZeroLiteral($expr)) {
            $key = self::VALUE_ZERO;
        }
        return $key;
    }

    /**
     * Check if a node is a literal zero value.
     *
     * Detects compile-time zero for two purposes:
     *  - Division-by-zero guard (any zero form: int, float, negated, numeric string)
     *  - C++ null pointer ambiguity guard: Scalar_Int(0) → 0L → nullptr → segfault
     *    when passed to functions with zend_string* overloads (setProperty, getProperty, etc.)
     */
    protected function isZeroLiteral(NodeAbstract $expr): bool
    {
        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value === 0;
        }
        if ($expr instanceof Node\Scalar\Float_) {
            return $expr->value == 0.0;
        }
        if ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus) {
            return $this->isZeroLiteral($expr->expr);
        }
        if ($expr instanceof Node\Scalar\String_) {
            $value = trim($expr->value);
            return $value !== '' && is_numeric($value) && (float)$value == 0.0;
        }
        if ($expr instanceof Node\Expr\ConstFetch or $expr instanceof Node\Expr\ClassConstFetch) {
            return in_array($this->parseExpr($expr), ['0L', '0LL']);
        }
        return false;
    }

    protected function parseIdentifier(Node $expr): string
    {
        if ($expr instanceof Variable) {
            return $this->parseVariable($expr);
        }
        if ($expr instanceof Node\Name\FullyQualified) {
            return '\\' . $expr->toString();
        }
        if ($expr instanceof Node\Name || $expr instanceof Node\VarLikeIdentifier || $expr instanceof Node\Identifier) {
            return $expr->toString();
        }
        if (
            $expr instanceof Node\Scalar\Int_
            || $expr instanceof Node\Scalar\Float_
            || $expr instanceof Node\Scalar\String_
        ) {
            return $this->parseScalar($expr);
        }
        if ($expr instanceof Expr\ConstFetch) {
            return $this->parseConstFetch($expr);
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            if (!$this->isVarExpr($expr->var) && !$this->isPropertyFetch($expr->var) && !$this->isArrayDimFetch($expr->var)) {
                $this->fatalError($expr, 'When an assignment expression serves as an rvalue, it must be an assignment of a variable, property, or array element');
            }
        }
        return $this->parseExprAsValue($expr);
    }

    protected function parseParamDefaultValue(?NodeAbstract $default): ?string
    {
        if (!$default) {
            return null;
        }
        return $this->withoutLocalClassEntryHoisting(function () use ($default): string {
            /*
             * Function parameter default values may only be literals; they
             * cannot be obtained through an expression. Since PHP 5.6, however,
             * constant expressions are allowed in default parameter values,
             * including class constants (self::FOO, ClassName::BAR,
             * \Full\Class::BAZ). The compiler must fold these into the
             * corresponding literal at compile time.
             *
             * PHP 8.1 also permits `new` in selected default-value contexts.
             * These expressions are emitted into standalone helper functions,
             * so a class entry must remain in the helper expression rather than
             * being hoisted into the containing function's entry block.
             */
            if ($default instanceof Expr\ConstFetch) {
                return $this->parseConstFetch($default, true);
            }
            if ($default instanceof Expr\ClassConstFetch) {
                return $this->parseClassConstFetch($default);
            }
            return $this->parseIdentifier($default);
        });
    }

    protected function getComment(Node\Stmt $v, string $class): string
    {
        if ($class == 'Stmt_Expression') {
            $class = 'Stmt_Expression(' . $v->expr->getType() . ')';
        }

        return '// ' . $class . ' [' . $v->getStartLine() . ':' . $v->getEndLine() . ']';
    }

    /**
     * For statements containing sub-statements (for/foreach, etc.), check
     * whether the currently pending code is empty. If not, the pending
     * statements must be emitted before the opening `{` scope brace.
     */
    protected function parseBeforeStmtLines(): string
    {
        if ($this->context->beforeStmtLines) {
            $code = implode(PHP_EOL, $this->context->beforeStmtLines);
            $this->context->beforeStmtLines = [];
            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseAfterStmtLines(): string
    {
        if ($this->context->afterStmtLines) {
            $code = implode(PHP_EOL, $this->context->afterStmtLines);
            $this->context->afterStmtLines = [];
            return $code . PHP_EOL;
        }
        return '';
    }

    protected function parseExprWithCapturedStmts(NodeAbstract $expr): array
    {
        $beforeStmtCount = count($this->context->beforeStmtLines);
        $afterStmtCount = count($this->context->afterStmtLines);
        $value = $this->parseExprAsValue($expr);
        $beforeStmts = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
        $afterStmts = array_slice($this->context->afterStmtLines, $afterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
        return [$value, $beforeStmts, $afterStmts];
    }

    protected function stringifyParsedExpr(mixed $expr): string
    {
        if (is_string($expr)) {
            return $expr;
        }
        if (is_int($expr) || is_float($expr)) {
            return (string) $expr;
        }
        if (is_object($expr)) {
            if (method_exists($expr, 'toString')) {
                return $expr->toString();
            }
            if (method_exists($expr, '__toString')) {
                return $expr->__toString();
            }
        }
        throw new \LogicException('Parsed expression must be stringable');
    }

    protected function formatCapturedStmtLines(array $stmts): string
    {
        if (!$stmts) {
            return '';
        }

        $code = '';
        foreach ($stmts as $stmt) {
            $code .= $this->formatStatementFragment($stmt) . PHP_EOL;
        }
        return $code;
    }

    protected function genConditionWithCapturedStmts(NodeAbstract $cond, string $openPrefix): string
    {
        $this->assertExprCanBeUsedAsCondition($cond);
        [$condExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($cond);
        $code = '';
        $code .= $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(Type::VAR);
            $code .= $this->getIndent() . $tmpVar . ' = ' . $condExpr . ';' . PHP_EOL;
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $condExpr = $tmpVar;
        }

        if ($cond instanceof Expr\Assign) {
            $condExpr = '(' . $condExpr . ')';
        }
        $condExpr = $this->convertConditionExpr($cond, $condExpr);
        $code .= $this->getIndent() . $openPrefix . '(' . $condExpr . ') {' . PHP_EOL;
        return $code;
    }

    protected function parseBlockStmts(array $stmts): string
    {
        $this->indentLevel++;
        $code = $this->parseStmts($stmts);
        $this->indentLevel--;
        return $code;
    }

    protected function parseStmts(array $stmts): string
    {
        $this->context->enterScope();
        $lines     = [];
        $inLoopTop = $this->context->inLoop;
        $inContinuableLoopTop = $this->context->inContinuableLoop;
        $breakableIsSwitchTop = $this->context->breakableIsSwitch;
        $breakableDepthTop = $this->context->breakableDepth;
        $last = array_key_last($stmts);
        foreach ($stmts as $i => $v) {
            $class                 = $v->getType();
            $this->context->beforeStmtLines = [];
            $this->context->afterStmtLines  = [];
            $result                = '';
            $this->writeLog('Line ' . $this->getLine($v) . ': ' . $class);
            if ($this->debug) {
                $lines[] = $this->genDebugInfo($v);
                $lines[] = $this->getComment($v, $class);
            }
            switch ($class) {
                case 'Stmt_Expression':
                    $v->expr->setAttribute(self::ATTR_STATEMENT_EXPRESSION, true);
                    $this->assertMustUseResultIsConsumed($v->expr);
                    if ($this->inGeneratorBody && $v->expr instanceof Expr\Yield_) {
                        $result = $this->parseYieldStmt($v->expr);
                    } elseif ($this->inGeneratorBody && $v->expr instanceof Expr\YieldFrom) {
                        $result = $this->parseYieldFromStmt($v->expr);
                    } else {
                        $expression = $this->parseExpr($v->expr);
                        $result = $expression === '' ? '' : $expression . ';';
                    }
                    break;
                case 'Stmt_Echo':
                    $result = $this->parseEcho($v);
                    break;
                case 'Stmt_Return':
                    $result = $this->parseReturn($v);
                    break;
                case 'Stmt_For':
                case 'Stmt_Foreach':
                case 'Stmt_Switch':
                case 'Stmt_While':
                case 'Stmt_Do':
                    $isSwitch = $class === 'Stmt_Switch';
                    $this->context->inLoop = true;
                    if (!$isSwitch) {
                        $this->context->inContinuableLoop = true;
                    }
                    $this->context->breakableIsSwitch = $isSwitch;
                    $this->context->breakableDepth = $breakableDepthTop + 1;
                    $result = match ($class) {
                        'Stmt_For' => $this->parseFor($v),
                        'Stmt_Foreach' => $this->parseForeach($v),
                        'Stmt_Switch' => $this->parseSwitch($v),
                        'Stmt_While' => $this->parseWhile($v),
                        default => $this->parseDo($v),
                    };
                    $this->context->inLoop = $inLoopTop;
                    $this->context->inContinuableLoop = $inContinuableLoopTop;
                    $this->context->breakableIsSwitch = $breakableIsSwitchTop;
                    $this->context->breakableDepth = $breakableDepthTop;
                    // A multi-level break/continue exits the nested construct
                    // with its countdown flag still set. The propagation check
                    // must run before any trailing statement of this body.
                    if ($inLoopTop) {
                        $flagCheck = $this->genMultiLevelJumpCheck($breakableIsSwitchTop);
                        if ($flagCheck !== '') {
                            $result = rtrim($result, "\r\n") . PHP_EOL . $flagCheck;
                        }
                    }
                    break;
                case 'Stmt_If':
                    $result = $this->parseIf($v);
                    break;
                case 'Stmt_Break':
                    $result = $this->parseBreak($v);
                    break;
                case 'Stmt_Goto':
                    $result = $this->parseGoto($v);
                    break;
                case 'Stmt_Label':
                    $result = $this->parseLabel($v);
                    if ($i === $last) {
                        $result .= self::OP_NOP;
                    }
                    break;
                case 'Stmt_Continue':
                    $result = $this->parseContinue($v);
                    break;
                case 'Stmt_Nop':
                    break;
                case 'Stmt_Global':
                    $result = $this->parseGlobal($v);
                    break;
                case 'Stmt_Enum':
                    $result = $this->parseEnum($v);
                    break;
                case 'Stmt_Static':
                    $result = $this->parseStatic($v);
                    break;
                case 'Stmt_Unset':
                    $result = $this->parseUnset($v);
                    break;
                case 'Stmt_TryCatch':
                    $result = $this->parseTryCatch($v);
                    break;
                case 'Stmt_Block':
                    $result = $this->parseStmts($v->stmts);
                    break;
                case 'Stmt_Class':
                    $this->fatalError($v, 'Cannot declare class in function');
                    break;
                case 'Stmt_Function':
                    $this->fatalError($v, 'Cannot declare function in function');
                    break;
                default:
                    $this->unsupportedSyntax($v);
                    break;
            }
            $lines                 = array_merge($lines, $this->context->beforeStmtLines);
            $this->context->beforeStmtLines = [];
            if ($result) {
                $lines[] = $result;
            }
            if ($this->context->afterStmtLines) {
                $lines                = array_merge($lines, $this->context->afterStmtLines);
                $this->context->afterStmtLines = [];
            }
        }

        $code = '';
        foreach ($lines as $line) {
            $code .= $this->formatStatementFragment($line) . PHP_EOL;
        }
        $this->context->leaveScope();

        return $code;
    }

    /**
     * Statement lowerers may return a multi-line fragment. Some nested lines
     * already carry their absolute indentation, while simple statements do
     * not. Apply the current scope indentation to every unindented physical
     * line instead of only the first line of the fragment.
     */
    protected function formatStatementFragment(string $fragment): string
    {
        $indent = $this->getIndent();
        $fragment = rtrim($fragment, "\r\n");
        $lines = preg_split('/\R/', $fragment);
        if ($lines === false) {
            return $indent . $fragment;
        }

        $firstContentLine = true;
        foreach ($lines as &$line) {
            if ($line === '') {
                continue;
            }
            if ($firstContentLine) {
                // A fragment represents one statement at the current scope.
                // Its first physical line must not retain indentation captured
                // from an intermediate expression-lowering context.
                $line = $indent . ltrim($line);
                $firstContentLine = false;
            } elseif ($line[0] !== ' ' && $line[0] !== "\t") {
                $line = $indent . $line;
            }
        }
        unset($line);

        return implode(PHP_EOL, $lines);
    }

    protected function assertMustUseResultIsConsumed(NodeAbstract $expr): void
    {
        $functionDef = $this->resolveCalledFunctionDef($expr);
        if ($functionDef?->mustUse) {
            $target = ($functionDef->method ? 'method ' : 'function ') . $functionDef->name . '()';
            $this->error(CompileTimeAttributeDiagnostic::formatPositions(
                'The return value of `' . $functionDef->name . '()` must be used',
                'MustUse',
                $target,
                $functionDef->sourceFile,
                $functionDef->startLine,
                'discarded call',
                $this->file,
                $expr->getStartLine(),
            ));
        }
    }

    protected function resolveCalledFunctionDef(NodeAbstract $expr): ?FunctionDef
    {
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            $name = $this->parseIdentifier($expr->name);
            $native = $this->findNativeFunction($name);
            return $native ? $this->getFunction($native) : null;
        }
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $class = $this->detectClassOfExpr($expr->var);
            if ($class === '' && $expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $var = $this->parseVariable($expr->var);
                $class = $var === 'this_' ? $this->getFullClassName() : $this->getDeclaredObjectType($var);
            }
            return $class === '' ? null : $this->findAotMethodFunctionDef($class, $expr->name->toString());
        }
        if (
            $expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
        ) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self' || $class === 'static') {
                $class = $this->getFullClassName();
            } elseif ($class === 'parent') {
                $class = $this->classDef?->extends ?? '';
            } else {
                $class = $this->getNamespacedClassName($class);
            }
            return $class === '' ? null : $this->findAotMethodFunctionDef($class, $expr->name->toString());
        }
        return null;
    }

    protected function parseEcho(mixed $v): string
    {
        $lines = [];
        foreach ($v->exprs as $expr) {
            $lines[] = 'php::echo(' . $this->parseExprToString($expr) . ');';
        }

        return implode("\n" . $this->getIndent(), $lines);
    }

    /**
     * Convert to a number whenever possible, with priority float > integer > string.
     */
    protected function parseNumericIdentifier(NodeAbstract $expr): string
    {
        if ($expr->getType() === 'Scalar_String') {
            if ($this->isFloatStr($expr->value)) {
                return (string) floatval($expr->value);
            }
            if ($this->isIntStr($expr->value)) {
                return (string) intval($expr->value);
            }
            if ($expr->value === '0') {
                return '0';
            }
        }

        return $this->normalizeNativeObjectValueExpr($expr, $this->parseIdentifier($expr));
    }

    protected function detectClassOfExpr(NodeAbstract $expr): string
    {
        // Error suppression changes diagnostics only; it must never erase the
        // static type of the wrapped expression. This is especially important
        // for Native objects because treating their typed pointer as php::Var
        // would cross the ZendVM boundary.
        if ($expr instanceof Expr\ErrorSuppress) {
            return $this->detectClassOfExpr($expr->expr);
        }
        if ($expr instanceof Expr\Clone_) {
            return $this->detectClassOfExpr($expr->expr);
        }
        if ($expr instanceof Expr\NullsafeMethodCall) {
            return $this->detectClassOfExpr(new Expr\MethodCall(
                $expr->var,
                $expr->name,
                $expr->args,
                $expr->getAttributes(),
            ));
        }
        if ($expr instanceof Expr\NullsafePropertyFetch) {
            return $this->detectClassOfExpr(new Expr\PropertyFetch(
                $expr->var,
                $expr->name,
                $expr->getAttributes(),
            ));
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return 'Closure';
        }
        if ($expr instanceof Expr\Ternary) {
            return $this->getCommonNativeObjectExpressionClass([
                $expr->if ?? $expr->cond,
                $expr->else,
            ]);
        }
        if ($expr instanceof Expr\Match_) {
            return $this->getCommonNativeObjectExpressionClass(array_map(
                static fn(Node\MatchArm $arm): Expr => $arm->body,
                $expr->arms,
            ));
        }
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->getCommonNativeObjectExpressionClass([$expr->left, $expr->right]);
        }
        if ($expr instanceof Expr\MethodCall && $this->isNamedMethod($expr->name)) {
            $keywordType = $this->findKeywordMethod($this->parseIdentifier($expr->name));
            if ($keywordType !== null && $keywordType !== Type::OBJECT) {
                return '';
            }
        }
        $pythonOperatorClass = $this->detectPythonOperatorReturnClass($expr);
        if ($pythonOperatorClass !== null) {
            return $pythonOperatorClass;
        }
        $pythonClass = $this->detectPythonExpressionReturnClass($expr);
        if ($pythonClass !== null) {
            return $pythonClass;
        }

        if ($this->isNewExpr($expr) and $this->isNameExpr($expr->class)) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                return $this->getFullClassName();
            }
            if ($class === 'static') {
                if ($this->classDef?->nativeObject) {
                    $this->fatalError($expr, 'Native classes do not support `new static()`');
                }
                // The exact class name of a `static` class cannot be obtained at compile time.
                return '';
            } else {
                return $this->getNamespacedClassName($class);
            }
        }
        if ($this->isVarExpr($expr)) {
            $object = $this->parseVariable($expr);
            if ($object === 'this_') {
                return $this->getFullClassName();
            }
            if ($this->isTypedObject($object)) {
                return $this->getObjectType($object);
            }
        }
        $globalSlot = $this->getStaticGlobalsSlot($expr);
        if ($globalSlot !== null && isset($this->nativeGlobalObjects[$globalSlot])) {
            return $this->nativeGlobalObjects[$globalSlot];
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isIdExpr($expr->name)) {
            $receiverClass = $this->detectClassOfExpr($expr->var);
            if ($this->isNativeObjectClass($receiverClass)) {
                $property = $this->findNativeObjectProperty($receiverClass, $expr->name->toString());
                if (
                    $property !== null
                    && $property->type === Type::OBJECT
                    && $this->isNativeObjectClass($property->class)
                ) {
                    return $property->class;
                }
            }
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdContainerExpr($expr)) {
            if ($this->isStdArrayExpr($expr)) {
                if (!$expr->hasAttribute('stdArrayDimFetch')) {
                    $this->parseStdArrayDimFetch($expr);
                }
                $attr = $expr->getAttribute('stdArrayDimFetch');
                if ($attr['accessLevel'] === $attr['totalLevel']) {
                    return $this->context->stdArrays[$attr['var']]['class'] ?? '';
                }
                return '';
            }
            if (!$expr->hasAttribute('stdContainerDimFetch')) {
                $this->parseStdContainerDimFetch($expr);
            }
            $attr = $expr->getAttribute('stdContainerDimFetch');
            return $this->context->stdContainers[$attr['var']]['class'] ?? '';
        }
        if ($this->isFuncCallExpr($expr) and $this->isNameExpr($expr->name)) {
            $fn = $this->parseIdentifier($expr->name);
            if (count($expr->args) === 2 and $fn === 'objval') {
                return $this->resolveClassNameArg($expr->args[1]->value);
            }
            if ($this->hasFunction($fn)) {
                return $this->getFunction($fn)->returnClass;
            }
        }
        if ($this->isMethodCall($expr) and $this->isNamedMethod($expr->name)) {
            $method = $this->parseIdentifier($expr->name);
            if ($method === 'toObject' and !empty($expr->args)) {
                return $this->resolveClassNameArg($expr->args[0]->value);
            }
            $classDef = $this->resolveObjectClassDef($expr->var);
            if ($classDef !== null && $classDef->hasMethod($method)) {
                return $classDef->getMethod($method)->functionDef->returnClass;
            }
            if ($this->isVarExpr($expr->var)) {
                $object = $this->parseVariable($expr->var);
                try {
                    $nativeFunc = $this->findNativeMethod($expr, $object, $method);
                    if ($nativeFunc) {
                        return $this->getFunction($nativeFunc)->returnClass;
                    }
                } catch (DynamicCall) {
                }
            }
        }
        if ($this->isStaticCall($expr) and $this->isNameExpr($expr->class) and $this->isNamedMethod($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                $class = $this->class;
            } elseif ($class === 'static' or $class === 'parent') {
                return '';
            }
            $class = $this->getNamespacedClassName($class);
            $method = $this->parseIdentifier($expr->name);
            if ($this->hasClass($class)) {
                $classDef = $this->getClass($class);
                if ($classDef->hasMethod($method)) {
                    return $classDef->getMethod($method)->functionDef->returnClass;
                }
            }
            $nativeFunc = $this->getNativeMethod($expr, $class, $method);
            if ($nativeFunc) {
                return $this->getFunction($nativeFunc)->returnClass;
            }
        }
        return '';
    }

    protected function detectDeclaredClassOfExpr(NodeAbstract $expr): string
    {
        // Object expressions carry two kinds of type information:
        // 1. detectClassOfExpr() returns the "actually inferable class", e.g. new Foo() or a typed object variable;
        // 2. getDeclaredObjectType() returns the declared type recorded at declaration/first assignment, which may be an interface or abstract class.
        // Parameter and property-assignment checks prefer the actual class, falling back to the declared type only when the actual class is unknown.
        $class = $this->detectClassOfExpr($expr);
        if ($class !== '') {
            return $class;
        }
        if ($this->isVarExpr($expr)) {
            return $this->getDeclaredObjectType($this->parseVariable($expr));
        }
        return '';
    }

    protected function isObjectClassStaticallyAssignableTo(string $class, string $expected): bool
    {
        // This function only answers "can the compiler prove at the static
        // stage that $class is-a $expected". It must not use
        // class_exists()/interface_exists()/is_a() to query the PHP process
        // currently running the compiler:
        // - Composer/tool classes already loaded in the compiler process are not
        //   equivalent to classes available at runtime for the compiled project;
        // - during bootstrapping, the compiler's own external dependencies would
        //   be mistaken for the project's static classes;
        // - AOT static analysis must rely only on the project class graph
        //   recorded by hasClass()/hasInterface(), or on explicitly built-in
        //   classes/interfaces.
        // If a class is not in one of these sets, it is a dynamic / external
        // library class and cannot be statically determined here. Return false
        // and let the caller decide whether to defer to runtime
        // php::toObject()/TypeCheck, or to fail fatally because of a
        // determined concrete mismatch.
        $class = ltrim($class, '\\');
        $expected = ltrim($expected, '\\');
        if (strcasecmp($class, $expected) === 0) {
            return true;
        }

        if (
            !$this->hasClass($class)
            && !$this->hasInterface($class)
            && !$this->isInternalClass($class)
            && !$this->isInternalInterface($class)
        ) {
            return false;
        }

        return $this->isInheritedFrom($class, $expected);
    }

    protected function isKnownConcreteObjectExpr(NodeAbstract $expr, string $class): bool
    {
        // "Known concrete object" is stricter than "the expression literally
        // says new SomeClass": only classes in the AOT project class graph or
        // built-in classes allow the compiler to confirm inheritance at the
        // static stage. Even if an external library class appears in a new
        // expression, it cannot be determined using the reflection info of the
        // current compiler process; doing so would leak the compiler/Composer
        // runtime environment into the type system of the compiled project.
        if ($class === '' || $this->isInterface($class) || $this->isAbstractClass($class)) {
            return false;
        }
        if (!$this->hasClass($class) && !$this->isInternalClass($class)) {
            return false;
        }
        if (!$this->isNewExpr($expr) || !$this->isNameExpr($expr->class)) {
            return false;
        }
        return $this->parseIdentifier($expr->class) !== 'static';
    }

    protected function resolveClassNameArg(NodeAbstract $arg): string
    {
        if ($this->isScalarString($arg)) {
            return $this->getNamespacedClassName($arg->value);
        }
        if ($this->isClassConstFetch($arg)) {
            if ($this->isNameExpr($arg->class) and $this->isIdExpr($arg->name) and $this->parseIdentifier($arg->name) === 'class') {
                $class = $this->parseIdentifier($arg->class);
                if ($class === 'self') {
                    $class = $this->class;
                } elseif ($class === 'parent') {
                    if (!$this->classDef || !$this->classDef->extends) {
                        $this->fatalError($arg, 'Cannot use "parent" outside a class or class does not extend any class');
                    }
                    return $this->classDef->extends;
                } elseif ($class === 'static') {
                    $this->fatalError($arg, "'static::class' cannot be resolved at compile time, use a concrete class name or 'self::class'");
                }
                return $this->getNamespacedClassName($class);
            }
        }
        $this->fatalError($arg, 'Only string literals or `ClassName::class` constant are supported');
    }

    /**
     * Resolve whether a call returns by reference. A null result means that
     * dispatch is dynamic and must be checked at runtime.
     */
    protected function resolveRefReturningCall(Node $expr): ?bool
    {
        if ($expr instanceof Expr\FuncCall && ($this->isNameExpr($expr->name) || $this->isFullNameExpr($expr->name))) {
            $name = $this->parseIdentifier($expr->name);
            $function = $this->findNativeFunction($name);
            if ($function !== false) {
                return $this->getFunction($function)->returnsByRef;
            }
            $reflection = \TypePhp\Resolver\Reflection::getFunction(ltrim($this->getNamespacedFuncName($name), '\\'));
            return $reflection?->returnsReference();
        }
        if ($expr instanceof Expr\FuncCall) {
            return null;
        }
        if ($expr instanceof Expr\MethodCall && $this->isNamedMethod($expr->name) && $this->isVarExpr($expr->var)) {
            $object = $this->parseIdentifier($expr->var);
            $method = $this->parseIdentifier($expr->name);
            if ($object === 'this_') {
                $class = $this->getFullClassName();
            } elseif (isset($this->context->objects[$object])) {
                $class = $this->context->stableObjects[$object] ?? $this->context->objects[$object];
            } else {
                return null;
            }
            try {
                $function = $this->getNativeMethod($expr, $class, $method, false);
            } catch (DynamicCall) {
                return null;
            }
            if ($function !== false) {
                return $this->getFunction($function)->returnsByRef;
            }
            return null;
        }
        if ($expr instanceof Expr\MethodCall) {
            return null;
        }
        if ($expr instanceof Expr\StaticCall && ($this->isNameExpr($expr->class) || $this->isFullNameExpr($expr->class)) && $this->isIdExpr($expr->name)) {
            $class = $this->parseIdentifier($expr->class);
            if ($class === 'self') {
                $class = $this->getFullClassName();
            } elseif ($class === 'parent') {
                if (!$this->classDef || !$this->classDef->extends) {
                    return false;
                }
                $class = $this->classDef->extends;
            } elseif ($class === 'static') {
                if (!$this->classDef) {
                    return null;
                }
                $class = $this->getFullClassName();
            } else {
                $class = $this->getNamespacedClassName($class);
            }
            $method = $this->parseIdentifier($expr->name);
            try {
                $function = $this->getNativeMethod($expr, $class, $method, false);
            } catch (DynamicCall) {
                return null;
            }
            if ($function !== false) {
                return $this->getFunction($function)->returnsByRef;
            }
            return null;
        }
        if ($expr instanceof Expr\StaticCall) {
            return null;
        }
        return false;
    }

    protected function parseReturn(Node\Stmt\Return_ $v): string
    {
        if ($v->expr !== null) {
            $this->assertImmutableObjectDoesNotEscape($v->expr, 'a return value');
        }
        if ($v->expr !== null && $this->isVarExpr($v->expr)) {
            $this->assertStdContainerDoesNotEscapeNativeObjects(
                $v,
                $this->parseIdentifier($v->expr),
            );
        }
        if ($this->functionDef->returnsByRef) {
            if ($v->expr === null) {
                return 'return ' . Type::REF . '{};';
            }
            if ($v->expr instanceof CallLike) {
                $returnsByRef = $this->resolveRefReturningCall($v->expr);
                if ($returnsByRef !== false) {
                    return 'return php::toReferenceExact(' . $this->parseExpr($v->expr) . ');';
                }
            }
            if (
                !$this->isVarExpr($v->expr)
                && !$this->isPropertyFetch($v->expr)
                && !$this->isStaticPropertyFetch($v->expr)
                && !$this->isArrayDimFetch($v->expr)
            ) {
                $this->fatalError($v, 'A function returning by reference must return a variable');
            }
            if ($this->isVarExpr($v->expr)) {
                $name = $this->parseIdentifier($v->expr);
                if (!$this->hasVar($name)) {
                    $this->errorUndefinedVariable($v->expr);
                }
                if ($this->hasLocalVar($name) && $this->getVarType($name) !== Type::VAR && $this->getVarType($name) !== Type::REF) {
                    $isParameter = false;
                    foreach ($this->functionDef->argInfoList as $argInfo) {
                        if ($argInfo->name === $name) {
                            $isParameter = true;
                            break;
                        }
                    }
                    if ($isParameter) {
                        $this->fatalError($v, 'A function returning by reference cannot return a native typed parameter');
                    }
                    // The declaration is emitted after parsing the body, so a local can
                    // be promoted to Variant before C++ is generated.
                    $this->context->localVars[$name] = Type::VAR;
                }
                return 'return ' . $name . '.toReference();';
            }
            if ($this->isPropertyFetch($v->expr)) {
                return 'return ' . $this->emitDynamicPropertyFetchRef($v->expr, $v) . ';';
            }
            if ($this->isStaticPropertyFetch($v->expr)) {
                return 'return ' . $this->emitStaticPropertyFetchRef($v->expr, $v) . ';';
            }
            return 'return ' . $this->parseChainedExpr($v->expr, self::OP_REFVAL) . ';';
        }
        if ($v->expr === null) {
            if (
                !$this->context->inClosure
                && $this->getNativeObjectReturnType($this->functionDef) !== null
            ) {
                $this->fatalError(
                    $v,
                    'A function with a Native object return type must return a value',
                );
            }
            $nullExpr = new Expr\ConstFetch(new Node\Name('null'));
            if ($this->shouldCheckClosureReturnType()) {
                $this->checkCompositeTypeAssignment(
                    $v,
                    $this->context->closureReturnTypeCheck,
                    $this->context->closureReturnTypeStr,
                    $nullExpr,
                    'closure return value'
                );
            } elseif ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
                $this->checkCompositeTypeAssignment(
                    $v,
                    $this->functionDef->returnTypeCheck,
                    $this->functionDef->returnTypeStr,
                    $nullExpr,
                    'return value'
                );
            }
            if ($this->functionDef->returnType === Type::VOID and !$this->context->inClosure) {
                return 'return;';
            } elseif ($this->shouldCheckClosureReturnType()) {
                return $this->genClosureCheckedReturn(self::VALUE_NULL);
            } elseif ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
                return $this->genUnionCheckedReturn(self::VALUE_NULL);
            } else {
                return 'return ' . self::VALUE_NULL . ';';
            }
        }
        if (!$this->context->inClosure && $this->functionDef->hasMultiReturn()) {
            if (!$v->expr instanceof Expr\Array_) {
                throw new \LogicException('Optimized multi-return function must return a fixed array literal');
            }

            // Assign tuple elements through Variant::operator= instead of constructing
            // temporary Vars. The rvalue overload can transfer an owned zval without
            // refcount churn while retaining PHP value-assignment semantics for
            // references and indirect zvals.
            $remainingVariableUses = [];
            foreach ($v->expr->items as $item) {
                if ($this->isVarExpr($item->value) && is_string($item->value->name)) {
                    $name = $this->parseIdentifier($item->value);
                    $remainingVariableUses[$name] = ($remainingVariableUses[$name] ?? 0) + 1;
                }
            }

            $tuple = $this->genTmpVarName();
            $lines = [$this->functionDef->getMultiReturnCppType() . ' ' . $tuple . ';'];
            foreach ($v->expr->items as $index => $item) {
                $value = $this->parseExprAsValue($item->value);
                if ($this->isVarExpr($item->value) && is_string($item->value->name)) {
                    $name = $this->parseIdentifier($item->value);
                    $remainingVariableUses[$name]--;
                    // Only consume a local on its final occurrence. Globals and
                    // statics outlive the function and must never be emptied.
                    if ($remainingVariableUses[$name] === 0 && $this->hasLocalVar($name)) {
                        $value = 'std::move(' . $value . ')';
                    }
                }
                $lines[] = 'std::get<' . $index . '>(' . $tuple . ') = ' . $value . ';';
            }
            $lines[] = 'return ' . $tuple . ';';
            return implode(PHP_EOL . $this->getIndent(), $lines);
        }
        // The return value of the actual function.
        $type = $this->detectTypeOfExpr($v->expr);
        // In ordinary PHP mode, int +/−/* int is only conditionally an int:
        // runtime overflow promotes the result to float. Keep the Variant
        // representation through the return boundary so a declared scalar
        // return type observes and rejects that float exactly as PHP does.
        // `use native_types` intentionally opts into native C++ arithmetic
        // semantics and is therefore excluded from this check.
        if (!$this->nativeTypes && $type === Type::INT && $this->exprCanOverflowInt($v->expr)) {
            $type = Type::VAR;
        }
        $nativeExpressionClass = $this->detectClassOfExpr($v->expr);
        if ($this->context->inClosure && $this->isNativeObjectClass($nativeExpressionClass)) {
            $this->fatalError($v, 'Zend closures cannot return native objects');
        }
        if (!$this->context->inClosure && $this->isNativeObjectClass($nativeExpressionClass)) {
            $declaredReturnClass = $this->getReturnClass();
            if (!$this->isNativeObjectClass($declaredReturnClass)) {
                if ($declaredReturnClass !== '' && $this->isInterface($declaredReturnClass)) {
                    $this->fatalError(
                        $v,
                        "Native objects cannot be returned as interface `{$declaredReturnClass}`",
                    );
                }
                $this->fatalError(
                    $v,
                    'Native object return values require an explicit native class return type',
                );
            }
        }
        if ($this->isCurrentConstructor() && !$this->context->inClosure) {
            $this->fatalError($v, 'Method `' . $this->getCurrentMethodDisplayName() . '()` cannot return a value');
        }
        if ($this->shouldCheckClosureReturnType()) {
            $this->checkCompositeTypeAssignment(
                $v,
                $this->context->closureReturnTypeCheck,
                $this->context->closureReturnTypeStr,
                $v->expr,
                'closure return value'
            );
        } elseif (!$this->context->inClosure && !empty($this->functionDef->returnTypeCheck)) {
            $this->checkCompositeTypeAssignment(
                $v,
                $this->functionDef->returnTypeCheck,
                $this->functionDef->returnTypeStr,
                $v->expr,
                'return value'
            );
        }
        if (
            !$this->context->inClosure
            && ($nativeReturnClass = $this->getReturnClass()) !== ''
            && $this->isNativeObjectClass($nativeReturnClass)
        ) {
            if ($this->isNull($v->expr)) {
                if (!$this->functionDef->returnNullable) {
                    $this->fatalError($v, "The return type is non-nullable native object `{$nativeReturnClass}`");
                }
                return 'return nullptr;';
            }
            $objectClass = $this->detectClassOfExpr($v->expr);
            if (
                $objectClass === '' || !$this->isNativeObjectClass($objectClass)
                || !$this->isObjectClassStaticallyAssignableTo($objectClass, $nativeReturnClass)
            ) {
                $this->fatalError(
                    $v,
                    "The return type is native object `{$nativeReturnClass}`, `{$objectClass}` given"
                );
            }
            $afterStmtCount = count($this->context->afterStmtLines);
            $returnExpr = $this->parseExprAsValue($v->expr);
            $returnCode = $this->functionDef->returnNullable
                ? $returnExpr
                : 'php::nativeRequireObject(' . $returnExpr . ', "'
                . addslashes($nativeReturnClass) . '")';

            if (count($this->context->afterStmtLines) === $afterStmtCount) {
                return 'return ' . $returnCode . ';';
            }

            // Cleanup emitted by the expression (for example restoring @'s
            // error_reporting state) must run before either the non-null
            // return check or the actual return. Materialize the pointer in a
            // precise root slot, then append the boundary operation after the
            // expression's cleanup statements.
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, $this->getNativeObjectPointerType($nativeReturnClass));
            $this->addNativeObject($tmpVar, $nativeReturnClass);
            $finalReturn = $this->functionDef->returnNullable
                ? $tmpVar
                : 'php::nativeRequireObject(' . $tmpVar . ', "'
                . addslashes($nativeReturnClass) . '")';
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $finalReturn . ';';
            return $tmpVar . ' = ' . $returnExpr . ';';
        }
        $expr = $this->parseExprAsValue($v->expr);
        $returnType = $this->getReturnType();

        // The return value of an anonymous function is always var.
        if (!$this->context->inClosure) {
            if ($returnType === Type::VOID) {
                $this->fatalError($v, 'The return type is void, cannot return any value');
            }
        } else {
            $returnType = Type::VAR;
        }

        if (
            !$this->context->inClosure
            && ($type === Type::VAR || $type === Type::REF)
            && $this->isStrictScalarType($returnType)
        ) {
            // Keep the zval type until the declared return boundary has been
            // checked. Converting first would silently coerce invalid values.
            $tmpVar = $this->addTmpVar(Type::VAR);
            $code = $tmpVar . ' = (' . $expr . ');' . PHP_EOL;
            $code .= $this->genStrictScalarReturnCheck($tmpVar, $returnType);
            $code .= $this->getIndent() . 'return '
                . $this->convertExprType($tmpVar, $returnType, Type::VAR) . ';';
            return $code;
        }

        $returnObjectCheckClass = '';
        // The return-value expression is an instance of a class.
        $objectClass = $this->detectDeclaredClassOfExpr($v->expr);
        $returnClass = $this->context->inClosure ? '' : $this->getReturnClass();
        if ($returnClass) {
            if ($objectClass === '') {
                $returnObjectCheckClass = $returnClass;
            } elseif (!$this->isObjectClassStaticallyAssignableTo($objectClass, $returnClass)) {
                if ($this->isKnownConcreteObjectExpr($v->expr, $objectClass)) {
                    $this->fatalError($v, 'The return type is `' . $returnClass . '`, cannot return an instance of `' . $objectClass . '`');
                }
                $returnObjectCheckClass = $returnClass;
            }
        }

        $exprCode = $this->convertExprType($expr, $returnType, $type);
        if ($returnObjectCheckClass !== '') {
            $exprCode = $this->convertObjectExpr($exprCode, $this->getClassEntryPtr($returnObjectCheckClass));
        }
        // Union/nullable return type: always use tmpVar for runtime check
        if ($this->shouldCheckClosureReturnType()) {
            [$code, $tmpVar] = $this->genClosureCheckedReturnAssignment($exprCode);
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } elseif ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
            [$code, $tmpVar] = $this->genUnionCheckedReturnAssignment($exprCode);
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } elseif (!$this->isVarExpr($v->expr) and !$this->isScalar($v->expr)) {
            // If return uses an Indirect statement, the variable may be
            // destructed early, producing a dangling pointer. Assign the
            // Indirect to a temporary variable; Ctor::Copy releases the
            // Indirect, guaranteeing memory safety.
            $tmpVar = $this->genTmpVarName();
            // The variable must be declared up front; otherwise declaring it at
            // the end and returning it could be optimized away by gcc.
            $this->addLocalVar($tmpVar, $returnType);
            $code = $tmpVar . ' = (' . $exprCode . ');' . PHP_EOL;
            // Parsing the expression may insert statements, so the return
            // statement must be appended at the end rather than returned directly.
            $this->context->afterStmtLines[] = $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code = 'return ' . $exprCode . ';';
        }

        return $code;
    }

    protected function getMultiReturnImplName(string $nativeName): string
    {
        return self::MULTI_RETURN_NAMESPACE . '::' . self::PREFIX . $nativeName;
    }

    protected function genClosureCheckedReturn(string $exprCode): string
    {
        [$code, $tmpVar] = $this->genClosureCheckedReturnAssignment($exprCode);
        return $code . $this->getIndent() . 'return ' . $tmpVar . ';';
    }

    protected function genClosureReturnValue(string $exprCode): string
    {
        if ($this->context->closureReturnTypeCheck) {
            return $this->genClosureCheckedReturn($exprCode);
        }

        return 'return ' . $exprCode . ';';
    }

    protected function genClosureReturnNull(): string
    {
        return $this->genClosureReturnValue(self::VALUE_NULL);
    }

    protected function genUnionCheckedReturn(string $exprCode): string
    {
        [$code, $tmpVar] = $this->genUnionCheckedReturnAssignment($exprCode);
        return $code . $this->getIndent() . 'return ' . $tmpVar . ';';
    }

    protected function genClosureCheckedReturnAssignment(string $exprCode): array
    {
        return $this->genCheckedReturnAssignment($exprCode, true);
    }

    protected function genUnionCheckedReturnAssignment(string $exprCode): array
    {
        return $this->genCheckedReturnAssignment($exprCode, false);
    }

    protected function genCheckedReturnAssignment(string $exprCode, bool $closure): array
    {
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, Type::VAR);
        $code = $tmpVar . ' = ' . $exprCode . ';' . PHP_EOL;
        $code .= $closure ? $this->genClosureReturnCheck($tmpVar) : $this->genUnionReturnCheck($tmpVar);

        return [$code, $tmpVar];
    }

    protected function shouldCheckClosureReturnType(): bool
    {
        return $this->context->inClosure && $this->context->closureReturnTypeCheck;
    }

    protected function checkNativeCallArgs(CallLike $expr, FunctionDef $funcDef, array $args, string $name): void
    {
        $this->validateNativeNamedCallArgs($funcDef, $args);

        if ($this->hasUnpackCallArg($args)) {
            return;
        }

        $argc = count($args);
        $type = str_contains($name, '::') ? 'Method' : 'Function';
        if ($argc < $funcDef->argCountRequired) {
            $this->fatalError($expr, $type . ' `' . $name . '()` requires ' . $funcDef->argCountRequired . ' arguments, ' . $argc . ' given');
        } elseif (!$funcDef->hasVariadicArg() and count($expr->args) > count($funcDef->argInfoList)) {
            $this->fatalError($expr, $type . ' `' . $name . '()` accepts ' . count($funcDef->argInfoList) . ' arguments, ' . $argc . ' given');
        }
    }

    protected function getFunctionArgNameIndex(FunctionDef $functionDef): array
    {
        $argNameIndex = [];
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            $argNameIndex[$argInfo->phpName ?: $this->unescapeVarName($argInfo->name)] = $k;
        }
        return $argNameIndex;
    }

    protected function getVariadicArgIndex(FunctionDef $functionDef): ?int
    {
        $lastIndex = count($functionDef->argInfoList) - 1;
        if ($lastIndex >= 0 and $functionDef->argInfoList[$lastIndex]->variadic) {
            return $lastIndex;
        }
        return null;
    }

    protected function validateNativeNamedCallArgs(FunctionDef $functionDef, array $callArgs): void
    {
        $hasNamedArg = false;
        $hasUnpack = false;
        $seenNamedArgs = [];
        $providedArgIndexes = [];
        $argNameIndex = $this->getFunctionArgNameIndex($functionDef);
        $variadicArgIndex = $this->getVariadicArgIndex($functionDef);

        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                continue;
            }
            if ($arg instanceof Node\Arg && $arg->unpack) {
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use argument unpacking after named arguments');
                }
                $hasUnpack = true;
                $providedArgIndexes[$i] = true;
                continue;
            }
            if ($arg->name === null) {
                if ($hasUnpack) {
                    $this->fatalError($arg, 'Cannot use positional argument after argument unpacking');
                }
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use positional argument after named argument');
                }
                $providedArgIndexes[$i] = true;
                continue;
            }
            if (!$this->isIdExpr($arg->name)) {
                $this->fatalError($arg, 'Named argument must be a string');
            }

            $argName = $arg->name->name;
            if (isset($seenNamedArgs[$argName])) {
                $this->fatalError($arg, "Duplicate named argument `{$argName}`");
            }
            if (!array_key_exists($argName, $argNameIndex)) {
                if ($variadicArgIndex === null) {
                    $this->fatalError($arg, "Unknown named argument `{$argName}`");
                }
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }

            $argIndex = $argNameIndex[$argName];
            if ($variadicArgIndex !== null and $argIndex === $variadicArgIndex) {
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }
            if (isset($providedArgIndexes[$argIndex])) {
                $this->fatalError($arg, "Named argument `{$argName}` overwrites previous argument");
            }

            $seenNamedArgs[$argName] = true;
            $providedArgIndexes[$argIndex] = true;
            $hasNamedArg = true;
        }
    }

    protected function getNativeMethod(CallLike $expr, string $class, string $method, bool $checkArgs = true): string|false
    {
        if (!$this->hasClass($class)) {
            return false;
        }

        $classDef = $this->getClass($class);
        $methodDef = null;
        // Search recursively: if the method is not defined in the child class,
        // try to find it in the parent class.
        while (true) {
            if (!$classDef->hasMethod($method)) {
                if (!$classDef->extends) {
                    return false;
                }
                if (!$this->hasClass($classDef->extends)) {
                    if ($classDef->inheritedFromInternalClass) {
                        if (!Reflection::hasMethod($classDef->extends, $method) and !Reflection::hasMethod($classDef->extends, $method . '__call')) {
                            $this->fatalError($expr, 'Class `' . $classDef->getNamespacedName() . '` inherits from a internal class, but the class `' .
                                $classDef->extends . '` does not have a `' . $method . '` method or a `__call` magic method');
                        } else {
                            $this->climate->cyan('Dynamically calling internal class method `' . $classDef->extends . '::' . $method . '()`');
                            throw new DynamicCall();
                        }
                    }
                    return false;
                }
                $classDef = $this->getClass($classDef->extends);
            } else {
                $methodDef = $classDef->getMethod($method);
                break;
            }
        }

        if (!$this->checkAccessible($classDef, $methodDef->flags)) {
            $this->fatalError($expr, 'Method `' . $classDef->getNamespacedName() . '::' . $method . '()` is not accessible');
        }
        // A function-call placeholder, not a real function call.
        if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
            return false;
        }
        if ($checkArgs) {
            $this->checkNativeCallArgs($expr, $methodDef->functionDef, $expr->args, $classDef->getNamespacedName() . '::' . $method);
        }
        return $this->getNativeName($method, $classDef->namespace, $classDef->name);
    }

    protected function findNativeClassConst(
        NodeAbstract $expr,
        string $class,
        string $const,
        ?string $accessingClass = null
    ): string|false {
        if (!$this->hasClass($class)) {
            return false;
        }

        $classDef = $this->getClass($class);
        $originClassDef = $classDef;
        $constDef = null;
        // Search recursively: if the constant is not defined in the child class,
        // try to find it in the parent class.
        while (true) {
            if (!$classDef->hasConstant($const)) {
                if (!$classDef->extends) {
                    break;
                }
                if (!$this->hasClass($classDef->extends)) {
                    break;
                }
                $classDef = $this->getClass($classDef->extends);
            } else {
                $constDef = $classDef->getConstant($const);
                break;
            }
        }
        if ($constDef === null) {
            foreach ($this->getClassImplementedInterfaces($originClassDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                if (!$interfaceDef->hasConstant($const)) {
                    continue;
                }
                $interfaceConstDef = $interfaceDef->constants[$const];
                if ($interfaceConstDef->type === Type::ARRAY) {
                    return self::PREFIX . $this->getNativeName($interfaceConstDef->name, $interfaceDef->namespace, $interfaceDef->name);
                }
                if (!$interfaceConstDef->codegenFinalized) {
                    return false;
                }
                $expr->setAttribute('nativeConst', $interfaceConstDef);
                return $interfaceConstDef->value;
            }
        }
        if ($constDef === null) {
            return false;
        }
        if (
            $classDef instanceof ClassDef
            && !$this->checkAccessibleByClassName(
                $classDef->getNamespacedName(false),
                $constDef->flags,
                $accessingClass,
            )
        ) {
            $this->fatalError($expr, 'Constant `' . $classDef->getNamespacedName() . '::' . $const . '` is not accessible');
        }
        if ($constDef->type === Type::ARRAY) {
            return self::PREFIX . $this->getNativeName($constDef->name, $classDef->namespace, $classDef->name);
        } else {
            // Forward constant references may be encountered while the
            // declaration-expression pass is still visiting another file.
            // Fall back to the Zend class-constant lookup instead of emitting
            // an incomplete value; the lookup is cached in the convert phase.
            if (!$constDef->codegenFinalized) {
                return false;
            }
            $expr->setAttribute('nativeConst', $constDef);
            return $constDef->value;
        }
    }

    /**
     * @return array<string>
     */
    protected function getClassImplementedInterfaces(ClassDef $classDef): array
    {
        $interfaces = [];
        $current = $classDef;
        while (true) {
            foreach ($current->implements as $interfaceName) {
                $this->collectInterfaceAndParents($interfaceName, $interfaces);
            }
            if (!$current->extends || !$this->hasClass($current->extends)) {
                break;
            }
            $current = $this->getClass($current->extends);
        }

        return array_values($interfaces);
    }

    /**
     * @param array<string, string> $interfaces
     */
    private function collectInterfaceAndParents(string $interfaceName, array &$interfaces): void
    {
        if (isset($interfaces[$interfaceName])) {
            return;
        }
        $interfaces[$interfaceName] = $interfaceName;
        if (!$this->hasInterface($interfaceName)) {
            return;
        }
        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentInterface) {
            $this->collectInterfaceAndParents($parentInterface, $interfaces);
        }
    }

    protected function detectVarType($var): string
    {
        // Unwrap ArrayDimFetch to get the underlying variable type;
        // the dim/index does not affect the base variable's type.
        if ($var instanceof Expr\ArrayDimFetch) {
            return $this->detectVarType($var->var);
        }
        $name = $this->parseIdentifier($var);
        if ($this->isStdContainer($name)) {
            return Type::ARRAY;
        }
        return $this->getVarType($name);
    }

    protected function detectTypeOfExpr($expr): string
    {
        if ($expr instanceof Expr\ErrorSuppress) {
            return $this->detectTypeOfExpr($expr->expr);
        }
        if ($expr instanceof Expr\MethodCall && $this->isNamedMethod($expr->name)) {
            $keywordType = $this->findKeywordMethod($this->parseIdentifier($expr->name));
            if ($keywordType !== null) {
                return $keywordType;
            }
        }
        $pythonOperatorType = $this->detectPythonOperatorReturnType($expr);
        if ($pythonOperatorType !== null) {
            return $pythonOperatorType;
        }
        $pythonType = $this->detectPythonExpressionReturnType($expr);
        if ($pythonType !== null) {
            return $pythonType;
        }

        $exprType = $expr->getType();
        switch ($exprType) {
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                $innerType = $this->detectTypeOfExpr($expr->expr);
                if (
                    !$this->nativeTypes
                    && $exprType === 'Expr_UnaryMinus'
                    && $innerType === Type::INT
                    && $this->constantIntValue($expr->expr) === PHP_INT_MIN
                ) {
                    return Type::FLOAT;
                }
                return $innerType;
            case 'Expr_BooleanNot':
            case 'Expr_BinaryOp_LogicalAnd':
            case 'Expr_BinaryOp_BooleanAnd':
            case 'Expr_BinaryOp_LogicalOr':
            case 'Expr_BinaryOp_BooleanOr':
            case 'Expr_BinaryOp_LogicalXor':
            case 'Expr_BinaryOp_Equal':
            case 'Expr_BinaryOp_NotEqual':
            case 'Expr_BinaryOp_Identical':
            case 'Expr_BinaryOp_NotIdentical':
            case 'Expr_BinaryOp_Smaller':
            case 'Expr_BinaryOp_SmallerOrEqual':
            case 'Expr_BinaryOp_Greater':
            case 'Expr_BinaryOp_GreaterOrEqual':
                return Type::BOOL;
            case 'Expr_BitwiseNot':
                $inner = $this->detectTypeOfExpr($expr->expr);
                return $inner === Type::BIGINT ? Type::BIGINT : Type::INT;
            case 'Expr_Print':
            case 'Expr_Cast_Int':
                return Type::INT;
            case 'Scalar_Int':
                return $this->bigintTypes ? Type::BIGINT : Type::INT;
            case 'Expr_Cast_Float':
            case 'Expr_Cast_Double':
                return Type::FLOAT;
            case 'Scalar_Float':
                if ($this->isBigIntLiteral($expr)) {
                    return Type::BIGINT;
                }
                if ($this->isDecimalLiteral($expr) || $this->decimalTypes) {
                    return Type::DECIMAL;
                }
                return Type::FLOAT;
            case 'Expr_Cast_Bool':
            case 'Scalar_Bool':
                return Type::BOOL;
            case 'Expr_Cast_Void':
                return Type::VOID;
            case 'Expr_Array':
            case 'Expr_Cast_Array':
                return Type::ARRAY;
            case 'Expr_BinaryOp_Concat':
            case 'Expr_AssignOp_Concat':
                return Type::STR;
            case 'Expr_Ternary':
                $ifType = $expr->if === null
                    ? $this->detectTypeOfExpr($expr->cond)
                    : $this->detectTypeOfExpr($expr->if);
                $elseType = $this->detectTypeOfExpr($expr->else);
                return $ifType === $elseType ? $ifType : Type::VAR;
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
            case 'Expr_BinaryOp_Mul':
            case 'Expr_BinaryOp_Div':
            case 'Expr_BinaryOp_Mod':
            case 'Expr_BinaryOp_Pow':
            case 'Expr_BinaryOp_ShiftLeft':
            case 'Expr_BinaryOp_ShiftRight':
            case 'Expr_BinaryOp_BitwiseAnd':
            case 'Expr_BinaryOp_BitwiseOr':
            case 'Expr_BinaryOp_BitwiseXor':
                $leftType  = $this->detectTypeOfExpr($expr->left);
                $rightType = $this->detectTypeOfExpr($expr->right);
                if ($leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
                    return Type::BIGFLOAT;
                }
                if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
                    return Type::DECIMAL;
                }
                if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
                    if ($exprType === 'Expr_BinaryOp_Div') {
                        // BigInt division produces BigInt (integer division); BigDecimal in future
                        return Type::BIGINT;
                    }
                    return Type::BIGINT;
                }
                if ($leftType === Type::FLOAT || $rightType === Type::FLOAT) {
                    return Type::FLOAT;
                }
                if (!$this->nativeTypes && $leftType === Type::INT && $rightType === Type::INT) {
                    $op = match ($exprType) {
                        'Expr_BinaryOp_Plus' => '+',
                        'Expr_BinaryOp_Minus' => '-',
                        'Expr_BinaryOp_Mul' => '*',
                        'Expr_BinaryOp_Div' => '/',
                        'Expr_BinaryOp_Mod' => '%',
                        default => null,
                    };
                    if ($op !== null) {
                        $evaluation = $this->evaluateConstantIntArithmetic($expr->left, $expr->right, $op);
                        if ($evaluation !== null && is_float($evaluation['result'])) {
                            return Type::FLOAT;
                        }
                        // Runtime integer division has a value-dependent PHP
                        // result: exact quotients are int, fractional quotients
                        // and PHP_INT_MIN / -1 are float. Keep it boxed when the
                        // operands are not compile-time constants.
                        if ($exprType === 'Expr_BinaryOp_Div' && $evaluation === null) {
                            return Type::VAR;
                        }
                    }
                }
                if ($leftType === Type::INT || $rightType === Type::INT) {
                    return Type::INT;
                }
                break;
            case 'Expr_FuncCall':
                if ($this->isNameExpr($expr->name)) {
                    $name = $this->parseIdentifier($expr->name);
                    $globalName = ltrim($name, '\\');
                    // Math function optimization: propagate Big* return types
                    if (in_array($name, ['abs', 'pow', 'sqrt', 'floor', 'ceil', 'round'], true) && !empty($expr->args)) {
                        $argType = $this->detectTypeOfExpr($expr->args[0]->value);
                        if (
                            $argType === Type::BIGINT
                            && in_array($name, ['abs', 'pow', 'sqrt'], true)
                        ) {
                            return Type::BIGINT;
                        }
                        if (
                            $argType === Type::DECIMAL
                            && in_array($name, ['abs', 'pow', 'sqrt', 'floor', 'ceil', 'round'], true)
                        ) {
                            return Type::DECIMAL;
                        }
                        if (
                            $argType === Type::BIGFLOAT
                            && in_array($name, ['abs', 'sqrt'], true)
                        ) {
                            return Type::BIGFLOAT;
                        }
                    }
                    if (in_array($name, self::STREAM_FUNCTIONS)) {
                        return Type::STREAM;
                    }
                    if ($globalName === 'expected' || $globalName === 'unexpected') {
                        return Type::BOOL;
                    }
                    if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                        return Type::OBJECT;
                    }
                    if ($this->hasFunction($name)) {
                        return $this->getFunction($name)->returnType;
                    }
                    return $this->detectFuncCallReturnType($name);
                }
                break;
            case 'Expr_MethodCall':
                if ($this->isNamedMethod($expr->name)) {
                    $method = $this->parseIdentifier($expr->name);
                    // Class definition resolution (handles this_, typed VarExpr)
                    $classDef = $this->resolveObjectClassDef($expr->var);
                    if ($classDef !== null && $classDef->hasMethod($method)) {
                        if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                            return Type::OBJECT;
                        }
                        return $classDef->getMethod($method)->getReturnType();
                    }
                    if ($this->isVarExpr($expr->var)) {
                        $object = $this->parseIdentifier($expr->var);
                        try {
                            $nativeFunc = $this->findNativeMethod($expr, $object, $method);
                            if ($nativeFunc) {
                                $funcDef = $this->getFunction($nativeFunc);
                                return $funcDef->returnType;
                            }
                        } catch (DynamicCall) {
                            // Method inherited from internal class, can't resolve type statically
                        }
                        if ($this->isTypedObject($object)) {
                            return $this->detectMethodCallReturnType($this->getObjectType($object), $method);
                        }
                        $type = $this->getVarType($object);
                    } else {
                        $type = $this->detectTypeOfExpr($expr->var);
                    }
                    if ($type !== Type::VAR && !$this->checkArgType($type, Type::OBJECT)) {
                        $retType = $this->detectUniversalMethodReturnType($type, $method);
                        if ($retType !== null) {
                            return $retType;
                        }
                    }
                }
                break;
            case 'Expr_StaticCall':
                if ($this->isNameExpr($expr->class) && $this->isIdExpr($expr->name)) {
                    // First-class callable syntax creates a Closure, not a method return value
                    if (count($expr->args) === 1 and $this->isPlaceholderExpr($expr->args[0])) {
                        return Type::OBJECT;
                    }
                    $className = $this->parseIdentifier($expr->class);
                    if (strtolower($className) === 'std') {
                        $method = strtolower($this->parseIdentifier($expr->name));
                        return match ($method) {
                            'int' => Type::INT,
                            'float' => Type::FLOAT,
                            'bool' => Type::BOOL,
                            'bigint' => Type::BIGINT,
                            'decimal' => Type::DECIMAL,
                            'bigfloat' => Type::BIGFLOAT,
                            default => Type::VAR,
                        };
                    }
                    if ($className === 'self') {
                        $className = $this->getFullClassName();
                    } elseif ($className === 'parent') {
                        if ($this->classDef->extends) {
                            $className = $this->classDef->extends;
                        } else {
                            break;
                        }
                    } elseif ($className === 'static') {
                        break;
                    } else {
                        $className = $this->getNamespacedClassName($className);
                    }
                    if ($this->hasClass($className)) {
                        $classDef = $this->getClass($className);
                        $methodName = $this->parseIdentifier($expr->name);
                        if ($classDef->hasMethod($methodName)) {
                            return $classDef->getMethod($methodName)->getReturnType();
                        }
                    }
                }
                break;
            case 'Expr_PropertyFetch':
                if ($this->isIdExpr($expr->name)) {
                    // Class definition property type
                    $propName = $this->parseIdentifier($expr->name);
                    $classDef = $this->resolveObjectClassDef($expr->var);
                    if ($classDef !== null && $classDef->hasProperty($propName)) {
                        return $classDef->getProperty($propName)->type;
                    }
                    // Native property var type
                    if ($this->isVarExpr($expr->var)) {
                        $this->parsePropertyFetch($expr);
                        $propVar = $this->getNativePropertyVar($expr);
                        if ($propVar !== null) {
                            $info = $this->getObjectPropInfoByVar($propVar);
                            if ($info !== null) {
                                return $info['type'];
                            }
                        }
                    }
                }
                break;
            case 'Expr_StaticPropertyFetch':
                if ($this->isIdExpr($expr->name)) {
                    if (!$this->getNativePropertyDef($expr)) {
                        $this->resolveNativeStaticPropertyFetch($expr);
                    }
                    $def = $this->getNativePropertyDef($expr);
                    if ($def) {
                        return $def->type;
                    }
                }
                break;
            case 'Expr_ClassConstFetch':
                if (
                    $this->isIdExpr($expr->name)
                    && strtolower($this->parseIdentifier($expr->name)) === 'class'
                ) {
                    return Type::STR;
                }
                break;
            case 'Expr_ArrayDimFetch':
                if ($this->isStdArrayExpr($expr)) {
                    if (!$expr->hasAttribute('stdArrayDimFetch')) {
                        $this->parseStdArrayDimFetch($expr);
                    }
                    $attr = $expr->getAttribute('stdArrayDimFetch');
                    if ($attr['accessLevel'] === $attr['totalLevel']) {
                        return $this->context->stdArrays[$attr['var']]['type'];
                    } else {
                        return Type::ARRAY;
                    }
                }
                if ($this->isStdContainerExpr($expr)) {
                    if (!$expr->hasAttribute('stdContainerDimFetch')) {
                        $this->parseStdContainerDimFetch($expr);
                    }
                    $attr = $expr->getAttribute('stdContainerDimFetch');
                    return $this->context->stdContainers[$attr['var']]['type'];
                }
                break;
            case 'Expr_New':
                return Type::OBJECT;
            case 'Expr_Assign':
            case 'Expr_AssignOp_BitwiseAnd':
            case 'Expr_AssignOp_BitwiseOr':
            case 'Expr_AssignOp_BitwiseXor':
                return $this->detectVarType($expr->var);
            case 'Expr_Variable':
                return $this->detectVarType($expr);
            case 'Expr_ConstFetch':
                return $this->detectConstType($expr);
            case 'Scalar_String':
                return Type::STR;
            default:
                break;
        }

        return Type::VAR;
    }

    protected function genDynamicPropIncDec($var, string $op, bool $isPre): ?string
    {
        if (!$this->isPropertyFetch($var)) {
            return null;
        }

        $target = $this->preparePropertyWriteTarget($var);
        if ($this->isNativeObjectPropertyHook($var)) {
            $this->fatalError(
                $var,
                'Native property hooks only support direct reads and assignments',
            );
        }
        $getter = $this->getPropertyHookGetter($var);
        $setter = $this->getPropertyHookSetter($var);
        if ($getter !== null && $setter === null) {
            $this->fatalError($var, 'Cannot write to read-only hooked property');
        }
        if ($getter !== null && $setter !== null) {
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, Type::VAR);
            $read = $this->emitPropertyHookGetterCall($var, $getter);
            if ($isPre) {
                $set = $this->emitPropertyHookSetterCall($var, $setter, new Expr\Variable($tmpVar));
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$read} {$op} 1; {$set};";
            } else {
                $nextVar = $this->genTmpVarName();
                $this->addLocalVar($nextVar, Type::VAR);
                $set = $this->emitPropertyHookSetterCall($var, $setter, new Expr\Variable($nextVar));
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$read};";
                $this->context->afterStmtLines[] = "{$nextVar} = {$tmpVar} {$op} 1; {$set};";
            }
            return $tmpVar;
        }
        if ($this->isNativePropertyAccess($var)) {
            return null;
        }

        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, Type::VAR);
        if ($isPre) {
            $this->context->beforeStmtLines[] = "{$tmpVar} = " . $this->emitDynamicPropertyFetchRead($var, $target) . " {$op} 1; " . $this->emitDynamicPropertyFetchWrite($var, $tmpVar, $target) . ';';
        } else {
            $this->context->beforeStmtLines[] = "{$tmpVar} = " . $this->emitDynamicPropertyFetchRead($var, $target) . ';';
            $this->context->afterStmtLines[] = $this->emitDynamicPropertyFetchWrite($var, "{$tmpVar} {$op} 1", $target) . ';';
        }

        return $tmpVar;
    }

    protected function parsePreInc(Expr\PreInc $expr): string
    {
        $this->assertImmutableMutationTarget($expr->var);
        $this->assertNativeArrayAccessDirectWrite($expr->var, false);
        $this->assertNativeObjectOperatorOperandSupported($expr->var, $expr, '++');
        $this->assertNotNullsafeWriteContext($expr->var);
        $this->assertNativePropertyHookDirectWriteTarget($expr->var);
        $result = $this->genDynamicPropIncDec($expr->var, '+', true);
        if ($result !== null) {
            return $result;
        }

        $type = $this->detectVarType($expr->var);
        if ($type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
            $this->fatalError($expr, 'Cannot use ++ on ' . $type . '. Use += 1 instead (Big* types are immutable).');
        }
        $var = $this->parseWritableIdentifier($expr->var);
        if ($this->isVarExpr($expr->var) && !$this->hasVar($var)) {
            $this->errorUndefinedVariable($expr->var);
        }
        return '++' . $var;
    }

    /**
     * Resolve a PHP function name to its native (compiled) name by trying
     * every candidate form: absolute names, qualified names resolved through
     * the class/namespace import table, unqualified names in the current
     * namespace, and `use function` imports. Returns false when no compiled
     * function matches.
     */
    protected function findNativeFunction(string $funcName): string|false
    {
        // Absolutely-qualified function name.
        if ($funcName[0] == '\\') {
            $funcName = ltrim($funcName, '\\');
            $possibleFunctionNames = [$this->escapeName($funcName)];
        } elseif (str_contains($funcName, '\\')) {
            // Qualified function names use the class/namespace import table
            // for their first segment, just like qualified class names.
            $possibleFunctionNames = [
                $this->escapeName($this->getNamespacedClassName($funcName)),
            ];
        } else {
            $possibleFunctionNames = [$this->escapeName($funcName)];
            if ($this->namespace) {
                $possibleFunctionNames[] = $this->escapeNamespace($this->namespace) . self::NAMESPACE_SEPARATOR . $this->escapeName($funcName);
            }
            if (isset($this->useFunctions[$funcName])) {
                $possibleFunctionNames[] = $this->escapeNamespace($this->useFunctions[$funcName]);
            }
        }

        foreach ($possibleFunctionNames as $nativeFunc) {
            if (str_contains($nativeFunc, '\\')) {
                $nativeFunc = $this->escapeNamespace($nativeFunc);
            }
            $this->checkFunction($nativeFunc);
            if ($this->hasFunction($nativeFunc) && !$this->getFunction($nativeFunc)->method) {
                return $nativeFunc;
            }
        }

        return false;
    }

    protected function checkInternalFunctionArgCount(string $funcName, Node\Expr\FuncCall $expr): void
    {
        $ref = Reflection::getFunction($funcName);
        if (!$ref) {
            return;
        }
        $this->validateInternalNamedCallArgs($ref, $expr->args);
        if ($this->hasUnpackCallArg($expr->args)) {
            return;
        }
        $minArgs = $ref->getNumberOfRequiredParameters();
        $maxArgs = $ref->getNumberOfParameters();
        $actualArgCount = count($expr->args);
        if ($minArgs > 0 && $actualArgCount < $minArgs) {
            $this->fatalError($expr, "{$funcName}() expects at least {$minArgs} argument(s), {$actualArgCount} given");
        }
        if (!$ref->isVariadic() && $maxArgs > 0 && $actualArgCount > $maxArgs) {
            $this->fatalError($expr, "{$funcName}() expects at most {$maxArgs} argument(s), {$actualArgCount} given");
        }
    }

    protected function hasUnpackBeforeNamedArg(array $args): bool
    {
        $hasUnpack = false;
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            if ($arg->unpack) {
                $hasUnpack = true;
            } elseif ($hasUnpack && $arg->name !== null) {
                return true;
            }
        }
        return false;
    }

    protected function hasUnpackCallArg(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg && $arg->unpack) {
                return true;
            }
        }
        return false;
    }

    protected function shouldUseDynamicCallForNativeArgs(string $nativeFunc, array $args): bool
    {
        if (!$this->hasUnpackCallArg($args)) {
            return false;
        }
        if ($this->hasUnpackBeforeNamedArg($args)) {
            return true;
        }

        $variadicArgIndex = $this->getVariadicArgIndex($this->getFunction($nativeFunc));
        foreach ($args as $i => $arg) {
            if (!$arg instanceof Node\Arg || !$arg->unpack) {
                continue;
            }
            if ($variadicArgIndex === null || $i < $variadicArgIndex) {
                return true;
            }
        }
        return false;
    }

    protected function genRuntimeFunctionCall(
        string $callable,
        array $args,
        string $funcName = '',
        string $className = '',
        bool $separateNamedArgs = true,
        string $scope = '',
    ): string {
        $scopeArg = $scope === '' ? '' : $scope . ', ';
        return 'php::call(' . $scopeArg . $callable . ', '
            . $this->parseCallArgs($args, $funcName, $className, $separateNamedArgs) . ')';
    }

    protected function genRuntimeObjectMethodCall(
        string $object,
        string $method,
        array $args,
        string $funcName = '',
        string $className = '',
        bool $requiresDynamicScope = true,
    ): string {
        $callArgs = $this->parseCallArgs($args, $funcName, $className);
        if ($requiresDynamicScope && $this->methodDef) {
            return 'php::callScoped(' . $object . ', ' . $method . ', ' . $this->getCallableScopeExpr() . ', ' . $callArgs . ')';
        }
        return $object . '.call(' . $method . ', ' . $callArgs . ')';
    }

    /** Reserve one reusable Zend user-code frame for scoped dynamic callbacks. */
    protected function markUserCodeCallableScope(): void
    {
        if ($this->methodDef) {
            // This state belongs to the generated function body, not to its
            // MethodDef. A method and each nested Closure have independent
            // FunctionContext instances and therefore independent guards.
            $this->context->needsUserCodeCallableScope = true;
            // Reserve the declaration before the generated function prologue
            // is emitted. The guard itself is inserted after parsing the body.
            $this->getCallableScopeExpr();
        }
    }

    /**
     * Mark PHP internal functions that synchronously invoke a user callback.
     * Closures and first-class callables already retain their creation scope.
     *
     * @param array<Node\Arg> $args
     */
    protected function markInternalFunctionCallbackCall(string $function, array $args): void
    {
        if (!$this->methodDef || $args === []) {
            return;
        }

        /**
         * Reflection metadata is deliberately not used here. This code is also
         * compiled by TypePHP itself, so the result must not depend on how a
         * particular PHP build exposes callable parameter types.
         *
         * Each entry identifies callback arguments by their positional index,
         * PHP named-argument name and optional handling strategy. Negative
         * indexes count from the end. The default strategy is `callable`.
         */
        static $callbackArgs = [
            'array_map' => [[0, 'callback']],
            'array_filter' => [[1, 'callback']],
            'array_reduce' => [[1, 'callback']],
            'array_all' => [[1, 'callback']],
            'array_any' => [[1, 'callback']],
            'array_find' => [[1, 'callback']],
            'array_find_key' => [[1, 'callback']],
            'array_walk' => [[1, 'callback']],
            'array_walk_recursive' => [[1, 'callback']],
            'usort' => [[1, 'callback']],
            'uasort' => [[1, 'callback']],
            'uksort' => [[1, 'callback']],
            'call_user_func' => [[0, 'callback', 'dynamic']],
            'call_user_func_array' => [[0, 'callback', 'dynamic']],
            'forward_static_call' => [[0, 'callback']],
            'forward_static_call_array' => [[0, 'callback']],
            'preg_replace_callback' => [[1, 'callback']],
            // Zend resolves every callback stored in the map. Preserve the
            // original array and expose the method scope once at function
            // entry instead of scanning/copying it into fake Closures.
            'preg_replace_callback_array' => [[0, 'pattern', 'dynamic-map']],
            'iterator_apply' => [[1, 'callback']],
            'array_udiff' => [[-1, 'value_compare_func']],
            'array_udiff_assoc' => [[-1, 'value_compare_func']],
            'array_uintersect' => [[-1, 'value_compare_func']],
            'array_uintersect_assoc' => [[-1, 'value_compare_func']],
            'array_diff_uassoc' => [[-1, 'key_compare_func']],
            'array_diff_ukey' => [[-1, 'key_compare_func']],
            'array_intersect_uassoc' => [[-1, 'key_compare_func']],
            'array_intersect_ukey' => [[-1, 'key_compare_func']],
            'array_udiff_uassoc' => [
                [-2, 'value_compare_func'],
                [-1, 'key_compare_func'],
            ],
            'array_uintersect_uassoc' => [
                [-2, 'value_compare_func'],
                [-1, 'key_compare_func'],
            ],
        ];

        $descriptors = $callbackArgs[strtolower(ltrim($function, '\\'))] ?? null;
        if ($descriptors === null) {
            return;
        }

        $firstStrategy = $descriptors[0][2] ?? 'callable';
        $fullyDynamic = $firstStrategy === 'dynamic';
        $dynamicMap = $firstStrategy === 'dynamic-map';
        if ($fullyDynamic || $dynamicMap) {
            $this->markUserCodeCallableScope();
        }
        $argCount = count($args);
        $matchedCallbacks = [];
        $hasUnpackedArg = false;
        foreach ($args as $index => $arg) {
            if ($arg->unpack) {
                $hasUnpackedArg = true;
                if ($fullyDynamic) {
                    $arg->setAttribute(self::ATTR_SCOPED_CALLBACK, 'normalize-unpacked');
                }
                continue;
            }

            foreach ($descriptors as $descriptorIndex => $descriptor) {
                [$position, $name] = $descriptor;
                $strategy = $descriptor[2] ?? 'callable';
                $scopeProvidedByGuard = $strategy === 'dynamic-map';
                $callbackPosition = $position < 0 ? $argCount + $position : $position;
                $matches = $arg->name === null
                    ? $index === $callbackPosition
                    : $arg->name->toString() === $name;
                if ($matches) {
                    $matchedCallbacks[$descriptorIndex] = true;
                    if (!$scopeProvidedByGuard && !$this->isScopeIndependentCallableExpr($arg->value)) {
                        $mode = $strategy === 'dynamic' ? 'normalize' : $strategy;
                        $arg->setAttribute(self::ATTR_SCOPED_CALLBACK, $mode);
                    }
                    // Some functions accept more than one callback (for
                    // example array_udiff_uassoc()). Mark every matching
                    // argument instead of stopping at the first one.
                    break;
                }
            }
        }

        if (!$fullyDynamic && $hasUnpackedArg && count($matchedCallbacks) !== count($descriptors)) {
            // If the unpacked value itself supplies a callback, its runtime
            // position is not known here. Retain a reusable user-code scope
            // guard for this complex case; ordinary callback arguments use
            // an explicit CallableScope instead.
            $this->markUserCodeCallableScope();
        }
    }

    private function isScopeIndependentCallableExpr(Expr $expr): bool
    {
        return $expr instanceof Expr\Closure
            || $expr instanceof Expr\ArrowFunction
            || ($expr instanceof CallLike && $expr->isFirstClassCallable())
            || $this->isNull($expr);
    }

    protected function validateInternalNamedCallArgs(\ReflectionFunctionAbstract $ref, array $callArgs): void
    {
        $hasNamedArg = false;
        $hasUnpack = false;
        $seenNamedArgs = [];
        $providedArgIndexes = [];
        $argNameIndex = [];
        $requiredArgIndexes = [];
        $variadicArgIndex = null;

        foreach ($ref->getParameters() as $i => $param) {
            $argNameIndex[$param->getName()] = $i;
            if (!$param->isOptional() && !$param->isVariadic()) {
                $requiredArgIndexes[$i] = $param->getName();
            }
            if ($param->isVariadic()) {
                $variadicArgIndex = $i;
            }
        }

        foreach ($callArgs as $i => $arg) {
            if ($this->isPlaceholderExpr($arg)) {
                continue;
            }
            if ($arg instanceof Node\Arg && $arg->unpack) {
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use argument unpacking after named arguments');
                }
                $hasUnpack = true;
                $providedArgIndexes[$i] = true;
                continue;
            }
            if ($arg->name === null) {
                if ($hasUnpack) {
                    $this->fatalError($arg, 'Cannot use positional argument after argument unpacking');
                }
                if ($hasNamedArg) {
                    $this->fatalError($arg, 'Cannot use positional argument after named argument');
                }
                $providedArgIndexes[$i] = true;
                continue;
            }
            if (!$this->isIdExpr($arg->name)) {
                $this->fatalError($arg, 'Named argument must be a string');
            }

            $argName = $arg->name->name;
            if (isset($seenNamedArgs[$argName])) {
                $this->fatalError($arg, "Duplicate named argument `{$argName}`");
            }
            if (!array_key_exists($argName, $argNameIndex)) {
                if ($variadicArgIndex === null) {
                    $this->fatalError($arg, "Unknown named argument `{$argName}`");
                }
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }

            $argIndex = $argNameIndex[$argName];
            if ($variadicArgIndex !== null && $argIndex === $variadicArgIndex) {
                $seenNamedArgs[$argName] = true;
                $hasNamedArg = true;
                continue;
            }
            if (isset($providedArgIndexes[$argIndex])) {
                $this->fatalError($arg, "Named argument `{$argName}` overwrites previous argument");
            }

            $seenNamedArgs[$argName] = true;
            $providedArgIndexes[$argIndex] = true;
            $hasNamedArg = true;
        }

        if ($hasNamedArg && !$hasUnpack) {
            foreach ($requiredArgIndexes as $index => $name) {
                if (!isset($providedArgIndexes[$index])) {
                    $this->fatalError($callArgs[array_key_last($callArgs)] ?? null, "Named argument `{$name}` is missing default value");
                }
            }
        }
    }

    /**
     * PHP 8.5 pipe operator: $value |> $callable.
     *
     * The left operand is evaluated first and passed as the single value
     * argument to the callable on the right. Materialising the left operand
     * also avoids relying on C++ argument-evaluation order.
     */

    protected function parsePostOp(Expr\PostDec|Expr\PostInc $expr, string $op): string
    {
        $this->assertImmutableMutationTarget($expr->var);
        $this->assertNativeArrayAccessDirectWrite($expr->var, false);
        $this->assertNativeObjectOperatorOperandSupported($expr->var, $expr, str_repeat($op, 2));
        $this->assertNotNullsafeWriteContext($expr->var);
        $this->assertNativePropertyHookDirectWriteTarget($expr->var);
        $result = $this->genDynamicPropIncDec($expr->var, $op, false);
        if ($result !== null) {
            return $result;
        }

        if ($this->isVarExpr($expr->var) or $this->isPropertyFetch($expr->var) or $this->isArrayDimFetch($expr->var)) {
            $var = $this->parseWritableIdentifier($expr->var);
            if ($this->isVarExpr($expr->var) and !$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->var);
            }
            $type = $this->detectVarType($expr->var);
            if ($type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
                $opName = $op === '+' ? '++' : '--';
                $this->fatalError($expr, "Cannot use {$opName} on {$type}. Use " . ($op === '+' ? '+= 1' : '-= 1') . ' instead (Big* types are immutable).');
            }
            return $var . str_repeat($op, 2);
        }
        if ($this->isStaticPropertyFetch($expr->var)) {
            $native = $this->parseNativeStaticPropertyFetch($expr->var);
            if ($native !== null) {
                return $native . str_repeat($op, 2);
            }

            $class = $this->identifierToStr($expr->var->class);
            $prop = $this->propertyNameToStr($expr->var->name);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, Type::VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . Symbol::getStaticProperty() . '(' . $class . ', ' . $prop . ');';
            $this->context->afterStmtLines[] = Symbol::setStaticProperty() . '(' . $class . ', ' . $prop . ', ' . $tmpVar . ' ' . $op . ' 1);';

            return $tmpVar;
        }
        $this->fatalError($expr, 'Post-increment operator is not supported for non-variable expressions');
    }

    protected function parsePostDec(Expr\PostDec $expr): string
    {
        return $this->parsePostOp($expr, '-');
    }

    protected function parsePostInc(Expr\PostInc $expr): string
    {
        return $this->parsePostOp($expr, '+');
    }

    protected function parsePreDec(Expr\PreDec $expr): string
    {
        $this->assertImmutableMutationTarget($expr->var);
        $this->assertNativeArrayAccessDirectWrite($expr->var, false);
        $this->assertNativeObjectOperatorOperandSupported($expr->var, $expr, '--');
        $this->assertNotNullsafeWriteContext($expr->var);
        $this->assertNativePropertyHookDirectWriteTarget($expr->var);
        $result = $this->genDynamicPropIncDec($expr->var, '-', true);
        if ($result !== null) {
            return $result;
        }

        $type = $this->detectVarType($expr->var);
        if ($type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
            $this->fatalError($expr, 'Cannot use -- on ' . $type . '. Use -= 1 instead (Big* types are immutable).');
        }
        $var = $this->parseWritableIdentifier($expr->var);
        if ($this->isVarExpr($expr->var) && !$this->hasVar($var)) {
            $this->errorUndefinedVariable($expr->var);
        }
        return '--' . $var;
    }

    protected function parsePrint(Expr\Print_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'print operand');
        if ($this->isNativeObjectClass($this->detectClassOfExpr($expr->expr))) {
            return 'php::print(' . $this->parseExprToString($expr->expr) . ')';
        }
        return 'php::print(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function formatCppLineComment(string $label, string $text): string
    {
        if (!$this->debug) {
            return '';
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $padding = str_repeat(' ', strlen($label));
        $comments = [];
        foreach ($lines as $i => $line) {
            $comments[] = '// ' . ($i === 0 ? $label : $padding) . $line;
        }
        return implode(PHP_EOL, $comments);
    }

    protected function packData(string $bytes): string
    {
        $out = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $out .= ord($bytes[$i]) . ', ';
            if ($i % 32 == 0) {
                $out .= "\n\t";
            }
        }
        $out .= '0,';
        return $out;
    }

    protected function addConstData(string $name, string $bytes): void
    {
        $this->constData[$name] = $this->packData($bytes);
    }

    protected function parseNew(Expr\New_ $expr): string
    {
        $this->validateImmutableCall($expr);
        if (!$expr->class instanceof Node\Stmt\Class_ && !$this->isNameExpr($expr->class)) {
            $this->assertNotNativeObjectDynamicClassTarget($expr->class, $expr);
        }
        $ctorClassName = '';
        // Anonymous class.
        if ($expr->class instanceof Node\Stmt\Class_) {
            if ($expr->class->name === null) {
                $classDef = $expr->class;
                $className = $this->genAnonClassName();
                $classDef->name = new Node\Identifier($className);
                // The inherited parent class and interfaces may be `use` names
                // and need to be converted to fully-qualified names.
                if ($classDef->extends !== null) {
                    $parentClass = $this->getNamespacedClassName($this->parseIdentifier($classDef->extends));
                    $classDef->extends = new Node\Name\FullyQualified($parentClass);
                }
                if (!empty($classDef->implements)) {
                    foreach ($classDef->implements as $i => $iface) {
                        $ifaceName = $this->getNamespacedClassName($this->parseIdentifier($iface));
                        $classDef->implements[$i] = new Node\Name\FullyQualified($ifaceName);
                    }
                }
                $this->flattenEmbeddedClassTraits($classDef);
                // Anonymous classes are defined by eval in the root namespace,
                // so symbols imported inside them must be converted to
                // fully-qualified names.
                $this->resolveAnonClassNames($classDef);
                $this->context->beforeStmtLines[] = 'static THREAD_LOCAL bool ' . $className . '_defined = false;';
                $classCode = $this->genEmbeddedCode($classDef);
                $this->addConstData($className . '_code', $classCode);
                $this->context->beforeStmtLines[] = 'if (!' . $className . '_defined) {'
                    . $className . '_defined = true; php::eval((const char *)' . $className . '_code);}';
                $className = '\\' . $className;
                $cePtr     = $this->getClassEntryPtr($className);
                $ctorClassName = $className;
            } else {
                $this->fatalError($expr, 'must be anonymous class');
            }
        } else {
            $className = $this->parseIdentifier($expr->class);
            if ($this->isNameExpr($expr->class)) {
                if ($className === 'static') {
                    if ($this->classDef?->nativeObject) {
                        $this->fatalError($expr, 'Native classes do not support `new static()`');
                    }
                    $cePtr = $this->getCalledCeExpr();
                } else {
                    if ($className === 'self') {
                        $className = $this->getFullClassName();
                    } elseif ($className === 'parent') {
                        if (!$this->classDef) {
                            $this->fatalError($expr, 'Cannot use "parent" outside a class');
                        }
                        $className = $this->classDef->extends;
                    } else {
                        $className = $this->getNamespacedClassName($className);
                    }
                    $ctorClassName = $className;
                    $this->assertNativeClassNotUsedWithReflection($expr, $className);
                    if ($this->isAbstractClass($className)) {
                        $this->fatalError($expr, "abstract class `{$className}` cannot be instantiated");
                    }
                    $constructor = $this->findConstructor($className);
                    if (
                        $constructor !== null
                        && !$this->checkAccessibleByClassName($constructor['className'], $constructor['flags'])
                    ) {
                        $this->fatalError(
                            $expr,
                            'Cannot call ' . $this->visibilityLabel($constructor['flags']) . ' '
                                . $constructor['className'] . '::__construct()'
                        );
                    }
                    if ($this->isNativeObjectClass($className)) {
                        if ($this->inGeneratorBody) {
                            // Generator lowering deliberately keeps Native
                            // values out of the Zend Closure/Fiber state.
                            $this->fatalError(
                                $expr,
                                'Native objects cannot be created inside Generator functions',
                            );
                        }
                        $cppClass = $this->getNativeObjectCppName($className);
                        $descriptor = $this->getNativeObjectDescriptorName($className);
                        if ($constructor === null) {
                            if ($expr->args !== []) {
                                $this->fatalError($expr, "Native class `{$className}` does not have a constructor");
                            }
                            return 'php::nativeConstruct<' . $cppClass . '>(' . $descriptor
                                . ', [&](auto &this_) { '
                                . $this->getNativeObjectInitializerName($className) . '(this_); })';
                        }
                        $nativeCtor = $this->getNativeMethod($expr, $className, '__construct');
                        if ($nativeCtor === false) {
                            $this->fatalError($expr, "Native constructor `{$className}::__construct()` cannot be resolved");
                        }
                        // Keep the generated C++ argument text separate from
                        // the AST argument array used by the ordinary-class
                        // path below. The self-hosted compiler assigns one
                        // fixed C++ type to each PHP local variable.
                        $nativeArgs = $expr->args === []
                            ? ''
                            : ', ' . $this->parseNativeCallArgs($expr->args, $nativeCtor);
                        return 'php::nativeConstruct<' . $cppClass . '>(' . $descriptor
                            . ', [&](auto &this_) { '
                            . $this->getNativeObjectInitializerName($className) . '(this_); '
                            . self::PREFIX . $nativeCtor . '(this_' . $nativeArgs . '); })';
                    }
                    $cePtr = $this->getLocalClassEntryPtr($className);
                }
            } else {
                $cePtr = $className;
            }
        }

        $args = $expr->args;
        if (empty($args)) {
            return 'php::newObject(' . $cePtr . ')';
        }
        return 'php::newObject(' . $cePtr . ', ' . $this->parseCallArgs($args, '__construct', $ctorClassName) . ')';
    }

    protected function parseClone(Expr\Clone_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'clone operand');
        $class = $this->detectClassOfExpr($expr->expr);
        if ($this->isNativeObjectClass($class)) {
            $source = $this->isVarExpr($expr->expr)
                ? $this->parseIdentifier($expr->expr)
                : $this->materializeNativeObjectReceiver($expr->expr, $class);
            $cpp = $this->getNativeObjectCppName($class);
            $descriptor = $this->getNativeObjectDescriptorName($class);
            $initializer = '';
            $cloneMethod = $this->findNativeObjectMethod($class, '__clone');
            if ($cloneMethod !== null) {
                $declaringClassName = $cloneMethod->functionDef->declaringClass;
                $declaringClass = $this->getClass($declaringClassName);
                if (!$this->checkAccessible($declaringClass, $cloneMethod->flags)) {
                    $visibility = $this->visibilityLabel($cloneMethod->flags);
                    $this->fatalError(
                        $expr,
                        "Call to {$visibility} {$declaringClassName}::__clone()",
                    );
                }
                $clone = self::PREFIX . $this->getNativeName(
                    '__clone',
                    $declaringClass->namespace,
                    $declaringClass->name,
                );
                $initializer = $clone . '(this_); ';
            }
            if ($this->nativeObjectUsesVirtualClone($class)) {
                return $this->getNativeObjectReceiver($source) . '.'
                    . self::NATIVE_VIRTUAL_CLONE_METHOD . '()';
            }
            return 'php::nativeClone<' . $cpp . '>(' . $descriptor . ', '
                . $this->getNativeObjectReceiver($source)
                . ', [&](auto &this_) { ' . $initializer . '})';
        }
        return 'php::clone(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function parseInstanceof(Expr\Instanceof_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'instanceof operand');
        $valueClass = $this->detectClassOfExpr($expr->expr);
        $targetClass = $this->resolveCompileTimeInstanceofClass($expr->class);
        $valueIsNative = $this->isNativeObjectClass($valueClass);
        $targetIsNative = $targetClass !== null && $this->isNativeObjectClass($targetClass);
        $targetIsInterface = $targetClass !== null && $this->isInterface($targetClass);

        if ($valueIsNative && $targetClass === null) {
            $this->fatalError($expr, 'Dynamic instanceof is not supported for native objects');
        }
        if ($valueIsNative || $targetIsNative) {
            $result = $valueIsNative
                && ($targetIsNative || $targetIsInterface)
                && $this->isObjectClassStaticallyAssignableTo($valueClass, $targetClass);
            if (
                !$result
                && $valueIsNative
                && $targetIsNative
                && $this->isObjectClassStaticallyAssignableTo($targetClass, $valueClass)
            ) {
                $this->fatalError(
                    $expr,
                    'Native instanceof cannot be resolved from the static base-class type'
                );
            }

            // Folding must not discard constructor/function side effects from
            // a non-variable left operand. Native objects cannot cross into a
            // Variant, so sequence the original pointer expression directly.
            $value = $this->parseExprAsValue($expr->expr);
            if ($result) {
                // Static assignability proves the class relationship, but a
                // nullable Native slot still follows PHP: null instanceof T
                // is false. The raw-pointer null check is the only runtime work.
                return '(' . $value . ' != nullptr)';
            }
            return '(static_cast<void>(' . $value . '), false)';
        }

        if ($this->isNameExpr($expr->class)) {
            $value = $this->parseExprAsValue($expr->expr);
            $classPtr = $this->resolveInstanceofClassPtr($expr->class);
            return 'php::instanceOf(' . $value . ', ' . $classPtr . ')';
        } else {
            [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr->expr);
            $tmpVar = $this->addTmpVar(Type::VAR);
            $this->appendCapturedStmtLinesToContext($beforeStmts);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
            $this->appendCapturedStmtLinesToContext($afterStmts);
            return 'php::instanceOf(' . $tmpVar . ', ' . $this->identifierToStr($expr->class) . ')';
        }
    }

    protected function resolveCompileTimeInstanceofClass(NodeAbstract $class): ?string
    {
        if (!$this->isNameExpr($class)) {
            return null;
        }
        $name = $this->parseIdentifier($class);
        if ($name === 'self' || $name === 'static') {
            return $this->getFullClassName();
        }
        if ($name === 'parent') {
            return $this->classDef?->extends ?? '';
        }
        return $this->getNamespacedClassName($name);
    }

    protected function resolveInstanceofClassPtr(NodeAbstract $class): string
    {
        $className = $this->parseIdentifier($class);
        if ($className === 'self') {
            $className = $this->getFullClassName();
        } elseif ($className === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($class, 'Cannot use "parent" when current class scope has no parent');
            }
            $className = $this->classDef->extends;
        } elseif ($className === 'static') {
            if (!$this->classDef) {
                $this->fatalError($class, 'Cannot use "static" outside a class');
            }
            return $this->getCalledCeExpr();
        } else {
            $className = $this->getNamespacedClassName($className);
        }
        return $this->getClassEntryPtr($className);
    }

    protected function parseInterpolatedString(Node\Scalar\InterpolatedString $expr): string
    {
        $parts = $expr->parts;
        $list  = [];
        foreach ($parts as $part) {
            if (!$part instanceof Node\InterpolatedStringPart) {
                $this->assertExprCanBeUsedAsValue($part, 'string interpolation value');
            }
            // Although C++17 orders the braced-list elements, materializing an
            // expression prevents captured statements from a later part from
            // being hoisted ahead of an earlier Call.
            if ($part instanceof Node\InterpolatedStringPart) {
                $list[] = $this->parseExpr($part);
            } elseif ($this->isNativeObjectClass($this->detectClassOfExpr($part))) {
                $list[] = $this->parseOrderedOperand(
                    new Expr\MethodCall($part, new Node\Identifier('toString')),
                    false,
                );
            } else {
                $list[] = $this->parseOrderedOperand($part, false);
            }
        }

        return 'php::concat({' . implode(', ', $list) . '})';
    }

    protected function parseInterpolatedStringPart(Node\InterpolatedStringPart $expr): string
    {
        return '"' . $this->escapeString($expr->value) . '"';
    }

    protected function parseGlobal(Node\Stmt\Global_ $expr): string
    {
        foreach ($expr->vars as $v) {
            $name = $this->parseVariable($v);
            if (!$this->hasGlobalVar($name)) {
                $this->addGlobalVar($name, Type::VAR);
            }
            if (!$this->hasScopeGlobalVar($name)) {
                $this->addScopeGlobalVar($name, $this->globalVars[$name]);
            }
            if (isset($this->nativeGlobalObjects[$name])) {
                $this->addNativeObject($name, $this->nativeGlobalObjects[$name]);
            }
        }
        return '';
    }

    protected function getArgInfo(Node $arg, string $funcName, int $index): ArgInfo
    {
        if (!$this->hasFunction($funcName)) {
            $this->fatalError($arg, "Function `{$funcName}` is undefined, you must adjust the order of function definition");
        }
        $funcDef = $this->getFunction($funcName);
        if (!array_key_exists($index, $funcDef->argInfoList)) {
            $this->fatalError($arg, "Argument `{$index}` of function `{$funcName}` not found");
        }

        return $funcDef->argInfoList[$index];
    }

    protected function parseExit(Expr\Exit_ $node): string
    {
        if (!$node->expr) {
            return 'php::aotExit()';
        }
        $status = $this->isNativeObjectClass($this->detectClassOfExpr($node->expr))
            ? $this->parseExprToString($node->expr)
            : $this->parseExprAsValue($node->expr);
        return 'php::aotExit(' . $status . ')';
    }

    protected function parseStatic(Node\Stmt\Static_ $v): string
    {
        $list = [];
        foreach ($v->vars as $var) {
            $varName = $this->escapeVarName($var->var->name);
            $type = $var->default ? $this->detectTypeOfExpr($var->default) : Type::VAR;
            if ($var->default) {
                $this->assertExprCanBeUsedAsValue($var->default, 'static variable default value');
                $this->assertNativeStdContainerFunctionLocal($var->default);
            }
            $globalVar = $this->addStaticVar($var->var, $varName, $type);
            if ($var->default) {
                $class = $this->detectClassOfExpr($var->default);
                if ($this->isNativeObjectClass($class)) {
                    $this->promoteGlobalOrStaticToNativeObject($varName, $class, $var->default);
                }
            }

            $list[] = 'auto &' . $varName . ' = ' . $this->escapeGlobalVar($globalVar) . ';';
            if ($var->default) {
                $initState = self::STATIC_VAR . $varName . '_initialized';
                if (isset($this->nativeGlobalObjects[$globalVar])) {
                    $flag = $globalVar . '__initialized';
                    $this->nativeStaticInitializers[$flag] = true;
                    $initState = $this->escapeGlobalVar($flag);
                    $initCode = '';
                } else {
                    $initCode = $this->getIndent() . 'static THREAD_LOCAL bool ' . $initState . ' = false;';
                }
                $initCode .= $this->getIndent() . "if (!{$initState}) { \n";
                $this->indentLevel++;
                $initCode .= $this->getIndent() . "{$initState} = true;\n";
                $initCode .= $this->genStaticVarInitLambda($var, $varName);
                $this->indentLevel--;
                $initCode .= $this->getIndent() . '}';
                $list[] = $initCode;
            }
        }

        return implode(PHP_EOL . $this->getIndent(), $list);
    }

    protected function genStaticVarInitLambda(Node\Stmt\StaticVar $var, string $varName): string
    {
        $oriCtx = $this->context;

        $this->context = new FunctionContext();
        $this->context->arguments = $oriCtx->localVars;

        $code = '([&](){' . PHP_EOL;
        $body = $this->getIndent() . $varName . ' = ' . $this->parseExpr($var->default) . ';';
        $code .= $this->genScopeVarDecl();
        $code .= $this->parseBeforeStmtLines();
        $code .= $body;
        $code .= $this->parseAfterStmtLines();
        $code .= '})();' . PHP_EOL;

        $this->context = $oriCtx;

        return $code;
    }

    protected function parseEnum(Node\Stmt\Enum_ $v): string
    {
        return 'php::eval("' . $this->escapeString($this->genEmbeddedCode($v)) . '");';
    }

    protected function parseEval(Expr\Eval_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'eval operand');
        // Disable literal-string optimization for the PHP code passed to eval().
        $expr->expr->setAttribute('noLiteralString', true);
        $source = $this->isNativeObjectClass($this->detectClassOfExpr($expr->expr))
            ? $this->parseExprToString($expr->expr)
            : $this->identifierToStr($expr->expr);
        return 'php::eval(' . $source . ')';
    }

    protected function parseInclude(Expr\Include_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'include operand');
        switch ($expr->type) {
            case Expr\Include_::TYPE_INCLUDE:
                $type = 'php::INCLUDE';
                break;
            case Expr\Include_::TYPE_INCLUDE_ONCE:
                $type = 'php::INCLUDE_ONCE';
                break;
            case Expr\Include_::TYPE_REQUIRE:
                $type = 'php::REQUIRE';
                break;
            case Expr\Include_::TYPE_REQUIRE_ONCE:
                $type = 'php::REQUIRE_ONCE';
                break;
            default:
                $this->fatalError($expr, 'Invalid include type');
                break;
        }

        $fileName = $this->isNativeObjectClass($this->detectClassOfExpr($expr->expr))
            ? $this->parseExprToString($expr->expr)
            : $this->parseIdentifier($expr->expr);

        $scope = [];
        foreach ($this->context->localVars as $name => $_type) {
            if ($name === 'this_' || str_starts_with($name, 'tmp_var_')) {
                continue;
            }
            // Native pointers have no zval representation. Boxing one into
            // the include symbol table would silently select a bool overload
            // and expose `true` instead of the object. Dynamic PHP is not
            // allowed to observe Native locals, so leave these names absent.
            if ($this->isNativeObjectVar($name)) {
                continue;
            }
            $phpName = $this->unescapeVarName($name);
            $scope[] = '{ ' . $this->getLiteralString($phpName) . '.str(), php::Var(' . $name . ') }';
        }

        if ($scope) {
            return "php::include(php::Var($fileName), $type, php::Array{" . implode(', ', $scope) . '})';
        }

        return "php::include(php::Var($fileName), $type)";
    }

    protected function parseScalarFloat(Node\Scalar\Float_ $expr): string
    {
        return $this->genFloatLiteral($expr->value);
    }

    protected function parseIsset(Expr\Isset_ $expr): string
    {
        $vars = $expr->vars;
        if (count($vars) > 1) {
            $list = [];
            foreach ($vars as $var) {
                $list[] = $this->parseChainedExpr($var, self::OP_ISSET);
            }
            return '(' . implode(' && ', $list) . ')';
        }
        return $this->parseChainedExpr($vars[0], self::OP_ISSET);
    }

    protected function parseEmpty(Expr\Empty_ $expr): string
    {
        $type = $this->detectTypeOfExpr($expr->expr);
        if (in_array($type, [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
            return '!(' . $this->convertBoolExpr($this->parseExprAsValue($expr->expr), $type) . ')';
        }
        return $this->parseChainedExpr($expr->expr, self::OP_EMPTY);
    }

    /**
     * The left value may only be a variable, array element, object property,
     * or class static property.
     */
    protected function checkLeftValue(NodeAbstract $expr): void
    {
        $this->assertNotNullsafeWriteContext($expr);
        if (!$this->isVarExpr($expr) && !$this->isArrayDimFetch($expr) && !$this->isPropertyFetch($expr) && !$this->isStaticPropertyFetch($expr)) {
            $this->fatalError($expr, 'The left value of assignment operation can only be variable, array item, object property, class static property');
        }
    }

    protected function assertNotNullsafeWriteContext(NodeAbstract $expr): void
    {
        if ($expr instanceof Expr\NullsafePropertyFetch) {
            $this->fatalError($expr, "Can't use nullsafe operator in write context");
        }
    }

    protected function getChainedFunc(string $op): string
    {
        return match ($op) {
            self::OP_ISSET => 'php::exists',
            self::OP_NOT_EMPTY => 'php::notEmpty',
            default => 'php::' . $op,
        };
    }

    protected function parseChainedExpr(NodeAbstract $node, string $op, bool $getValue = false): string
    {
        if ($op === self::OP_REFVAL) {
            $this->assertNativeArrayAccessReferenceForbidden($node);
            $this->assertNativeObjectReferenceForbidden($node, $node);
        }
        if (
            $node instanceof Expr\ArrayDimFetch
            && $this->isNativeObjectClass($this->detectClassOfExpr($node->var))
        ) {
            return $this->parseNativeArrayAccessPresence($node, $op, $getValue);
        }
        if (in_array($op, [self::OP_ISSET, self::OP_EMPTY, self::OP_NOT_EMPTY], true)) {
            $nativePresence = $this->parseNativeObjectPresenceChain($node, $op);
            if ($nativePresence !== null) {
                return $nativePresence;
            }
        }
        if (
            $op === self::OP_ISSET
            && $node instanceof Expr\ArrayDimFetch
            && $node->dim !== null
            && $node->var instanceof Expr\StaticPropertyFetch
            && $this->detectTypeOfExpr($node->var) === Type::ARRAY
        ) {
            // A single offset on a statically known array property needs no
            // materialized operation chain. The TypePHP array helper reads the
            // element directly and still applies isset's null semantics. Keep
            // the general walker for deeper/dynamic chains.
            $array = $this->parseStaticPropertyFetch($node->var);
            $key = $this->parseIdentifier($node->dim);
            if ($getValue) {
                $result = $this->addTmpVar(Type::VAR);
                $node->setAttribute('chainOpResult', $result);
                return 'typephp_array_isset(' . $array . ', ' . $key . ', &' . $result . ')';
            }
            return 'typephp_array_isset(' . $array . ', ' . $key . ')';
        }
        // The TypePHP compiler disallows operating on undefined variables;
        // in PHP, isset($var) may be used with an undefined $var.
        $this->checkVarMustExist($node, $this->parseIdentifier($node));
        $fn = $this->getChainedFunc($op);
        $expr = $node;
        if ($this->isVarExpr($expr)) {
            $nativeObject = $this->parseIdentifier($expr);
            if ($this->isNativeObjectVar($nativeObject)) {
                return match ($op) {
                    self::OP_ISSET, self::OP_NOT_EMPTY => '(' . $nativeObject . ' != nullptr)',
                    self::OP_EMPTY => '(' . $nativeObject . ' == nullptr)',
                    default => $fn . '(' . $this->parseExpr($expr) . ')',
                };
            }
            if (!$getValue) {
                return $fn . '(' . $this->parseExpr($expr) . ')';
            }
            // $getValue is true: fall through to use the chain+result mechanism,
            // which ensures the result type is TYPE_VAR (compatible with ternaries).
        }
        // Single property read (non-chained).
        if ($this->isPropertyFetch($expr) and $this->isVarExpr($expr->var) and $this->isIdExpr($expr->name)) {
            $prop = $this->parsePropertyFetch($expr);
            if ($this->isNativePropertyAccess($expr)) {
                if ($op === self::OP_REFVAL) {
                    return $prop . '.toReference()';
                }
                if ($this->isNativeObjectClass($this->detectClassOfExpr($expr))) {
                    return match ($op) {
                        self::OP_ISSET, self::OP_NOT_EMPTY => '(' . $prop . ' != nullptr)',
                        self::OP_EMPTY => '(' . $prop . ' == nullptr)',
                        default => $fn . '(' . $prop . ')',
                    };
                }
                return $fn . '(' . $prop . ')';
            }
        }
        if ($this->isStaticPropertyFetch($expr) and $this->isNameExpr($expr->class) and $this->isIdExpr($expr->name)) {
            $prop = $this->parseStaticPropertyFetch($expr);
            if ($this->isNativePropertyAccess($expr)) {
                if ($op === self::OP_REFVAL) {
                    return $prop . '.toReference()';
                }
                return $fn . '(' . $prop . ')';
            }
        }

        $list = [];
        while (true) {
            if ($this->isArrayDimFetch($expr)) {
                if ($this->isNativeObjectClass($this->detectClassOfExpr($expr->var))) {
                    $var = $this->parseArrayDimFetchRead($expr);
                    break;
                }
                if ($expr->dim === null) {
                    $this->fatalError($expr, 'Cannot use [] for reading');
                }
                $dim = $this->parseIdentifier($expr->dim);
                $list[] = '{php::ArrayDimFetch, ' . Type::VAR . '(' . $dim . ')}';
            } elseif ($this->isPropertyFetch($expr)) {
                // Start the dynamic tail at a statically resolved property.
                // Keeping it as a name-based PropertyFetch would make PHPX
                // resolve a parent private property against the runtime child
                // class, so isset()/?? could observe a different slot from a
                // normal native read or write.
                if ($this->isNativePropertyAccess($expr)) {
                    $var = $this->parsePropertyFetch($expr);
                    break;
                }
                $name = $this->propertyNameToStr($expr->name, literal: true);
                $list[] = '{php::PropertyFetch, ' . Type::VAR . '(' . $name . ')}';
            } elseif ($this->isVarExpr($expr)) {
                $var = $this->parseIdentifier($expr);
                break;
            } else {
                $var = $this->genTmpVarName();
                $this->addLocalVar($var, Type::VAR);
                $this->context->beforeStmtLines[] = $var . '=' . $this->parseExpr($expr) . ';';
                break;
            }
            $expr = $expr->var;
        }

        $list = array_reverse($list);

        if ($getValue) {
            $result = $this->addTmpVar(Type::VAR);
            $node->setAttribute('chainOpResult', $result);
            return $fn . '(' . $var . ', {' . implode(', ', $list) . '}, ' . $result . ')';
        } else {
            // toReference(var, {}) returns an empty reference; use the member
            // function form instead when the chain is empty.
            if ($op === self::OP_REFVAL && empty($list)) {
                return $var . '.toReference()';
            }
            return $fn . '(' . $var . ', {' . implode(', ', $list) . '})';
        }
    }

    /**
     * Lower isset/empty/coalesce without exposing the Native pointer to the
     * generic Variant chain walker. Repeated offsetExists/offsetGet operations
     * share one receiver and key evaluation, matching PHP ArrayAccess order.
     */
    protected function parseNativeArrayAccessPresence(
        Expr\ArrayDimFetch $access,
        string $op,
        bool $getValue,
    ): string {
        if ($access->dim === null) {
            $this->fatalError($access, 'Cannot use [] for reading');
        }

        if ($op === self::OP_REFVAL) {
            $this->assertNativeArrayAccessReferenceForbidden($access);
        }
        if (!in_array($op, [self::OP_ISSET, self::OP_EMPTY, self::OP_NOT_EMPTY], true)) {
            $this->fatalError($access, 'Unsupported Native ArrayAccess operation');
        }

        if ($op === self::OP_ISSET && !$getValue) {
            return $this->parseNativeArrayAccessCall(
                $access,
                'offsetExists',
                [new Node\Arg($access->dim)],
            );
        }

        $receiver = $access->var;
        $class = $this->getNativeArrayAccessClass($receiver, $access);
        if (!$this->isVarExpr($receiver)) {
            $receiverName = $this->materializeNativeObjectReceiver($receiver, $class);
            $receiver = new Expr\Variable($receiverName, $receiver->getAttributes());
        }

        $key = $this->addTmpVar(Type::VAR);
        $keyExpr = $this->parseOrderedOperand($access->dim, false);
        $this->context->beforeStmtLines[] = $key . ' = ' . $keyExpr . ';';
        $stableAccess = new Expr\ArrayDimFetch(
            $receiver,
            new Expr\Variable($key, $access->dim->getAttributes()),
            $access->getAttributes(),
        );
        $exists = $this->parseNativeArrayAccessCall(
            $stableAccess,
            'offsetExists',
            [new Node\Arg($stableAccess->dim)],
        );
        $value = $this->parseNativeArrayAccessCall(
            $stableAccess,
            'offsetGet',
            [new Node\Arg($stableAccess->dim)],
        );

        if ($getValue) {
            $result = $this->addTmpVar(Type::VAR);
            $access->setAttribute('chainOpResult', $result);
            return '(' . $exists . ' && ((' . $result . ' = ' . $value . '), true))';
        }
        if ($op === self::OP_EMPTY) {
            return '(!(' . $exists . ') || !php::notEmpty(' . $value . '))';
        }
        return '(' . $exists . ' && php::notEmpty(' . $value . '))';
    }

    /**
     * Lower a named Native property chain without converting its raw pointers
     * to Variant. The short-circuit lambda preserves PHP's isset()/empty()
     * behavior when either the root or an intermediate Native slot is null.
     */
    protected function parseNativeObjectPresenceChain(NodeAbstract $node, string $op): ?string
    {
        $properties = [];
        $base = $node;
        while ($base instanceof Expr\PropertyFetch) {
            if (!$base->name instanceof Node\Identifier) {
                return null;
            }
            $properties[] = $base;
            $base = $base->var;
        }
        if ($properties === [] || !$this->isVarExpr($base)) {
            return null;
        }

        $baseName = $this->parseIdentifier($base);
        if (!$this->isNativeObjectVar($baseName)) {
            return null;
        }
        $this->checkVarMustExist($base, $baseName);

        $properties = array_reverse($properties);
        $nullResult = $op === self::OP_EMPTY ? 'true' : 'false';
        $current = $this->genTmpVarName();
        $class = $this->getNativeObjectVarClass($baseName);
        $code = '[&]() -> bool {' . PHP_EOL;
        $code .= $this->getIndent() . 'auto *' . $current . ' = ' . $baseName . ';' . PHP_EOL;
        $code .= $this->getIndent() . 'if (' . $current . ' == nullptr) { return ' . $nullResult . '; }' . PHP_EOL;

        $last = array_key_last($properties);
        foreach ($properties as $index => $propertyExpr) {
            $property = $propertyExpr->name->toString();
            $resolution = $this->resolveNativeInstanceProperty($propertyExpr, $property, $class);
            if ($resolution === null) {
                $this->fatalError($propertyExpr, "Native class `{$class}` has no property `\${$property}`");
            }
            $this->applyNativePropertyAccessResult($propertyExpr, $resolution);
            $definition = $resolution->propertyDef;
            if ($definition->getter !== null || $definition->setter !== null) {
                $this->fatalError(
                    $propertyExpr,
                    'isset()/empty() are not supported for Native property hooks',
                );
            }
            $field = $current . '->'
                . $this->getNativeObjectPropertyCppName($definition, $resolution->classDef);
            $isNativePointer = $definition->type === Type::OBJECT
                && $this->isNativeObjectClass($definition->class);

            if ($index !== $last) {
                if (!$isNativePointer) {
                    $this->fatalError(
                        $propertyExpr,
                        'Native isset()/empty() chains may only traverse Native object properties',
                    );
                }
                $next = $this->genTmpVarName();
                $code .= $this->getIndent() . 'auto *' . $next . ' = ' . $field . ';' . PHP_EOL;
                $code .= $this->getIndent() . 'if (' . $next . ' == nullptr) { return '
                    . $nullResult . '; }' . PHP_EOL;
                $current = $next;
                $class = $definition->class;
                continue;
            }

            if ($isNativePointer) {
                $condition = '(' . $field . ($op === self::OP_EMPTY ? ' == ' : ' != ') . 'nullptr)';
            } else {
                $condition = $this->getChainedFunc($op) . '(' . $field . ')';
            }
            $code .= $this->getIndent() . 'return ' . $condition . ';' . PHP_EOL;
        }
        return $code . $this->getIndent() . '}()';
    }

    protected function parseCastArray(Expr\Cast\Array_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'cast operand');
        $native = $this->parseNativeObjectExplicitConversion($expr->expr, 'toArray');
        if ($native !== null) {
            return $native;
        }
        return $this->convertArrayExpr($this->parseExprAsValue($expr->expr));
    }

    protected function hasGlobalVar(string $name): bool
    {
        return array_key_exists($name, $this->globalVars);
    }

    protected function hasScopeGlobalVar(string $name): bool
    {
        return array_key_exists($name, $this->context->globalVars);
    }

    protected function hasStaticVar(string $name): bool
    {
        return array_key_exists($name, $this->context->staticVars);
    }

    protected function parseCastDouble(Expr\Cast\Double $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'cast operand');
        $native = $this->parseNativeObjectExplicitConversion($expr->expr, 'toFloat');
        if ($native !== null) {
            return $native;
        }
        return $this->convertFloatExpr(
            $this->parseIdentifier($expr->expr),
            $this->detectTypeOfExpr($expr->expr)
        );
    }

    protected function detectFuncCallReturnType(string $name): string
    {
        $name = ltrim($name, '\\');
        $returnType = Reflection::getFunctionReturnType($name);
        if ($returnType !== null) {
            return $this->getTypeFromZendType($returnType);
        }

        return Type::VAR;
    }

    protected function detectMethodCallReturnType(string $class, string $method): string
    {
        $returnType = Reflection::getMethodReturnType($class, $method);
        if ($returnType) {
            return $this->getTypeFromZendType($returnType);
        }
        return Type::VAR;
    }

    protected function genObjvalCall(Expr\FuncCall $expr): string
    {
        if (count($expr->args) !== 2) {
            $this->fatalError($expr, 'objval() requires exactly 2 arguments');
        }
        $receiver = $this->parseExpr($expr->args[0]->value);
        $className = $this->resolveClassNameArg($expr->args[1]->value);
        return 'php::toObject(' . $receiver . ', ' . $this->getClassEntryPtr($className) . ')';
    }

    protected function identifierToStr(NodeAbstract $node, bool $require = true, bool $literal = false): string
    {
        $id = $this->parseIdentifier($node);
        if ($this->isVarExpr($node)) {
            if ($require) {
                $this->requireVar($node, $id);
            }
            return $id;
        }
        if ($id === 'self') {
            $id = $this->getFullClassName();
        } elseif ($id === 'static') {
            return $this->getCalledClassExpr();
        }
        if ($this->isNameExpr($node) or $this->isIdExpr($node)) {
            return $literal ? $this->getLiteralString($id) : $this->genCharPtr($id, true);
        }
        if ($this->isZeroLiteral($node)) {
            return self::VALUE_ZERO;
        }
        return $id;
    }

    /**
     * Convert an object/static property name without interpreting `self` or
     * `static` as a class-name keyword. Those words are valid PHP property
     * names and only have special meaning in class-name positions.
     */
    protected function propertyNameToStr(NodeAbstract $node, bool $require = true, bool $literal = false): string
    {
        if ($this->isIdExpr($node)) {
            $name = $this->parseIdentifier($node);
            return $literal ? $this->getLiteralString($name) : $this->genCharPtr($name, true);
        }
        return $this->identifierToStr($node, $require, $literal);
    }

    /**
     * Method identifiers share the same lexical rules as property names:
     * `self` and `static` are ordinary member names here, not class keywords.
     */
    protected function methodNameToStr(NodeAbstract $node, bool $require = true, bool $literal = false): string
    {
        return $this->propertyNameToStr($node, $require, $literal);
    }

    protected function requireVar($node, string $var): void
    {
        if (!$this->hasVar($var)) {
            $this->fatalError($node, 'The variable `' . $var . '` is not defined');
        }
    }

    protected function createPropertyAccessResolver(): PropertyAccessResolver
    {
        $this->assertCompilerPhase(self::PHASE_CONVERT, 'PropertyAccessResolver');
        return new PropertyAccessResolver($this);
    }

    protected function isSameClassName(string $classA, string $classB): bool
    {
        return strcasecmp(ltrim($classA, '\\'), ltrim($classB, '\\')) === 0;
    }

    protected function isSameOrSubclassOf(string $class, string $parent): bool
    {
        $class = strtolower(ltrim($class, '\\'));
        $parent = strtolower(ltrim($parent, '\\'));
        while ($class !== '') {
            if ($class === $parent) {
                return true;
            }
            $class = $this->getParentClass($class);
        }
        return false;
    }

    protected function canAccessProtectedProperty(string $scope, string $declaringClass): bool
    {
        if ($scope === '') {
            return false;
        }
        return $this->isSameOrSubclassOf($scope, $declaringClass)
            || $this->isSameOrSubclassOf($declaringClass, $scope);
    }

    protected function resolveNativeInstanceProperty(NodeAbstract $expr, string $property, string $class): ?PropertyAccessResult
    {
        $scope = $this->class ? $this->getFullClassName() : '';
        return $this->createPropertyAccessResolver()->resolveNativeInstanceProperty($expr, $property, $class, $scope);
    }

    protected function resolveNativeStaticProperty(NodeAbstract $expr, string $property, string $class): ?PropertyAccessResult
    {
        $scope = $this->class ? $this->getFullClassName() : '';
        return $this->createPropertyAccessResolver()->resolveNativeStaticProperty($expr, $property, $class, $scope);
    }

    protected function applyNativePropertyAccessResult(NodeAbstract $expr, PropertyAccessResult $result): string
    {
        $offset = $this->getPropertyOffset($result->classDef->getNamespacedName(false), $result->property);
        $expr->setAttribute('nativePropertyAccess', new NativePropertyAccess($offset, $result));
        return $offset;
    }

    protected function isNativePropertyAccess(NodeAbstract $expr): bool
    {
        return $this->getNativePropertyAccess($expr) !== null;
    }

    protected function getNativePropertyDef(NodeAbstract $expr): ?PropertyDef
    {
        return $this->getNativePropertyAccess($expr)?->getPropertyDef();
    }

    protected function getNativePropertyClassDef(NodeAbstract $expr): ?ClassDef
    {
        return $this->getNativePropertyAccess($expr)?->getClassDef();
    }

    public function getNativePropertyAccess(NodeAbstract $expr): ?NativePropertyAccess
    {
        $access = $expr->getAttribute('nativePropertyAccess');
        return $access instanceof NativePropertyAccess ? $access : null;
    }

    protected function setNativePropertyVar(NodeAbstract $expr, string $var): void
    {
        $expr->setAttribute('nativePropertyVar', $var);
    }

    protected function getNativePropertyVar(NodeAbstract $expr): ?string
    {
        $var = $expr->getAttribute('nativePropertyVar');
        return is_string($var) ? $var : null;
    }

    protected function setNativePropertyValueSource(NodeAbstract $expr, string $source): void
    {
        $expr->setAttribute('nativePropertyValueSource', $source);
    }

    protected function isNativePropertyTypedValue(NodeAbstract $expr): bool
    {
        return $expr->getAttribute('nativePropertyValueSource') === self::NATIVE_PROPERTY_VALUE_VAR;
    }

    protected function parseShellExec(Expr\ShellExec $expr): string
    {
        if ($this->isWasiTarget()) {
            $this->fatalError($expr, 'Backtick shell execution is not supported by the WASI target');
        }
        $list = [];
        foreach ($expr->parts as $part) {
            if (!$part instanceof Node\InterpolatedStringPart) {
                $this->assertExprCanBeUsedAsValue($part, 'shell command interpolation value');
            }
            if ($part instanceof Node\InterpolatedStringPart) {
                $list[] = $this->parseExpr($part);
            } elseif ($this->isNativeObjectClass($this->detectClassOfExpr($part))) {
                $list[] = $this->parseOrderedOperand(
                    new Expr\MethodCall($part, new Node\Identifier('toString')),
                    false,
                );
            } else {
                $list[] = $this->parseOrderedOperand($part, false);
            }
        }
        return 'php::fn::shell_exec(php::concat({' . implode(', ', $list) . '}))';
    }

    protected function parseGoto(Node\Stmt\Goto_ $v): string
    {
        return 'goto ' . $v->name->name . ';';
    }

    protected function parseLabel(Node\Stmt\Label $v): string
    {
        return $v->name->name . ':';
    }

    protected function parseModifiers(int $flags): int
    {
        if (!($flags & Modifiers::PRIVATE) and !($flags & Modifiers::PROTECTED)) {
            $flags |= Modifiers::PUBLIC;
        }
        return $flags;
    }

    protected function setBuildDir(string $string): void
    {
        if (!is_dir($string)) {
            mkdir($string, 0777, true);
        }
        $resolved = realpath($string);
        if ($resolved === false) {
            throw new \RuntimeException('Failed to resolve build path: ' . $string);
        }
        $this->buildDir = $resolved;
    }

    protected function isStubFile(string $file): bool
    {
        return str_ends_with($file, '.stub.php');
    }

    /**
     * @throws \Exception
     */
    protected function loadFile(string $file): string
    {
        if (!file_exists($file)) {
            throw new \Exception('File not exists: ' . $file);
        }
        $phpCode = file_get_contents($file);
        if (!$phpCode) {
            throw new \Exception('Can not read file: ' . $file);
        }
        if (!mb_check_encoding($phpCode, 'UTF-8')) {
            throw new \Exception('File encoding must be UTF-8, got: ' . mb_detect_encoding($phpCode, ['UTF-8', 'ISO-8859-1', 'GBK', 'Shift_JIS'], true) . ' in ' . $file);
        }
        $this->file     = realpath($file);
        $this->dir      = dirname($this->file);
        $this->stubFile = $this->isStubFile($file);

        return $phpCode;
    }

    protected function parseErrorSuppress(Expr\ErrorSuppress $expr): string
    {
        $tmpVar = $this->genTmpVarName();
        $this->context->beforeStmtLines[] = 'auto ' . $tmpVar . ' = EG(error_reporting);';
        $this->context->beforeStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {E_FATAL_ERRORS});';
        $code = $this->parseExpr($expr->expr);
        $this->context->afterStmtLines[] = 'php::call(' . $this->getFuncPtr('error_reporting') . ', {' . $tmpVar . '});';
        return $code;
    }

    protected function checkVar(NodeAbstract $node, string $name, string $defaultType = Type::VAR): void
    {
        if (!$this->hasVar($name)) {
            $this->addLocalVar($name, $defaultType);
        } else {
            if ($this->getVarType($name) !== $defaultType) {
                $this->fatalError($node, 'Cannot assign value to variable $' . $name . ' of type ' . $this->getVarType($name) . ' with type ' . $defaultType);
            }
        }
    }

    protected function checkVarMustExist(NodeAbstract $node, string $name): void
    {
        if ($this->isVarExpr($node) and !$this->hasVar($name)) {
            $this->errorUndefinedVariable($node);
        }
    }

    protected function checkVarAssignExpr(NodeAbstract $left, string $toType, string $fromType): bool
    {
        if ($toType === Type::VAR or $fromType === Type::VAR) {
            return true;
        }
        // References currently carry no type information, so treat them as var.
        if ($toType === Type::REF or $fromType === Type::REF) {
            return true;
        }
        // Types are identical, so they can be assigned to each other.
        if ($toType === $fromType) {
            return true;
        }
        // Native types can be converted between each other, handled by the C++ layer.
        if ($this->isNativeType($toType) and $this->isNativeType($fromType)) {
            return true;
        }
        // Implicit conversions between BigInt/BigFloat/Decimal and native types
        // are possible, so re-assignment is allowed.
        $bigTypes = [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT];
        if (in_array($toType, $bigTypes, true) or in_array($fromType, $bigTypes, true)) {
            return true;
        }
        $varName = 'variable';
        if ($this->isVarExpr($left)) {
            $varName = '`$' . $this->parseIdentifier($left) . '`';
        }
        $this->fatalError($left, "Cannot re-assign $varName from `{$fromType}` to `{$toType}`");
    }

    /**
     * Check a value against a composite PHP type when the value's static type
     * is precise enough to prove a mismatch. Composite declarations still use
     * Variant in generated C++, so unknown values must be left to the runtime
     * type check emitted from the same descriptor.
     *
     * The outer descriptor list is a union (OR); an allOf entry represents an
     * intersection (AND). Nullable is represented by an isNull union member.
     */
    protected function checkAccessible(ClassDef $classDef, int $flags): bool
    {
        return $this->checkAccessibleByClassName($classDef->getNamespacedName(false), $flags);
    }

    protected function checkAccessibleByClassName(
        string $declaringClass,
        int $flags,
        ?string $accessingClass = null
    ): bool {
        if ($accessingClass !== null) {
            $accessingClass = ltrim($accessingClass, '\\');
            $scopeClassDef = $this->hasClass($accessingClass)
                ? $this->getClass($accessingClass)
                : null;
        } else {
            $scopeClassDef = $this->classDef;
            if (
                $this->functionDef !== null
                && $this->functionDef->attributeFactoryScope !== ''
                && $this->hasClass($this->functionDef->attributeFactoryScope)
            ) {
                $scopeClassDef = $this->getClass($this->functionDef->attributeFactoryScope);
            }
        }
        // Private methods can only be used by the current class.
        if ($flags & Modifiers::PRIVATE) {
            return $scopeClassDef !== null
                && $this->isSameClassName($declaringClass, $scopeClassDef->getNamespacedName(false));
        }
        // Protected methods can only be used by the current class and its subclasses.
        if ($flags & Modifiers::PROTECTED) {
            if (!$scopeClassDef) {
                return false;
            }
            return $this->canAccessProtectedProperty(
                $scopeClassDef->getNamespacedName(false),
                $declaringClass
            );
        }
        // Calls from outside the class are only allowed for public methods.
        return true;
    }

    /**
     * Walk the inheritance chain to find the constructor that is actually
     * invoked, including constructors of internal classes inherited by project
     * classes.
     *
     * @return array{className: string, flags: int}|null
     */
    protected function findConstructor(string $className): ?array
    {
        $current = $className;
        while ($current !== '') {
            if ($this->hasClass($current)) {
                $classDef = $this->getClass($current);
                if ($classDef->hasMethod('__construct')) {
                    return [
                        'className' => $classDef->getNamespacedName(false),
                        'flags' => $classDef->getMethod('__construct')->flags,
                    ];
                }
                $current = $classDef->extends;
                continue;
            }
            if (!$this->isInternalClass($current)) {
                return null;
            }

            $constructor = Reflection::getClass($current)?->getConstructor();
            if ($constructor === null) {
                return null;
            }
            return [
                'className' => $constructor->getDeclaringClass()->getName(),
                'flags' => $constructor->getModifiers(),
            ];
        }
        return null;
    }

    protected function visibilityLabel(int $flags): string
    {
        if ($flags & Modifiers::PRIVATE) {
            return 'private';
        }
        if ($flags & Modifiers::PROTECTED) {
            return 'protected';
        }
        return 'public';
    }

    protected function genDebugInfo(?NodeAbstract $stmt = null, string $functionName = '', int $startLine = 0): string
    {
        $code = '';
        if ($this->debug) {
            if ($stmt) {
                $code .= 'php::traceDebugInfo("' . $this->escapeString($this->file) . '", ' . $stmt->getLine() . ');' . PHP_EOL;
            } elseif ($functionName) {
                $code .= 'php::enableDebugInfo();' . PHP_EOL;
                $code .= 'php::pushDebugFrame("' . $this->escapeString($this->file) . '", ' . $startLine . ', "' . $this->escapeString($functionName) . '");' . PHP_EOL;
                $code .= 'ON_SCOPE_EXIT(php::popDebugFrame());' . PHP_EOL;
            } else {
                $code .= 'php::enableDebugInfo();' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function genLocalVarDecl(array $localVars): string
    {
        $code = '';
        foreach ($localVars as $name => $type) {
            if (isset($this->context->arguments[$name])) {
                continue;
            }
            if (isset($this->context->globalVars[$name])) {
                continue;
            }
            $stdContainerInfo = null;
            $code .= $this->getIndent();
            if ($type === Type::STD_ARRAY) {
                $info = $this->context->stdArrays[$name];
                $stdContainerInfo = $info;
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    $code .= 'php::Var ' . $name . ' = php::Var(new ' . $containerType . '(' . $info['typeId'] . '));' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
                if (!isset($info['boxExpr']) && ($defaultValue = $this->getStdContainerDefaultValueExpr($info['type'])) !== null) {
                    $code .= PHP_EOL . $this->getIndent() . 'php::initializeStdContainer(' . $name . '_ref, ' . $defaultValue . ');';
                }
            } elseif ($type === Type::STD_VECTOR) {
                $info = $this->context->stdContainers[$name];
                $stdContainerInfo = $info;
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    if ($info['size'] !== null) {
                        $boxCtor = 'new ' . $containerType . '(' . $info['typeId'] . ', ' . $info['size'] . ')';
                    } else {
                        $boxCtor = 'new ' . $containerType . '(' . $info['typeId'] . ')';
                    }
                    $code .= 'php::Var ' . $name . ' = php::Var(' . $boxCtor . ');' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
                if (
                    !isset($info['boxExpr']) && $info['size'] !== null
                    && ($defaultValue = $this->getStdContainerDefaultValueExpr($info['type'])) !== null
                ) {
                    $code .= PHP_EOL . $this->getIndent() . 'php::initializeStdContainer(' . $name . '_ref, ' . $defaultValue . ');';
                }
            } elseif ($type === Type::STD_MAP || $type === Type::STD_ORDERED_MAP) {
                $info = $this->context->stdContainers[$name];
                $stdContainerInfo = $info;
                if (isset($info['boxExpr'])) {
                    $code .= 'auto &' . $name . '_ref = php::toStdContainer<' . $info['decl'] . '>(' . $info['boxExpr'] . ', ' . $info['typeId'] . ');';
                } else {
                    $containerType = 'php::StdContainerBox<' . $info['decl'] . '>';
                    $code .= 'php::Var ' . $name . ' = php::Var(new ' . $containerType . '(' . $info['typeId'] . '));' . PHP_EOL;
                    $code .= $this->getIndent() . 'auto &' . $name . '_ref = ' . $name . '.toBox<' . $containerType . '>()->container;';
                }
            } elseif ($type === Type::STREAM || $type === Type::BIGINT || $type === Type::DECIMAL || $type === Type::BIGFLOAT) {
                $code .= Type::VAR . ' ' . $name . ';';
            } else {
                $code .= $type . ' ' . $name;
                if (array_key_exists($name, $this->context->localVarInitializers)) {
                    $code .= ' = ' . $this->context->localVarInitializers[$name];
                } elseif ($this->isNativeObjectVar($name)) {
                    $code .= ' = nullptr';
                } elseif ($type === Type::INT or $type === Type::FLOAT or $type === Type::BOOL) {
                    $code .= ' = 0';
                }
                $code .= ';';
            }
            $code .= PHP_EOL;
            if (
                $stdContainerInfo !== null
                && isset($stdContainerInfo['class'])
                && $this->isNativeObjectClass($stdContainerInfo['class'])
            ) {
                $rootType = 'std::remove_reference_t<decltype(' . $name . '_ref)>';
                $code .= $this->getIndent() . 'php::NativeContainerRootFrame<' . $rootType . '> '
                    . $name . '_native_root_frame(' . $name . '_ref);' . PHP_EOL;
            }
        }
        return $code;
    }

    protected function genScopeVarDecl(): string
    {
        $code = '';
        if ($this->context->hasMultiLevelBreak) {
            $code .= $this->getIndent() . 'int _brk_flag = 0;' . PHP_EOL;
        }
        if ($this->context->hasMultiLevelContinue) {
            $code .= $this->getIndent() . 'int _cnt_flag = 0;' . PHP_EOL;
        }
        if ($this->context->callableScopeVar !== null) {
            $code .= $this->getIndent() . 'php::CallableScope '
                . $this->context->callableScopeVar . ' = php::getCallableScope('
                . $this->getMethodPtr($this->getFullClassName(), $this->methodDef->name)
                . ', this_);' . PHP_EOL;
        }
        if ($this->context->needsCalledCe) {
            $code .= $this->getIndent()
                . 'zend_class_entry *const _typephp_called_ce = typephp_get_called_ce(this_);'
                . PHP_EOL;
        }
        if ($this->context->needsCalledClass) {
            $code .= $this->getIndent()
                . 'const php::Str _typephp_called_class = typephp_get_called_class(_typephp_called_ce);'
                . PHP_EOL;
        }
        $code .= $this->genLocalVarDecl($this->context->localVars);
        foreach ($this->context->classEntryPtrs as $className => $entry) {
            $code .= $this->getIndent() . 'zend_class_entry *' . $entry . ' = '
                . $this->getClassEntryPtr($className) . ';' . PHP_EOL;
        }
        if ($this->context->nativeObjects !== []) {
            $rootSlots = [];
            foreach ($this->context->nativeObjects as $name => $_class) {
                // `this_` and Native parameters are borrowed from a generated
                // caller which already owns a root slot (nativeConstruct/
                // nativeClone do the same for lifecycle callbacks). Only
                // function-owned pointer slots must be registered here.
                if ($name !== 'this_' && !$this->hasArgument($name) && $this->hasLocalVar($name)) {
                    $rootSlots[] = '&' . $name;
                }
            }
            if ($rootSlots !== []) {
                $code .= $this->getIndent() . 'php::NativeRootSlot _native_root_slots[] = {'
                    . implode(', ', $rootSlots) . '};' . PHP_EOL;
                $code .= $this->getIndent() . 'php::NativeRootFrame _native_root_frame('
                    . '_native_root_slots, ' . count($rootSlots) . ');' . PHP_EOL;
            }
        }
        // Native static calls pass a lightweight Object containing the called
        // class entry. A wrapper can be shared by all calls to the same class,
        // but its initialization must dominate every control-flow branch that
        // may use it. Emitting it at the first call site is unsafe when that
        // site belongs to an if/switch/loop branch that is not executed.
        foreach ($this->context->ceWrappers as $className => $object) {
            $code .= $this->getIndent() . 'Z_PTR_P(' . $object . '.ptr()) = '
                . $this->getClassEntryPtr($className) . ';' . PHP_EOL;
        }
        foreach ($this->context->globalVars as $name => $type) {
            // $GLOBALS is handled via php::globalsArray() at each read site
            if ($name === 'GLOBALS') {
                continue;
            }
            $code .= $this->getIndent() . 'auto &' . $name . ' = ' . $this->escapeGlobalVar($name) . ';' . PHP_EOL;
        }
        foreach ($this->context->objectProps as $name => $info) {
            if (($info['kind'] ?? 'zval') === 'var') {
                $code .= $this->getIndent() . Type::VAR . ' ' . $name . ' = ' . $info['getter'] . ';' . PHP_EOL;
            } else {
                $zvalMacro = ($info['type'] === Type::FLOAT) ? 'Z_DVAL_P' : 'Z_LVAL_P';
                $code .= $this->getIndent() . $info['type'] . ' &' . $name . ' = ' . $zvalMacro . '(' . $info['getter'] . '.unwrap_ptr());' . PHP_EOL;
            }
        }
        foreach ($this->context->staticPropRefs as $info) {
            $code .= $this->getIndent() . 'zval *' . $info['name'] . ' = nullptr;' . PHP_EOL;
            $code .= $this->getIndent() . 'const auto ' . $info['accessorName'] . ' = [&]() {'
                . ' return typephp_get_static_property_cached(' . $info['name'] . ', [&]() {'
                . ' return ' . $info['resolver'] . '; }); };' . PHP_EOL;
        }
        return $code;
    }

    protected function genReturnCode(): string
    {
        if ($this->functionDef->returnsByRef) {
            return $this->getIndent() . 'return ' . Type::REF . '{};';
        }
        if ($this->shouldCheckClosureReturnType()) {
            return $this->genClosureCheckedReturn(self::VALUE_NULL);
        }
        if ($this->functionDef->returnType === Type::VOID) {
            return '';
        }
        if ($this->getNativeObjectReturnType($this->functionDef) !== null) {
            $class = $this->getReturnClass();
            $pointerType = $this->getNativeObjectPointerType($class);
            return $this->getIndent() . 'return static_cast<' . $pointerType . '>('
                . 'php::nativeGcRequireObject(nullptr, "' . addslashes($class) . '"));';
        }
        if ($this->functionDef->returnTypeCheck && !$this->context->inClosure) {
            return $this->genUnionCheckedReturn(self::VALUE_NULL);
        }
        if (
            $this->functionDef->returnType === Type::INT
            or $this->functionDef->returnType === Type::FLOAT
            or $this->functionDef->returnType === Type::BOOL
        ) {
            return $this->getIndent() . 'return 0;';
        } else {
            return $this->getIndent() . 'return ' . self::VALUE_NULL . ';';
        }
    }

    protected function parseFullyQualifiedName(Node\Name\FullyQualified $expr): string
    {
        return $expr->name;
    }
}
