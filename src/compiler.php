<?php
use TypePhp\Translator;
use TypePhp\Build\WasiToolchain;
use TypePhp\Build\WasiProjectConfig;
use TypePhp\Build\PhpxLocator;
use TypePhp\PythonTools\Command as PythonToolsCommand;
use TypePhp\Cli\CompletionCommand;

function main(int $argc, array $argv): void
{
    // Compiling a complete project keeps the parsed AST and generated sources in
    // memory. The default CLI limit (commonly 128M) is too small for larger builds.
    ini_set('memory_limit', '-1');

    if (!defined('TYPEPHP_ROOT_PATH')) {
        define('TYPEPHP_ROOT_PATH', getenv("TYPEPHP_HOME") ?: getcwd());
    }
    if (!defined('TYPEPHP_DEBUG')) {
        define('TYPEPHP_DEBUG', true);
    }

    // The Zend PHP entrypoint loads the consumer project's Composer autoloader
    // in bin/bootstrap.php. The AOT compiler starts here directly and therefore
    // must load the dependencies packaged alongside tpc itself.
    if (!defined('TYPEPHP_PHP_SCRIPT_ENTRY')) {
        require_once TYPEPHP_ROOT_PATH . '/vendor/autoload.php';
    }

    $completionStatus = CompletionCommand::execute($argv);
    if ($completionStatus !== null) {
        if ($completionStatus !== 0) {
            exit($completionStatus);
        }
        return;
    }

    $pythonToolStatus = PythonToolsCommand::execute($argv);
    if ($pythonToolStatus !== null) {
        if ($pythonToolStatus !== 0) {
            exit($pythonToolStatus);
        }
        return;
    }

    if (getenv('TYPEPHP_WASM_INTERNAL_COMPILE') !== '1' && shouldCompileWasm($argv)) {
        compileWasmProgram($argv);
        return;
    }

    // .prof file analysis mode: ./tpc app.prof
    if ($argc >= 2 && str_ends_with($argv[1], '.prof')) {
        profileAnalyze($argc, $argv);
        return;
    }

    $translator = Translator::getInstance();
    $translator->setIndent('    ');
    // Scan all PHP files and preprocess them.
    $files = $translator->prepare($translator->parseArgv($argv));
    // Generate the C++ source files.
    $sourceFiles = $translator->convert($files);

    $wasmManifest = getenv('TYPEPHP_WASM_INTERFACE_MANIFEST');
    if (is_string($wasmManifest) && $wasmManifest !== '') {
        $wasmWit = getenv('TYPEPHP_WASM_INTERFACE_WIT');
        $wasmAdapter = getenv('TYPEPHP_WASM_INTERFACE_ADAPTER');
        $wasmAsyncExports = getenv('TYPEPHP_WASM_INTERFACE_ASYNC_EXPORTS');
        $wasmPackage = getenv('TYPEPHP_WASM_PACKAGE');
        $wasmWorld = getenv('TYPEPHP_WASM_WORLD');
        if (!is_string($wasmWit) || $wasmWit === ''
            || !is_string($wasmAdapter) || $wasmAdapter === ''
            || !is_string($wasmAsyncExports) || $wasmAsyncExports === ''
            || !is_string($wasmPackage) || $wasmPackage === ''
            || !is_string($wasmWorld) || $wasmWorld === '') {
            throw new RuntimeException('Incomplete internal WASM interface configuration');
        }
        $translator->writeWasmInterface(
            $wasmManifest,
            $wasmWit,
            $wasmAdapter,
            $wasmAsyncExports,
            $wasmPackage,
            $wasmWorld,
        );
        $sourceFiles[] = $wasmAdapter;
    }

    // --dry mode: only generate the C++ code, without compiling.
    if ($translator->isDryRun()) {
        $buildDir = $translator->getBuildDir();
        $count = count($sourceFiles);
        $sourceListFile = getenv('TYPEPHP_GENERATED_SOURCE_LIST');
        if (is_string($sourceListFile) && $sourceListFile !== '') {
            $sourceListDir = dirname($sourceListFile);
            if (!is_dir($sourceListDir) && !mkdir($sourceListDir, 0777, true) && !is_dir($sourceListDir)) {
                throw new RuntimeException("Unable to create generated source manifest directory: {$sourceListDir}");
            }
            if (file_put_contents($sourceListFile, implode(PHP_EOL, $sourceFiles) . PHP_EOL) === false) {
                throw new RuntimeException("Unable to write generated source manifest: {$sourceListFile}");
            }
        }
        $translator->output("Dry run completed: {$count} C++ source file(s) generated in {$buildDir}", 'lightBlue');
        return;
    }

    // Compile all C++ source files.
    $objectFiles = $translator->compile($sourceFiles);
    // Link all object files to produce the executable.
    $binaryFile = $translator->build($objectFiles);
    // If --run / -r was specified, execute immediately after compilation.
    if ($translator->isRunRequested()) {
        $translator->run($binaryFile); // never returns
    }
}

