<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp;

use Ajaxray\AnsiKit\AnsiTerminal;
use Ajaxray\AnsiKit\Components\Progressbar;
use MJS\TopSort\Implementations\StringSort;
use TypePhp\Analysis\SsaBuilder;
use TypePhp\Backend\CompilerFactory;
use TypePhp\Build\CompileOptions;
use TypePhp\Build\FileScanner;
use TypePhp\Build\NativeCommandOptionsTrait;
use TypePhp\Build\NativeBuilder;
use TypePhp\Build\PrecompiledHeaderManager;
use TypePhp\Build\SourcePipelineTrait;
use TypePhp\Build\WasmInterfaceGenerator;
use TypePhp\Config\ProjectYamlLoader;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;
use TypePhp\Build\ResourceCompilationTrait;
use TypePhp\Entity\ArgInfo;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ClassLikeDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\FunctionDef;
use TypePhp\Entity\InterfaceDef;
use TypePhp\Entity\InterfacePropertyDef;
use TypePhp\Entity\MethodDef;
use TypePhp\Entity\PropertyDef;
use TypePhp\Exception\Redo;
use TypePhp\Exception\Skip;
use TypePhp\Exception\SyntaxError;
use TypePhp\Generator\DefaultArgumentGenerator;
use TypePhp\Generator\LibraryImportStubGenerator;
use TypePhp\Generator\Symbol;
use TypePhp\Metadata\Constants;
use TypePhp\Platform\PlatformFactory;
use TypePhp\Platform\Wasi;
use TypePhp\Platform\Windows;
use TypePhp\Resolver\Reflection;
use TypePhp\Resolver\ClassConstantValueTrait;
use TypePhp\Transform\Visitor;
use TypePhp\Transform\ConstructorLowering;
use TypePhp\Transform\ConstantExpressionValidationVisitor;
use TypePhp\Transform\PropertyHookLowering;
use TypePhp\Transform\RuntimeAttributeFactoryLowering;
use TypePhp\Transform\VoidCastValidationVisitor;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\CloningVisitor;
use function TypePhp\StubGenerator\generateStubFile;

class Translator extends Preprocessor
{
    private const string TRAIT_ORIGIN_ATTRIBUTE = 'typephp_trait_origin';
    private const string TRAIT_METHOD_ATTRIBUTE = 'typephp_trait_method';
    use DefaultArgumentGenerator;
    use NativeCommandOptionsTrait;
    use SourcePipelineTrait;
    use ResourceCompilationTrait;
    use ClassConstantValueTrait;

    public const string VERSION = '0.6.8';
    public const string APP_NAME = 'TypePHP Compiler (AOT)';

    protected bool $hasExplicitOutput = false;
    protected ?string $explicitOutputExtension = null;
    protected array $sourceDirs = [];
    private ?ProjectYamlLoader $projectYamlLoader = null;
    private ?NativeBuilder $nativeBuilder = null;
    protected array $ignorePaths = [];
    protected array $argInfoHeaderFiles = [];
    protected array $registerSymbols = [];

    // Windows resource file configuration (icon, version info, etc.)
    protected array $resourceConfig = [];
    protected array $globalHeaders = [
        'cstring',
        'phpx.h',
        'phpx_helper.h',
        'phpx_big_int.h',
        'phpx_big_float.h',
        'phpx_decimal.h',
        'phpx_python.h',
        'typephp_helper.h',
        'typephp_fiber_generator.h',
        'phpx_std.h',
    ];
    /**
     * @var array<string>
     */
    protected array $classCeList = [];
    protected array $classCeInfo = [];

    protected function isConstructorNativeFunction(FunctionDef $func): bool
    {
        return $func->method && str_ends_with($func->name, self::NAMESPACE_SEPARATOR . '__construct');
    }

    public function __construct(string $rootPath)
    {
        parent::__construct($rootPath);
        $this->climate->arguments->add(Constants::COMPILER_OPTIONS);
        $this->preprocessArgvAdvanced();
        $this->climate->arguments->parse();

        // Only read the command-line arguments here; do not apply them yet
        // (they are applied after YAML parsing). This preserves the priority:
        // command line > YAML > defaults.
        $this->internalFunctions = [];
        foreach (get_defined_functions()['internal'] as $functionName) {
            $function = Reflection::getFunction($functionName);
            if ($function !== null && Reflection::isTypePhpExtension($function->getExtensionName())) {
                continue;
            }
            $this->internalFunctions[$functionName] = true;
        }
        unset($this->internalFunctions[self::ENTRY_FUNCTION]);
        $this->internalConstants = $this->loadInternalConstants();
        if ($this->climate->arguments->defined('help')) {
            $this->showUsage();
            exit(0);
        }
        if ($this->climate->arguments->defined('version')) {
            $this->showVersion();
            exit(0);
        }

        // Handle --no-color early so all subsequent output is colorless.
        if ($this->climate->arguments->defined('no-color')) {
            $this->climate->forceAnsiOff();
        }


        // Detect the OS, the compiler, and (on Windows) the PHP lib files.
        $this->detectPlatform();
    }

    protected function loadInternalConstants(): array
    {
        $groups = get_defined_constants(true);
        if (!is_array($groups)) {
            return get_defined_constants();
        }

        $constants = [];
        foreach ($groups as $groupName => $group) {
            // User constants in the compiler process belong to the compiled
            // program's runtime state and must not be expanded in the static phase.
            if (strcasecmp((string) $groupName, 'user') === 0
                || Reflection::isTypePhpExtension($groupName)
                || !is_array($group)) {
                continue;
            }
            foreach ($group as $name => $value) {
                $constants[$name] = $value;
            }
        }
        return $constants;
    }

    /**
     * Detect the OS, the compiler, and (on Windows) the PHP lib files.
     */
    protected function detectPlatform(): void
    {
        try {
            $targetPlatform = $this->climate->arguments->defined('target-platform')
                ? (string) $this->climate->arguments->get('target-platform')
                : '';
            if ($targetPlatform === 'wasm32-wasip1' || $targetPlatform === 'wasm32-wasi') {
                throw new \RuntimeException('WASI Preview 1 is not supported; use wasm32-wasip2');
            }
            if ($targetPlatform === 'wasm32-wasip2' || $targetPlatform === 'wasm32-unknown-wasip2') {
                $detectedTarget = getenv('TYPEPHP_WASI_TARGET');
                $this->platform = new Wasi(
                    is_string($detectedTarget) && $detectedTarget !== '' ? $detectedTarget : $targetPlatform,
                );
            } else {
                $this->platform = PlatformFactory::create();
            }
            $this->cppCompiler = $this->platform instanceof Wasi
                ? $this->platform->getDefaultCompiler()
                : CompilerFactory::detectCompilerName($this->platform);

            if ($this->platform instanceof Windows) {
                $libInfo = $this->platform->detectPhpLibs($this->getPhpDir());
                $this->windowsPhpEmbedLib = $libInfo['embed'];
                $this->windowsPhpCoreLib = $libInfo['core'];
                $this->isPhpZts = $libInfo['is_zts'];

                $this->platform = new Windows(
                    phpLibs: [$this->windowsPhpCoreLib, $this->windowsPhpEmbedLib],
                    isZts: $this->isPhpZts,
                    phpSdkPath: $this->getPhpDir() . '\\SDK'
                );
            }

            $this->compilerBackend = CompilerFactory::createByName($this->cppCompiler, $this->platform);
            $backendName = $this->compilerBackend->getName();
            if ($this->platform instanceof Wasi) {
                $clangVersion = getenv('TYPEPHP_WASI_CLANG_VERSION');
                $backendName = 'LLVM Clang'
                    . (is_string($clangVersion) && $clangVersion !== '' ? " {$clangVersion}" : '');
            }
            $label = $this->platform instanceof Wasi ? 'Initialized target/toolchain' : 'Initialized platform/backend';
            $this->climate->info(
                "{$label}: {$this->platform->getName()} + {$backendName} ({$this->compilerBackend->getCompilerCommand()})"
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function parseArgv(array $argv)
    {
        $path = null;
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] !== '' && $argv[$i][0] !== '-') {
                $path = $argv[$i];
                break;
            }
        }
        if (empty($path)) {
            $this->showUsage();
            exit(1);
        }
        return $path;
    }

    public function showUsage(): void
    {
        $climate = $this->climate;
        $this->showVersion();
        $climate->br();

        global $argv;
        $cmd = $argv[0];

        $climate->bold('USAGE:');
        $climate->tab()->out($cmd . ' <file/dir/config.yml> [options]');
        $climate->br();

        $climate->bold('ARGUMENTS:');
        $climate->tab()->out('<file>    Input PHP file/directory/YAML config to compile');
        $climate->br();

        $climate->bold('EXAMPLES:');
        $climate->tab()->out($cmd . ' hello.php');
        $climate->tab()->out($cmd . ' bench.php -O2');
        $climate->tab()->out($cmd . ' project/config.yml -O2');
        $climate->tab()->out($cmd . ' my-ext/ -O2 -o myapp -m ext');
        $climate->tab()->out($cmd . ' app.php -r -O2 -- --flag1 value1');
        $climate->br();

        $climate->bold('OPTIONS:');
        $climate->tab()->out('-O <level>           Optimization level (0-3, default: 0)');
        $climate->tab()->out('--profile            Enable performance profiling (adds -lprofiler, forces recompile)');
        $climate->tab()->out('-d, --debug            Enable debug mode (auto-disable optimizations, add debug symbols)');
        $climate->tab()->out('-o, --output <file>  Output binary name (default: input basename)');
        $climate->tab()->out('-v, --version        Show version');
        $climate->tab()->out('-h, --help           Show this help message');
        $climate->tab()->out('-f, --force          Force recompile phpx misc files (ignore cache)');
        $climate->tab()->out('-m, --mode <mode>    Compilation mode: bin (binary), lib (shared library), or ext (PHP extension); default: bin');
        $climate->tab()->out('-r, --run           Run the compiled binary after build');
        $climate->tab()->out('-j, --job <num>      Number of parallel compilation jobs (default: 4)');
        $climate->tab()->out('--cxx-std <ver>      C++ standard version (c++17, c++20, etc., default: c++17)');
        $climate->tab()->out('--march <arch>       Target CPU instruction set (e.g. native, x86-64-v3, armv8-a)');
        $climate->tab()->out('--target-platform <triple> Cross-compilation target triple (e.g. aarch64-linux-gnu)');
        $climate->tab()->out('--wasm[=profile]     Build WASI component (default) or browser output');
        $climate->tab()->out('--gen-python-helper <module> [--output-dir <dir>] Generate a Python namespace IDE helper');
        $climate->tab()->out('--convert-python-to-php <file.py> Convert Python source to TypePHP source');
        $climate->tab()->out('--generate-completion=bash Generate Bash completion script');
        $climate->tab()->out('--lto                Enable Link Time Optimization (-flto)');
        $climate->tab()->out('--no-literal-strings Disable literal strings optimization');
        $climate->tab()->out('--php-version <ver>  PHP language version to accept (8.4-8.5, default: 8.5)');
        $climate->tab()->out('--no-progress        Disable progress bar, output per-file compilation progress line by line');
        $climate->tab()->out('--no-console         Hide console window (Windows only, GUI application)');
        $climate->tab()->out('--no-color           Disable ANSI color output');
        $climate->tab()->out('--sanitize <type>    Enable sanitizers (address, undefined, etc.)');
        $climate->tab()->out('--build-dir <dir>   Specify build directory for generated C++ code (default: <root>/build)');
        $climate->tab()->out('--dry                Dry run: only generate C++ code, skip compilation and linking');
        $climate->tab()->out('-I, --include-path <dir> Add an additional C++ include directory (repeatable)');
        $climate->tab()->out('-D, --define <macro>  Define a preprocessor macro (repeatable, e.g. -D FOO=bar)');
        $climate->tab()->out('--format             Enable clang-format code formatting (disabled by default)');
        $climate->tab()->out('-l, --link-lib <lib> Link against a library (repeatable, e.g. -lcurl)');
        $climate->tab()->out('-L, --link-path <dir> Add a library search path (repeatable, e.g. -L/usr/local/lib)');
        $climate->br();
    }

    /**
     * Apply command-line arguments (called after YAML parsing so command-line
     * arguments take the highest priority).
     */
    protected function applyCommandLineArguments(): void
    {
        $this->applyPhpVersionCommandLineArgument();

        // Optimization level
        if ($this->climate->arguments->defined('optimize')) {
            $this->optimizeLevel = $this->climate->arguments->get('optimize');
        }

        // Build mode
        if ($this->climate->arguments->defined('mode')) {
            $this->setBuildMode($this->climate->arguments->get('mode'));
        }

        // Debug line number
        if ($this->climate->arguments->defined('debug-line')) {
            $this->debugLine = intval($this->climate->arguments->get('debug-line'));
        }

        // Maximum number of parallel jobs
        if ($this->climate->arguments->defined('job')) {
            $this->maxJob = intval($this->climate->arguments->get('job'));
        }

        // Debug mode
        if ($this->climate->arguments->defined('debug')) {
            $this->debug = true;
        }

        // Disable literal string optimization
        if ($this->climate->arguments->defined('no-literal-strings')) {
            $this->noLiteralStrings = true;
        }

        // Enable profiling (forces recompilation of misc files so the PPROF_ON
        // macro takes effect; Linux only)
        if ($this->climate->arguments->defined('profile')) {
            if (!$this->isLinux()) {
                $this->climate->error('--profile is only supported on Linux (requires gperftools)');
                exit(1);
            }
            $this->enableProfiler = true;
        }

        // Disable the progress bar
        if ($this->climate->arguments->defined('no-progress')) {
            $this->noProgress = true;
        }

        // Hide the console window
        if ($this->climate->arguments->defined('no-console')) {
            $this->noConsole = true;
        }

        // Sanitizer
        if ($this->climate->arguments->defined('sanitize')) {
            $this->sanitize = $this->climate->arguments->get('sanitize');
        }

        // C++ standard version
        if ($this->climate->arguments->defined('cxx-std')) {
            $this->cxxStd = $this->climate->arguments->get('cxx-std');
        }

        // Target CPU instruction set
        if ($this->climate->arguments->defined('march')) {
            $this->march = $this->climate->arguments->get('march');
        }

        // Cross-compilation target platform
        if ($this->climate->arguments->defined('target-platform')) {
            $this->targetPlatform = $this->climate->arguments->get('target-platform');
        }

        // Output file name/path
        if ($this->climate->arguments->defined('output')) {
            $this->setOutputPath($this->climate->arguments->get('output'));
        }

        // Build directory
        if ($this->climate->arguments->defined('build-dir')) {
            $buildDir = $this->climate->arguments->get('build-dir');
            if (!empty($buildDir)) {
                $this->setBuildDir($buildDir);
            }
        }

        // Dry-run mode
        if ($this->climate->arguments->defined('dry')) {
            $this->dryRun = true;
        }

        // User-defined C++ include paths (parsed directly from argv to support
        // multiple values)
        if ($this->hasRepeatableArgvFlag(['-I', '--include-path'])) {
            $this->userIncludePaths = $this->parseRepeatableArgv(['-I', '--include-path']);
        }
        // User-defined preprocessor macros (parsed directly from argv to support
        // multiple values)
        if ($this->hasRepeatableArgvFlag(['-D', '--define'])) {
            $this->userDefines = $this->parseRepeatableArgv(['-D', '--define']);
        }

        // Link-time optimization
        if ($this->climate->arguments->defined('lto')) {
            $this->enableLto = true;
        }

        // clang-format code formatting (disabled by default; requires explicit --format)
        if ($this->climate->arguments->defined('format')) {
            $this->enableCodeFormattingIfAvailable('--format');
        }

        // User-defined link libraries (parsed directly from argv to support
        // multiple values)
        if ($this->hasRepeatableArgvFlag(['-l', '--link-lib'])) {
            $this->linkLibs = $this->parseRepeatableArgv(['-l', '--link-lib']);
        }
        // User-defined library search paths (parsed directly from argv to support
        // multiple values)
        if ($this->hasRepeatableArgvFlag(['-L', '--link-path'])) {
            $this->linkPaths = $this->parseRepeatableArgv(['-L', '--link-path']);
        }
    }

    /** Apply this option early because YAML source conditions depend on it. */
    protected function applyPhpVersionCommandLineArgument(): void
    {
        if ($this->climate->arguments->defined('php-version')) {
            $this->setPhpVersion((string) $this->climate->arguments->get('php-version'));
        }
    }

    /**
     * Parse repeatable arguments from the raw $argv, supporting both the
     * "-X val" and "--long val" forms. CLImate's "multiple" option only keeps
     * the last value, so these must be parsed manually.
     *
     * @param string[] $flags Flags to match, e.g. ['-I', '--include-path']
     * @return string[] All collected values
     */
    protected function parseRepeatableArgv(array $flags): array
    {
        global $argv;
        $values = [];
        for ($i = 1; $i < count($argv); $i++) {
            // Exact flag match (e.g. -I, --include-path)
            if (in_array($argv[$i], $flags, true) && isset($argv[$i + 1]) && $argv[$i + 1] !== '' && $argv[$i + 1][0] !== '-') {
                $values[] = $argv[$i + 1];
                $i++; // Skip the value
            }
            // Combined form: -I/path or --include-path=/path
            elseif (!$this->isLongFlagWithEquals($argv[$i], $flags, $values)) {
                // Check short-flag combined form: -I/path
                foreach ($flags as $flag) {
                    if (strlen($flag) === 2 && $flag[0] === '-') {
                        $short = substr($flag, 1);
                        if (preg_match('/^-' . preg_quote($short, '/') . '(.+)$/', $argv[$i], $m)) {
                            $values[] = $m[1];
                        }
                    }
                }
            }
        }
        return $values;
    }

