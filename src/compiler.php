<?php
use TypePhp\Translator;
use TypePhp\Build\WasiToolchain;
use TypePhp\Build\WasiProjectConfig;
use TypePhp\Build\PhpxLocator;
use TypePhp\Build\CompilerRuntime;
use TypePhp\Build\NativeSourceProjectBuilder;
use TypePhp\Build\NativeSourceProjectConfig;
use TypePhp\Build\ProjectBuildRunner;
use TypePhp\PythonTools\Command as PythonToolsCommand;
use TypePhp\Cli\CompletionCommand;
use TypePhp\Resolver\Reflection as ReflectionResolver;

function main(int $argc, array $argv): void
{
    runCompiler($argc, $argv, CompilerRuntime::native($argv[0]));
}

function runCompiler(int $argc, array $argv, CompilerRuntime $runtime): void
{
    // Compiling a complete project keeps the parsed AST and generated sources in
    // memory. The default CLI limit (commonly 128M) is too small for larger builds.
    ini_set('memory_limit', '-1');

    // Drop cached reflector wrappers during shutdown while PHPX request state
    // and their referenced internal symbols are still available. Embed serves
    // one request per compiler process; keep this traversal out of the
    // extension/CLI/FPM request-shutdown path.
    if (php_sapi_name() === 'embed') {
        register_shutdown_function([ReflectionResolver::class, 'clearCaches']);
    }

    // The PHP entrypoint already loaded Composer's project autoloader in
    // bin/bootstrap.php. The native binary loads its embedded copy here.
    if (!$runtime->sourceEntry) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    configureProxyEnvironment($argv);

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

    try {
        $nativeSourceProject = shouldCompileNativeSourceProject($argv);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Native source build failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }
    if ($nativeSourceProject) {
        compileNativeSourceProject($argv, $runtime);
        return;
    }

    if (getenv('TYPEPHP_WASM_INTERNAL_COMPILE') !== '1' && shouldCompileWasm($argv)) {
        compileWasmProgram($argv, $runtime);
        return;
    }

    // .prof file analysis mode: ./tpc app.prof
    if ($argc >= 2 && str_ends_with($argv[1], '.prof')) {
        profileAnalyze($argc, $argv);
        return;
    }

    (new ProjectBuildRunner(Translator::getInstance($runtime)))->run($argv);
}

/**
 * Export an explicit CLI proxy for every network-capable child process. The
 * downloader still receives the value directly so credentials can be redacted
 * from its diagnostics, while tools such as package managers inherit the
 * conventional proxy variables from the compiler process.
 */
function configureProxyEnvironment(array $argv): void
{
    $proxy = null;
    for ($i = 1, $count = count($argv); $i < $count; ++$i) {
        $argument = $argv[$i];
        if ($argument === '--proxy') {
            if (!isset($argv[$i + 1]) || trim($argv[$i + 1]) === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
            $proxy = trim($argv[++$i]);
            continue;
        }
        if (str_starts_with($argument, '--proxy=')) {
            $proxy = trim(substr($argument, strlen('--proxy=')));
            if ($proxy === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
        }
    }

    if ($proxy === null) {
        return;
    }
    foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $name) {
        putenv($name . '=' . $proxy);
        $_ENV[$name] = $proxy;
    }
}

function shouldCompileNativeSourceProject(array $argv): bool
{
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; ++$i) {
        $argument = $arguments[$i];
        if ($argument === '--proxy') {
            ++$i;
            continue;
        }
        if (str_starts_with($argument, '--proxy=')) {
            continue;
        }
        if ($argument === '' || $argument[0] === '-') {
            continue;
        }
        $path = $argument;
        if ($path[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }
        return NativeSourceProjectConfig::isNativeProject($path);
    }
    return false;
}

function compileNativeSourceProject(array $argv, CompilerRuntime $runtime): void
{
    $input = null;
    $buildDir = null;
    $run = false;
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; ++$i) {
        $argument = $arguments[$i];
        if ($argument === '--run' || $argument === '-r') {
            $run = true;
            continue;
        }
        if ($argument === '--proxy') {
            if (!isset($arguments[$i + 1]) || $arguments[$i + 1] === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
            ++$i;
            continue;
        }
        if (str_starts_with($argument, '--proxy=')) {
            if (substr($argument, strlen('--proxy=')) === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
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
        if ($argument !== '' && $argument[0] === '-') {
            fwrite(STDERR, "Unsupported native option: {$argument}\n");
            exit(1);
        }
        if ($input !== null) {
            fwrite(STDERR, "Native mode accepts exactly one project.xml\n");
            exit(1);
        }
        $input = $argument;
    }

    if ($input === null) {
        fwrite(STDERR, "Usage: vendor/bin/tpc project.xml [--build-dir DIR] [--run]\n");
        exit(1);
    }

    try {
        $project = NativeSourceProjectConfig::load($input, $buildDir);
        $builder = new NativeSourceProjectBuilder(compilerRuntime: $runtime);
        $result = $builder->build($project);
        fwrite(
            STDOUT,
            "Native source build completed: {$result['sourceCount']} source file(s), "
            . "{$result['compiledCount']} compiled, "
            . "output {$result['output']}\n"
        );
        if ($run) {
            $builder->runOutput($project);
        }
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Native source build failed: {$exception->getMessage()}\n");
        exit(1);
    }
}

/**
 * Build a self-contained WASI 0.2 command component through the public CLI.
 * The lower-level build scripts are implementation details and are not part of
 * the user-facing workflow.
 */
function compileWasmProgram(array $argv, CompilerRuntime $runtime): void
{
    $input = null;
    $buildDir = null;
    $profile = null;
    $nano = false;
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; $i++) {
        $argument = $arguments[$i];
        if ($argument === '--wasm') {
            continue;
        }
        if ($argument === '--nano') {
            $nano = true;
            continue;
        }
        if ($argument === '--proxy') {
            if (!isset($arguments[$i + 1]) || $arguments[$i + 1] === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
            ++$i;
            continue;
        }
        if (str_starts_with($argument, '--proxy=')) {
            if (substr($argument, strlen('--proxy=')) === '') {
                fwrite(STDERR, "Option --proxy requires a URL\n");
                exit(1);
            }
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
            $runtime->installationRoot . DIRECTORY_SEPARATOR . 'build',
            $profile,
        );
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Invalid WASI project: {$exception->getMessage()}\n");
        exit(1);
    }

    $builder = $runtime->installationRoot . '/wasm/'
        . ($nano ? 'build-nano-program.sh' : 'build-program.sh');
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
    $environment['TYPEPHP_WASM_NANO'] = $nano ? '1' : '0';
    if (!is_file($runtime->executable) || !is_executable($runtime->executable)) {
        fwrite(STDERR, "Unable to resolve the current TypePHP compiler executable: {$argv[0]}\n");
        exit(1);
    }
    if ($project->browserDir !== null) {
        $environment['TYPEPHP_WASM_BROWSER_DIR'] = $project->browserDir;
    }

    try {
        $phpxDir = PhpxLocator::resolve($runtime->installationRoot);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Unable to locate PHPX: {$exception->getMessage()}\n");
        exit(1);
    }
    if ($project->mode === 'library') {
        $environment['TYPEPHP_WIT_BINDGEN'] = $tools['wit-bindgen'];
    }
    $command = [$builder, $project->input, $project->output ?? '-', $phpxDir, $runtime->executable];

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
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; ++$i) {
        $argument = $arguments[$i];
        if ($argument === '--proxy') {
            ++$i;
            continue;
        }
        if (str_starts_with($argument, '--proxy=')) {
            continue;
        }
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