/**
 * Build a self-contained WASI 0.2 command component through the public CLI.
 * The lower-level build scripts are implementation details and are not part of
 * the user-facing workflow.
 */
function compileWasmProgram(array $argv): void
{
    $input = null;
    $buildDir = null;
    $profile = null;
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; $i++) {
        $argument = $arguments[$i];
        if ($argument === '--wasm') {
            continue;
        }
        if (str_starts_with($argument, '--wasm=')) {
            $value = substr($argument, strlen('--wasm='));
            if ($value === '') {
                fwrite(STDERR, "Option --wasm requires browser or component after `=`\n");
                exit(1);
            }
            if ($profile !== null && $profile !== $value) {
                fwrite(STDERR, "Option --wasm was specified with conflicting profiles\n");
                exit(1);
            }
            $profile = $value;
            continue;
        }
        if ($argument === '--build-dir') {
            if (!isset($arguments[$i + 1]) || $arguments[$i + 1] === '') {
                fwrite(STDERR, "Option --build-dir requires a directory\n");
                exit(1);
            }
            $buildDir = $arguments[++$i];
            continue;
        }
        if (str_starts_with($argument, '--build-dir=')) {
            $buildDir = substr($argument, strlen('--build-dir='));
            if ($buildDir === '') {
                fwrite(STDERR, "Option --build-dir requires a directory\n");
                exit(1);
            }
            continue;
        }
        if (str_starts_with($argument, '-')) {
            fwrite(STDERR, "Unsupported option in --wasm mode: {$argument}\n");
            exit(1);
        }
        if ($input !== null) {
            fwrite(STDERR, "The --wasm mode accepts exactly one PHP file or project.yml\n");
            exit(1);
        }
        $input = $argument;
    }

    if ($input === null) {
        fwrite(STDERR, "Usage: php bin/tpc.php <program.php|project.yml> [--wasm[=browser|component]] [--build-dir <directory>]\n");
        exit(1);
    }

    $workingDirectory = getcwd();
    try {
        $project = WasiProjectConfig::load(
            $input,
            $buildDir,
            $workingDirectory,
            TYPEPHP_ROOT_PATH . DIRECTORY_SEPARATOR . 'build',
            $profile,
        );
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Invalid WASI project: {$exception->getMessage()}\n");
        exit(1);
    }

    $builder = dirname(__DIR__) . '/wasm/build-program.sh';
    if (!is_executable($builder)) {
        fwrite(STDERR, "TypePHP WASI builder is not executable: {$builder}\n");
        exit(1);
    }

    try {
        $tools = (new WasiToolchain())->detect(
            requireBrowserTools: $project->profile === 'browser',
            requireWitBindgen: $project->mode === 'library',
        );
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "WASI toolchain check failed: {$exception->getMessage()}\n");
        fwrite(STDERR, "Add WASI SDK and Wasmtime bin directories to PATH");
        if ($project->profile === 'browser') {
            fwrite(STDERR, ", and install Jco (`npm install -g @bytecodealliance/jco`)"
                . " or use --wasm=component");
        }
        if ($project->mode === 'library') {
            fwrite(STDERR, ", and install wit-bindgen-cli 0.60.0"
                . " (`cargo install wit-bindgen-cli --version 0.60.0 --locked`)");
        }
        fwrite(STDERR, ", then try again.\n");
        exit(1);
    }

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['TYPEPHP_WASI_CC'] = $tools['clang'];
    $environment['TYPEPHP_WASI_CXX'] = $tools['clang++'];
    $environment['TYPEPHP_WASI_AR'] = $tools['llvm-ar'];
    $environment['TYPEPHP_WASI_RANLIB'] = $tools['llvm-ranlib'];
    $environment['TYPEPHP_WASI_NM'] = $tools['llvm-nm'];
    $environment['TYPEPHP_WASI_LD'] = $tools['wasm-ld'];
    $environment['TYPEPHP_WASMTIME'] = $tools['wasmtime'];
    $environment['TYPEPHP_WASM_BROWSER'] = $project->profile === 'browser' ? '1' : '0';
    if ($project->profile === 'browser') {
        $environment['TYPEPHP_JCO'] = $tools['jco'];
        $environment['TYPEPHP_JCO_VERSION'] = $tools['jco-version'];
    }
    $environment['TYPEPHP_WASI_TARGET'] = $tools['target'];
    $environment['TYPEPHP_WASI_CLANG_VERSION'] = $tools['clang-version'];
    $environment['TYPEPHP_WASMTIME_VERSION'] = $tools['wasmtime-version'];
    $environment['TYPEPHP_WASM_PROGRAM_BUILD_DIR'] = $project->buildDir;
    $environment['TYPEPHP_WASM_MODE'] = $project->mode;
    $environment['TYPEPHP_WASM_PACKAGE'] = $project->package;
    $environment['TYPEPHP_WASM_WORLD'] = $project->world;
    $compilerExecutable = realpath($argv[0]);
    if ($compilerExecutable === false || !is_executable($compilerExecutable)) {
        fwrite(STDERR, "Unable to resolve the current TypePHP compiler executable: {$argv[0]}\n");
        exit(1);
    }
    if ($project->browserDir !== null) {
        $environment['TYPEPHP_WASM_BROWSER_DIR'] = $project->browserDir;
    }

    try {
        $phpxDir = PhpxLocator::resolve(TYPEPHP_ROOT_PATH);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Unable to locate PHPX: {$exception->getMessage()}\n");
        exit(1);
    }
    if ($project->mode === 'library') {
        $environment['TYPEPHP_WIT_BINDGEN'] = $tools['wit-bindgen'];
    }
    $command = [$builder, $project->input, $project->output ?? '-', $phpxDir, $compilerExecutable];

    $process = proc_open(
        $command,
        [STDIN, STDOUT, STDERR],
        $pipes,
        getcwd(),
        $environment,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start the TypePHP WASI builder\n");
        exit(1);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

function shouldCompileWasm(array $argv): bool
{
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--wasm' || str_starts_with($argument, '--wasm=')) {
            return true;
        }
    }

    $workingDirectory = getcwd();
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '' || $argument[0] === '-') {
            continue;
        }
        $path = $argument;
        if ($path[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = $workingDirectory . DIRECTORY_SEPARATOR . $path;
        }
        if (WasiProjectConfig::isWasmEnabled($path)) {
            return true;
        }
    }
    return false;
}

function profileAnalyze(int $argc, array $argv): void
{
    $profFile = $argv[1];

    if (!file_exists($profFile)) {
        fwrite(STDERR, "Profile file not found: {$profFile}\n");
        exit(1);
    }

    // Derive the binary name from the prof file name (app.prof → app).
    $binary = basename($profFile, '.prof');
    if (!file_exists($binary) && file_exists('./' . $binary)) {
        $binary = './' . $binary;
    }

    if (!file_exists($binary)) {
        fwrite(STDERR, "Binary not found: {$binary} (expected from prof file name)\n");
        fwrite(STDERR, "Usage: ./tpc <binary>.prof\n");
        exit(1);
    }

    $cmd = 'pprof --web ' . escapeshellarg($binary) . ' ' . escapeshellarg($profFile);
    fwrite(STDERR, "Running: {$cmd}\n");
    passthru($cmd, $exitCode);
    exit($exitCode);
}