    protected function hasRepeatableArgvFlag(array $flags): bool
    {
        global $argv;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (in_array($arg, $flags, true)) {
                return true;
            }

            foreach ($flags as $flag) {
                if (str_starts_with($arg, $flag . '=')) {
                    return true;
                }
                if (strlen($flag) === 2 && $flag[0] === '-') {
                    $short = substr($flag, 1);
                    if (preg_match('/^-' . preg_quote($short, '/') . '(.+)$/', $arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Handle long flags in the --flag=value form.
     */
    private function isLongFlagWithEquals(string $arg, array $flags, array &$values): bool
    {
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--') && preg_match('/^' . preg_quote($flag, '/') . '=(.+)$/', $arg, $m)) {
                $values[] = $m[1];
                return true;
            }
        }
        return false;
    }

    private function showVersion(): void
    {
        $this->climate->bold()->out(self::APP_NAME . ' v' . self::VERSION);
    }

    private function enableCodeFormattingIfAvailable(string $source): void
    {
        $clangFormatVersion = shell_exec('clang-format --version');
        if (!empty($clangFormatVersion)) {
            $this->formatCode = true;
            return;
        }

        $this->climate->warning($source . ' requested but clang-format not found, skipping formatting');
    }

    protected function formatCppCode(string $file): void
    {
        if (!$this->formatCode) {
            return;
        }

        $cmd = 'cd ' . escapeshellarg($this->rootPath) . ' && clang-format -i ' . escapeshellarg($file);
        $this->climate->info('format: ' . $this->getRelativePath($file));
        $this->climate->comment($cmd);
        shell_exec($cmd);
    }

    public function save(string $code, string $file): void
    {
        $this->writeFile($file, $code);
        $this->formatCppCode($file);
    }

    public function convertFile(string $file): ?string
    {
        $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
        try {
            if (!$this->declarationExpressionsFinalized) {
                $this->finalizeDeclarationExpressions(array_keys($this->preparedFileAsts));
            }
            $this->finalizeMethodOverrideFlags();
            $file = realpath($file);
            $phpCode = $this->loadFile($file);
            $this->localHeaders = [];
            while (true) {
                try {
                    $cppCode = $this->doConvert($phpCode);
                    $cppFile = $this->getCppFile($file);
                    if ($cppCode === '') {
                        $this->removeEmptyTranslationUnitArtifacts($cppFile);
                    } else {
                        $this->save($cppCode, $cppFile);
                    }
                    // Generate the stub file, which depends on the use statements
                    // and other info collected during the convert phase.
                    $this->genStubFile($this->file);
                    return $cppCode === '' ? null : $cppFile;
                } catch (Redo $e) {
                    continue;
                }
            }
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }

    /**
     * Remove artifacts left by an earlier build when a PHP source no longer
     * emits a C++ translation unit. Trait-only files are the common case.
     */
    private function removeEmptyTranslationUnitArtifacts(string $cppFile): void
    {
        $objectFile = $this->getObjectFile($cppFile);
        foreach ([$cppFile, $objectFile, $this->getMiscObjectCacheMetadataFile($objectFile)] as $artifact) {
            if (is_file($artifact) && !unlink($artifact)) {
                throw new \RuntimeException("Unable to remove stale generated artifact: {$artifact}");
            }
        }
    }

    public function getRegisterClassFunctionArgs(ClassDef|InterfaceDef $classDef): string
    {
        return implode(', ', $this->getRegisterClassFunctionCeList($classDef));
    }

    /**
     * Initialize the new Platform and Backend abstraction layers.
     * This is an incremental migration that preserves backward compatibility.
     */
    protected function initializeNewArchitecture(): void
    {
        try {
            $platform = $this->platform ?? PlatformFactory::create();
            $this->platform = $platform;

            // Auto-detect the platform and compiler
            $result = CompilerFactory::autoDetect($this->cppCompiler, $platform);
            $this->platform = $result['platform'];
            $this->compilerBackend = $result['compiler'];

            $this->climate->info(
                "Initialized new architecture: {$this->platform->getName()} + {$this->compilerBackend->getName()}"
            );
        } catch (\Exception $e) {
            // Fall back to the legacy logic if initialization fails
            $this->climate->warning(
                "Failed to initialize new architecture: {$e->getMessage()}. Using legacy mode."
            );
            $this->platform = null;
            $this->compilerBackend = null;
        }
    }

    /**
     * Set the C++ compiler (read from the config file).
     */
    public function setCppCompiler(string $compiler): void
    {
        $this->cppCompiler = $compiler;
        $this->climate->info("Using compiler from config: {$this->cppCompiler}");

        // Re-initialize the Backend
        $this->initializeNewArchitecture();
    }

    public function setBuildMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        $mode = match ($mode) {
            'binary', 'cli' => self::BUILD_MODE_BIN,
            'extension' => self::BUILD_MODE_EXT,
            'library', 'shared', 'dll', 'dylib', 'so' => self::BUILD_MODE_LIB,
            default => $mode,
        };

        if (!in_array($mode, [self::BUILD_MODE_BIN, self::BUILD_MODE_EXT, self::BUILD_MODE_LIB], true)) {
            $this->error("Invalid build mode `{$mode}`. Expected bin, lib, or ext.");
        }

        $this->buildMode = $mode;
    }

    public function setTargetName(string $name): void
    {
        // If a path was given (contains a directory separator), split it into
        // directory and file name.
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            $this->outputDir = dirname($name);
            $name = basename($name);
        }
        $name = str_replace(['-', '*'], '_', $name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $this->climate->red('The target name `' . $name . '` must be a valid identifier');
            exit(1);
        }
        $realTargetPath = $this->rootPath . '/' . $name;
        if (is_dir($realTargetPath)) {
            $this->climate->red('The target name `' . $name . '` must not be a directory');
            exit(1);
        }
        $this->targetName = $name;
    }

    /**
     * Set an explicit output path without using its extension in generated symbols.
     */
    public function setOutputPath(string $path): void
    {
        if (str_contains($path, '/') || str_contains($path, '\\')) {
            $this->outputDir = dirname($path);
            $path = basename($path);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $this->explicitOutputExtension = '.' . $extension;
            $path = substr($path, 0, -strlen($this->explicitOutputExtension));
        } else {
            $this->explicitOutputExtension = null;
        }

        $this->hasExplicitOutput = true;
        $this->setTargetName($path);
    }

    protected function getTargetFileName(): string
    {
        $targetFile = $this->targetName;
        if ($this->isBuildModeLib() && !$this->isWindows() && !$this->hasExplicitOutput) {
            $targetFile = 'lib' . $targetFile;
        }

        $extension = $this->explicitOutputExtension ?? $this->getPlatform()->getTargetExtension($this->buildMode);
        if ($extension !== '' && !str_ends_with($targetFile, $extension)) {
            $targetFile .= $extension;
        }

        if ($this->outputDir !== '') {
            $targetFile = rtrim($this->outputDir, '/\\') . '/' . $targetFile;
        }

        return $targetFile;
    }

    public function getLibraryImportStubFile(): string
    {
        $directory = $this->outputDir !== '' ? $this->outputDir : (getcwd() ?: $this->rootPath);
        return rtrim($directory, '/\\') . '/' . $this->targetName . '.stub.php';
    }

    /** @param array<string> $files */
    public function genLibraryImportStub(array $files): string
    {
        $file = $this->getLibraryImportStubFile();
        $generator = new LibraryImportStubGenerator($this->parser, $this->printer);
        $this->writeFile($file, $generator->generate($files, $this->externalImportStubFiles));
        $this->climate->info('generate library import stub: ' . $this->getRelativePath($file));
        return $file;
    }

    public function preprocessArgvAdvanced(): void
    {
        global $argv;
        $processed = [$argv[0]];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (preg_match('/^-([a-zA-Z])(.+)$/', $arg, $matches)) {
                $option = $matches[1];
                $value = $matches[2];
                $processed[] = "-{$option}";
                $processed[] = $value;
            } elseif (preg_match('/^-([a-zA-Z]{2,})$/', $arg, $matches)) {
                $options = str_split($matches[1]);
                foreach ($options as $opt) {
                    $processed[] = "-{$opt}";
                }
            } else {
                $processed[] = $arg;
            }
        }
        $argv = $processed;
    }

    public function genDataDeclarations(string $file): void
    {
        $projectNamespace = $this->getProjectNamespace();
        $lines[] = '#include <phpx.h>';
        $lines[] = '#include <typephp_helper.h>';
        $lines[] = PHP_EOL;
        $lines[] = 'namespace ' . $projectNamespace . ' {';
        $lines[] = PHP_EOL;

        // Embedded binaries populate the CLI script fields in $_SERVER at
        // request startup, even when the source does not reference $_SERVER.
        if ($this->isBuildModeBin() && !$this->hasGlobalVar('_SERVER')) {
            $this->addGlobalVar('_SERVER', Type::ARRAY);
        }

        foreach ($this->globalVars as $name => $type) {
            $cppType = isset($this->nativeGlobalObjects[$name])
                ? $this->getNativeObjectPointerType($this->nativeGlobalObjects[$name])
                : Type::VAR;
            $lines[] = 'extern THREAD_LOCAL ' . $cppType . ' ' . $this->escapeGlobalVar($name) . ';';
        }
        foreach ($this->nativeStaticInitializers as $name => $_) {
            $lines[] = 'extern THREAD_LOCAL bool ' . $this->escapeGlobalVar($name) . ';';
        }

        if ($this->literalStrings) {
            $lines[] = 'ZEND_ATTRIBUTE_CONST ' . Type::STR . ' &'
                . self::LITERAL_STRING_GETTER . '(uint32_t index);' . PHP_EOL;
        }

        foreach ($this->constants as $name => $constant) {
            $lines[] = 'extern ' . $constant->type . ' ' . $name . ';';
        }

        $pythonModuleDeclarations = $this->genPythonModuleDataDeclarations();
        if ($pythonModuleDeclarations !== '') {
            $lines[] = $pythonModuleDeclarations;
        }

        $lines[] = 'enum class RequestClassId : uint32_t {};';
        $lines[] = 'enum class PersistentClassId : uint32_t {};';
        $lines[] = 'enum class RequestFuncId : uint32_t {};';
        $lines[] = 'enum class PersistentFuncId : uint32_t {};';
        $lines[] = 'enum class PersistentPropertyId : uint32_t {};' . PHP_EOL;

        $lines[] = 'zend_class_entry *get_class(RequestClassId class_id, const php::Str &class_name);';
        $lines[] = 'zend_function *get_func(RequestFuncId func_id, const php::Str &func_name);';
        $lines[] = 'zend_function *get_method(RequestFuncId func_id, const php::Str &method_name, RequestClassId class_id, const php::Str &class_name);';
        $lines[] = 'zend_class_entry *get_persistent_class(PersistentClassId class_id, const php::Str &class_name);';
        $lines[] = 'zend_function *get_persistent_func(PersistentFuncId func_id, const php::Str &func_name);';
        $lines[] = 'zend_function *get_persistent_method(PersistentFuncId func_id, const php::Str &method_name, PersistentClassId class_id, const php::Str &class_name);';
        $lines[] = 'uint32_t get_persistent_prop(PersistentPropertyId prop_id, const php::Str &prop_name, const php::Str &class_name);' . PHP_EOL;

        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $lines[] = 'extern ' . Type::VAR . ' ' . $constName . ';' . PHP_EOL;
                }
            }
        }

        $lines[] = '}  // namespace ' . $projectNamespace;
        $lines[] = 'using namespace ' . $projectNamespace . ';';

        $code = implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
        $this->writeFile($file, $code);
    }

    public function genExtension(): string
    {
        $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
        try {
            return $this->doGenExtension();
        } finally {
            $this->restoreCompilerPhase($previousPhase);
        }
    }

    private function doGenExtension(): string
    {
        if ($this->isBuildModeBin()) {
            if (!$this->hasFunction(self::ENTRY_FUNCTION)) {
                $this->climate->red('When the build mode is a binary executable file, the `main()` function must be defined');
                exit(1);
            }
        }
        $file = $this->getBuildDir() . '/extension-' . $this->targetName . '.cc';
        $this->localHeaders = $this->argInfoHeaderFiles;
        $this->genClassCeList();
        $this->indentLevel++;

        $code = $this->genIncludeHeaderFiles();

        if ($this->isBuildModeEmbed()) {
            $code .= '#include <typephp_runtime.h>' . PHP_EOL;
        }

        if ($this->isBuildModeLib() && !$this->isWindows()) {
            // PHPX's embedded runtime references this CLI-only symbol even when main() is disabled.
            $code .= 'extern "C" void save_ps_args(int, char **) {}' . PHP_EOL;
        }

        if ($this->isBuildModeBin() && !$this->isWasiTarget()) {
            $cliHeaders = [
                '#include "php_cli_process_title.h"',
                '#include "php_cli_process_title_arginfo.h"',
            ];
            $code .= 'extern "C" {' . PHP_EOL;
            $code .= implode(PHP_EOL, $cliHeaders) . PHP_EOL;
            $code .= '}' . PHP_EOL;
        }

        $projectNamespace = $this->getProjectNamespace();
        $code .= 'namespace ' . $projectNamespace . ' {' . PHP_EOL . PHP_EOL;

        $code .= "// global vars \n";
        foreach ($this->globalVars as $name => $type) {
            $cppType = isset($this->nativeGlobalObjects[$name])
                ? $this->getNativeObjectPointerType($this->nativeGlobalObjects[$name])
                : Type::VAR;
            $code .= 'THREAD_LOCAL ' . $cppType . ' ' . $this->escapeGlobalVar($name)
                . (isset($this->nativeGlobalObjects[$name]) ? ' = nullptr' : '') . ';' . PHP_EOL;
        }
        foreach ($this->nativeStaticInitializers as $name => $_) {
            $code .= 'THREAD_LOCAL bool ' . $this->escapeGlobalVar($name) . ' = false;' . PHP_EOL;
        }

        $code .= "// class register functions \n";
        foreach ($this->classCeList as $ce) {
            $code .= 'zend_class_entry *' . $ce . ';' . PHP_EOL;
        }

        $code .= "// class entry \n";
        // Ensure the array has at least one element to avoid C/C++ compile errors.
        $code .= 'static THREAD_LOCAL zend_class_entry *' . self::PREFIX . self::CLASS_MAP . '[' . max(1, count($this->classMap)) . '];' . PHP_EOL;
        // Internal/compiled symbols have module lifetime. They are initialized
        // lazily after PHP startup, so disable_functions/disable_classes have
        // already finalized the runtime tables. ZTS publishes them atomically.
        $code .= 'static php::PersistentCacheSlot<zend_class_entry *> ' . self::PREFIX . self::PERSISTENT_CLASS_MAP . '[' . max(1, count($this->persistentClassMap)) . ']{};' . PHP_EOL;

        $code .= "// func \n";
        $code .= 'static THREAD_LOCAL zend_function *' . self::PREFIX . self::FUNC_MAP . '[' . max(1, count($this->funcMap)) . '];' . PHP_EOL;
        $code .= 'static php::PersistentCacheSlot<zend_function *> ' . self::PREFIX . self::PERSISTENT_FUNC_MAP . '[' . max(1, count($this->persistentFuncMap)) . ']{};' . PHP_EOL;

        $code .= $this->genPythonModuleStorage();

        $code .= "// property \n";
        // No dynamic propMap: the property offset cache only covers declared
        // properties of compiled/built-in classes (see getPropertyId).
        $code .= 'static php::PersistentCacheSlot<uint32_t> ' . self::PREFIX . self::PERSISTENT_PROP_MAP . '[' . max(1, count($this->persistentPropMap)) . ']{};' . PHP_EOL;

        $code .= "// functions \n";

        $code .= <<<'CODE'
zend_class_entry *get_class(RequestClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(class_id);
    if (UNEXPECTED(php_class_map[index] == nullptr)) {
        php_class_map[index] = php::getClassEntrySafe(class_name);
    }
    return php_class_map[index];
}

zend_function *get_func(RequestFuncId func_id, const php::Str &func_name) {
    const auto index = static_cast<uint32_t>(func_id);
    if (UNEXPECTED(php_func_map[index] == nullptr)) {
        php_func_map[index] = php::getFunction(func_name);
    }
    return php_func_map[index];
}

zend_function *get_method(RequestFuncId func_id, const php::Str &method_name, RequestClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(func_id);
    if (UNEXPECTED(php_func_map[index] == nullptr)) {
        auto ce = get_class(class_id, class_name);
        php_func_map[index] = php::getMethod(ce, method_name);
    }
    return php_func_map[index];
}

zend_class_entry *get_persistent_class(PersistentClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(class_id);
    return php::getPersistentCache(php_persistent_class_map[index], [&]() {
        return php::getClassEntrySafe(class_name);
    });
}

zend_function *get_persistent_func(PersistentFuncId func_id, const php::Str &func_name) {
    const auto index = static_cast<uint32_t>(func_id);
    return php::getPersistentCache(php_persistent_func_map[index], [&]() {
        return php::getFunction(func_name);
    });
}

zend_function *get_persistent_method(PersistentFuncId func_id, const php::Str &method_name, PersistentClassId class_id, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(func_id);
    return php::getPersistentCache(php_persistent_func_map[index], [&]() {
        auto ce = get_persistent_class(class_id, class_name);
        return php::getMethod(ce, method_name);
    });
}

uint32_t get_persistent_prop(PersistentPropertyId prop_id, const php::Str &prop_name, const php::Str &class_name) {
    const auto index = static_cast<uint32_t>(prop_id);
    auto value = php::getPersistentCache(php_persistent_property_map[index], [&]() {
        return php::getPropertyOffset(class_name, prop_name) + 1024;
    });
    return value - 1024;
}
CODE;
        $code .= "\n\n";

        $code .= $this->genPythonModuleGetter();

        $code .= "// literal strings \n";
        if ($this->literalStrings) {
            $code .= 'static ' . Type::STR . ' ' . self::LITERAL_STRINGS . '[] = {' . PHP_EOL;
            foreach ($this->literalStrings as $str => $index) {
                // PHP converts canonical integer-string array keys (for
                // example "0" and "-1") to int. literalStrings only accepts
                // strings, so restore the original key type at this boundary.
                $code .= Type::STR . '{ZEND_STRL("' . $this->escapeString((string) $str) . '"), true}, // [' . $index . ']' . PHP_EOL;
            }
            $code .= '};' . PHP_EOL . PHP_EOL;
            $code .= 'ZEND_ATTRIBUTE_CONST ' . Type::STR . ' &'
                . self::LITERAL_STRING_GETTER . '(uint32_t index) {' . PHP_EOL;
            $code .= $this->getIndent() . 'return ' . self::LITERAL_STRINGS . '[index];' . PHP_EOL;
            $code .= '}' . PHP_EOL . PHP_EOL;
        } else {
            $code .= PHP_EOL;
        }

        $code .= '}  // namespace ' . $projectNamespace . PHP_EOL . PHP_EOL;

        $code .= "// default argument values \n";
        $code .= $this->genDefaultArgumentHelperDefinitions();

        $code .= 'namespace ' . $projectNamespace . ' {' . PHP_EOL . PHP_EOL;
        $code .= "// constants \n";
        foreach ($this->constants as $name => $const) {
            $code .= $const->type . ' ' . $name . ";\n";
        }

        $code .= "// class \n";
        $code .= $this->genRequestArrayDefaultStorage();
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            if ($classDef instanceof ClassDef
                && !$classDef->nativeObject
                && !$classDef->trait
                && !$classDef->enum
            ) {
                $code .= 'static zend_object* (*create_object_' . $classDef->getNamespacedName() . ")(zend_class_entry *class_type);\n";
                $code .= 'static zend_object_handlers property_handlers_' . $classDef->getNamespacedName() . ";\n";
            }
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= Type::VAR . ' ' . $constName . ";\n";
                }
            }
        }
        $code .= $this->genRequestArrayDefaultInitializers();

        $code .= "// clang-format off\n";
        $code .= "static const zend_function_entry ext_functions[] = {\n";
        if ($this->isBuildModeBin() && !$this->isWasiTarget()) {
            $code .= $this->getIndent() . "PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)\n";
            $code .= $this->getIndent() . "PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)\n";
        }

        foreach ($this->symbols->functions() as $functionDef) {
            if ($functionDef->attributeFactory) {
                continue;
            }
            if ($this->isBuildModeExt() and $functionDef->name === self::ENTRY_FUNCTION) {
                continue;
            }
            if ($functionDef->method) {
                continue;
            }
            if ($this->functionUsesNativeObject($functionDef)) {
                continue;
            }
            $fullName = $functionDef->getNamespacedName();
            $zifName = $this->escapeZendFnName($fullName);
            // TypePHP is always strict. Store the flag in the registered
            // zend_function instead of rewriting shared metadata on every call.
            $code .= $this->getIndent() . 'ZEND_RAW_FENTRY("' . $this->escapeString($fullName)
                . '", ZEND_FN(' . $zifName . '), arginfo_' . $zifName
                . ', ZEND_ACC_STRICT_TYPES, NULL, NULL)' . PHP_EOL;
        }
        $code .= $this->getIndent() . "ZEND_FE_END\n};\n// clang-format on" . PHP_EOL . PHP_EOL;

        // minit begin
        $code .= 'PHP_MINIT_FUNCTION(' . $this->getModuleName() . ') {' . PHP_EOL;
        $code .= '// class/interface class entries' . PHP_EOL;
        $code .= 'if (typephp_install_reflection_attribute_handlers() != SUCCESS) {' . PHP_EOL;
        $code .= $this->getIndent() . 'return FAILURE;' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        if (!$this->isWasiTarget()) {
            $code .= 'typephp_register_fiber_generator_class();' . PHP_EOL;
        }
        $code .= $this->genClassPropertyInit() . PHP_EOL;

        $code .= '// register symbols' . PHP_EOL;
        foreach ($this->registerSymbols as $registerSymbolFn) {
            $code .= $registerSymbolFn . '(module_number);' . PHP_EOL;
        }
        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;
        // minit end

        $code .= 'PHP_MSHUTDOWN_FUNCTION(' . $this->getModuleName() . ') {' . PHP_EOL;
        // The cache owns no Zend symbols, but its pointers must not survive a
        // complete module shutdown/startup cycle in an embedded process.
        $code .= 'for (auto &slot : ' . self::PREFIX . self::PERSISTENT_CLASS_MAP . ') {' . PHP_EOL;
        $code .= $this->getIndent() . 'php::resetPersistentCache(slot);' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        $code .= 'for (auto &slot : ' . self::PREFIX . self::PERSISTENT_FUNC_MAP . ') {' . PHP_EOL;
        $code .= $this->getIndent() . 'php::resetPersistentCache(slot);' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        $code .= 'for (auto &slot : ' . self::PREFIX . self::PERSISTENT_PROP_MAP . ') {' . PHP_EOL;
        $code .= $this->getIndent() . 'php::resetPersistentCache(slot);' . PHP_EOL;
        $code .= '}' . PHP_EOL;
        if (!$this->isWasiTarget()) {
            $code .= 'typephp_unregister_fiber_generator_class();' . PHP_EOL;
        }
        $code .= 'typephp_uninstall_reflection_attribute_handlers();' . PHP_EOL;
        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;

        $code .= 'THREAD_LOCAL zval globals_array;' . PHP_EOL;

        // request-level module state initialization
        $code .= 'static void module_init() {' . PHP_EOL;
        $code .= '// register constants' . PHP_EOL;
        foreach ($this->constants as $name => $const) {
            $code .= "{$name} = {$const->value};\n";
            $code .= 'php::fn::define(' . $this->genCharPtr($const->name, true) . ', ' . $name . ');' . PHP_EOL;
        }
        $code .= '// global vars ' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            if ($name == 'GLOBALS') {
                continue;
            }
            if (isset($this->nativeGlobalObjects[$name])) {
                $code .= 'php::nativeGcRegisterRequestRoot(&'
                    . $this->escapeGlobalVar($name) . ');' . PHP_EOL;
                continue;
            }
            $code .= 'php::initGlobal(' . $this->genCharPtr($name) . ', ' . $this->escapeGlobalVar($name) . ');' . PHP_EOL;
        }

        $code .= '// static property ' . PHP_EOL;
        foreach ($this->symbols->classes() as $classDef) {
            // Traits are never instantiated on their own; their static properties
            // live on the classes that use them (where the members are flattened).
            // Initialising a default on the trait itself would write to the trait's
            // static property table and, on PHP >= 8.3, trigger a
            // "Accessing static trait property" deprecation when the value is read
            // through `self::` from a consuming class. Skip traits here; the
            // consuming classes still initialise their own (flattened) copies.
            if ($classDef->trait) {
                continue;
            }
            foreach ($classDef->properties as $property) {
                if (!$property->isStatic() || $property->default === null) {
                    continue;
                }
                if ($property->arrayInitPlan) {
                    $statement = 'php::setStaticProperty('
                        . $this->genCharPtr($classDef->getNamespacedName(false), true) . ', '
                        . $this->genCharPtr($property->name) . ', '
                        . $property->arrayInitPlan->expr . ');' . PHP_EOL;
                    $code .= $this->wrapArrayInitPlan($property->arrayInitPlan, $statement);
                } else {
                    $default = $property->type === Type::FLOAT
                        ? $this->convertFloatExpr($property->default)
                        : $property->default;
                    $statement = 'php::setStaticProperty('
                        . $this->genCharPtr($classDef->getNamespacedName(false), true) . ', '
                        . $this->genCharPtr($property->name) . ', '
                        . 'php::Var(' . $default . '));' . PHP_EOL;
                    $code .= $statement;
                }
            }
        }

        $code .= '// class array constants' . PHP_EOL;
        $code .= $this->genClassArrayConstants();
        $code .= '}' . PHP_EOL . PHP_EOL;
        // module_init end

        // request-level module state cleanup
        $code .= 'static void module_clean() {' . PHP_EOL;
        foreach ($this->globalVars as $name => $type) {
            if ($name != 'GLOBALS') {
                if (isset($this->nativeGlobalObjects[$name])) {
                    $code .= $this->escapeGlobalVar($name) . ' = nullptr;' . PHP_EOL;
                    continue;
                }
                $code .= $this->escapeGlobalVar($name) . '.unset();' . PHP_EOL;
                $code .= 'php::unsetGlobal("' . $name . '");' . PHP_EOL;
            }
        }
        foreach ($this->nativeStaticInitializers as $name => $_) {
            $code .= $this->escapeGlobalVar($name) . ' = false;' . PHP_EOL;
        }
        $code .= $this->genRequestArrayDefaultCleanup();
        foreach ($this->constants as $name => $const) {
            if ($const->type !== Type::VAR) {
                continue;
            }
            $code .= $name . '.unset();' . PHP_EOL;
        }

        $code .= $this->genPythonModuleCleanup();

        $code .= '// class array constants' . PHP_EOL;
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= $constName . ".unset();\n";

                    if (!$classDef instanceof ClassDef || !$classDef->nativeObject) {
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
            }
        }

        // Clean up inherited array constants from child classes
        foreach ($this->symbols->classes() as $className => $classDef) {
            if ($classDef->nativeObject) {
                continue;
            }
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && $this->symbols->hasClass($parentName)) {
                $parentDef = $this->symbols->class($parentName);
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }

            foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                foreach ($interfaceDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, php::null);\n";
                    }
                }
            }
        }

        // User-code symbols have request lifetime regardless of the build mode.
        // Embedded/library hosts may start more than one Zend request in the
        // same process, so never let these pointers survive RSHUTDOWN.
        // Internal/compiled symbols remain in the module-lifetime persistent maps.
        $code .= 'std::memset(' . self::PREFIX . self::FUNC_MAP . ', 0, sizeof(' . self::PREFIX . self::FUNC_MAP . '));' . PHP_EOL;
        $code .= 'std::memset(' . self::PREFIX . self::CLASS_MAP . ', 0, sizeof(' . self::PREFIX . self::CLASS_MAP . '));' . PHP_EOL;

        $code .= '}' . PHP_EOL . PHP_EOL;
        // module_clean end

        $moduleName = $this->getModuleName();
        // rinit begin
        $code .= 'PHP_RINIT_FUNCTION(' . $moduleName . ') {' . PHP_EOL;
        $code .= 'php::request_init();' . PHP_EOL;
        $code .= 'module_init();' . PHP_EOL;

        if ($this->isBuildModeBin()) {
            $entryFunction = $this->symbols->function(self::ENTRY_FUNCTION);
            // FunctionDef::sourceFile comes from loadFile()'s realpath(), so the
            // CLI script fields always identify main()'s canonical absolute file.
            $entryFile = $entryFunction->sourceFile;
            $entryFileArg = $this->genCharPtr($entryFile, true);
            $entryLineOffset = max(0, $entryFunction->startLine - 1);
            if (count($entryFunction->argInfoList) == 2) {
                $entryScript = 'global $argc, $argv; main($argc, $argv);';
            } else {
                $entryScript = 'main();';
            }

            $entryScriptArg = $this->genCharPtr($entryScript, true);
            if ($entryLineOffset > 0) {
                // entryLineOffset is main()'s source start line minus one. The
                // generated std::string(N, '\n') supplies N padding newlines at
                // runtime, so the eval() entry call is reported on main()'s
                // original PHP source line. Constructing the padding at runtime
                // avoids embedding hundreds of escaped newlines in the C++ file.
                $entryScriptArg = 'std::string(' . $entryLineOffset . ", '\\n') + " . $entryScriptArg;
            }

            $code .= 'php::eval(' . $entryScriptArg . ', ' . $entryFileArg . ');' . PHP_EOL;
        }

        $code .= 'return SUCCESS;' . PHP_EOL;
        $code .= '}' . PHP_EOL . PHP_EOL;
        // rinit end

        $code .= <<<CODE
PHP_RSHUTDOWN_FUNCTION({$moduleName}) {
    php::request_shutdown();
    module_clean();
    return SUCCESS;
}
CODE;

        if ($this->extensionDependencies === []) {
            $moduleHeader = '    STANDARD_MODULE_HEADER,';
        } else {
            $dependencyArray = $moduleName . '_module_deps';
            $code .= PHP_EOL . 'static const zend_module_dep ' . $dependencyArray . '[] = {' . PHP_EOL;
            foreach ($this->extensionDependencies as $dependency) {
                $code .= '    ZEND_MOD_REQUIRED(' . $this->genCharPtr($dependency, true) . ')' . PHP_EOL;
            }
            $code .= '    ZEND_MOD_END' . PHP_EOL . '};' . PHP_EOL;
            $moduleHeader = "    STANDARD_MODULE_HEADER_EX,\n    nullptr,\n    {$dependencyArray},";
        }

        $code .= <<<CODE

zend_module_entry {$moduleName}_module_entry = {
{$moduleHeader}
    "{$moduleName}",
    ext_functions,
    PHP_MINIT({$moduleName}),
    PHP_MSHUTDOWN({$moduleName}),
    PHP_RINIT({$moduleName}),
    PHP_RSHUTDOWN({$moduleName}),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};
CODE;
        $code .= PHP_EOL . PHP_EOL;

        if ($this->isBuildModeExt()) {
            $code .= "ZEND_GET_MODULE({$moduleName});\n";
            $code .= '}  // namespace ' . $projectNamespace . PHP_EOL;
        } elseif ($this->isBuildModeEmbed()) {
            $code .= '}  // namespace ' . $projectNamespace . PHP_EOL . PHP_EOL;
            $code .= 'TYPEPHP_EMBED_GET_MODULE_FUNCTION(' . $this->targetName . ') {' . PHP_EOL;
            $code .= $this->getIndent() . 'return &' . $projectNamespace . '::' . $moduleName . '_module_entry;' . PHP_EOL;
            $code .= '}' . PHP_EOL;
        } else {
            $code .= '}  // namespace ' . $projectNamespace . PHP_EOL;
        }

        $this->indentLevel--;

        $this->writeFile($file, $code);
        $this->formatCppCode($file);
        $this->localHeaders = [];
        return $file;
    }

    public function getModuleName(): string
    {
        return Constants::EXTENSION_PREFIX . $this->targetName;
    }

    public function getProjectNamespace(): string
    {
        return $this->getModuleName();
    }

    /**
     * Check whether source files under phpx/src/misc/ have a valid cache, always
     * effective (unless --force is specified). The cache must match the compile
     * command and the PHP ABI, and the .o file must not be older than the source
     * files and phpx headers.
     */
    public function hasMiscObjectFileCache(string $cppFile): bool
    {
        // This translation unit emits project-specific runtime symbols and is
        // intentionally rebuilt for every target.
        if ($this->isProjectRuntimeEntryFile($cppFile)) {
            return false;
        }
        if ($this->climate->arguments->defined('force') || $this->enableProfiler) {
            return false;
        }

        $objectFile = $this->getObjectFile($cppFile);
        if (!file_exists($objectFile)) {
            return false;
        }

        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (!is_file($metadataFile)) {
            return false;
        }

        $cachedKey = file_get_contents($metadataFile);
        if ($cachedKey === false || trim($cachedKey) !== $this->getMiscObjectCacheKey($cppFile, $objectFile)) {
            return false;
        }

        $objectMtime = filemtime($objectFile);
        if ($objectMtime <= filemtime($cppFile)) {
            return false;
        }

        $phpxDir = $this->getPhpxDir();
        $headerDirs = [$phpxDir . '/include', $phpxDir . '/src/misc'];

        foreach ($headerDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'h' && $file->getMTime() > $objectMtime) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function getMiscObjectCacheMetadataFile(string $objectFile): string
    {
        return $objectFile . '.typephp-cache';
    }

    protected function getMiscObjectCacheKey(string $sourceFile, string $objectFile): string
    {
        $abi = [
            'php_version_id' => PHP_VERSION_ID,
            'php_api_version' => defined('PHP_API_VERSION') ? constant('PHP_API_VERSION') : null,
            'zend_module_api' => defined('ZEND_MODULE_API_NO') ? constant('ZEND_MODULE_API_NO') : null,
            'php_zts' => defined('PHP_ZTS') ? PHP_ZTS : null,
            'php_debug' => defined('PHP_DEBUG') ? PHP_DEBUG : null,
            'integer_size' => PHP_INT_SIZE,
        ];

        return hash('sha256', $this->buildCompileFileCommand($sourceFile, $objectFile) . "\0" . serialize($abi));
    }

    protected function writeMiscObjectCacheMetadata(string $sourceFile, string $objectFile): void
    {
        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (file_put_contents($metadataFile, $this->getMiscObjectCacheKey($sourceFile, $objectFile) . PHP_EOL) === false) {
            throw new \RuntimeException('Cannot write misc object cache metadata: ' . $metadataFile);
        }
    }

    protected function invalidateMiscObjectCache(string $objectFile): void
    {
        $metadataFile = $this->getMiscObjectCacheMetadataFile($objectFile);
        if (is_file($metadataFile)) {
            unlink($metadataFile);
        }
    }

    public function isPhpxMiscFile(string $cppFile): bool
    {
        $miscDir = $this->getPhpxDir() . '/src/misc/';
        return str_starts_with($cppFile, $miscDir);
    }

    /**
     * Determine whether a file is a C++ source file.
     */
    protected function isCppFile(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($extension, ['cc', 'cpp', 'cxx'], true);
    }

    /**
     * Get the language type identifier from the file extension (used for the -x flag).
     *
     * @return string|null Language identifier (c, assembler, objective-c, objective-c++),
     *                     or null to use the default detection (C++ files).
     */
    protected function getLanguageFromExtension(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'c' => 'c',
            's', 'S' => 'assembler',
            'm' => 'objective-c',
            'mm' => 'objective-c++',
            'cc', 'cpp', 'cxx' => null,
            default => null,
        };
    }

    /**
     * Determine whether a file is a natively compiled source file (C/C++/asm/ObjC, etc.).
     */
    protected function isNativeSourceFile(string $filePath): bool
    {
        return FileScanner::isNativeSourceFile($filePath);
    }

    public function compileFile(string $cppFile, string $objectFile, bool $parallel = false): void
    {
        $isCacheableMiscFile = $this->isPhpxMiscFile($cppFile)
            && !$this->isProjectRuntimeEntryFile($cppFile);
        if ($isCacheableMiscFile && $this->hasMiscObjectFileCache($cppFile)) {
            if (!$parallel) {
                $this->climate->darkGray('[cache] skip: ' . $cppFile);
            }
            return;
        }

        if ($isCacheableMiscFile) {
            $this->invalidateMiscObjectCache($objectFile);
        }

        $language = $this->getLanguageFromExtension($cppFile);
        $options = $this->getSourceCompileCommandOptions($cppFile, $language);
        $result = $this->getNativeBuilder()->compile($cppFile, $objectFile, $options, $language, $parallel);
        if (!$parallel) {
            $this->climate->comment($result['command']);
        }
        if ($result['status'] !== 0) {
            if ($parallel && !empty($result['output'])) {
                foreach ($result['output'] as $line) {
                    $this->climate->red($line);
                }
            }
            $this->error('compile failed: ' . $cppFile);
        }

        if ($isCacheableMiscFile) {
            $this->writeMiscObjectCacheMetadata($cppFile, $objectFile);
        }
    }

    protected function getSourceCompileCommandOptions(string $sourceFile, ?string $language): CompileOptions
    {
        if ($this->isProjectRuntimeEntryFile($sourceFile)) {
            return $this->getProjectRuntimeEntryCompileCommandOptions();
        }

        return match ($language) {
            null => $this->getCompileCommandOptions(),
            'c' => $this->getCCompileCommandOptions(),
            default => $this->getNativeCompileCommandOptions($language),
        };
    }

    protected function buildCompileFileCommand(string $sourceFile, string $objectFile): string
    {
        $language = $this->getLanguageFromExtension($sourceFile);
        $options = $this->getSourceCompileCommandOptions($sourceFile, $language);
        return $this->getNativeBuilder()->compileCommand($sourceFile, $objectFile, $options, $language);
    }

    public function compile(array $sourceFiles): array
    {
        $job = $this->maxJob;

        // The embed build needs the main function and the CLI's built-in function definitions.
        if ($this->isBuildModeEmbed()) {
            $runtimeSource = $this->getPhpxDir() . '/src/misc/typephp_runtime.cc';
            // PHPX 2.6.3 keeps the common runtime in typephp_main.cc. Newer
            // PHPX versions split it out so the object can be shared across
            // projects. Keep the old layout buildable during release rollout.
            if (is_file($runtimeSource)) {
                $sourceFiles[] = $runtimeSource;
            }
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/typephp_main.cc';
        }

        if ($this->isBuildModeBin() && !$this->isWasiTarget()) {
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/php_cli_process_title.c';
            $sourceFiles[] = $this->getPhpxDir() . '/src/misc/ps_title.c';
        }

        $this->preparePhpXPrecompiledHeader();

        // Windows: compile the resource file (icon, version info, etc.)
        $this->compileResourceFile();

        if (!$this->getPlatform()->supportsPcntlParallelCompile() or $job <= 1) {
            return $this->compileSourceFile($sourceFiles);
        }

        // Unix/Linux/macOS compile in parallel using pcntl
        return $this->compileWithPcntl($sourceFiles, $job);
    }

    protected function preparePhpXPrecompiledHeader(): void
    {
        $backend = $this->getCompilerBackend();
        if (!$backend->supportsPrecompiledHeaders()) {
            return;
        }

        $phpxDir = $this->getPhpxDir();
        $phpDir = $this->getPhpDir();
        $dependencies = [
            $phpxDir . '/include',
            $phpxDir . '/src/misc',
            $phpxDir . '/thirdparty/mpdecimal/libmpdec',
            $phpxDir . '/thirdparty/mpdecimal/libmpdec++',
            $phpDir . '/include',
        ];

        try {
            $result = (new PrecompiledHeaderManager($backend, $this->getNativeBuilder()))->prepare(
                $this->globalHeaders,
                $dependencies,
                $this->getBuildDir() . '/cache/pch',
                $this->getPrecompiledHeaderCompileCommandOptions(),
            );
            $this->precompiledHeader = [
                'header' => $result['header'],
                'artifact' => $result['artifact'],
            ];
            $displayArtifact = $this->getRelativePath($result['artifact']);
            $this->climate->darkGray($result['cached']
                ? '[pch] cache: ' . $displayArtifact
                : '[pch] built: ' . $displayArtifact);
        } catch (\Throwable $e) {
            // PCH is an optimization. A compiler-specific failure must not make
            // an otherwise valid TypePHP project unbuildable.
            $this->precompiledHeader = null;
            $this->climate->warning('[pch] disabled: ' . $e->getMessage());
        }
    }

    protected function compileSourceFile(array $sourceFiles): array
    {
        $objectFiles = [];
        $totalFiles = count($sourceFiles);
        $failedFiles = [];

        $this->climate->lightBlue("Starting compilation for {$totalFiles} files");

        $index = 0;
        foreach ($sourceFiles as $cppFile) {
            $objectFile = $this->getObjectFile($cppFile);

            try {
                $this->compileFile($cppFile, $objectFile, false);
                if (is_file($objectFile)) {
                    $objectFiles[] = $objectFile;
                } else {
                    $failedFiles[] = $cppFile;
                    $this->climate->red("Compilation failed: {$cppFile}");
                    $index++;
                    continue;
                }
            } catch (\Throwable $e) {
                $failedFiles[] = $cppFile;
                $this->climate->red("Compilation error: {$cppFile} - " . $e->getMessage());
                $index++;
                continue;
            }

            $index++;
            if ($this->noProgress) {
                $percent = intval($index / $totalFiles * 100);
                $cppFileShorted = $this->removeCommonPrefix($this->buildDir, $cppFile);
                $this->climate->white("[{$index}/{$totalFiles}] {$percent}% {$cppFileShorted}");
            }
        }

        if (!empty($failedFiles)) {
            throw new \Exception('Compilation failed for: ' . implode(', ', $failedFiles));
        }

        $this->climate->green("Successfully compiled {$totalFiles} files");
        return $objectFiles;
    }

    /**
     * Parallel compilation on Unix/Linux/macOS (using pcntl).
     */
    protected function pcntlWait(?int &$status): int
    {
        return pcntl_wait($status);
    }

    protected function pcntlFork(): int
    {
        return pcntl_fork();
    }

    protected function pcntlLastError(): int
    {
        return pcntl_get_last_error();
    }

    protected function waitForCompileChild(): array
    {
        do {
            $status = null;
            $pid = $this->pcntlWait($status);
            $error = $pid === -1 ? $this->pcntlLastError() : 0;
        } while ($pid === -1 && defined('PCNTL_EINTR') && $error === PCNTL_EINTR);

        if ($pid === -1) {
            $message = function_exists('pcntl_strerror') ? pcntl_strerror($error) : 'error ' . $error;
            throw new \RuntimeException('Failed to wait for compiler process: ' . $message);
        }

        return [$pid, (int) $status];
    }

    protected function compileChildSucceeded(int $status): bool
    {
        return pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    }

    protected function getCompileChildFailureReason(int $status): string
    {
        if (pcntl_wifsignaled($status)) {
            return 'terminated by signal ' . pcntl_wtermsig($status);
        }
        if (pcntl_wifexited($status)) {
            return 'exited with status ' . pcntl_wexitstatus($status);
        }
        return 'terminated abnormally';
    }

    protected function compileWithPcntl(array $sourceFiles, int $job): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->climate->warning('pcntl extension not available, using sequential compilation');
            return $this->compileSourceFile($sourceFiles);
        }

        $totalFiles = count($sourceFiles);
        $this->climate->lightBlue("Starting parallel compilation with {$job} jobs for {$totalFiles} files");
        $progress = null;
        if (!$this->noProgress) {
            $progress = new Progressbar();
            $progress->barStyle([AnsiTerminal::FG_GREEN])
                ->percentageStyle([AnsiTerminal::TEXT_BOLD])
                ->labelStyle([AnsiTerminal::FG_CYAN]);
            $progress->renderInPlace(0, $totalFiles, 'Compiling');
        }
        $result = $this->getNativeBuilder()->dispatchParallel(
            $sourceFiles,
            $job,
            fn(string $source): string => $this->getObjectFile($source),
            function (string $source, string $object): void {
                $this->compileFile($source, $object, true);
            },
            fn(): int => $this->pcntlFork(),
            fn(): array => $this->waitForCompileChild(),
            fn(int $status): bool => $this->compileChildSucceeded($status),
            function (string $source, string $object, int $status, bool $success, int $completed) use ($progress, $totalFiles): void {
                if (!$success) {
                    echo PHP_EOL;
                    $this->climate->red("Compilation failed: {$source} ({$this->getCompileChildFailureReason($status)})");
                }
                if ($this->noProgress) {
                    $percent = $completed >= $totalFiles
                        ? 100
                        : min(99, (int) ceil($completed / $totalFiles * 100));
                    $shortSource = $this->removeCommonPrefix($this->buildDir, $source);
                    $this->climate->white("[{$completed}/{$totalFiles}] {$percent}% {$shortSource}");
                } else {
                    $progress->renderInPlace($completed, $totalFiles, 'Compiling');
                }
            },
        );

        if (!$this->noProgress) {
            echo PHP_EOL;
        }

        if ($result['failures'] !== []) {
            throw new \Exception('Compilation failed for: ' . implode(', ', $result['failures']));
        }
        $this->climate->green("Successfully compiled {$totalFiles} files");
        return $result['objects'];
    }

    public function output(string $message, string $style = 'out'): void
    {
        $this->climate->{$style}($message);
    }

    protected function buildLinkCommand(array $objectFiles, string $targetFile): string
    {
        return $this->getNativeBuilder()->linkCommand($objectFiles, $targetFile, $this->getLinkCommandOptions());
    }

    public function build(array $objectFiles): string
    {
        $targetFile = $this->getTargetFileName();

        // Windows: add the .res resource file to the link
        if ($this->isWindows() && $this->hasResourceFile()) {
            $resFile = $this->getResourceResFile();
            if (file_exists($resFile)) {
                $objectFiles[] = $resFile;
            }
        }

        $buildError = null;
        $result = $this->getNativeBuilder()->link($objectFiles, $targetFile, $this->getLinkCommandOptions());
        $this->climate->comment($result['command']);
        foreach ($result['output'] as $line) {
            $this->climate->out($line);
        }
        if ($result['status'] !== 0) {
            $buildError = 'link failed: ' . $targetFile;
        } elseif (!$result['generated']) {
            $buildError = 'target file not generated: ' . $targetFile;
        }

        if ($buildError !== null) {
            $this->error($buildError);
        }

        $this->climate->green('Build successful: ' . $targetFile);

        return $targetFile;
    }

    protected function getNativeBuilder(): NativeBuilder
    {
        return $this->nativeBuilder ??= new NativeBuilder($this->getCompilerBackend());
    }

    public function isRunRequested(): bool
    {
        return $this->climate->arguments->defined('run');
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function run(string $targetFile): never
    {
        if ($this->buildMode !== self::BUILD_MODE_BIN) {
            $this->climate->error('--run is only supported in binary mode (-m bin), not library or extension mode');
            exit(1);
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !str_starts_with($targetFile, '/')) {
            $targetFile = './' . $targetFile;
        }

        $targetArgs = $this->getTargetArgs();
        $command = escapeshellcmd($targetFile);
        if (!empty($targetArgs)) {
            $escapedArgs = [];
            foreach ($targetArgs as $targetArg) {
                $escapedArgs[] = escapeshellarg($targetArg);
            }
            $command .= ' ' . implode(' ', $escapedArgs);
        }

        fwrite(STDERR, "Running: {$command}\n");
        passthru($command, $exitCode);
        exit($exitCode);
    }

    public function getTargetArgs(): array
    {
        return $this->climate->arguments->trailingArray() ?? [];
    }

    public function genFunctionDeclarations(string $file): void
    {
        $code = '#pragma once' . PHP_EOL . PHP_EOL;
        $code .= '#include <phpx.h>' . PHP_EOL;
        $code .= '#include <typephp_helper.h>' . PHP_EOL;
        $code .= '#include <typephp_fiber_generator.h>' . PHP_EOL;
        $code .= PHP_EOL;

        $code .= $this->genNativeObjectDeclarations();

        if ($this->isBuildModeLib()) {
            $code .= $this->genLibraryApiMacro($this->targetName);
        }
        $importLibraries = [];
        foreach ($this->symbols->functions() as $function) {
            if ($this->isImportedFunction($function)) {
                $importLibraries[$function->importLibrary] = true;
            }
        }
        foreach (array_keys($importLibraries) as $library) {
            $code .= $this->genLibraryImportMacro($library);
        }

        $code .= $this->genDefaultArgumentHelperDeclarations();

        foreach ($this->symbols->functions() as $name => $func) {
            if ($func->abstractMethod) {
                continue;
            }
            $functionDeclarationPrefix = $this->getFunctionDeclarationPrefix($func);
            $list = [];
            if ($func->method) {
                $list[] = ($this->getNativeObjectMethodThisType($func) ?? (Type::OBJECT . ' &')) . 'this_';
            }
            $argInfoList = $func->argInfoList;
            if ($argInfoList) {
                foreach ($argInfoList as $argumentIndex => $argInfo) {
                    if ($argInfo->variadic) {
                        $arg = Type::ARRAY . ' ' . $argInfo->name
                            . ' = ' . $this->genDefaultArgumentExpr($name, $argumentIndex);
                    } else {
                        $arg = $this->genArgumentDeclaration($argInfo);
                        if ($argInfo->hasDefaultValue() && !$this->isConstructorNativeFunction($func)) {
                            $arg .= ' = ' . $this->genDefaultArgumentExpr($name, $argumentIndex);
                        }
                    }
                    $list[] = $arg;
                }
            }
            $params = implode(', ', $list);
            $functionAttribute = $this->getFunctionOptimizationAttribute($func);
            $returnType = $func->returnsByRef
                ? Type::REF
                : ($this->getNativeObjectReturnType($func) ?? $func->returnType);
            $code .= $functionDeclarationPrefix . $functionAttribute . $returnType . ' ' . self::PREFIX . $name . '(' . $params . ');' . PHP_EOL;
            if ($func->hasMultiReturn()) {
                $code .= 'namespace ' . self::MULTI_RETURN_NAMESPACE . ' {' . PHP_EOL;
                $code .= $functionDeclarationPrefix . $functionAttribute . $func->getMultiReturnCppType() . ' ' . self::PREFIX . $name . '(' . $params . ');' . PHP_EOL;
                $code .= '}' . PHP_EOL;
            }
        }

        $this->writeFile($file, $code);
    }

    protected function getLibraryApiMacroName(): string
    {
        return 'TYPEPHP_' . strtoupper($this->targetName) . '_API';
    }

    protected function genLibraryApiMacro(string $library): string
    {
        $apiMacro = $this->getNamedLibraryApiMacroName($library);
        $exportsMacro = $this->getNamedLibraryExportsMacroName($library);
        $code = "#if defined({$exportsMacro})\n";
        $code .= "# define {$apiMacro} TYPEPHP_SYMBOL_EXPORT\n";
        $code .= "#else\n";
        $code .= "# define {$apiMacro} TYPEPHP_SYMBOL_IMPORT\n";
        return $code . "#endif\n\n";
    }

    protected function genLibraryImportMacro(string $library): string
    {
        $importMacro = $this->getNamedLibraryImportMacroName($library);
        return "#define {$importMacro} TYPEPHP_SYMBOL_IMPORT\n\n";
    }

    protected function getFunctionDeclarationPrefix(FunctionDef $function): string
    {
        if ($this->isImportedFunction($function)) {
            return $this->getNamedLibraryImportMacroName($function->importLibrary) . ' ';
        }
        if ($this->isBuildModeLib() && $function->exported) {
            return $this->getLibraryApiMacroName() . ' ';
        }
        return 'extern ';
    }

    protected function getFunctionOptimizationAttribute(FunctionDef $function): string
    {
        if ($function->hot) {
            return 'TYPEPHP_HOT_ATTRIBUTE ';
        }
        if ($function->cold) {
            return 'TYPEPHP_COLD_ATTRIBUTE ';
        }
        return '';
    }

    protected function isImportedFunction(FunctionDef $function): bool
    {
        return $function->importLibrary !== '';
    }

    protected function getNamedLibraryApiMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_API';
    }

    protected function getNamedLibraryImportMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_IMPORT';
    }

    protected function getNamedLibraryExportsMacroName(string $library): string
    {
        return 'TYPEPHP_' . strtoupper($library) . '_EXPORTS';
    }

    protected function getLibraryExportsMacroName(): string
    {
        return $this->getNamedLibraryExportsMacroName($this->targetName);
    }

    public function getBuildMode(): string
    {
        return $this->buildMode;
    }

    /** Write the public WIT contract and PHPX host-bindgen manifest. */
    public function writeWasmInterface(
        string $manifestFile,
        string $witFile,
        string $adapterFile,
        string $asyncExportsFile,
        string $package,
        string $world,
    ): void
    {
        if (!$this->isWasiTarget() || !$this->isBuildModeLib()) {
            $this->error('WIT interfaces can only be generated for a WASI library build');
        }
        try {
            $generator = new WasmInterfaceGenerator();
            $manifest = $generator->buildManifest(
                $this->symbols->functions(),
                $package,
                $world,
                $this->targetName,
                fn (FunctionDef $function): string => self::PREFIX
                    . $this->getNativeName($function->name, $function->namespace),
            );
            foreach (array_unique([
                dirname($manifestFile),
                dirname($witFile),
                dirname($adapterFile),
                dirname($asyncExportsFile),
            ]) as $directory) {
                if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new \RuntimeException("Unable to create WASM interface directory: {$directory}");
                }
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifestFile, $json . PHP_EOL) === false
                || file_put_contents($witFile, $generator->renderWit($manifest)) === false
                || file_put_contents($adapterFile, $generator->renderCppAdapter($manifest)) === false
                || file_put_contents($asyncExportsFile, $generator->renderJcoAsyncExports($manifest)) === false) {
                throw new \RuntimeException('Unable to write the generated WASM interface');
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
    }

    public function getArgInfoStubFilename(string $stubFile): string
    {
        $rs = str_replace(['.stub.php', '.php'], '', $stubFile);
        return str_replace('-', '_', $rs);
    }

    public function isNativeClassForStub(string $class): bool
    {
        return $this->isNativeObjectClass($class);
    }

    public function isNativeFunctionForStub(string $function): bool
    {
        return $this->hasFunction($function)
            && $this->functionUsesNativeObject($this->getFunction($function));
    }

    public function isNativeMethodForStub(string $class, string $method): bool
    {
        $class = ltrim($class, '\\');
        return $this->hasClass($class)
            && $this->getClass($class)->hasMethod($method)
            && $this->functionUsesNativeObject($this->getClass($class)->getMethod($method)->functionDef);
    }

    public function getArgInfoHeaderFile(string $file, bool $relative = false): string
    {
        $filePath = $this->getRelativePath(str_replace(['.stub.php', '.php'], '', $file));
        $filename = self::PREFIX . str_replace(['/', '\\'], '_', $filePath);
        $filename = $this->escapeFileName($filename);
        $absPath = $this->getIncludeDir() . "/{$filename}_arginfo.h";
        if ($relative) {
            return ltrim($this->removeCommonPrefix($this->getIncludeDir(), $absPath), '/');
        }
        return $absPath;
    }

    public function genIncludeHeaderFiles(): string
    {
        $headers = array_merge($this->globalHeaders, [
            "php_{$this->targetName}_func_decl.h",
            "php_{$this->targetName}_data_decl.h",
        ], $this->localHeaders);
        $lines = [];
        foreach ($headers as $header) {
            $lines[] = '#include <' . $header . '>';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @return array<PropertyDef> */
    private function getRequestArrayDefaultProperties(ClassDef $classDef): array
    {
        $properties = [];
        foreach ($classDef->properties as $property) {
            if (!$property->isStatic()
                && $property->requiresRuntimeDefaultInit
                && $property->arrayInitPlan !== null
            ) {
                $properties[] = $property;
            }
        }
        return $properties;
    }

    private function getRequestArrayDefaultInitializedName(ClassDef $classDef): string
    {
        return 'typephp_request_array_defaults_initialized_' . $classDef->getNamespacedName();
    }

    private function getRequestArrayDefaultInitializerName(ClassDef $classDef): string
    {
        return 'typephp_ensure_request_array_defaults_' . $classDef->getNamespacedName();
    }

    private function getRequestArrayDefaultTemplateName(ClassDef $classDef, PropertyDef $property): string
    {
        return 'typephp_request_array_default_'
            . $this->getNativeName($property->name, $classDef->namespace, $classDef->name);
    }

    private function indentGeneratedBlock(string $code, int $level): string
    {
        $code = rtrim($code, "\r\n");
        if ($code === '') {
            return '';
        }
        $indent = str_repeat('    ', $level);
        return $indent . str_replace("\n", "\n{$indent}", $code) . PHP_EOL;
    }

    private function genRequestArrayDefaultStorage(): string
    {
        $code = '';
        foreach ($this->symbols->classes() as $classDef) {
            if ($classDef->trait || $classDef->enum || $classDef->nativeObject) {
                continue;
            }
            $properties = $this->getRequestArrayDefaultProperties($classDef);
            if (!$properties) {
                continue;
            }
            $code .= 'THREAD_LOCAL bool '
                . $this->getRequestArrayDefaultInitializedName($classDef)
                . ' = false;' . PHP_EOL;
            foreach ($properties as $property) {
                $code .= 'THREAD_LOCAL php::Var '
                    . $this->getRequestArrayDefaultTemplateName($classDef, $property)
                    . ';' . PHP_EOL;
            }
        }
        return $code === '' ? '' : "// request array property defaults\n{$code}";
    }

    private function genRequestArrayDefaultInitializers(): string
    {
        $code = '';
        foreach ($this->symbols->classes() as $classDef) {
            if ($classDef->trait || $classDef->enum || $classDef->nativeObject) {
                continue;
            }
            $properties = $this->getRequestArrayDefaultProperties($classDef);
            if (!$properties) {
                continue;
            }

            $initialized = $this->getRequestArrayDefaultInitializedName($classDef);
            $code .= 'static inline void '
                . $this->getRequestArrayDefaultInitializerName($classDef)
                . '() {' . PHP_EOL;
            $code .= "    if (UNEXPECTED(!{$initialized})) {" . PHP_EOL;

            foreach ($properties as $index => $_property) {
                $code .= "        php::Var prepared_default_{$index};" . PHP_EOL;
            }
            foreach ($properties as $index => $property) {
                $plan = $property->arrayInitPlan;
                $code .= '        do {' . PHP_EOL;
                $code .= $this->indentGeneratedBlock($plan->init, 3);
                $code .= $this->indentGeneratedBlock(
                    "prepared_default_{$index} = {$plan->expr};",
                    3,
                );
                $code .= $this->indentGeneratedBlock($plan->clean, 3);
                $code .= '        } while (0);' . PHP_EOL;
            }
            foreach ($properties as $index => $property) {
                $template = $this->getRequestArrayDefaultTemplateName($classDef, $property);
                $code .= "        {$template} = std::move(prepared_default_{$index});" . PHP_EOL;
            }
            $code .= "        {$initialized} = true;" . PHP_EOL;
            $code .= '    }' . PHP_EOL;
            $code .= '}' . PHP_EOL . PHP_EOL;
        }
        return $code;
    }

    private function genRequestArrayDefaultCleanup(): string
    {
        $code = '';
        foreach ($this->symbols->classes() as $classDef) {
            if ($classDef->trait || $classDef->enum || $classDef->nativeObject) {
                continue;
            }
            $properties = $this->getRequestArrayDefaultProperties($classDef);
            if (!$properties) {
                continue;
            }
            $initialized = $this->getRequestArrayDefaultInitializedName($classDef);
            $code .= "if ({$initialized}) {" . PHP_EOL;
            foreach ($properties as $property) {
                $code .= '    '
                    . $this->getRequestArrayDefaultTemplateName($classDef, $property)
                    . '.unset();' . PHP_EOL;
            }
            $code .= "    {$initialized} = false;" . PHP_EOL;
            $code .= '}' . PHP_EOL;
        }
        return $code === '' ? '' : "// request array property defaults\n{$code}";
    }

    public function genClassPropertyInit(): string
    {
        $code = '';
        foreach ($this->classCeList as $ce) {
            $info = $this->classCeInfo[$ce] ?? $this->getInternalCeInfo($ce);
            $code .= "{$ce} = {$info['func']}({$info['args']});\n";
            $classDef = !empty($info['classDef']) ? $info['classDef'] : null;

            /**
             * @var ClassDef $classDef
             */
            if ($classDef && !$classDef->trait && !$classDef->enum) {
                $className = $classDef->getNamespacedName();
                $handlers = "property_handlers_{$className}";
                $requestArrayDefaults = $this->getRequestArrayDefaultProperties($classDef);
                $ensureRequestArrayDefaults = $requestArrayDefaults
                    ? $this->getRequestArrayDefaultInitializerName($classDef) . '();' . PHP_EOL
                    : '';
                $initBlock = '';
                foreach ($classDef->properties as $property) {
                    // Scalar/null/empty-array defaults already live in the
                    // Zend class default-property table generated by
                    // gen_stub.php. Only values represented there by a
                    // placeholder (for example non-empty arrays and enum
                    // cases) belong in the per-object initialization path.
                    if ($property->isStatic()
                        || $property->default === null
                        || !$property->requiresRuntimeDefaultInit
                    ) {
                        continue;
                    }
                    if ($property->arrayInitPlan) {
                        $initBlock .= 'target.attr(' . $property->runtimeDefaultOffset
                            . ', php::AttrMode::Update) = '
                            . $this->getRequestArrayDefaultTemplateName($classDef, $property)
                            . ';' . PHP_EOL;
                    } else {
                        // Runtime-only constant value (for example an enum
                        // case). Wrap it in php::Var and write the already
                        // resolved backing slot directly. Each property uses a
                        // separate block so its local `value` cannot clash with
                        // siblings in the same create_object body.
                        $default = $property->type === Type::FLOAT
                            ? $this->convertFloatExpr($property->default)
                            : $property->default;
                        $init = "do {\n";
                        $init .= "auto value = php::Var({$default});\n";
                        $init .= 'target.attr(' . $property->runtimeDefaultOffset
                            . ", php::AttrMode::Update) = value;\n";
                        $init .= "} while (0);\n";
                        $initBlock .= $init;
                    }
                }

                $delegateToParentAllocator = $this->parentHasCustomCreateObjectOnPhp84($classDef);
                $buildCreateBody = function () use (
                    $classDef,
                    $className,
                    $handlers,
                    $ensureRequestArrayDefaults,
                    $initBlock,
                    $delegateToParentAllocator,
                ): string {
                    $body = $classDef->ctorInit;
                    $body .= $ensureRequestArrayDefaults;
                    $body .= "auto obj = typephp_create_object_with_defaults(\n";
                    $body .= "class_type, create_object_{$className}, ";
                    $body .= ($delegateToParentAllocator ? 'true' : 'false') . ",\n";
                    $body .= "[&](zend_object *obj) {\n";
                    if ($initBlock !== '') {
                        $body .= "php::Object target{obj};\n";
                    }
                    $body .= $initBlock;
                    $body .= "});\n";
                    $body .= $classDef->ctorClean;
                    return $body . "return obj;\n";
                };

                $needsHookReadHandler = false;
                $needsHookWriteHandler = false;
                foreach ($classDef->properties as $property) {
                    if ($property->getter !== null) {
                        $needsHookReadHandler = true;
                        $needsHookWriteHandler = true;
                    }
                    if ($property->setter !== null || $property->isPrivateSet() || $property->isProtectedSet()) {
                        $needsHookWriteHandler = true;
                    }
                }
                if (!$needsHookReadHandler || !$needsHookWriteHandler) {
                    $baseHandlers = "base_property_handlers_{$className}";
                    // Keep the inherited handlers before PHPX installs the
                    // TypePHP unset/clone table. Most classes have no hooks;
                    // routing every ordinary property access through the hook
                    // name lookup makes dynamic properties several times
                    // slower even though the lookup can never succeed.
                    $code .= "const auto *{$baseHandlers} = {$ce}->default_object_handlers;\n";
                }
                $code .= "typephp_install_property_handlers({$ce}, &{$handlers});\n";
                if (!$needsHookReadHandler) {
                    $code .= "{$handlers}.read_property = {$baseHandlers}->read_property;\n";
                }
                if (!$needsHookWriteHandler) {
                    $code .= "{$handlers}.write_property = {$baseHandlers}->write_property;\n";
                }
                if ($classDef->requireCtor) {
                    $code .= "create_object_{$className} = php::getCreateObjectFn({$ce});\n";
                    $code .= "{$ce}->create_object = [](zend_class_entry *class_type) -> zend_object* {\n";
                    $code .= $buildCreateBody();
                    $code .= "};\n";
                }
            }
        }
        return $code;
    }

    private function parentHasCustomCreateObjectOnPhp84(ClassDef $classDef): bool
    {
        if ($classDef->extends === '') {
            return false;
        }
        if ($classDef->inheritedFromInternalClass) {
            return true;
        }

        $parent = $this->getClassDef($classDef->extends);
        while ($parent !== null) {
            foreach ($parent->properties as $property) {
                if (!$property->isStatic() && $property->requiresRuntimeDefaultInit) {
                    return true;
                }
            }
            if ($parent->extends === '') {
                break;
            }
            if ($parent->inheritedFromInternalClass) {
                return true;
            }
            $parent = $this->getClassDef($parent->extends);
        }
        return false;
    }

    protected function getRegisterClassFunction(string $name): string
    {
        return self::PREFIX . 'register_class_' . $name;
    }

    protected function getRegisterClassFunctionCeList(ClassDef|InterfaceDef $classDef): array
    {
        $list = [];
        if ($classDef instanceof InterfaceDef) {
            foreach ($classDef->extendsList ?: ($classDef->extends ? [$classDef->extends] : []) as $parentInterface) {
                $list[] = self::PREFIX . 'class_entry_' . $this->escapeCeName($parentInterface);
            }
            return $list;
        }

        $parentCe = $this->getParentClassCe($classDef);
        if ($parentCe !== '') {
            $list = [$parentCe];
        }
        $implements = $this->getImplementCe($classDef);

        return array_merge($list, $implements);
    }

    protected function getClassCe(ClassLikeDef $classDef): string
    {
        return self::PREFIX . 'class_entry_' . $this->escapeCeName($classDef->getNamespacedName());
    }

    /**
     * @return array<ClassDef|InterfaceDef>
     */
    private function getClassLikesWithConstants(): array
    {
        $classes = array_filter(
            $this->symbols->classes(),
            static fn (ClassDef $classDef): bool => $classDef->trait === null,
        );
        return array_merge($classes, $this->symbols->interfaces());
    }

    protected function getFilesFromDir(string $path): array
    {
        $scanner = new FileScanner($path);

        return $scanner->scan();
    }

    protected function genClassArrayConstants(): string
    {
        $code = '';
        foreach ($this->getClassLikesWithConstants() as $classDef) {
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $constName = self::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
                    $code .= "do {\n";
                    $code .= $constant->arrayExpr;
                    $code .= $constName . ' = ' . $constant->value . ";\n";
                    if (!$classDef instanceof ClassDef || !$classDef->nativeObject) {
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constant->value});\n";
                    }
                    $code .= "} while(0);\n";
                }
            }
        }

        // Propagate array constants to child classes that don't override them
        foreach ($this->symbols->classes() as $className => $classDef) {
            if ($classDef->nativeObject) {
                continue;
            }
            $ownConstNames = [];
            foreach ($classDef->constants as $constant) {
                if ($constant->type === Type::ARRAY) {
                    $ownConstNames[$constant->name] = true;
                }
            }

            $parentName = $this->escapeClass($classDef->extends);
            while ($parentName && $this->symbols->hasClass($parentName)) {
                $parentDef = $this->symbols->class($parentName);
                foreach ($parentDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $constName = self::PREFIX . $this->getNativeName($constant->name, $parentDef->namespace, $parentDef->name);
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constName});\n";
                    }
                }
                $parentName = $this->escapeClass($parentDef->extends);
            }

            foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
                if (!$this->hasInterface($interfaceName)) {
                    continue;
                }
                $interfaceDef = $this->getInterface($interfaceName);
                foreach ($interfaceDef->constants as $constant) {
                    if ($constant->type === Type::ARRAY && !isset($ownConstNames[$constant->name])) {
                        $ownConstNames[$constant->name] = true;
                        $constName = self::PREFIX . $this->getNativeName($constant->name, $interfaceDef->namespace, $interfaceDef->name);
                        $classNameStr = $this->genCharPtr($classDef->getNamespacedName(false), true);
                        $classConstStr = $this->genCharPtr($constant->name);
                        $code .= "php::updateConstant($classNameStr, $classConstStr, {$constName});\n";
                    }
                }
            }
        }

        return $code;
    }

    protected function getAbsolutePath(string $path, string $projectDir): string
    {
        $absPath = $this->resolvePath($path, $projectDir, 'Source path');
        return realpath($absPath);
    }

    protected function resolvePath(string $path, string $baseDir, string $label = 'Path'): string
    {
        $path = trim($path);
        if ($path === '') {
            $this->error($label . ' must not be empty');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && (
                $path[0] === '/'
                || $path[0] === '\\'
                || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            );
    }

    protected function parseProjectYaml(string $path): array
    {
        $cfg = $this->getProjectYamlLoader()->load($path);
        $projectDir = dirname($path);

        if (array_key_exists('php-version', $cfg) && !$this->climate->arguments->defined('php-version')) {
            $this->setPhpVersion((string) $cfg['php-version']);
        }

        if (!empty($cfg['sources'])) {
            $sources = $cfg['sources'];
            if (!is_array($sources)) {
                $this->error('`sources` must be array');
            }
            $list = [];
            foreach ($sources as $sourceEntry) {
                [$src, $condition] = $this->parseProjectYamlSourceEntry($sourceEntry);
                if ($condition !== null && !$this->evaluateProjectYamlCondition($condition)) {
                    continue;
                }
                $realPath = $this->getAbsolutePath($src, $projectDir);
                if (!$realPath) {
                    $this->error('Source file not exists: `' . $src . '`');
                }
                if (is_file($realPath)) {
                    $list[] = $realPath;
                    $this->sourceDirs[] = basename($realPath);
                } else {
                    $tmp = $this->getFilesFromDir($realPath);
                    $list = array_merge($list, $tmp);
                    $this->sourceDirs[] = $realPath;
                }
            }
        } else {
            $list = $this->getFilesFromDir($projectDir);
        }

        if (array_key_exists('optimize', $cfg)) {
            $this->optimizeLevel = (int) $cfg['optimize'];
        }

        if (array_key_exists('job', $cfg)) {
            $this->maxJob = (int) $cfg['job'];
        }

        if (!empty($cfg['debug'])) {
            $this->debug = true;
        }

        if (!empty($cfg['no-literal-strings'])) {
            $this->noLiteralStrings = true;
        }

        if (!empty($cfg['profile'])) {
            if (!$this->isLinux()) {
                $this->climate->error('`profile` in YAML is only supported on Linux (requires gperftools)');
                exit(1);
            }
            $this->enableProfiler = true;
        }

        if (!empty($cfg['no-progress'])) {
            $this->noProgress = true;
        }

        if (!empty($cfg['no-console'])) {
            $this->noConsole = true;
        }

        $sanitize = $cfg['sanitize'] ?? null;
        if (!empty($sanitize)) {
            $this->sanitize = (string) $sanitize;
        }

        // Read cxx-flags
        $cxxFlags = $cfg['cxx-flags'] ?? null;
        if (!empty($cxxFlags)) {
            if (is_array($cxxFlags)) {
                $this->cxxFlags = implode(' ', $cxxFlags);
            } else {
                $this->cxxFlags = str_replace("\n", ' ', $cxxFlags);
            }
        }

        // Read cxx-std
        $cxxStd = $cfg['cxx-std'] ?? null;
        if (!empty($cxxStd)) {
            $this->cxxStd = $cxxStd;
        }

        // Read march (target CPU instruction set)
        $march = $cfg['march'] ?? null;
        if (!empty($march)) {
            $this->march = $march;
        }

        // Read target-platform
        $targetPlatform = $cfg['target-platform'] ?? null;
        if (!empty($targetPlatform)) {
            $this->targetPlatform = (string) $targetPlatform;
        }

        // Read build-dir
        $buildDir = $cfg['build-dir'] ?? null;
        if (!empty($buildDir)) {
            $this->setBuildDir($this->resolvePath((string) $buildDir, $projectDir, 'Build path'));
        }

        if (!empty($cfg['dry'])) {
            $this->dryRun = true;
        }

        // Read ld-flags
        $ldflags = $cfg['ld-flags'] ?? null;
        if (!empty($ldflags)) {
            if (is_array($ldflags)) {
                $this->ldflags = implode(' ', $ldflags);
            } else {
                $this->ldflags = str_replace("\n", ' ', $ldflags);
            }
        }

        // Read include-paths
        $includePaths = $cfg['include-paths'] ?? null;
        if (!empty($includePaths) && is_array($includePaths)) {
            foreach ($includePaths as $includePath) {
                $this->userIncludePaths[] = $this->resolvePath((string) $includePath, $projectDir, 'Include path');
            }
        }

        // Read defines
        $defines = $cfg['defines'] ?? null;
        if (!empty($defines) && is_array($defines)) {
            foreach ($defines as $define) {
                $this->userDefines[] = (string) $define;
            }
        }

        // Read lto
        if (!empty($cfg['lto'])) {
            $this->enableLto = true;
        }

        // Read format
        if (!empty($cfg['format'])) {
            $this->enableCodeFormattingIfAvailable('YAML format');
        }

        // Read link-libs
        $linkLibs = $cfg['link-libs'] ?? null;
        if (!empty($linkLibs) && is_array($linkLibs)) {
            foreach ($linkLibs as $lib) {
                $this->linkLibs[] = (string)$lib;
            }
        }

        // Read link-paths
        $linkPaths = $cfg['link-paths'] ?? null;
        if (!empty($linkPaths) && is_array($linkPaths)) {
            foreach ($linkPaths as $linkPath) {
                $this->linkPaths[] = $this->resolvePath((string) $linkPath, $projectDir, 'Link path');
            }
        }

        // Required PHP modules. These are emitted as zend_module_dep entries;
        // they are unrelated to native libraries configured through link-libs.
        $hasExtensionDependencies = array_key_exists('extension-dependencies', $cfg);
        $hasExtensionDependenciesAlias = array_key_exists('ext-deps', $cfg);
        if ($hasExtensionDependencies && $hasExtensionDependenciesAlias) {
            $this->error('`extension-dependencies` and `ext-deps` cannot be used together');
        }
        if ($hasExtensionDependencies || $hasExtensionDependenciesAlias) {
            $configKey = $hasExtensionDependencies ? 'extension-dependencies' : 'ext-deps';
            $dependencies = $cfg[$configKey];
            if (!is_array($dependencies)) {
                $this->error("`{$configKey}` must be array");
            }
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || trim($dependency) === '') {
                    $this->error("Each `{$configKey}` entry must be a non-empty string");
                }
                $dependency = trim($dependency);
                if (str_contains($dependency, "\0")) {
                    $this->error('Extension dependency names must not contain NUL bytes');
                }
                if (!in_array($dependency, $this->extensionDependencies, true)) {
                    $this->extensionDependencies[] = $dependency;
                }
            }
        }

        // Read output/name. `name` only denotes the target name; it must not be
        // resolved against the YAML directory as an output path.
        $output = $cfg['output'] ?? null;
        if (!empty($output)) {
            $this->setOutputPath($this->resolvePath((string) $output, $projectDir, 'Output path'));
        } elseif (!empty($cfg['name'])) {
            $this->setTargetName((string) $cfg['name']);
        }

        // Read cpp-compiler
        $cppCompiler = $cfg['cpp-compiler'] ?? null;
        if (!empty($cppCompiler)) {
            $this->setCppCompiler($cppCompiler);
        }

        // Read mode/type/build-mode (supports both the CLI and YAML naming)
        $buildMode = $cfg['mode'] ?? $cfg['build-mode'] ?? $cfg['type'] ?? null;
        if (!empty($buildMode)) {
            $this->setBuildMode((string) $buildMode);
        }

        // Read ignore (supports both hyphen and underscore)
        $ignore = $cfg['ignore'] ?? null;
        if (!empty($ignore)) {
            if (!is_array($ignore)) {
                $this->error('`ignore` must be array');
            }
            foreach ($ignore as $src) {
                $realPath = $this->getAbsolutePath($src, $projectDir);
                if (!$realPath) {
                    // Ignore entries describe optional exclusions. Projects often
                    // share one configuration across dependency versions where an
                    // excluded file or directory may not exist.
                    continue;
                }
                $this->ignorePaths[] = $realPath;
            }
        }

        // Read resource (Windows resource config: icon, version info)
        $resource = $cfg['resource'] ?? null;
        if (!empty($resource)) {
            if (!is_array($resource)) {
                $this->error('`resource` must be array');
            }
            // Verify that the icon file exists
            if (!empty($resource['icon'])) {
                $iconPath = $resource['icon'];
                if (!preg_match('/^[A-Za-z]:\\|^\//', $iconPath)) {
                    $iconPath = $projectDir . DIRECTORY_SEPARATOR . $iconPath;
                }
                if (!file_exists($iconPath)) {
                    $this->error('Icon file not exists: `' . $resource['icon'] . '`');
                }
            }
            $this->resourceConfig = $resource;
            $this->resourceConfig['_projectDir'] = $projectDir;
        }

        // Read manifest (Windows manifest file, same level as resource, omitted by default)
        $manifest = $cfg['manifest'] ?? null;
        if (!empty($manifest)) {
            if (!is_string($manifest)) {
                $this->error('`manifest` must be a string (path to manifest file)');
            }
            $manifestPath = $manifest;
            if (!preg_match('/^[A-Za-z]:\\|^\//', $manifestPath)) {
                $manifestPath = $projectDir . DIRECTORY_SEPARATOR . $manifestPath;
            }
            if (!file_exists($manifestPath)) {
                $this->error('Manifest file not exists: `' . $manifest . '`');
            }
            if (empty($this->resourceConfig)) {
                $this->resourceConfig = ['_projectDir' => $projectDir];
            }
            $this->resourceConfig['manifest'] = $manifest;
        }

        return $this->filterIgnoredFiles($list);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function parseProjectYamlSourceEntry(mixed $entry): array
    {
        return $this->getProjectYamlLoader()->parseSourceEntry($entry);
    }

    protected function evaluateProjectYamlCondition(string $condition): bool
    {
        return $this->getProjectYamlLoader()->evaluateCondition($condition);
    }

    protected function getProjectYamlLoader(): ProjectYamlLoader
    {
        $this->projectYamlLoader ??= new ProjectYamlLoader($this->phpVersion, fn(string $message): never => $this->error($message));
        $this->projectYamlLoader->setPhpVersion($this->phpVersion);
        return $this->projectYamlLoader;
    }

    protected function getInternalCeInfo(string $ce): array
    {
        // This metadata is consumed only by genClassPropertyInit() in MINIT to
        // register compiled classes against internal parents/interfaces. It is
        // deliberately separate from both persistentClassMap (module-lifetime
        // lazy call-site cache) and classMap (request-lifetime dynamic cache).
        return [
            'func' => 'php::getInternalClassEntrySafe',
            'args' => '"' . substr($ce, strlen(self::PREFIX . 'class_entry_')) . '"',
        ];
    }

    protected function getParentClassCe(ClassLikeDef $classDef): string
    {
        if (!$classDef->extends) {
            return '';
        }

        return self::PREFIX . 'class_entry_' . $this->escapeCeName($classDef->extends);
    }

    protected function doConvert(string $phpCode): string
    {
        $this->climate->info('convert: ' . $this->getRelativePath($this->file));

        $ast = $this->parser->parse($phpCode);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->addVisitor(new VoidCastValidationVisitor(
            fn (Node $node, string $message) => $this->fatalError($node, $message),
        ));
        $traverser->addVisitor(new Visitor(sourceFile: $this->file));
        $traverser->addVisitor(new ConstantExpressionValidationVisitor(
            $this->phpVersion,
            fn (Node $node, string $message) => $this->fatalError($node, $message),
        ));
        $traverser->addVisitor(new RuntimeAttributeFactoryLowering(
            $this->file,
            fn (string $class, string $case): bool => $this->isDeclaredEnumCase($class, $case),
        ));

        $stmts = $traverser->traverse($ast);

        $this->resetFile();
        $this->resetNamespace();
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();

        $cppCode = '';
        foreach ($stmts as $v) {
            if ($v instanceof Node\Stmt\Declare_) {
                $this->parseDeclare($v);
            } elseif ($v instanceof Node\Stmt\Namespace_) {
                $cppCode .= $this->parseNamespace($v);
            } elseif ($v instanceof Node\Stmt\Class_ || $v instanceof Node\Stmt\Trait_ || $v instanceof Node\Stmt\Enum_) {
                $cppCode .= $this->parseClass($v);
            } elseif ($v instanceof Node\Stmt\Use_) {
                $this->parseUse($v);
            } elseif ($v instanceof Node\Stmt\GroupUse) {
                $this->parseGroupUse($v);
            } elseif ($v instanceof Node\Stmt\Function_) {
                $cppCode .= $this->parseFunction($v) . PHP_EOL;
            } elseif ($v instanceof Node\Stmt\Const_) {
                $this->parseConstDef($v);
            } elseif ($v instanceof Node\Stmt\Interface_) {
                $this->validateInterfaceOverrideAttributes($v);
            } elseif (!$v instanceof Node\Stmt\Nop) {
                $this->unsupportedSyntax($v);
            }
        }

        foreach ($this->classesDefineInFile as $classDef) {
            if ($classDef->nativeObject) {
                continue;
            }
            $cppCode .= $this->genClassWrapper($classDef);
        }

        foreach ($this->interfacesDefineInFile as $interfaceDef) {
            $cppCode .= $this->genClassWrapper($interfaceDef);
        }

        foreach ($this->functionDefineInFile as $functionDef) {
            if ($functionDef->attributeFactory || $this->functionUsesNativeObject($functionDef)) {
                continue;
            }
            $cppCode .= $this->genFunctionWrapper($functionDef);
        }

        $constDataCode = '';
        foreach ($this->constData as $name => $data) {
            $constDataCode .= 'static const unsigned char ' . $name . '[] = {' . $data . '};' . PHP_EOL;
        }

        // Preparing and converting a compile-time-only source is still
        // required for diagnostics, symbol collection and trait AST
        // composition. Avoid creating a header-only .cc file when that work
        // produced no C++ entity.
        if (trim($constDataCode . $cppCode) === '') {
            return '';
        }

        $constDataCode .= PHP_EOL;

        return $this->genIncludeHeaderFiles() . $constDataCode . $cppCode;
    }

    protected function genClassCeList(): void
    {
        if (empty($this->symbols->interfaces()) and empty($this->symbols->classes())) {
            return;
        }

        $sorter = new StringSort();

        foreach ($this->symbols->interfaces() as $interfaceDef) {
            $ce = $this->getClassCe($interfaceDef);
            $deps = [];

            foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parent) {
                $tmpCe = self::PREFIX . 'class_entry_' . $this->escapeCeName($parent);
                // A non-existent interface is likely a built-in interface
                if (!$this->hasInterface($parent)) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $this->classCeInfo[$ce] = [
                'deps' => $deps,
                'func' => $this->getRegisterClassFunction($interfaceDef->getNamespacedName()),
                'args' => $this->getRegisterClassFunctionArgs($interfaceDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($interfaceDef),
            ];
            $sorter->add($ce, $deps);
        }

        foreach ($this->symbols->classes() as $classDef) {
            if ($classDef->trait !== null || $classDef->nativeObject) {
                continue;
            }
            $ce = $this->getClassCe($classDef);
            $deps = [];
            $parent = $classDef->extends;
            if ($parent) {
                // A non-existent parent is likely a built-in class
                $tmpCe = $this->getParentClassCe($classDef);
                if (!$this->hasClass($parent)) {
                    $sorter->add($tmpCe);
                }
                $deps[] = $tmpCe;
            }

            $implements = $classDef->implements;
            if ($implements) {
                foreach ($implements as $interface) {
                    $tmpCe = self::PREFIX . 'class_entry_' . $this->escapeCeName($interface);
                    if (!$this->hasInterface($interface)) {
                        $sorter->add($tmpCe);
                    }
                    $deps[] = $tmpCe;
                }
            }

            $this->classCeInfo[$ce] = [
                'classDef' => $classDef,
                'deps' => $deps,
                'func' => $this->getRegisterClassFunction($classDef->getNamespacedName()),
                'args' => $this->getRegisterClassFunctionArgs($classDef),
                'argDef' => $this->getRegisterClassFunctionArgDef($classDef),
            ];
            $sorter->add($ce, $deps);
        }

        // StringSort yields an empty placeholder when the symbol table contains
        // only compile-time traits. Never turn that placeholder into a bogus
        // `zend_class_entry *;` declaration.
        $this->classCeList = array_values(array_filter(
            $sorter->sort(),
            static fn (mixed $ce): bool => is_string($ce) && $ce !== '',
        ));
    }

    protected function getNativeMethodName(ClassDef $classDef, MethodDef $methodDef): string
    {
        return $this->getNativeName($methodDef->name, $classDef->namespace, $classDef->name);
    }

    protected function parseDeclare(mixed $v): void
    {
        $declares = $v->declares;
        foreach ($declares as $declare) {
            $key = $this->parseIdentifier($declare->key);
            $value = match (true) {
                $declare->value instanceof Node\Scalar\String_ => $declare->value->value,
                $declare->value instanceof Node\Scalar\Int_ => (string) $declare->value->value,
                default => $this->parseIdentifier($declare->value),
            };
            if ($key === 'ticks') {
                $this->fatalError($v, 'declare(ticks=1) is not supported');
            } elseif ($key === 'encoding') {
                if (strtolower($value) !== 'utf-8') {
                    $this->fatalError($v, 'declare(encoding="' . $value . '") is not supported, only UTF-8 is supported');
                }
            } elseif ($key === 'strict_types') {
                if (!($declare->value instanceof Node\Scalar\Int_) or $declare->value->value !== 1) {
                    $this->fatalError($v, 'declare(strict_types=0) is not allowed, only strict_types=1 is supported');
                }
            } else {
                $this->fatalError($v, 'declare(' . $key . '=' . $value . ') is not supported');
            }
        }
    }

    protected function parseNamespace(Node\Stmt\Namespace_ $node): string
    {
        $ns = $node->name ? $this->parseIdentifier($node->name) : '';
        $code = '';

        $this->resetNamespace();
        $this->resetClass();
        $this->resetMethod();
        $this->resetFunction();

        $this->namespace = $ns;
        $ns_end = '';

        foreach ($node->stmts as $v2) {
            if ($v2 instanceof Node\Stmt\Class_ || $v2 instanceof Node\Stmt\Trait_ || $v2 instanceof Node\Stmt\Enum_) {
                $code .= $this->parseClass($v2);
            } elseif ($v2 instanceof Node\Stmt\Const_) {
                $this->parseConstDef($v2);
            } elseif ($v2 instanceof Node\Stmt\Function_) {
                $code .= $this->parseFunction($v2) . PHP_EOL;
            } elseif ($v2 instanceof Node\Stmt\Use_) {
                $this->parseUse($v2);
            } elseif ($v2 instanceof Node\Stmt\GroupUse) {
                $this->parseGroupUse($v2);
            } elseif ($v2 instanceof Node\Stmt\Interface_) {
                $this->validateInterfaceOverrideAttributes($v2);
            } elseif (!$v2 instanceof Node\Stmt\Nop) {
                $this->unsupportedSyntax($v2);
            }
        }
        $code .= $ns_end;
        $this->resetNamespace();

        return $code;
    }

    protected function genStubFile(string $file): void
    {
        $headerFile = $this->getArgInfoHeaderFile($file, true);

        $this->climate->info('generate arginfo file: ' . $this->getRelativePath($file));
        generateStubFile($file, $this->getIncludeDir() . '/' . $headerFile, true, $this->getPhpVersion());

        $headerCode = file_get_contents($this->getBuildDir() . '/include/' . $headerFile);
        $needsAttributeSymbols = str_contains($headerCode, 'zend_add_function_attribute(')
            || str_contains($headerCode, 'zend_add_parameter_attribute(')
            || str_contains($headerCode, 'zend_add_global_constant_attribute(');
        if ($needsAttributeSymbols) {
            if (preg_match('/\bstatic\s+void\s+(register_[A-Za-z0-9_]+_symbols)\s*\(\s*int\s+module_number\s*\)/', $headerCode, $matches)) {
                $registerSymbolFn = $matches[1];
                $this->registerSymbols[] = $registerSymbolFn;
            }
        }
        $this->argInfoHeaderFiles[] = $headerFile;
    }

    public function composeTraitAst(Node\Stmt\ClassLike $stmt, Node\Name $className): void
    {
        $methods = [];
        $constants = [];
        $properties = [];
        $traitMethods = [];
        $traitConstants = [];
        $traitProperties = [];
        $classDef = $this->getClass($className->toString());
        $usingClassDef = $classDef;
        $compositionOwner = $classDef->getNamespacedName(false);

        foreach ($stmt->stmts as $classStmt) {
            if ($classStmt instanceof Node\Stmt\ClassMethod) {
                $name = strtolower($classStmt->name->toString());
                $methods[$name] = $classStmt;
            }
            if ($classStmt instanceof Node\Stmt\Property) {
                foreach ($classStmt->props as $prop) {
                    $name = strtolower($prop->name->toString());
                    $properties[$name] = $prop;
                }
            }
            if ($classStmt instanceof Node\Stmt\ClassConst) {
                foreach ($classStmt->consts as $const) {
                    $name = strtolower($const->name->toString());
                    $constants[$name] = $const;
                }
            }
        }

        foreach ($stmt->stmts as $classStmt) {
            if (!$classStmt instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($classStmt->traits as $trait1) {
                $traitFullName = $this->getNamespacedClassName($this->parseIdentifier($trait1));
                if (!$this->hasClass($traitFullName)) {
                    $this->fatalError($classStmt, "Trait `{$traitFullName}` not found");
                }

                $traitDef = $this->getClass($traitFullName);
                if (!$traitDef->trait) {
                    $this->fatalError($classStmt, "Trait `{$traitFullName}` not found");
                }

                /** @var Node\Stmt\Trait_ $traitAst */
                $traitAst = $this->cloneAstNode($traitDef->trait);
                // Recursively flatten traits used by this trait before copying
                // its members into the final class. The nested TraitUse nodes
                // must be resolved in the lexical context of the trait being
                // expanded, not in the context of its eventual consumer.
                $this->withTraitNameContext($traitFullName, function () use ($traitAst, $traitFullName): void {
                    $this->composeTraitAst($traitAst, new Node\Name($traitFullName));
                });
                $traitStmts = $traitAst->stmts;
                $aliasStmts = [];
                foreach ($traitStmts as $k1 => $traitStmt) {
                    if ($traitStmt instanceof Node\Stmt\ClassMethod) {
                        $methodName = strtolower($traitStmt->name->toString());
                        if ($traitStmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE) === null) {
                            $traitStmt->setAttribute(self::TRAIT_ORIGIN_ATTRIBUTE, $traitFullName);
                            $traitStmt->setAttribute(self::TRAIT_METHOD_ATTRIBUTE, $traitStmt->name->toString());
                        }
                        $fullMethodName = $this->getFullMethodName($traitFullName, $methodName);
                        // A trait method's `self`/`static`/`parent` return and parameter
                        // types refer to the class that uses the trait, not the trait
                        // itself. Re-resolve them on the cloned AST so the generated
                        // arginfo reflects the consuming class (PHP trait semantics) and
                        // passes ZendVM's runtime signature-compatibility checks. The
                        // alias clones below inherit this rewrite.
                        $traitOrigin = (string) $traitStmt->getAttribute(
                            self::TRAIT_ORIGIN_ATTRIBUTE,
                            $traitFullName,
                        );
                        $this->reresolveTraitMethodAstLateBoundTypes($usingClassDef, $traitOrigin, $traitStmt);
                        // Every adaptation derives its flags from the original
                        // method's flags: a same-name visibility change must not
                        // leak into aliases of the same method that are processed
                        // after it, so the original statement is only mutated once
                        // all adaptations have been handled.
                        $originalFlags = $traitStmt->flags;
                        $adaptedFlags = $originalFlags;
                        foreach ($classDef->traitAliases[$fullMethodName] ?? [] as $alias) {
                            $aliasName = strtolower($alias['newName']);
                            if ($aliasName === $methodName) {
                                if ($alias['newModifier']) {
                                    $adaptedFlags = $this->applyTraitAliasModifier($originalFlags, $alias['newModifier']);
                                }
                            } elseif (isset($methods[$aliasName])) {
                                // The class defines the alias name itself. An
                                // abstract source is still a requirement the
                                // class method has to satisfy.
                                if ($traitStmt->isAbstract()) {
                                    [$requirementSource, $requirementDef] = $this->resolveTraitStmtMethodDef($traitStmt, $traitFullName);
                                    $this->validateTraitAbstractImplementation(
                                        $classStmt,
                                        $traitStmt, $requirementSource, $requirementDef,
                                        $methods[$aliasName], $compositionOwner,
                                        $classDef->methods[$aliasName] ?? $classDef->abstractMethodDefs[$aliasName] ?? null,
                                        $usingClassDef,
                                    );
                                }
                            } elseif (!isset($traitMethods[$aliasName])) {
                                $aliasStmt = clone $traitStmt;
                                $aliasStmt->name = new Node\Identifier($alias['newName']);
                                if ($alias['newModifier']) {
                                    $aliasStmt->flags = $this->applyTraitAliasModifier($originalFlags, $alias['newModifier']);
                                }
                                $aliasStmts[] = $aliasStmt;
                                $traitMethods[$aliasName] = [$traitFullName, $aliasStmt];
                            }
                        }
                        $traitStmt->flags = $adaptedFlags;
                        if (isset($classDef->traitIgnored[$fullMethodName])) {
                            unset($traitStmts[$k1]);
                            continue;
                        }
                        if (isset($methods[$methodName])) {
                            // The class's own method always wins: suppressed
                            // trait copies must not take part in trait-vs-trait
                            // conflict resolution. An abstract trait method is
                            // still a requirement the class method must satisfy.
                            if ($traitStmt->isAbstract()) {
                                [$requirementSource, $requirementDef] = $this->resolveTraitStmtMethodDef($traitStmt, $traitFullName);
                                $this->validateTraitAbstractImplementation(
                                    $classStmt,
                                    $traitStmt, $requirementSource, $requirementDef,
                                    $methods[$methodName], $compositionOwner,
                                    $classDef->methods[$methodName] ?? $classDef->abstractMethodDefs[$methodName] ?? null,
                                    $usingClassDef,
                                );
                            }
                            unset($traitStmts[$k1]);
                            continue;
                        }
                        if (isset($traitMethods[$methodName])) {
                            [$existingTraitName, $existingStmt] = $traitMethods[$methodName];
                            $newAbstract = $traitStmt->isAbstract();
                            $existingAbstract = $existingStmt->isAbstract();

                            if ($newAbstract && $existingAbstract) {
                                // Both abstract: validate signature compatibility
                                $this->validateTraitAbstractMethodCompatibility(
                                    $classStmt, $existingTraitName, $traitFullName,
                                    $methodName, $existingStmt, $traitStmt
                                );
                                // Signatures compatible, skip this duplicate
                                unset($traitStmts[$k1]);
                                continue;
                            }

                            if ($newAbstract && !$existingAbstract) {
                                // Existing concrete wins over new abstract, but
                                // it must satisfy the abstract requirement.
                                [$requirementSource, $requirementDef] = $this->resolveTraitStmtMethodDef($traitStmt, $traitFullName);
                                [$implementationSource, $implementationDef] = $this->resolveTraitStmtMethodDef($existingStmt, $existingTraitName);
                                $this->validateTraitAbstractImplementation(
                                    $classStmt,
                                    $traitStmt, $requirementSource, $requirementDef,
                                    $existingStmt, $implementationSource, $implementationDef,
                                    $usingClassDef,
                                );
                                unset($traitStmts[$k1]);
                                continue;
                            }

                            if (!$newAbstract && $existingAbstract) {
                                // The new concrete method fulfills the abstract
                                // requirement: validate it against the
                                // requirement, then drop the already-collected
                                // abstract declaration and keep this one.
                                [$requirementSource, $requirementDef] = $this->resolveTraitStmtMethodDef($existingStmt, $existingTraitName);
                                [$implementationSource, $implementationDef] = $this->resolveTraitStmtMethodDef($traitStmt, $traitFullName);
                                $this->validateTraitAbstractImplementation(
                                    $classStmt,
                                    $existingStmt, $requirementSource, $requirementDef,
                                    $traitStmt, $implementationSource, $implementationDef,
                                    $usingClassDef,
                                );
                                foreach ($stmt->stmts as $k3 => $mergedStmt) {
                                    if ($mergedStmt === $existingStmt) {
                                        unset($stmt->stmts[$k3]);
                                    }
                                }
                                foreach ($aliasStmts as $k3 => $pendingAliasStmt) {
                                    if ($pendingAliasStmt === $existingStmt) {
                                        unset($aliasStmts[$k3]);
                                    }
                                }
                                $traitMethods[$methodName] = [$traitFullName, $traitStmt];
                                continue;
                            }

                            // Both concrete — error
                            $this->fatalError($classStmt, "Trait `{$traitFullName}` method `{$methodName}` already exists");
                        }
                        $traitMethods[$methodName] = [$traitFullName, $traitStmt];
                    }
                    if ($traitStmt instanceof Node\Stmt\ClassConst) {
                        foreach ($traitStmt->consts as $k2 => $const) {
                            $constName = strtolower($const->name->toString());
                            if (isset($constants[$constName])) {
                                unset($traitStmt->consts[$k2]);
                                if (!$traitStmt->consts) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            if (isset($traitConstants[$constName])) {
                                [$existingConstStmt, $existingConst] = $traitConstants[$constName];
                                if ($existingConstStmt->flags !== $traitStmt->flags ||
                                    $this->typeNodeToStringOrNull($existingConstStmt->type) !== $this->typeNodeToStringOrNull($traitStmt->type) ||
                                    $this->printer->prettyPrintExpr($existingConst->value) !== $this->printer->prettyPrintExpr($const->value)) {
                                    $this->fatalError($classStmt, "Trait `{$traitFullName}` constant `{$constName}` already exists");
                                }
                                unset($traitStmt->consts[$k2]);
                                if (!$traitStmt->consts) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            $traitConstants[$constName] = [$traitStmt, $const];
                        }
                    }
                    if ($traitStmt instanceof Node\Stmt\Property) {
                        if ($traitStmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE) === null) {
                            $traitStmt->setAttribute(self::TRAIT_ORIGIN_ATTRIBUTE, $traitFullName);
                        }
                        foreach ($traitStmt->props as $k2 => $prop) {
                            $propName = strtolower($prop->name->toString());
                            if (isset($properties[$propName])) {
                                unset($traitStmt->props[$k2]);
                                if (!$traitStmt->props) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            if (isset($traitProperties[$propName])) {
                                [$existingPropStmt, $existingProp] = $traitProperties[$propName];
                                $existingDefault = $existingProp->default ? $this->printer->prettyPrintExpr($existingProp->default) : null;
                                $propDefault = $prop->default ? $this->printer->prettyPrintExpr($prop->default) : null;
                                if ($existingPropStmt->flags !== $traitStmt->flags ||
                                    $this->typeNodeToStringOrNull($existingPropStmt->type) !== $this->typeNodeToStringOrNull($traitStmt->type) ||
                                    $existingDefault !== $propDefault) {
                                    $this->fatalError($classStmt, "Trait `{$traitFullName}` property `{$propName}` already exists");
                                }
                                unset($traitStmt->props[$k2]);
                                if (!$traitStmt->props) {
                                    unset($traitStmts[$k1]);
                                }
                                continue;
                            }
                            $traitProperties[$propName] = [$traitStmt, $prop];
                        }
                    }
                }

                $stmt->stmts = array_merge($stmt->stmts, $traitStmts, $aliasStmts);
            }
        }

    }

    /**
     * Apply a trait alias modifier the way PHP does: a new visibility replaces
     * only the visibility bits, and every other flag (static, final, abstract)
     * is kept. A modifier without visibility (e.g. `as final`) keeps the
     * original visibility.
     */
    private function applyTraitAliasModifier(int $flags, int $newModifier): int
    {
        if ($newModifier & Modifiers::VISIBILITY_MASK) {
            $flags &= ~Modifiers::VISIBILITY_MASK;
        }
        return $flags | $newModifier;
    }

    /**
     * Resolve the trait a flattened method statement originated from and its
     * preprocessed method definition. Recursive trait composition tags every
     * copied statement with its true origin, which may be a nested trait
     * rather than the trait currently being applied.
     *
     * @return array{string, ?MethodDef}
     */
    private function resolveTraitStmtMethodDef(Node\Stmt\ClassMethod $stmt, string $fallbackTrait): array
    {
        $origin = $stmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE);
        if (!is_string($origin) || $origin === '') {
            $origin = $fallbackTrait;
        }
        $originalName = $stmt->getAttribute(self::TRAIT_METHOD_ATTRIBUTE);
        if (!is_string($originalName) || $originalName === '') {
            $originalName = $stmt->name->toString();
        }
        $def = null;
        if ($this->hasClass($origin)) {
            $originDef = $this->getClass($origin);
            $lower = strtolower($originalName);
            $def = $originDef->methods[$lower] ?? $originDef->abstractMethodDefs[$lower] ?? null;
        }
        return [$origin, $def];
    }

    /**
     * Validate that a concrete method satisfies an abstract requirement
     * declared by a trait, following Zend's trait-composition rules: the
     * static modifier must match, an abstract by-reference return must be
     * kept, the implementation cannot require more parameters, parameter
     * types are contravariant, and the return type is covariant. Visibility
     * is deliberately not restricted — Zend allows an implementation of any
     * visibility to fulfill an abstract trait requirement.
     */
    private function validateTraitAbstractImplementation(
        Node $errorNode,
        Node\Stmt\ClassMethod $requirement,
        string $requirementSource,
        ?MethodDef $requirementDef,
        Node\Stmt\ClassMethod $implementation,
        string $implementationSource,
        ?MethodDef $implementationDef,
        ClassDef $usingClassDef,
    ): void {
        $requirementName = $requirement->name->toString();
        $implementationName = $implementation->name->toString();
        $consumingClass = $usingClassDef->getNamespacedName(false);

        if ($requirement->isStatic() !== $implementation->isStatic()) {
            $this->fatalError($errorNode, $requirement->isStatic()
                ? "Cannot make static method `{$requirementSource}::{$requirementName}()` non static in class `{$consumingClass}`"
                : "Cannot make non static method `{$requirementSource}::{$requirementName}()` static in class `{$consumingClass}`");
        }

        $incompatible = function () use ($errorNode, $implementationSource, $implementationName, $requirementSource, $requirementName): never {
            $this->fatalError(
                $errorNode,
                "Declaration of `{$implementationSource}::{$implementationName}()` must be compatible " .
                "with `{$requirementSource}::{$requirementName}()`"
            );
        };

        // The requirement's by-reference return must be kept; the
        // implementation may add one.
        if ($requirement->byRef && !$implementation->byRef) {
            $incompatible();
        }

        if ($this->countRequiredParams($implementation->params) > $this->countRequiredParams($requirement->params)) {
            $incompatible();
        }
        $implParamCount = count($implementation->params);
        $lastImplParam = $implParamCount > 0 ? $implementation->params[$implParamCount - 1] : null;
        foreach ($requirement->params as $i => $requiredParam) {
            // A trailing variadic accepts every remaining requirement position.
            $implParam = $implementation->params[$i]
                ?? ($lastImplParam?->variadic ? $lastImplParam : null);
            if ($implParam === null
                || $implParam->byRef !== $requiredParam->byRef
                || ($requiredParam->variadic && !$implParam->variadic)
            ) {
                $incompatible();
            }
        }
        foreach ($implementation->params as $i => $implParam) {
            if ($i >= count($requirement->params) && !$implParam->default && !$implParam->variadic) {
                $incompatible();
            }
        }

        // Type variance is checked on the preprocessed definitions, whose
        // names were resolved in each declaration's own lexical context.
        $requirementFunc = $requirementDef?->functionDef;
        $implementationFunc = $implementationDef?->functionDef;
        if (!$requirementFunc || !$implementationFunc) {
            return;
        }

        $implArgs = $implementationFunc->argInfoList;
        $lastImplArg = $implArgs === [] ? null : $implArgs[count($implArgs) - 1];
        foreach ($requirementFunc->argInfoList as $i => $requiredArg) {
            $implArg = $implArgs[$i] ?? ($lastImplArg?->variadic ? $lastImplArg : null);
            if ($implArg === null) {
                continue;
            }
            if (!$this->isTraitParameterTypeCompatible(
                $implArg,
                $requiredArg,
                $usingClassDef,
                $errorNode,
            )) {
                $incompatible();
            }
        }

        if ($requirementFunc->returnTypeUndeclared) {
            return;
        }
        if ($implementationFunc->returnTypeUndeclared) {
            $incompatible();
        }
        $requirementTypes = $this->getTraitReturnAcceptedTypes(
            $requirementFunc,
            $usingClassDef,
            $errorNode,
        );
        $implementationTypes = $this->getTraitReturnAcceptedTypes(
            $implementationFunc,
            $usingClassDef,
            $errorNode,
        );
        foreach ($implementationTypes as $implementationType) {
            if (!$this->isReturnTypeCoveredBy($implementationType, $requirementTypes)) {
                $incompatible();
            }
        }
    }

    /**
     * @param array<Node\Param> $params
     */
    private function countRequiredParams(array $params): int
    {
        $required = 0;
        foreach (array_values($params) as $i => $param) {
            if (!$param->default && !$param->variadic) {
                $required = $i + 1;
            }
        }
        return $required;
    }

    private function isTraitParameterTypeCompatible(
        ArgInfo $implementation,
        ArgInfo $requirement,
        ClassDef $usingClassDef,
        Node $errorNode,
    ): bool {
        if ($this->isTopParameterType($implementation)) {
            return true;
        }
        if ($this->isTopParameterType($requirement)) {
            return false;
        }

        $requirementTypes = $this->getTraitParameterAcceptedTypes(
            $requirement,
            $usingClassDef,
            $errorNode,
        );
        $implementationTypes = $this->getTraitParameterAcceptedTypes(
            $implementation,
            $usingClassDef,
            $errorNode,
        );
        if ($requirementTypes === null || $implementationTypes === null) {
            return $this->isParameterTypeOverrideCompatible($implementation, $requirement);
        }
        return $this->isAcceptedTypeSubset($requirementTypes, $implementationTypes);
    }

    private function getTraitParameterAcceptedTypes(
        ArgInfo $argument,
        ClassDef $usingClassDef,
        Node $errorNode,
    ): ?array {
        if ($argument->typeKeyword !== '') {
            return $this->getLateBoundTraitAcceptedType($argument->typeKeyword, $usingClassDef, $errorNode);
        }
        return $this->resolveLateBoundAcceptedTypes(
            $this->getParameterAcceptedTypes($argument),
            $usingClassDef,
            $errorNode,
        );
    }

    private function getTraitReturnAcceptedTypes(
        FunctionDef $function,
        ClassDef $usingClassDef,
        Node $errorNode,
    ): array {
        if ($function->returnTypeKeyword !== '') {
            return $this->getLateBoundTraitAcceptedType($function->returnTypeKeyword, $usingClassDef, $errorNode);
        }
        return $this->resolveLateBoundAcceptedTypes(
            $this->getReturnAcceptedTypes($function, $usingClassDef->getNamespacedName(false)),
            $usingClassDef,
            $errorNode,
        ) ?? [];
    }

    private function getLateBoundTraitAcceptedType(
        string $keyword,
        ClassDef $usingClassDef,
        Node $errorNode,
    ): array {
        if ($keyword === 'static') {
            return [['kind' => 'isStatic', 'class' => $usingClassDef->getNamespacedName(false)]];
        }
        $class = $this->resolveLateBoundClass($usingClassDef, $keyword);
        if ($class === null) {
            $this->fatalError($errorNode, 'Cannot use "parent" when current class scope has no parent');
        }
        return [['kind' => 'instanceof', 'class' => $class]];
    }

    private function resolveLateBoundAcceptedTypes(
        ?array $types,
        ClassDef $usingClassDef,
        Node $errorNode,
    ): ?array {
        if ($types === null) {
            return null;
        }
        foreach ($types as &$type) {
            if (($type['kind'] ?? null) === 'allOf') {
                $type['types'] = $this->resolveLateBoundAcceptedTypes(
                    $type['types'],
                    $usingClassDef,
                    $errorNode,
                );
                continue;
            }
            $lateBound = $type['lateBound'] ?? '';
            if (!is_string($lateBound) || $lateBound === '') {
                continue;
            }
            $resolved = $this->getLateBoundTraitAcceptedType($lateBound, $usingClassDef, $errorNode)[0];
            $type['kind'] = $resolved['kind'];
            $type['class'] = $resolved['class'];
            unset($type['lateBound']);
        }
        return $types;
    }

    private function cloneAstNode(Node $node): Node
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        return $traverser->traverse([$node])[0];
    }

    /**
     * Re-resolve a trait method's late-bound `self`/`static`/`parent` return and
     * parameter types on the cloned AST that is being flattened into a class.
     *
     * `resolveTypeDecl()` mutates a trait method's `self`/`static`/`parent` type
     * node to the trait's own name at parse time, so the cloned AST carries the
     * trait name rather than the late-bound keyword. We instead rewrite those
     * nodes to the consuming class (or its parent) using the keyword recorded on
     * the trait method's FunctionDef, matching PHP's trait semantics. This keeps
     * the generated arginfo correct for ZendVM's runtime compatibility checks.
     */
    private function reresolveTraitMethodAstLateBoundTypes(
        ClassDef $usingClassDef,
        string $traitFullName,
        Node\Stmt\ClassMethod $methodStmt
    ): void {
        if (!$this->hasClass($traitFullName)) {
            return;
        }
        $traitDef = $this->getClass($traitFullName);
        $methodName = strtolower($methodStmt->name->toString());
        $methodDef = $traitDef->methods[$methodName]
            ?? $traitDef->abstractMethodDefs[$methodName]
            ?? null;
        if ($methodDef === null) {
            return;
        }
        $fn = $methodDef->functionDef;

        if ($fn->returnTypeKeyword !== '' && $methodStmt->returnType instanceof Node\Name) {
            $methodStmt->returnType = $this->resolveTraitTypeNode(
                $methodStmt->returnType,
                $fn->returnTypeKeyword,
                $usingClassDef,
            );
        } elseif ($methodStmt->returnType !== null) {
            $methodStmt->returnType = $this->reresolveCompositeTraitTypeNode(
                $methodStmt->returnType,
                $usingClassDef,
            );
        }

        foreach ($fn->argInfoList as $i => $arg) {
            if (
                $arg->typeKeyword !== ''
                && isset($methodStmt->params[$i])
                && $methodStmt->params[$i]->type instanceof Node\Name
            ) {
                $methodStmt->params[$i]->type = $this->resolveTraitTypeNode(
                    $methodStmt->params[$i]->type,
                    $arg->typeKeyword,
                    $usingClassDef,
                );
            } elseif (isset($methodStmt->params[$i]) && $methodStmt->params[$i]->type !== null) {
                $methodStmt->params[$i]->type = $this->reresolveCompositeTraitTypeNode(
                    $methodStmt->params[$i]->type,
                    $usingClassDef,
                );
            }
        }
    }

    private function reresolveCompositeTraitTypeNode(
        Node\ComplexType|Node\Identifier|Node\Name $type,
        ClassDef $usingClassDef,
    ): Node\ComplexType|Node\Identifier|Node\Name {
        if ($type instanceof Node\NullableType) {
            $type->type = $this->reresolveTraitTypeMember($type->type, $usingClassDef);
            return $type;
        }
        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $i => $member) {
                $type->types[$i] = $this->reresolveTraitTypeMember($member, $usingClassDef);
            }
            return $type;
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $i => $member) {
                if ($member instanceof Node\IntersectionType) {
                    foreach ($member->types as $j => $intersectionMember) {
                        $member->types[$j] = $this->reresolveTraitTypeMember(
                            $intersectionMember,
                            $usingClassDef,
                        );
                    }
                } else {
                    $type->types[$i] = $this->reresolveTraitTypeMember($member, $usingClassDef);
                }
            }
            return $type;
        }
        return $this->reresolveTraitTypeMember($type, $usingClassDef);
    }

    private function reresolveTraitTypeMember(
        Node\Identifier|Node\Name $type,
        ClassDef $usingClassDef,
    ): Node\Identifier|Node\Name {
        if (!$type instanceof Node\Name) {
            return $type;
        }
        $keyword = $type->getAttribute(self::LATE_BOUND_TYPE_ATTRIBUTE);
        if (!is_string($keyword) || $keyword === '') {
            return $type;
        }
        return $this->resolveTraitTypeNode($type, $keyword, $usingClassDef);
    }

    private function resolveTraitTypeNode(
        Node\Name $type,
        string $keyword,
        ClassDef $usingClassDef,
    ): Node\Name {
        if ($keyword === 'static') {
            return new Node\Name('static', $type->getAttributes());
        }
        $resolved = $this->resolveLateBoundClass($usingClassDef, $keyword);
        if ($resolved === null) {
            // A trait can import another trait whose signature contains
            // `parent` before any class consumes either one. PHP keeps that
            // type late-bound until the outer trait is flattened into a
            // class. Signature compatibility checks performed while traits
            // are composed still reject `parent` in this scope, because
            // there is no parent against which the two methods can be
            // compared.
            if ($keyword === 'parent' && $usingClassDef->trait !== null) {
                return new Node\Name('parent', $type->getAttributes());
            }
            $this->fatalError($type, 'Cannot use "parent" when current class scope has no parent');
        }
        return new Node\Name\FullyQualified($resolved, $type->getAttributes());
    }

    /**
     * Validate that two abstract trait methods have compatible signatures.
     * PHP allows multiple traits to declare the same abstract method as long
     * as parameters and return type are compatible.
     */
    protected function validateTraitAbstractMethodCompatibility(
        Node\Stmt\TraitUse $classStmt,
        string $traitA,
        string $traitB,
        string $methodName,
        Node\Stmt\ClassMethod $a,
        Node\Stmt\ClassMethod $b
    ): void {
        // Compare visibility
        if ($a->flags !== $b->flags) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with different visibility'
            );
        }

        // Compare return type
        $aRet = $a->returnType ? $this->typeNodeToString($a->returnType) : null;
        $bRet = $b->returnType ? $this->typeNodeToString($b->returnType) : null;
        if ($aRet !== $bRet) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with different return types'
            );
        }

        // Compare parameter count
        if (count($a->params) !== count($b->params)) {
            $this->fatalError(
                $classStmt,
                "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                'but with incompatible parameter counts'
            );
        }

        // Compare parameter types
        foreach ($a->params as $i => $paramA) {
            $paramB = $b->params[$i];
            $typeA = $paramA->type ? $this->typeNodeToString($paramA->type) : null;
            $typeB = $paramB->type ? $this->typeNodeToString($paramB->type) : null;
            if ($typeA !== $typeB) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} has incompatible types"
                );
            }
            if ($paramA->byRef !== $paramB->byRef) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} differs in by-reference"
                );
            }
            if ($paramA->variadic !== $paramB->variadic) {
                $this->fatalError(
                    $classStmt,
                    "Trait `{$traitA}` and Trait `{$traitB}` define the same abstract method `{$methodName}` " .
                    "but parameter #{$i} differs in variadic"
                );
            }
        }
    }

    /**
     * Convert a PHP-Parser type node to a normalized string for comparison.
     */
    private function typeNodeToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof Node\NullableType) {
            return '?' . $this->typeNodeToString($typeNode->type);
        }
        if ($typeNode instanceof Node\UnionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->typeNodeToString($t);
            }
            sort($parts);
            return implode('|', $parts);
        }
        if ($typeNode instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $t) {
                $parts[] = $this->typeNodeToString($t);
            }
            sort($parts);
            return implode('&', $parts);
        }
        // Fallback: use pretty printer
        return $this->printer->prettyPrint([$typeNode]);
    }

    private function typeNodeToStringOrNull(?NodeAbstract $typeNode): ?string
    {
        return $typeNode ? $this->typeNodeToString($typeNode) : null;
    }

    private function configureGeneratedConstructorParentCall(Node\Stmt\Class_ $class): void
    {
        $constructor = null;
        foreach ($class->getMethods() as $method) {
            if ($method->getAttribute(ConstructorLowering::GENERATED_ATTRIBUTE, false)) {
                $constructor = $method;
                break;
            }
        }
        if ($constructor === null || $this->classDef->extends === '') {
            return;
        }

        $parent = $this->classDef->extends;
        while ($parent !== '') {
            $parentDef = $this->getClassDef($parent);
            if ($parentDef === null) {
                $reflection = Reflection::getClass($parent);
                $parentConstructor = $reflection?->getConstructor();
                if ($parentConstructor === null) {
                    return;
                }
                $owner = $parentConstructor->getDeclaringClass()->getName();
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $owner,
                    $parentConstructor->getModifiers(),
                    $parentConstructor->getNumberOfRequiredParameters(),
                    $parentConstructor->isAbstract(),
                );
                return;
            }

            if ($parentDef->hasMethod('__construct')) {
                $parentConstructor = $parentDef->getMethod('__construct');
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $parent,
                    $parentConstructor->flags,
                    $parentConstructor->functionDef?->argCountRequired ?? 0,
                    false,
                );
                return;
            }
            if ($parentDef->hasAbstractMethod('__construct')) {
                $parentConstructor = $parentDef->getAbstractMethod('__construct');
                $this->applyGeneratedConstructorParentRule(
                    $constructor,
                    $parent,
                    $parentConstructor->flags,
                    $parentConstructor->functionDef?->argCountRequired ?? 0,
                    true,
                );
                return;
            }
            $parent = $parentDef->extends;
        }
    }

    private function applyGeneratedConstructorParentRule(
        Node\Stmt\ClassMethod $constructor,
        string $parent,
        int $flags,
        int $requiredArguments,
        bool $abstract,
    ): void {
        $attributeTarget = $constructor->getAttribute(
            \TypePhp\Diagnostics\CompileTimeAttributeDiagnostic::GENERATED_TARGET,
            $constructor,
        );
        if (!$attributeTarget instanceof Node) {
            $attributeTarget = $constructor;
        }
        if ($flags & Modifiers::FINAL) {
            $this->fatalCompileTimeAttribute(
                $attributeTarget,
                'Constructor',
                "Cannot override final method `{$parent}::__construct()`",
                $attributeTarget,
            );
        }
        if ($flags & Modifiers::PRIVATE) {
            return;
        }
        if ($requiredArguments > 0) {
            $this->fatalCompileTimeAttribute(
                $attributeTarget,
                'Constructor',
                "Constructor cannot be generated because parent constructor `{$parent}::__construct()` " .
                "requires {$requiredArguments} argument(s); declare `__construct()` explicitly",
                $attributeTarget,
            );
        }
        if ($abstract) {
            return;
        }

        array_unshift($constructor->stmts, new Node\Stmt\Expression(new Node\Expr\StaticCall(
            new Node\Name('parent'),
            new Node\Identifier('__construct'),
        )));
    }

    protected function parseClass(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $class): string
    {
        $this->class = $this->parseIdentifier($class->name);
        $fullName = $this->getFullClassName();
        if (!$this->hasClass($fullName)) {
            $this->fatalError($class, "class {$fullName} not found");
        }
        $this->classDef = $this->getClass($fullName);
        $this->parseMethodsForTarget($class);

        if ($class instanceof Node\Stmt\Class_) {
            $this->configureGeneratedConstructorParentCall($class);
        }

        if ($class instanceof Node\Stmt\Class_ && $this->classDef->printerGenerated) {
            $available = [...$this->parentPublicProperties($this->classDef->extends), ...\TypePhp\Transform\ClassFieldSelection::ownPublicProperties($class)];
            try {
                $properties = \TypePhp\Transform\ClassFieldSelection::resolve(
                    $this->classDef->printerFields,
                    $this->classDef->printerFields === null
                        ? $available
                        : $this->selectableProperties($this->classDef),
                    'Printer',
                );
            } catch (SyntaxError $error) {
                throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                    $error->getMessage(),
                    'Printer',
                    $class,
                    $this->file,
                ), 0, $error);
            }
            \TypePhp\Transform\PrinterLowering::rebuildGeneratedMethod(
                $class,
                $properties,
                $this->classDef->printerFields,
                $this->classStringProperties($this->classDef),
            );
        }
        if ($class instanceof Node\Stmt\Class_ && $this->classDef->arrayableGenerated) {
            $available = [...$this->parentPublicProperties($this->classDef->extends), ...\TypePhp\Transform\ClassFieldSelection::ownPublicProperties($class)];
            try {
                $properties = \TypePhp\Transform\ClassFieldSelection::resolve(
                    $this->classDef->arrayableFields,
                    $this->classDef->arrayableFields === null
                        ? $available
                        : $this->selectableProperties($this->classDef),
                    'Arrayable',
                );
            } catch (SyntaxError $error) {
                throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                    $error->getMessage(),
                    'Arrayable',
                    $class,
                    $this->file,
                ), 0, $error);
            }
            \TypePhp\Transform\ArrayableLowering::rebuildGeneratedMethod(
                $class,
                $properties,
                $this->classDef->arrayableFields,
            );
        }

        // When not inheriting from a built-in class, verify the parent exists.
        // The preprocess phase only checks whether a built-in class is inherited.
        // Currently, inheriting from an autoloaded custom class is not allowed.
        if ($this->classDef->extends and !$this->classDef->inheritedFromInternalClass) {
            $parentClass = $this->getNamespacedClassName($this->parseIdentifier($class->extends));
            if ($this->hasClass($parentClass)) {
                $parent = $this->getClass($parentClass);
                if ($this->classDef->nativeObject !== $parent->nativeObject) {
                    $this->fatalError(
                        $class,
                        'Native and ZendVM-backed classes cannot inherit from each other'
                    );
                }
                // The parent class is final and cannot be extended
                if ($parent->flags & Modifiers::FINAL) {
                    $this->fatalError($class, "Class `{$this->class}` cannot extend final class `{$parentClass}`");
                }
            } else {
                $this->fatalError($class, "Class `{$this->class}` inherits from a non-existent class `{$parentClass}`");
            }
        }

        if (is_array($this->classDef->implements)) {
            foreach ($this->classDef->implements as $interfaceName) {
                if (!$this->hasInterface($interfaceName) and !$this->isInternalInterface($interfaceName)) {
                    $this->fatalError($class, "Class `{$this->class}` implements a non-existent interface `{$interfaceName}`");
                }
            }
        }

        if ($class instanceof Node\Stmt\Class_ || $class instanceof Node\Stmt\Enum_) {
            /** @var Node\Stmt\Class_|Node\Stmt\Enum_ $composedClass */
            $composedClass = $this->cloneAstNode($class);
            $this->composeTraitAst($composedClass, new Node\Name($fullName));
            $this->installComposedTraitDataMembers($composedClass);
        } else {
            $composedClass = null;
        }

        $this->checkPropertyOverride($class);
        $this->checkConstantOverride($class);

        $className = $this->classDef->getNamespacedName();
        $this->classesDefineInFile[$className] = $this->classDef;

        $methodCodes = [];

        // Trait methods are flattened into the consuming class and therefore
        // participate in that class's lexical visibility. Install every
        // composed declaration before lowering any method body: a class method
        // may call a protected/private trait method on another instance, and
        // runtime dispatch needs to know that the call carries class scope.
        $composedTraitMethods = [];
        if ($composedClass !== null) {
            foreach ($composedClass->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod
                    || !is_string($stmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE))) {
                    continue;
                }
                $origin = (string) $stmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE);
                $this->withTraitNameContext($origin, function () use ($stmt): void {
                    $this->installComposedTraitMethod($stmt);
                });
                $composedTraitMethods[] = [$stmt, $origin];
            }
        }

        foreach ($class->stmts as $v) {
            $type = $v->getType();
            switch ($type) {
                case 'Stmt_ClassConst':
                case 'Stmt_Property':
                case 'Stmt_Nop':
                case 'Stmt_EnumCase':
                    break;
                case 'Stmt_ClassMethod':
                    if (!$class instanceof Node\Stmt\Trait_) {
                        $this->parseClassMethod($v, $methodCodes);
                    }
                    break;
                case 'Stmt_TraitUse':
                    $this->parseTraitUse($v, $methodCodes);
                    break;
                default:
                    $this->unsupportedSyntax($v);
                    break;
            }
        }
        // All composed declarations are now visible. Lower their bodies in a
        // separate pass so one trait method can call another method declared
        // later in the same or a nested trait.
        foreach ($composedTraitMethods as [$stmt, $origin]) {
            $this->withTraitNameContext($origin, function () use ($stmt, &$methodCodes): void {
                $this->parseClassMethod($stmt, $methodCodes);
            });
        }
        if (!$class instanceof Node\Stmt\Trait_) {
            $this->validateOverrideAttributes($class);
            $this->checkInterfaceImplementations($class);
            $this->checkInheritedAbstractMethodsAreImplemented($class);
        }
        $code = $this->genNativeMethod($methodCodes);
        if ($this->classDef->nativeObject) {
            $code .= $this->genNativeObjectRuntimeDefinition($this->classDef);
        }

        $oriCtx = $this->context;
        $this->context = $this->classDef->propertyContext;
        $this->classDef->ctorInit .= $this->genScopeVarDecl() . $this->parseBeforeStmtLines();
        $this->classDef->ctorClean .= $this->parseAfterStmtLines();
        $this->context = $oriCtx;

        $this->resetClass();

        return $code;
    }

    protected function genNativeMethod(array $methodCodes): string
    {
        $code = '';
        foreach ($methodCodes as $methodCode) {
            $code .= $methodCode . PHP_EOL;
        }
        $code .= PHP_EOL;

        return $code;
    }

    protected function genWrapperFunctionArgs(
        string $fn,
        FunctionDef $functionDef,
        string $displayName,
        array $implicitMethodArgs = []
    ): string
    {
        // A generated ZEND_FUNCTION/ZEND_METHOD is a callback boundary owned by
        // ZendVM. Native TypePHP code uses C++ exceptions so its local RAII
        // objects unwind correctly, but that exception must not escape through
        // Zend's C frames: those frames perform their cleanup after the handler
        // returns with EG(exception) set. Convert back to normal Zend exception
        // propagation at the outermost wrapper.
        $this->indentLevel++;
        $cppCode = $this->getIndent() . 'try {' . PHP_EOL;
        $this->indentLevel++;

        $argCountCheck = $this->genParameterCountCheck(
            $functionDef->argCountRequired,
            count($functionDef->argInfoList),
            $functionDef->hasVariadicArg(),
        );
        if ($argCountCheck !== '') {
            $cppCode .= $this->getIndent() . rtrim($argCountCheck) . PHP_EOL;
        }

        $callParams = '';
        foreach ($functionDef->argInfoList as $k => $argInfo) {
            $var = 'arg_' . $argInfo->name;
            if ($argInfo->variadic) {
                $cppCode .= $this->getIndent() . Type::ARRAY . ' ' . $var . ';' . PHP_EOL;
                $cppCode .= $this->getIndent() . 'for (uint32_t i = ' . $k . '; i < php::getCallArgNum(); i++) {' . PHP_EOL;
                $this->indentLevel++;
                if ($argInfo->byRef) {
                    $cppCode .= $this->getIndent() . $var . '.append(php::getCallArgByRef(i));' . PHP_EOL;
                } elseif ($this->isStrictScalarType($argInfo->type)) {
                    $rawVar = 'raw_' . $var;
                    $cppCode .= $this->getIndent() . Type::VAR . ' ' . $rawVar . ' = php::getCallArg(i);' . PHP_EOL;
                    $cppCode .= $this->genStrictScalarParamCheck($argInfo, $rawVar, $displayName, 'i + 1');
                    $cppCode .= $this->getIndent() . $var . '.appendValue('
                        . $this->convertExprFromType($argInfo->type, $rawVar) . ');' . PHP_EOL;
                } else {
                    $cppCode .= $this->getIndent() . $var . '.appendValue(php::getCallArg(i));' . PHP_EOL;
                }
                $this->indentLevel--;
                $cppCode .= $this->getIndent() . '}' . PHP_EOL;
                $cppCode .= $this->genExtraNamedVariadicArgs($var);
            } else {
                if ($argInfo->hasDefaultValue()) {
                    $nativeName = str_starts_with($fn, self::PREFIX)
                        ? substr($fn, strlen(self::PREFIX))
                        : $fn;
                    $defaultExpr = $this->genDefaultArgumentExpr($nativeName, $k);
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ', ' . $defaultExpr . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ', ' . $defaultExpr . ')';
                    }
                } else {
                    if ($argInfo->byRef) {
                        $argExpr = 'php::getCallArgByRef(' . $k . ')';
                    } else {
                        $argExpr = 'php::getCallArg(' . $k . ')';
                    }
                }
                $cppType = $this->getDefaultArgumentType($argInfo);
                $declaredClass = $argInfo->declaredClass ?: $argInfo->class;
                if ($this->isStrictScalarType($argInfo->type)) {
                    $rawVar = 'raw_' . $var;
                    $cppCode .= $this->getIndent() . Type::VAR . ' ' . $rawVar . ' = ' . $argExpr . ';' . PHP_EOL;
                    $cppCode .= $this->genStrictScalarParamCheck(
                        $argInfo,
                        $rawVar,
                        $displayName,
                        (string) ($k + 1)
                    );
                    $expr = $this->convertExprFromType($argInfo->type, $rawVar);
                } elseif ($argInfo->type === Type::OBJECT && $declaredClass !== '') {
                    $expr = $this->convertObjectExpr($argExpr, $this->getClassEntryPtr($declaredClass));
                } else {
                    $expr = $this->convertExprFromType($argInfo->type, $argExpr);
                }
                $cppCode .= $this->getIndent() . $cppType . ' ' . $var . ' = ' . $expr . ';' . PHP_EOL;
            }
            $callParam = $var;
            if ($this->canConsumeForwardedArgument($argInfo)) {
                $callParam = 'php::takeValue(' . $var . ')';
            }
            $callParams .= $callParam . ',';
        }

        if ($functionDef->method) {
            $methodArgs = implode(', ', array_merge(['this_'], $implicitMethodArgs));
            $callParams = $functionDef->argInfoList ? $methodArgs . ', ' . rtrim($callParams, ',') : $methodArgs;
        } else {
            $isEntryFunction = $this->hasFunction(self::ENTRY_FUNCTION)
                && $functionDef === $this->getFunction(self::ENTRY_FUNCTION);
            if ($this->isBuildModeBin() && $isEntryFunction) {
                // $_SERVER initialization must live inside the main entry function so
                // the runtime environment and superglobal context are fully ready
                // before it is accessed.
                $cppCode .= $this->registerServerEnvironment($functionDef->sourceFile);
            }
            $callParams = $functionDef->argInfoList ? rtrim($callParams, ',') : '';
        }

        if ($functionDef->returnType !== Type::VOID) {
            $cppCode .= $this->getIndent() . 'auto retval = ' . $fn . '(' . $callParams . ');' . PHP_EOL;
            $cppCode .= $this->getIndent() . 'php::move(retval, return_value);' . PHP_EOL;
            if (!$functionDef->returnsByRef) {
                $cppCode .= $this->getIndent() . 'php::deref(return_value);' . PHP_EOL;
            }
        } else {
            $cppCode .= $this->getIndent() . $fn . '(' . $callParams . ');' . PHP_EOL;
        }
        $this->indentLevel--;
        $cppCode .= $this->getIndent() . '} catch (zend_object *) {' . PHP_EOL;
        $this->indentLevel++;
        $cppCode .= $this->getIndent() . '/* EG(exception) is already set; return control to ZendVM for frame cleanup. */' . PHP_EOL;
        $this->indentLevel--;
        $cppCode .= $this->getIndent() . '}' . PHP_EOL;
        $this->indentLevel--;
        $cppCode .= $this->getIndent() . '}' . PHP_EOL . PHP_EOL;

        return $cppCode;
    }

    private function registerServerEnvironment(string $entryFile): string
    {
        /**
         * For long-running (resident) applications, control enters a long-lived
         * event loop immediately after the current logic finishes. These
         * variables are therefore only temporary and should be destroyed as soon
         * as they are used, rather than held for the long term.
         */
        $indent = $this->getIndent();
        $cppCode = $indent . "const char *value = " . $this->genCharPtr($entryFile, true) . ';' . PHP_EOL;
        $cppCode .= $indent . 'php::Var &_SERVER = ' . $this->escapeGlobalVar('_SERVER') . ';' . PHP_EOL;
        $cppCode .= $indent . '_SERVER.item("PHP_SELF", true) = value;'. PHP_EOL;
        $cppCode .= $indent . '_SERVER.item("SCRIPT_NAME", true) = value;'. PHP_EOL;
        $cppCode .= $indent . '_SERVER.item("SCRIPT_FILENAME", true) = value;'. PHP_EOL;
        $cppCode .= $indent . '_SERVER.item("PATH_TRANSLATED", true) = value;'. PHP_EOL;
        $cppCode .= $indent . '_SERVER.item("DOCUMENT_ROOT", true) = "";' . PHP_EOL;

        return $cppCode . PHP_EOL;
    }

    private function canConsumeForwardedArgument(ArgInfo $argInfo): bool
    {
        if ($argInfo->byRef) {
            return false;
        }
        if ($argInfo->variadic) {
            return true;
        }

        return in_array($this->getDefaultArgumentType($argInfo), [
            Type::VAR,
            Type::STR,
            Type::ARRAY,
            Type::OBJECT,
        ], true);
    }

    protected function genMethodWrapper(ClassDef $classDef, MethodDef $methodDef): string
    {
        $name = $classDef->getNamespacedName();
        $cppCode = 'ZEND_METHOD(' . $name . ', ' . $methodDef->name . ') {' . PHP_EOL;
        $this->indentLevel++;
        $cppCode .= $this->getIndent() . Type::OBJECT . ' this_(&execute_data->This);' . PHP_EOL;
        $this->indentLevel--;
        $fn = self::PREFIX . $this->getNativeMethodName($classDef, $methodDef);
        $cppCode .= $this->genWrapperFunctionArgs(
            $fn,
            $methodDef->functionDef,
            $classDef->getNamespacedName(false) . '::' . $methodDef->name,
        );

        return $cppCode;
    }

    protected function genClassWrapper(ClassDef|InterfaceDef $classDef): string
    {
        $cppCode = '';

        // Interfaces have no method bodies
        if ($classDef instanceof ClassDef && $classDef->trait === null) {
            if ($classDef->nativeObject) {
                return '';
            }
            $defaultPropCount = 0;
            foreach ($classDef->properties as $property) {
                if (!$property->isStatic() && $property->requiresRuntimeDefaultInit) {
                    $property->runtimeDefaultOffset = $this->getPropertyOffset(
                        $classDef->getNamespacedName(false),
                        $property->name,
                    );
                    $defaultPropCount++;
                }
            }
            if ($defaultPropCount > 0) {
                $classDef->requireCtor = true;
            }
            $methods = $classDef->methods;
            foreach ($methods as $methodDef) {
                if ($this->functionUsesNativeObject($methodDef->functionDef)) {
                    continue;
                }
                $cppCode .= $this->genMethodWrapper($classDef, $methodDef);
            }
        }

        return $cppCode;
    }

    /**
     * Build type-check descriptor array from UnionType or NullableType AST node.
     * Returns ['check' => array, 'typeStr' => string] or empty check array if no check needed.
     */
    /**
     * Generate a C++ boolean expression for a single type descriptor entry.
     */
    /**
     * Generate C++ runtime type-check block for a function parameter with union/nullable type.
     */
    /**
     * Generate C++ runtime type-check block for a function return value with union/nullable type.
     */
    /**
     * @throws \Exception
     */
    protected function parseFunction(Node\Stmt\Function_|Node\Stmt\ClassMethod $v): string
    {
        $this->resetFunction();
        $name = $this->getFunctionName($v);
        $this->function = $this->parseIdentifier($v->name);

        if (!$this->hasFunction($name)) {
            $this->fatalError($v, 'Function `' . $name . '` not found');
        }
        $this->functionDef = $this->getFunction($name);

        // Class methods are not stored in `functions`
        if ($this->methodDef) {
            $this->methodDef->functionDef = $this->functionDef;
        } else {
            $this->functionDefineInFile[$name] = $this->functionDef;
        }

        // Stub functions have no concrete implementation, only a declaration;
        // the implementation is defined in C++ or a .so file.
        if ($this->functionDef->stub) {
            $this->resetFunction();
            return '';
        }

        if ($this->class) {
            if ($this->classDef->nativeObject) {
                $this->addArgument('this_', $this->getNativeObjectCppName($this->classDef) . ' &');
                $this->addNativeObject('this_', $this->classDef->getNamespacedName(false));
            } else {
                $this->addArgument('this_', Type::OBJECT);
            }
        }
        foreach ($this->functionDef->argInfoList as $argInfo) {
            $argumentType = $argInfo->variadic
                ? Type::ARRAY
                : ($this->getNativeObjectArgumentType($argInfo) ?? $argInfo->type);
            $this->addArgument($argInfo->name, $argumentType);
            if (!$argInfo->variadic and $argInfo->declaredClass) {
                $this->addObject($argInfo->name, $argInfo->declaredClass);
            }
            $argumentClass = $argInfo->declaredClass ?: $argInfo->class;
            if (!$argInfo->nullable && $this->isNativeObjectClass($argumentClass)) {
                // genNativeObjectParameterChecks() establishes this invariant
                // once at function entry. Rebinding the local pointer later
                // invalidates it conservatively in parseAssign()/parseUnset().
                $this->markNativeObjectNonNull($argInfo->name);
            }
        }
        $this->initializeImmutableFunctionContext();

        if ($this->functionDef->generator) {
            try {
                return $this->genFiberGeneratorFunction($v, $this->functionDef, $name);
            } finally {
                $this->resetFunction();
            }
        }

        // Build SSA/e-SSA analysis for this function
        if ($v->stmts) {
            $oriLocalVars = $this->context->localVars;
            $oriTmpVarIndex = $this->context->tmpVarIndex;
            $oriDeclaredObjects = $this->context->declaredObjects;
            $oriNativeObjects = $this->context->nativeObjects;
            $oriNonNullNativeObjects = $this->context->nonNullNativeObjects;
            /** SSA/e-SSA analysis for the current function. Built once per function, discarded with the context. */
            $ssaBuilder = new SsaBuilder($v->stmts, $this->functionDef->argInfoList);
            $ssaBuilder->build();
            $this->context->ssaBuilder = $ssaBuilder;
            $this->analyzeStableObjects($ssaBuilder);
            // Range-proven loop counters are safe to narrow even without
            // `use native_types`: the optimizer rejects counters whose PHP
            // integer semantics could widen to float or otherwise escape.
            $optimizedLoopVars = $this->optimizeLoopVars($ssaBuilder);
            if ($this->nativeTypes) {
                // Narrow local variable types based on SSA analysis.
                $this->optimizeVarTypes($ssaBuilder);
                // Narrow native property accesses.
                $this->optimizeObjectProps($ssaBuilder);
            }
            $this->context->resetAnalysisTemporaries(
                $oriLocalVars,
                $oriTmpVarIndex,
                $oriDeclaredObjects,
                $oriNativeObjects,
                $oriNonNullNativeObjects,
            );
            foreach ($optimizedLoopVars as $varName => $type) {
                $this->context->localVars[$varName] = $type;
            }
        }

        $stmts = '';
        $this->indentLevel++;
        try {
            if ($v->stmts) {
                $stmts = $this->parseStmts($v->stmts);
            }
            if (!$this->isReturnStmtInLastLine($v->stmts ?? [])) {
                $returnCode = $this->genReturnCode();
                if ($returnCode !== '') {
                    $stmts .= rtrim($returnCode, "\r\n") . PHP_EOL;
                }
            }
        } catch (Skip) {
            $this->climate->cyan('Skip function ' . $name);
        }
        $this->indentLevel--;

        $multiReturn = $this->functionDef->hasMultiReturn();
        $cppReturnType = $multiReturn
            ? $this->functionDef->getMultiReturnCppType()
            : ($this->functionDef->returnsByRef
                ? Type::REF
                : ($this->getNativeObjectReturnType($this->functionDef) ?? $this->getReturnType()));
        $nativeName = self::PREFIX . $name;
        $functionAttribute = $this->getFunctionOptimizationAttribute($this->functionDef);
        $functionDeclCode = $functionAttribute . $cppReturnType . ' ' . ($multiReturn ? $this->getMultiReturnImplName($name) : $nativeName) . '(';
        if ($this->class) {
            $functionDeclCode .= ($this->getNativeObjectMethodThisType($this->functionDef)
                ?? (Type::OBJECT . ' &')) . 'this_';
            if ($this->functionDef->params) {
                $functionDeclCode .= ', ';
            }
        }
        // Rebuild parameter declarations from ArgInfo at code-generation time.
        // Native classes may be discovered after an earlier declaration was
        // normalized; the final ABI must use the precise native pointer type,
        // not a stale php::Object spelling cached during preprocessing.
        $functionDeclCode .= $this->getNativeMethodParameterDeclarations($this->functionDef) . ')';

        $code = $functionDeclCode . ' {' . PHP_EOL;
        $this->indentLevel++;
        $preamble = $this->genScopeVarDecl();
        $preamble .= $this->genNativeObjectParameterChecks($this->functionDef);
        // Runtime union/nullable parameter type checks
        foreach ($this->functionDef->argInfoList as $i => $argInfo) {
            if (!empty($argInfo->typeCheck)) {
                $preamble .= $this->genUnionParamCheck($argInfo, $i);
            }
        }
        // Constructor Property Promotion happens after parameter type validation.
        foreach ($this->functionDef->argInfoList as $argInfo) {
            if (!$argInfo->property) {
                continue;
            }
            $preamble .= $this->genPropertyPromotion($argInfo);
        }
        if ($preamble !== '') {
            $code .= $preamble . PHP_EOL;
        }
        $this->indentLevel--;
        // Build the PHP-level function name for debug backtraces
        if ($this->class) {
            $debugName = $this->class . '::' . $this->function;
        } else {
            $debugName = $this->function;
            if ($this->namespace) {
                $debugName = $this->namespace . '\\' . $debugName;
            }
        }
        $code .= $this->genDebugInfo(null, $debugName, $v->getStartLine());

        // Argument unpacking can hide a callback position from the compiler.
        // Only that fallback exposes the called class through the user frame;
        // ordinary callback resolution uses the explicit CallableScope above.
        if ($this->context->needsUserCodeCallableScope) {
            $code .= $this->genUserCodeCallableScopeGuard();
        }

        $code .= $stmts;
        $code .= "}\n";

        if ($multiReturn) {
            $forwardArgs = implode(', ', array_map(
                fn($argInfo) => $this->canConsumeForwardedArgument($argInfo)
                    ? 'php::takeValue(' . $argInfo->name . ')'
                    : $argInfo->name,
                $this->functionDef->argInfoList,
            ));
            $code .= $functionAttribute . Type::ARRAY . ' ' . $nativeName . '(' . $this->functionDef->params . ') {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->getIndent() . 'return ' . Type::ARRAY . '(' . $this->getMultiReturnImplName($name) . '(' . $forwardArgs . '));' . PHP_EOL;
            $this->indentLevel--;
            $code .= '}' . PHP_EOL;
        }

        $this->resetFunction();

        return $code;
    }

    /**
     * Check whether a parent method can be overridden: private methods cannot be
     * overridden, and the signature must be compatible.
     */
    protected function checkParentMethodCanBeOverridden(Node\Stmt\ClassMethod $v, string $name): void
    {
        if ($name === '__construct') {
            return;
        }

        $classDef = $this->classDef;
        $childFuncDef = $this->methodDef->functionDef;
        while (true) {
            $extends = $classDef->extends;
            if (!$extends) {
                break;
            }
            // The parent class is a built-in class
            if ($classDef->inheritedFromInternalClass) {
                $modifiers = Reflection::getClassMethodModifiers($extends, $name);
                if ($modifiers & \ReflectionMethod::IS_PRIVATE) {
                    goto _error;
                }
                if ($modifiers & \ReflectionMethod::IS_FINAL) {
                    goto _final_error;
                }
                break;
            }
            $classDef = $this->getClass($extends);
            if ($classDef->hasMethod($name)) {
                $methodDef = $classDef->getMethod($name);
                if ($methodDef->flags & Modifiers::PRIVATE) {
                    _error:
                    $message = 'Cannot override private method `' . $extends . '::' . $name . '()`';
                    $this->fatalGeneratedMethodAttributeIfAny($v, $message, $extends, $name);
                    $this->fatalError($v,
                        $message);
                }
                if ($methodDef->flags & Modifiers::FINAL) {
                    _final_error:
                    $hook = $v->getAttribute(PropertyHookLowering::METHOD_ATTRIBUTE);
                    if (is_array($hook)
                        && isset($hook['property'], $hook['kind'])
                        && is_string($hook['property'])
                        && is_string($hook['kind'])
                    ) {
                        $message = 'Cannot override final property hook '
                            . $extends . '::$' . $hook['property'] . '::' . $hook['kind'] . '()';
                    } else {
                        $message = 'Cannot override final method `' . $extends . '::' . $name . '()`';
                    }
                    $this->fatalGeneratedMethodAttributeIfAny($v, $message, $extends, $name);
                    $this->fatalError($v,
                        $message);
                }
                $this->validateMethodOverrideSignature($v, $name, $this->methodDef, $methodDef, $extends);
                break;
            }
            if ($classDef->hasAbstractMethod($name) && isset($classDef->abstractMethodDefs[strtolower($name)])) {
                $this->validateMethodOverrideSignature($v, $name, $this->methodDef, $classDef->getAbstractMethod($name), $extends);
                break;
            }
        }
    }

    protected function validateMethodOverrideSignature(
        NodeAbstract $v,
        string $methodName,
        MethodDef $childMethodDef,
        MethodDef $parentMethodDef,
        string $parentClass
    ): void {
        $className = $this->getFullClassName();

        // PHP allows widening visibility in overrides (e.g. protected -> public),
        // but forbids narrowing it.
        if ($this->getVisibilityRank($childMethodDef->flags) < $this->getVisibilityRank($parentMethodDef->flags)) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        if (($childMethodDef->flags & Modifiers::STATIC) !== ($parentMethodDef->flags & Modifiers::STATIC)) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        $childFuncDef = $childMethodDef->functionDef;
        $parentFuncDef = $parentMethodDef->functionDef;
        if (!$childFuncDef || !$parentFuncDef) {
            return;
        }

        // MustUse is part of the callable contract. An override may strengthen
        // this guarantee, but it must not drop one promised by a parent class
        // or interface.
        if ($parentFuncDef->mustUse && !$childFuncDef->mustUse) {
            $message = "Declaration of `{$className}::{$methodName}()` must be compatible with " .
                "`{$parentClass}::{$methodName}()`";
            $this->error(CompileTimeAttributeDiagnostic::formatPositions(
                $message,
                'MustUse',
                "method {$parentClass}::{$methodName}()",
                $parentFuncDef->sourceFile,
                $parentFuncDef->startLine,
                'override drops MustUse contract',
                $this->file,
                $v->getStartLine(),
            ));
        }

        // Immutable is an effect contract. Code compiled against the parent
        // may pass a read-only object or call the method through a read-only
        // receiver, so an override must not silently regain write access.
        if ($parentFuncDef->immutable && !$childFuncDef->immutable) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        if (!$this->isReturnTypeOverrideCompatible(
            $childFuncDef,
            $parentFuncDef,
            $className,
            $parentClass,
        )) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }
        // Zend treats by-ref returns as covariant: an override may add `&`
        // (callers expecting a value still work), but it must not drop one
        // promised by the parent contract.
        if ($parentFuncDef->returnsByRef && !$childFuncDef->returnsByRef) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        // Child methods may add optional trailing parameters, but they cannot
        // require more arguments than the parent contract.
        if ($childFuncDef->argCountRequired > $parentFuncDef->argCountRequired) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        // A variadic parent accepts unbounded arguments, so Zend requires the
        // override to be variadic as well.
        $parentVariadic = $parentFuncDef->hasVariadicArg();
        $childVariadic = $childFuncDef->hasVariadicArg();
        if ($parentVariadic && !$childVariadic) {
            $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
        }

        // Compare each parent-declared parameter position. Following Zend's
        // inheritance check, a trailing child variadic stands in for every
        // remaining parent position (the decorator pattern), and when the
        // parent is variadic each extra child parameter is validated against
        // the parent's variadic slot.
        $positions = count($parentFuncDef->argInfoList);
        if ($parentVariadic) {
            $positions = max($positions, count($childFuncDef->argInfoList));
        }
        for ($i = 0; $i < $positions; $i++) {
            $parentArg = $parentFuncDef->argInfoList[$i]
                ?? $parentFuncDef->argInfoList[count($parentFuncDef->argInfoList) - 1];
            $childArg = $childFuncDef->argInfoList[$i] ?? null;
            if ($childArg === null) {
                if (!$childVariadic) {
                    $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
                }
                $childArg = $childFuncDef->argInfoList[count($childFuncDef->argInfoList) - 1];
            }
            if ($parentArg->immutable && !$childArg->immutable) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
            if (!$this->isParameterTypeOverrideCompatible($childArg, $parentArg)) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
            if ($childArg->byRef !== $parentArg->byRef) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
        }

        // Any extra child parameters must be optional or variadic.
        for ($i = count($parentFuncDef->argInfoList); $i < count($childFuncDef->argInfoList); $i++) {
            $childArg = $childFuncDef->argInfoList[$i];
            if (!$childArg->variadic && $childArg->defaultValue === null) {
                $this->fatalMethodOverrideIncompatible($v, $className, $methodName, $parentClass);
            }
        }

        if ($this->classDef?->nativeObject
            && $this->hasClass($parentClass)
            && $this->getClass($parentClass)->nativeObject
        ) {
            $this->assertNativeVirtualByRefStorageCompatible($v, $childFuncDef, $parentFuncDef);
        }
    }

    private function fatalMethodOverrideIncompatible(
        NodeAbstract $v,
        string $className,
        string $methodName,
        string $parentClass
    ): void {
        $message = "Declaration of `{$className}::{$methodName}()` must be compatible " .
            "with `{$parentClass}::{$methodName}()`";
        $this->fatalGeneratedMethodAttributeIfAny($v, $message, $parentClass, $methodName);
        $this->fatalError($v, $message);
    }

    private function fatalGeneratedMethodAttributeIfAny(
        NodeAbstract $method,
        string $message,
        string $parentClass,
        string $methodName,
    ): void {
        $attribute = $method->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_BY);
        $target = $method->getAttribute(CompileTimeAttributeDiagnostic::GENERATED_TARGET);
        if (!is_string($attribute) || !$target instanceof Node) {
            return;
        }

        $parentFunction = null;
        $parentDef = $this->getClassDef($parentClass);
        if ($parentDef instanceof ClassDef) {
            if ($parentDef->hasMethod($methodName)) {
                $parentFunction = $parentDef->getMethod($methodName)->functionDef;
            } elseif ($parentDef->hasAbstractMethod($methodName)
                && isset($parentDef->abstractMethodDefs[strtolower($methodName)])) {
                $parentFunction = $parentDef->getAbstractMethod($methodName)->functionDef;
            }
        }
        $this->error(CompileTimeAttributeDiagnostic::formatPositions(
            $message,
            $attribute,
            CompileTimeAttributeDiagnostic::describeTarget($target),
            $this->file,
            $target->getStartLine(),
            $parentFunction === null ? null : 'parent method',
            $parentFunction?->sourceFile,
            $parentFunction?->startLine,
        ));
    }

    private function isReturnTypeOverrideCompatible(
        FunctionDef $childFuncDef,
        FunctionDef $parentFuncDef,
        string $childClass,
        string $parentClass,
    ): bool {
        if ($parentFuncDef->returnTypeUndeclared) {
            return true;
        }
        if ($childFuncDef->returnTypeUndeclared) {
            return false;
        }

        $parentTypes = $this->getReturnAcceptedTypes($parentFuncDef, $parentClass);
        $childTypes = $this->getReturnAcceptedTypes($childFuncDef, $childClass);

        // Type checks are stored in disjunctive normal form: the outer list is
        // a union, while an allOf entry is an intersection. Every child union
        // branch must imply at least one complete parent branch.
        foreach ($childTypes as $childType) {
            if (!$this->isReturnTypeCoveredBy($childType, $parentTypes)) {
                return false;
            }
        }
        return true;
    }

    private function getReturnAcceptedTypes(FunctionDef $functionDef, string $declaringClass): array
    {
        $returnTypeCheck = $functionDef->generator
            ? $functionDef->declaredReturnTypeCheck
            : $functionDef->returnTypeCheck;
        $returnType = $functionDef->generator
            ? $functionDef->declaredReturnType
            : $functionDef->returnType;
        $returnClass = $functionDef->generator
            ? $functionDef->declaredReturnClass
            : $functionDef->returnClass;
        $returnTypeStr = $functionDef->generator
            ? $functionDef->declaredReturnTypeStr
            : $functionDef->returnTypeStr;

        if (!empty($returnTypeCheck)) {
            return array_map(
                fn (array $type): array => $this->normalizeReturnTypeEntry($type, $declaringClass),
                $returnTypeCheck,
            );
        }

        if ($functionDef->returnTypeKeyword === 'static') {
            return [['kind' => 'isStatic', 'class' => $declaringClass]];
        }
        if ($returnType === Type::OBJECT && $returnClass !== '') {
            return [['kind' => 'instanceof', 'class' => $returnClass]];
        }

        $declaredType = strtolower($returnTypeStr);
        return match ($declaredType) {
            'mixed' => [['kind' => 'isMixed']],
            'never' => [['kind' => 'isNever']],
            'void' => [['kind' => 'isVoid']],
            'null' => [['kind' => 'isNull']],
            'true' => [['kind' => 'isTrue']],
            'false' => [['kind' => 'isFalse']],
            'callable' => [['kind' => 'callable']],
            'iterable' => [['kind' => 'iterable']],
            'object' => [['kind' => 'isObject']],
            default => match ($returnType) {
                Type::INT => [['kind' => 'isInt']],
                Type::FLOAT => [['kind' => 'isFloat']],
                Type::BOOL => [['kind' => 'isBool']],
                Type::STR => [['kind' => 'isString']],
                Type::ARRAY => [['kind' => 'isArray']],
                Type::RESOURCE => [['kind' => 'isResource']],
                Type::OBJECT => [['kind' => 'isObject']],
                default => [['kind' => 'isMixed']],
            },
        };
    }

    private function normalizeReturnTypeEntry(array $type, string $declaringClass): array
    {
        if (($type['kind'] ?? null) === 'allOf') {
            $type['types'] = array_map(
                fn (array $member): array => $this->normalizeReturnTypeEntry($member, $declaringClass),
                $type['types'],
            );
        } elseif (($type['kind'] ?? null) === 'instanceof' && ($type['class'] ?? null) === 'static') {
            $type = ['kind' => 'isStatic', 'class' => $declaringClass];
        }
        return $type;
    }

    private function isReturnTypeCoveredBy(array $childType, array $parentTypes): bool
    {
        $childClause = ($childType['kind'] ?? null) === 'allOf'
            ? $childType['types']
            : [$childType];

        foreach ($parentTypes as $parentType) {
            $parentClause = ($parentType['kind'] ?? null) === 'allOf'
                ? $parentType['types']
                : [$parentType];
            if ($this->isReturnTypeClauseSubtype($childClause, $parentClause)) {
                return true;
            }
        }
        return false;
    }

    private function isReturnTypeClauseSubtype(array $childClause, array $parentClause): bool
    {
        foreach ($parentClause as $parentType) {
            $covered = false;
            foreach ($childClause as $childType) {
                if ($this->isReturnTypeEntryCompatible($childType, $parentType)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }
        return true;
    }

    private function isReturnTypeEntryCompatible(array $childType, array $parentType): bool
    {
        $childKind = $childType['kind'] ?? null;
        $parentKind = $parentType['kind'] ?? null;

        if ($childKind === 'isNever' || $parentKind === 'isMixed') {
            return true;
        }
        if (($childKind === 'isTrue' || $childKind === 'isFalse') && $parentKind === 'isBool') {
            return true;
        }
        if ($childKind === 'isArray' && $parentKind === 'iterable') {
            return true;
        }
        if ($childKind === 'isStatic') {
            if ($parentKind === 'isObject' || $parentKind === 'isStatic') {
                return true;
            }
            if ($parentKind === 'instanceof') {
                return $this->isInheritedFrom(
                    $childType['class'] ?? '',
                    $parentType['class'] ?? '',
                );
            }
            return false;
        }
        if ($childKind === 'instanceof') {
            if ($parentKind === 'isObject') {
                return true;
            }
            $childClass = $childType['class'] ?? '';
            if ($parentKind === 'iterable') {
                return $childClass !== '' && $this->isInheritedFrom($childClass, 'Traversable');
            }
            if ($parentKind === 'instanceof') {
                $parentClass = $parentType['class'] ?? '';
                return $childClass !== ''
                    && $parentClass !== ''
                    && $this->isInheritedFrom($childClass, $parentClass);
            }
            return false;
        }
        return $childKind !== null && $childKind === $parentKind;
    }

    private function isParameterTypeOverrideCompatible(ArgInfo $childArg, ArgInfo $parentArg): bool
    {
        // Child methods may omit parameter types (contravariance — accepting a
        // wider set of inputs is always compatible with the parent contract).
        if ($this->isTopParameterType($childArg)) {
            return true;
        }
        if ($this->isTopParameterType($parentArg)) {
            return false;
        }

        $parentAcceptedTypes = $this->getParameterAcceptedTypes($parentArg);
        $childAcceptedTypes = $this->getParameterAcceptedTypes($childArg);
        if ($parentAcceptedTypes !== null || $childAcceptedTypes !== null) {
            if ($parentAcceptedTypes === null || $childAcceptedTypes === null) {
                return false;
            }
            return $this->isAcceptedTypeSubset($parentAcceptedTypes, $childAcceptedTypes);
        }

        if ($childArg->type !== $parentArg->type) {
            return false;
        }
        if ($parentArg->type !== Type::OBJECT) {
            return true;
        }
        if ($childArg->class === $parentArg->class) {
            return true;
        }
        if (!$childArg->class || !$parentArg->class) {
            return false;
        }
        return $this->isInheritedFrom($parentArg->class, $childArg->class);
    }

    private function isTopParameterType(ArgInfo $arg): bool
    {
        return $arg->undeclared || $arg->explicitMixed;
    }

    private function getParameterAcceptedTypes(ArgInfo $arg): ?array
    {
        if (!empty($arg->typeCheck)) {
            return $arg->typeCheck;
        }

        $declaredClass = $arg->declaredClass ?: $arg->class;
        return match ($arg->type) {
            Type::INT => [['kind' => 'isInt']],
            Type::FLOAT => [['kind' => 'isFloat']],
            Type::BOOL => [['kind' => 'isBool']],
            Type::STR => [['kind' => 'isString']],
            Type::ARRAY => [['kind' => 'isArray']],
            Type::RESOURCE => [['kind' => 'isResource']],
            Type::OBJECT => $declaredClass
                ? [['kind' => 'instanceof', 'class' => $declaredClass]]
                : [['kind' => 'isObject']],
            default => null,
        };
    }

    private function isAcceptedTypeSubset(array $parentTypes, array $childTypes): bool
    {
        foreach ($parentTypes as $parentType) {
            if (!$this->isAcceptedTypeCovered($parentType, $childTypes)) {
                return false;
            }
        }
        return true;
    }

    private function isAcceptedTypeCovered(array $parentType, array $childTypes): bool
    {
        // Parameter contravariance requires every value accepted by the parent
        // clause to also be accepted by at least one child clause. The same DNF
        // clause-subtyping relation used for covariant returns applies here,
        // with the parent clause as the narrower candidate.
        return $this->isReturnTypeCoveredBy($parentType, $childTypes);
    }

    private function checkInterfaceImplementations(Node\Stmt\Class_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
            $this->checkInterfaceImplementation($classStmt, $classDef, $interfaceName);
        }
    }

    private function validateOverrideAttributes(Node\Stmt\Class_|Node\Stmt\Enum_ $classStmt): void
    {
        $methods = [...$this->classDef->methods, ...$this->classDef->abstractMethodDefs];
        foreach ($methods as $methodDef) {
            if (!$methodDef->functionDef?->overrideRequired) {
                continue;
            }
            if ($this->hasMatchingOverrideDeclaration($this->classDef, $methodDef->name)) {
                continue;
            }
            $this->fatalMissingOverride(
                $methodDef->node ?? $classStmt,
                $this->classDef->getNamespacedName(false),
                $methodDef->name,
            );
        }
    }

    private function hasMatchingOverrideDeclaration(ClassDef $classDef, string $methodName): bool
    {
        if (strtolower($methodName) === '__construct') {
            return false;
        }

        $current = $classDef;
        while ($current->extends !== '') {
            $parentName = $current->extends;
            if ($current->inheritedFromInternalClass || $this->isInternalClass($parentName)) {
                $modifiers = Reflection::getClassMethodModifiers($parentName, $methodName);
                if ($modifiers !== null && !($modifiers & \ReflectionMethod::IS_PRIVATE)) {
                    return true;
                }
                break;
            }
            if (!$this->hasClass($parentName)) {
                break;
            }
            $current = $this->getClass($parentName);
            if ($current->hasMethod($methodName)) {
                if (!($current->getMethod($methodName)->flags & Modifiers::PRIVATE)) {
                    return true;
                }
            } elseif ($current->hasAbstractMethod($methodName)) {
                if (!($current->getMethodFlags($methodName) & Modifiers::PRIVATE)) {
                    return true;
                }
            }
        }

        foreach ($this->getClassImplementedInterfaces($classDef) as $interfaceName) {
            if ($this->isInternalInterface($interfaceName)) {
                if (Reflection::hasMethod($interfaceName, $methodName)) {
                    return true;
                }
                continue;
            }
            if ($this->hasInterface($interfaceName) && $this->getInterface($interfaceName)->hasMethod($methodName)) {
                return true;
            }
        }
        return false;
    }

    private function validateInterfaceOverrideAttributes(Node\Stmt\Interface_ $interfaceStmt): void
    {
        $name = $this->parseIdentifier($interfaceStmt->name);
        $interfaceName = $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
        if (!$this->hasInterface($interfaceName)) {
            return;
        }

        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->methods as $methodDef) {
            if (!$methodDef->functionDef?->overrideRequired) {
                continue;
            }
            $visited = [];
            if ($this->interfaceParentsHaveMethod($interfaceDef, $methodDef->name, $visited)) {
                continue;
            }
            $this->fatalMissingOverride($methodDef->node ?? $interfaceStmt, $interfaceName, $methodDef->name);
        }
    }

    private function fatalMissingOverride(NodeAbstract $node, string $className, string $methodName): never
    {
        $this->fatalCompileTimeAttribute(
            $node,
            'Override',
            "{$className}::{$methodName}() has #[\\Override] attribute, " .
            'but no matching parent method exists',
        );
    }

    /** @param array<string, true> $visited */
    private function interfaceParentsHaveMethod(
        InterfaceDef $interfaceDef,
        string $methodName,
        array &$visited,
    ): bool {
        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentName) {
            $key = strtolower($parentName);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            if ($this->isInternalInterface($parentName)) {
                if (Reflection::hasMethod($parentName, $methodName)) {
                    return true;
                }
                continue;
            }
            if (!$this->hasInterface($parentName)) {
                continue;
            }
            $parent = $this->getInterface($parentName);
            if ($parent->hasMethod($methodName)
                || $this->interfaceParentsHaveMethod($parent, $methodName, $visited)) {
                return true;
            }
        }
        return false;
    }

    private function checkInterfaceImplementation(NodeAbstract $node, ClassDef $classDef, string $interfaceName): void
    {
        if ($this->isInternalInterface($interfaceName)) {
            // Zend validates ordinary classes while registering their class
            // entries. Only Native classes need the compiler-side reflection
            // contract because they deliberately have no zend_class_entry.
            if ($classDef->nativeObject) {
                $this->checkInternalInterfaceImplementation($node, $classDef, $interfaceName);
            }
            return;
        }
        if (!$this->hasInterface($interfaceName)) {
            return;
        }

        $interfaceDef = $this->getInterface($interfaceName);
        foreach ($interfaceDef->methods as $methodName => $interfaceMethodDef) {
            $childMethodDef = $this->findClassMethodDef($classDef, $methodName, $classDef->isAbstract());
            if ($childMethodDef === null) {
                if ($classDef->isAbstract()) {
                    continue;
                }
                $this->fatalError($node, "Class `{$classDef->getNamespacedName(false)}` must implement method `{$interfaceName}::{$interfaceMethodDef->name}()`");
            }
            $this->validateMethodOverrideSignature(
                $node,
                $interfaceMethodDef->name,
                $childMethodDef,
                $interfaceMethodDef,
                $interfaceName
            );
        }

        foreach ($interfaceDef->properties as $property) {
            $this->checkInterfacePropertyImplementation($node, $classDef, $interfaceName, $property);
        }

        foreach ($interfaceDef->extendsList ?: ($interfaceDef->extends ? [$interfaceDef->extends] : []) as $parentInterface) {
            $this->checkInterfaceImplementation($node, $classDef, $parentInterface);
        }
    }

    private function checkInterfacePropertyImplementation(
        NodeAbstract $node,
        ClassDef $classDef,
        string $interfaceName,
        InterfacePropertyDef $contract,
    ): void {
        $property = $this->findClassPropertyDef($classDef, $contract->name);
        if ($property === null) {
            if ($classDef->isAbstract()) {
                return;
            }
            $this->fatalError(
                $node,
                "Class `{$classDef->getNamespacedName(false)}` must implement property " .
                "`{$interfaceName}::\${$contract->name}`",
            );
        }
        if (!$property->isPublic()) {
            $this->fatalError(
                $node,
                "Property `{$classDef->getNamespacedName(false)}::\${$contract->name}` must be public " .
                "to satisfy `{$interfaceName}::\${$contract->name}`",
            );
        }

        $readable = $property->getter !== null || !$property->virtual;
        $writable = $property->setter !== null
            || (!$property->virtual
                && !$property->isReadonly()
                && !$property->isPrivateSet()
                && !$property->isProtectedSet());
        if (($contract->readable && !$readable) || ($contract->writable && !$writable)) {
            $this->fatalError(
                $node,
                "Property `{$classDef->getNamespacedName(false)}::\${$contract->name}` does not satisfy " .
                "the required hooks of `{$interfaceName}::\${$contract->name}`",
            );
        }

        $implementationTypes = $this->getPropertyAcceptedTypes($property);
        $contractTypes = $this->getPropertyAcceptedTypes($contract);
        $compatible = match (true) {
            $contract->readable && !$contract->writable =>
                $this->isPropertyTypeSubset($implementationTypes, $contractTypes),
            $contract->writable && !$contract->readable =>
                $this->isPropertyTypeSubset($contractTypes, $implementationTypes),
            default =>
                $this->isPropertyTypeSubset($implementationTypes, $contractTypes)
                && $this->isPropertyTypeSubset($contractTypes, $implementationTypes),
        };
        if (!$compatible) {
            $this->fatalError(
                $node,
                "Property `{$classDef->getNamespacedName(false)}::\${$contract->name}` must be compatible " .
                "with `{$interfaceName}::\${$contract->name}`",
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getPropertyAcceptedTypes(PropertyDef|InterfacePropertyDef $property): array
    {
        if ($property->typeCheck !== []) {
            return $property->typeCheck;
        }
        return match ($property->type) {
            Type::INT => [['kind' => 'isInt']],
            Type::FLOAT => [['kind' => 'isFloat']],
            Type::BOOL => [['kind' => 'isBool']],
            Type::STR => [['kind' => 'isString']],
            Type::ARRAY => [['kind' => 'isArray']],
            Type::RESOURCE => [['kind' => 'isResource']],
            Type::OBJECT => $property->class !== ''
                ? [['kind' => 'instanceof', 'class' => $property->class]]
                : [['kind' => 'isObject']],
            default => [['kind' => 'isMixed']],
        };
    }

    private function isPropertyTypeSubset(array $candidateTypes, array $acceptedTypes): bool
    {
        foreach ($candidateTypes as $candidateType) {
            if (!$this->isReturnTypeCoveredBy($candidateType, $acceptedTypes)) {
                return false;
            }
        }
        return true;
    }

    private function findClassPropertyDef(ClassDef $classDef, string $propertyName): ?PropertyDef
    {
        $current = $classDef;
        while (true) {
            if ($current->hasProperty($propertyName)) {
                return $current->getProperty($propertyName);
            }
            if (!$current->extends || !$this->hasClass($current->extends)) {
                return null;
            }
            $current = $this->getClass($current->extends);
        }
    }

    protected function findClassMethodDef(ClassDef $classDef, string $methodName, bool $includeAbstract = true): ?MethodDef
    {
        $current = $classDef;
        while (true) {
            if ($current->hasMethod($methodName)) {
                return $current->getMethod($methodName);
            }
            if ($includeAbstract && $current->hasAbstractMethod($methodName) && isset($current->abstractMethodDefs[strtolower($methodName)])) {
                return $current->getAbstractMethod($methodName);
            }
            if (!$current->extends || !$this->hasClass($current->extends)) {
                return null;
            }
            $current = $this->getClass($current->extends);
        }
    }

    private function checkInheritedAbstractMethodsAreImplemented(NodeAbstract $node): void
    {
        $classDef = $this->classDef;
        if ($classDef->isAbstract()) {
            return;
        }

        $current = $classDef;
        while ($current->extends && $this->hasClass($current->extends)) {
            $parent = $this->getClass($current->extends);
            foreach ($parent->abstractMethodDefs as $methodName => $abstractMethodDef) {
                if ($this->findClassMethodDef($classDef, $methodName, false) === null) {
                    $this->fatalError(
                        $node,
                        "Class `{$classDef->getNamespacedName(false)}` must implement abstract method `{$parent->getNamespacedName(false)}::{$abstractMethodDef->name}()`"
                    );
                }
            }
            $current = $parent;
        }
    }

    private function getVisibilityRank(int $flags): int
    {
        if ($flags & Modifiers::PUBLIC) {
            return 3;
        }
        if ($flags & Modifiers::PROTECTED) {
            return 2;
        }
        if ($flags & Modifiers::PRIVATE) {
            return 1;
        }
        return 3;
    }

    private function checkPropertyOverride(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        $className = $this->getFullClassName();
        $matchedOverrides = [];
        $chainNode = $classDef;
        while ($chainNode->extends && !$chainNode->inheritedFromInternalClass) {
            $parentClass = $chainNode->extends;
            $chainNode = $this->getClass($parentClass);
            if (!$chainNode) {
                break;
            }
            foreach ($this->classDef->properties as $name => $childProp) {
                if ($chainNode->hasProperty($name)) {
                    $parentProp = $chainNode->getProperty($name);
                    // TypePHP deliberately forbids the two independent slots
                    // PHP would create when a child hides a parent private
                    // property. This applies equally to Zend-backed and Native
                    // classes, even though Native storage could represent it.
                    if ($parentProp->flags & Modifiers::PRIVATE) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` conflicts with private property " .
                            "`{$parentClass}::\${$name}`; property shadowing across inheritance is not allowed");
                    }
                    $matchedOverrides[$name] = true;
                    // A static property and an instance property are different
                    // kinds of storage; Zend forbids redeclaring one as the
                    // other in either direction.
                    if (($childProp->flags & Modifiers::STATIC) !== ($parentProp->flags & Modifiers::STATIC)) {
                        $this->fatalError($classStmt, ($parentProp->flags & Modifiers::STATIC)
                            ? "Cannot redeclare static `{$parentClass}::\${$name}` as non static `{$className}::\${$name}`"
                            : "Cannot redeclare non static `{$parentClass}::\${$name}` as static `{$className}::\${$name}`");
                    }
                    // PHP inherits get and set independently. A child may
                    // override only one hook, or redeclare the property
                    // without hooks while retaining both parent hooks.
                    $childProp->getter ??= $parentProp->getter;
                    $childProp->setter ??= $parentProp->setter;
                    // PHP 8.4 treats private(set) properties as implicitly
                    // final because a child cannot widen their write scope.
                    if ($parentProp->flags & (Modifiers::FINAL | Modifiers::PRIVATE_SET)) {
                        $this->fatalError(
                            $classStmt,
                            "Cannot override final property {$parentClass}::\${$name}"
                        );
                    }
                    if ($childProp->type !== $parentProp->type || $childProp->class !== $parentProp->class) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                    if ($this->getVisibilityRank($childProp->flags) < $this->getVisibilityRank($parentProp->flags)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                    if ($this->getPropertySetVisibilityRank($childProp) < $this->getPropertySetVisibilityRank($parentProp)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must not restrict set visibility " .
                            "of `{$parentClass}::\${$name}`");
                    }
                    if (($childProp->flags & Modifiers::READONLY) !== ($parentProp->flags & Modifiers::READONLY)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::\${$name}` must be compatible " .
                            "with `{$parentClass}::\${$name}`");
                    }
                }
            }
        }

        if ($classStmt instanceof Node\Stmt\Trait_) {
            // A trait property is validated after it is composed into the
            // consuming class, where the actual parent chain is known.
            return;
        }
        foreach ($classDef->properties as $name => $property) {
            if (!$property->overrideRequired || isset($matchedOverrides[$name])) {
                continue;
            }
            $this->fatalCompileTimeAttribute(
                $property->node ?? $classStmt,
                'Override',
                "{$className}::\${$name} has #[\\Override] attribute, "
                    . 'but no matching parent class property exists',
            );
        }
    }

    private function getPropertySetVisibilityRank(PropertyDef $property): int
    {
        if ($property->isPrivateSet()) {
            return 1;
        }
        if ($property->isProtectedSet()) {
            return 2;
        }
        return $this->getVisibilityRank($property->flags);
    }

    private function checkConstantOverride(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_ $classStmt): void
    {
        $classDef = $this->classDef;
        $className = $this->getFullClassName();
        $chainNode = $classDef;
        while ($chainNode->extends && !$chainNode->inheritedFromInternalClass) {
            $parentClass = $chainNode->extends;
            $chainNode = $this->getClass($parentClass);
            if (!$chainNode) {
                break;
            }
            foreach ($this->classDef->constants as $name => $childConst) {
                if ($chainNode->hasConstant($name)) {
                    $parentConst = $chainNode->getConstant($name);
                    if ($parentConst->flags & Modifiers::PRIVATE) {
                        continue;
                    }
                    if ($parentConst->flags & Modifiers::FINAL) {
                        $this->fatalError($classStmt,
                            "Cannot override final constant `{$parentClass}::{$name}`");
                    }
                    // PHP only enforces type compatibility when the parent constant
                    // carries an explicit declared type. Overriding an untyped constant
                    // with a value of any type is permitted, so the type check is skipped
                    // in that case. Visibility is always enforced below.
                    if ($parentConst->declaredType !== null) {
                        if ($childConst->declaredType === null) {
                            $this->fatalError($classStmt,
                                "Declaration of `{$className}::{$name}` must be compatible " .
                                "with `{$parentClass}::{$name}`");
                        }
                        // An untyped child constant whose value is an expression (e.g.
                        // `X = ParentClass::Y`) is inferred as a variant. Resolve its real
                        // type from the referenced constant so the compatibility check uses
                        // the actual value type.
                        $childType = $childConst->type;
                        if ($childType === Type::VAR
                            && $childConst->valueExpr instanceof Node\Expr\ClassConstFetch) {
                            $resolved = $this->resolveReferencedConstantType($childConst->valueExpr, $this->getFullClassName());
                            if ($resolved !== null) {
                                $childType = $resolved;
                            }
                        }
                        if ($childType !== $parentConst->type || $childConst->class !== $parentConst->class) {
                            $this->fatalError($classStmt,
                                "Declaration of `{$className}::{$name}` must be compatible " .
                                "with `{$parentClass}::{$name}`");
                        }
                    }
                    if ($this->getVisibilityRank($childConst->flags) < $this->getVisibilityRank($parentConst->flags)) {
                        $this->fatalError($classStmt,
                            "Declaration of `{$className}::{$name}` must be compatible " .
                            "with `{$parentClass}::{$name}`");
                    }
                }
            }
        }
    }

    protected function parseClassMethod(Node\Stmt\ClassMethod $v, array &$methodCodes): void
    {
        $name = $this->getMethodName($v);
        $this->method = $name;
        $flags = $this->parseModifiers($v->flags);

        if (!($flags & Modifiers::ABSTRACT)) {
            $this->methodDef = $this->classDef->getMethod($name);
            // Keep the AST node so trait-composed methods can report accurate
            // line numbers when validated for override compatibility later.
            $this->methodDef->node = $v;
            if ($this->classDef->trait === null
                && $this->methodDef->functionDef?->overrideRequired
                && !$this->hasMatchingOverrideDeclaration($this->classDef, $name)) {
                $this->fatalMissingOverride($v, $this->classDef->getNamespacedName(false), $name);
            }
            // The preprocess phase has no parent-class info, so the check can
            // only run in the implementation phase.
            $this->checkParentMethodCanBeOverridden($v, $name);
            $methodCodes[$name] = $this->parseFunction($v);
        }

        $this->resetMethod();
    }

    protected function parseTraitUse(Node\Stmt\TraitUse $v, array &$methodCodes): void
    {
        $classDef = $this->classDef;
        foreach ($v->traits as $trait) {
            $traitName = $this->parseIdentifier($trait);
            $traitFullName = $this->getNamespacedClassName($traitName);
            if (!$this->hasClass($traitFullName)) {
                $this->fatalError($v, $traitFullName . ' not found');
            }
            $traitDef = $this->getClass($traitFullName);
            foreach ($traitDef->constants as $const) {
                if ($classDef->hasConstant($const->name)) {
                    if (!$this->isCompatibleTraitConstant($classDef->getConstant($const->name), $const)) {
                        $this->fatalError($v, "Trait `{$traitFullName}` constant `{$const->name}` conflicts with class `{$classDef->getNamespacedName(false)}`");
                    }
                    continue;
                }
                $classDef->constants[$const->name] = $const;
            }
            foreach ($traitDef->properties as $prop) {
                if ($classDef->hasProperty($prop->name)) {
                    if (!$this->isCompatibleTraitProperty($classDef->getProperty($prop->name), $prop)) {
                        $this->fatalError($v, "Trait `{$traitFullName}` property `{$prop->name}` conflicts with class `{$classDef->getNamespacedName(false)}`");
                    }
                    continue;
                }
                $classDef->properties[$prop->name] = $prop;
            }
        }
    }

    private function installComposedTraitDataMembers(Node\Stmt\ClassLike $class): void
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    if (!$this->classDef->hasConstant($const->name->toString())) {
                        $this->parseClassConstDef($stmt);
                        break;
                    }
                }
            } elseif ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if (!$this->classDef->hasProperty($prop->name->toString())) {
                        $origin = $stmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE);
                        if (is_string($origin)) {
                            $this->withTraitNameContext($origin, function () use ($stmt): void {
                                $this->parseClassPropertyDef($stmt);
                            });
                        } else {
                            $this->parseClassPropertyDef($stmt);
                        }
                        break;
                    }
                }
            }
        }
    }

    private function installComposedTraitMethod(Node\Stmt\ClassMethod $methodStmt): void
    {
        $name = $methodStmt->name->toString();
        $this->assertNativeMagicMethodSupported($methodStmt, $name);
        if ($this->classDef->hasMethod($name)) {
            return;
        }

        $flags = $this->parseModifiers($methodStmt->flags);
        if ($this->classDef->nativeObject && ($flags & Modifiers::STATIC)) {
            $this->fatalError($methodStmt, 'Native class static methods are not supported');
        }
        $methodDef = new MethodDef($flags, $name);
        $methodDef->node = $methodStmt;
        $methodDef->traitOrigin = (string) $methodStmt->getAttribute(self::TRAIT_ORIGIN_ATTRIBUTE, '');

        $this->method = $name;
        $this->methodDef = $methodDef;
        if ($flags & Modifiers::ABSTRACT) {
            $methodDef->functionDef = $this->parseFunctionDecl($methodStmt);
            $methodDef->functionDef->method = true;
            $this->checkRequiredArgNum($name, $methodDef, $methodStmt);
            $this->classDef->addAbstractMethod($name, $flags, $methodDef);
        } else {
            $this->prepareFunction($methodStmt);
            $this->checkRequiredArgNum($name, $methodDef, $methodStmt);
            $this->classDef->addMethod($methodDef);
        }
        $this->resetMethod();
    }

    private function withTraitNameContext(string $traitName, callable $callback): mixed
    {
        if (!$this->hasClass($traitName)) {
            $this->error("Internal compiler error: trait `{$traitName}` is not available while composing AST");
        }
        $traitDef = $this->getClass($traitName);
        if ($traitDef->trait === null) {
            $this->error("Internal compiler error: `{$traitName}` is not a trait AST template");
        }

        $savedNamespace = $this->namespace;
        $savedUseNamespaces = $this->useNamespaces;
        $savedUseAliases = $this->useAliases;
        $savedUseFunctions = $this->useFunctions;
        $savedUseConstants = $this->useConstants;

        $this->namespace = $traitDef->namespace;
        $this->useNamespaces = $traitDef->traitUseNamespaces;
        $this->useAliases = $traitDef->traitUseAliases;
        $this->useFunctions = $traitDef->traitUseFunctions;
        $this->useConstants = $traitDef->traitUseConstants;
        try {
            return $callback();
        } finally {
            $this->namespace = $savedNamespace;
            $this->useNamespaces = $savedUseNamespaces;
            $this->useAliases = $savedUseAliases;
            $this->useFunctions = $savedUseFunctions;
            $this->useConstants = $savedUseConstants;
        }
    }

    private function isCompatibleTraitConstant(ConstantDef $existing, ConstantDef $incoming): bool
    {
        return $existing->flags === $incoming->flags
            && $existing->type === $incoming->type
            && $existing->class === $incoming->class
            && $existing->value === $incoming->value;
    }

    private function isCompatibleTraitProperty(PropertyDef $existing, PropertyDef $incoming): bool
    {
        return $existing->flags === $incoming->flags
            && $existing->type === $incoming->type
            && $existing->class === $incoming->class
            && $existing->nullable === $incoming->nullable
            && $existing->default === $incoming->default
            && $existing->arrayDef == $incoming->arrayDef;
    }

    private function resolveLateBoundClass(ClassDef $usingClassDef, string $keyword): ?string
    {
        if ($keyword === 'self') {
            return $usingClassDef->getNamespacedName(false);
        }
        if ($keyword === 'parent') {
            return $usingClassDef->extends !== '' ? $usingClassDef->extends : null;
        }
        // `static` is late-static-bound and resolved to the concrete class only at
        // call time, so it must keep an empty class (matching a directly-declared
        // `: static` method). Resolving it to the consuming class here would break
        // interface/trait signature-compatibility checks, which compare the empty
        // `static` class on both sides.
        return null;
    }

    private function getRegisterClassFunctionArgDef(ClassDef|InterfaceDef $classDef): string
    {
        $depsCeList = $this->getRegisterClassFunctionCeList($classDef);
        if (empty($depsCeList)) {
            return '';
        }

        return 'zend_class_entry *' . implode(', zend_class_entry *', $depsCeList);
    }

    private function getImplementCe(ClassDef $classDef): array
    {
        $list = [];
        foreach ($classDef->implements as $interface) {
            $list[] = self::PREFIX . 'class_entry_' . $this->escapeCeName($interface);
        }

        return $list;
    }

    private function genFunctionWrapper(FunctionDef $functionDef): string
    {
        $name = $this->escapeZendFnName($functionDef->getNamespacedName());
        $cppCode = 'ZEND_FUNCTION(' . $name . ') {' . PHP_EOL;
        $fn = self::PREFIX . $this->getNativeName($functionDef->name, $functionDef->namespace);
        $cppCode .= $this->genWrapperFunctionArgs($fn, $functionDef, $functionDef->getNamespacedName());

        return $cppCode;
    }

    /** Return the generated C++ symbol for a hidden runtime-attribute factory. */
    public function getRuntimeAttributeFactoryNativeName(string $fullName): string
    {
        $fullName = ltrim($fullName, '\\');
        $separator = strrpos($fullName, '\\');
        if ($separator === false) {
            return self::PREFIX . $this->getNativeName($fullName);
        }
        return self::PREFIX . $this->getNativeName(
            substr($fullName, $separator + 1),
            substr($fullName, 0, $separator),
        );
    }

}
