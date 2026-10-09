<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use Ajaxray\AnsiKit\AnsiTerminal;
use Ajaxray\AnsiKit\Components\Progressbar;
use TypePhp\Backend\CompilerFactory;
use TypePhp\Exception\SyntaxError;
use TypePhp\Exception\Unsupported;
use TypePhp\Installer\InteractiveConsole;
use TypePhp\Installer\LibPhpxInstaller;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Wasi;
use TypePhp\Platform\Windows;

trait SourcePipelineTrait
{
    use PreparedProjectCacheTrait;

    /** @var list<string> PHP files selected by embedded-files. */
    private array $embeddedPhpFiles = [];

    /** @var list<string> All regular files selected by embedded-files. */
    private array $embeddedFiles = [];

    /** @var list<string> Selected PHP files not translated to native code. */
    private array $embeddedOpcodeFiles = [];

    /** @var list<string> Anonymous classes generated from the current source file. */
    private array $currentAnonymousFiles = [];

    /** @var array<string, string> Build-time PHP path to runtime anonymous-class key. */
    private array $anonymousOpcodeKeys = [];

    /** @var ?list<string> Zend extension arguments for the build PHP CLI. */
    private ?array $opcodeBuildExtensionArgs = null;
    private bool $opcodeBuildChecked = false;
    private string $opcodeBuildProbeError = '';
    private string $opcodeBuildPhpVersion = '';
    private string $opcodeBuildSignature = '';
    private ?string $embeddedArchiveFile = null;

    private function getOpcodeBuildPhpCli(): string
    {
        return $this->getPhpDir() . ($this->isWindows() ? '/php.exe' : '/bin/php');
    }

    /**
     * Prepare PHP inputs for the Composer php-nano source-composition build.
     *
     * The compiler itself still runs on Zend PHP, but generated sources do not
     * inspect or link the host libphp installation.
     *
     * @param list<string> $files
     * @return list<string>
     */
    public function prepareNanoSources(
        array $files,
        string $targetName,
        string $buildDir,
        bool $wasi,
    ): array {
        if ($files === []) {
            return [];
        }

        $this->nanoMode = true;
        $this->nanoPolicyMode = true;
        // Persistent literal wrappers are normally constructed after libphp has
        // initialized Zend. A standalone executable starts php-nano from main(),
        // so keep literals inside function scope for now.
        $this->noLiteralStrings = true;
        $this->buildMode = self::BUILD_MODE_BIN;
        $this->targetPlatform = $wasi ? 'wasm32-wasip2' : '';
        $this->setTargetName($targetName);
        $this->setBuildDir($buildDir);
        if ($this->climate->arguments->defined('force')) {
            $this->clearIncrementalBuildCache();
        }

        $resolvedFiles = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false || !is_file($resolved)
                || !FileScanner::isPhpFile($resolved)) {
                throw new \RuntimeException("Invalid TypePHP native source: {$file}");
            }
            $resolvedFiles[] = $resolved;
            $this->sourceDirs[] = dirname($resolved);
        }
        $resolvedFiles = array_values(array_unique($resolvedFiles));
        $this->sourceDirs = array_values(array_unique($this->sourceDirs));

        $this->discoverNativeClassDeclarations($resolvedFiles);
        foreach ($resolvedFiles as $key => $file) {
            try {
                $this->prepareFile($file);
            } catch (Unsupported $exception) {
                $this->output(
                    ' unsupported syntax: ' . $exception->getMessage()
                    . "\n skip: {$file}\n",
                    'error',
                );
                unset($resolvedFiles[$key]);
            } catch (SyntaxError $exception) {
                $this->output(
                    ' syntax error: ' . $exception->getMessage()
                    . "\n skip: {$file}\n",
                    'error',
                );
                unset($resolvedFiles[$key]);
            }
        }

        $resolvedFiles = array_values($resolvedFiles);
        $this->composeTraitDeclarations($resolvedFiles);
        $this->discoverNativeGlobalObjects($resolvedFiles);
        $resolvedFiles = $this->getSortedFiles($resolvedFiles);
        $this->initializeIncrementalCompilation($resolvedFiles);
        return $resolvedFiles;
    }

    public function addFiles(array $files): void
    {
        $this->sourceDirs = array_merge($this->sourceDirs, $files);
    }

    protected function embedAnonymousClassCode(string $name, string $code): string
    {
        $path = $this->getBuildDir() . '/cache/anonymous/source/anonymous-' . $this->targetName . '-'
            . substr(hash('sha256', $this->file), 0, 12) . '-' . $name . '.php';
        $this->writeFile($path, "<?php\n" . $code . "\n");
        $this->embeddedOpcodeFiles[] = $path;
        $this->anonymousOpcodeKeys[$path] = $this->anonymousOpcodeKey($path);
        $this->currentAnonymousFiles[] = $path;
        return $this->anonymousOpcodeKeys[$path];
    }

    private function anonymousOpcodeKey(string $path): string
    {
        return '@typephp:anon:' . substr(hash('sha256', $path), 0, 32);
    }

    private function anonymousManifestPath(string $source): string
    {
        return $this->getBuildDir() . '/cache/anonymous/manifests/'
            . substr(hash('sha256', $source), 0, 20) . '.json';
    }

    private function moveLegacyBuildCache(string $legacy, string $current): void
    {
        if (!file_exists($legacy)) {
            return;
        }
        $destination = $current;
        if (file_exists($destination)) {
            $base = $this->getBuildDir() . '/cache/legacy/' . basename($legacy);
            $destination = $base;
            for ($suffix = 1; file_exists($destination); $suffix++) {
                $destination = $base . '-' . $suffix;
            }
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create build cache directory: {$directory}");
        }
        if (!rename($legacy, $destination)) {
            throw new \RuntimeException("Cannot move build cache into {$directory}: {$legacy}");
        }
    }

    private function restoreAnonymousManifest(string $source): void
    {
        $manifest = $this->anonymousManifestPath($source);
        if (!is_file($manifest)) {
            return;
        }
        $paths = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($paths)) {
            throw new \RuntimeException("Invalid anonymous class manifest: {$manifest}");
        }
        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path)) {
                throw new \RuntimeException("Missing anonymous class source: {$path}");
            }
            $this->embeddedOpcodeFiles[] = $path;
            $this->anonymousOpcodeKeys[$path] = $this->anonymousOpcodeKey($path);
        }
    }

    /** Check whether the target PHP CLI can generate OPcache file-cache blobs. */
    private function probeOpcodeBuildExtensionArgs(): ?array
    {
        if ($this->opcodeBuildChecked) {
            return $this->opcodeBuildExtensionArgs;
        }
        $this->opcodeBuildChecked = true;

        $php = $this->getOpcodeBuildPhpCli();
        if (!is_file($php) || !is_executable($php)) {
            $this->opcodeBuildProbeError = "Build PHP CLI is not executable: {$php}";
            return null;
        }

        $extensionCandidates = [[], ['-d', 'zend_extension=opcache']];
        if ($this->isWindows()) {
            $extensionCandidates[] = ['-d', 'zend_extension=' . $this->getPhpDir() . '/ext/php_opcache.dll'];
        }
        foreach ($extensionCandidates as $extensionArgs) {
            $process = proc_open(
                [
                    $php, '-n', ...$extensionArgs, '-d', 'opcache.enable_cli=1',
                    '-r', 'if (!extension_loaded("Zend OPcache") || !function_exists("opcache_compile_file")) exit(1); '
                        . '$extension = PHP_OS_FAMILY === "Windows" ? dirname(PHP_BINARY) . "/ext/php_opcache.dll" '
                        . ': ini_get("extension_dir") . DIRECTORY_SEPARATOR . "opcache." . PHP_SHLIB_SUFFIX; '
                        . 'echo json_encode(["php" => PHP_VERSION, "opcache" => phpversion("Zend OPcache"), '
                        . '"binary" => [PHP_BINARY, hash_file("sha256", PHP_BINARY)], '
                        . '"extension" => [$extension, is_file($extension) ? hash_file("sha256", $extension) : null], '
                        . '"zts" => PHP_ZTS, "debug" => PHP_DEBUG, "int_size" => PHP_INT_SIZE]);',
                ],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                $this->opcodeBuildProbeError = "Cannot start the build PHP CLI: {$php}";
                return null;
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) === 0) {
                $signature = json_decode((string) $stdout, true);
                if (!is_array($signature) || !is_string($signature['php'] ?? null)
                    || !is_bool($signature['zts'] ?? null) || !is_bool($signature['debug'] ?? null)
                ) {
                    throw new \RuntimeException("Invalid OPcache build PHP signature from {$php}");
                }
                if ($this->isPhpBuilderBuild()) {
                    $this->validatePhpBuilderRuntimeSignature($signature);
                }
                $this->opcodeBuildPhpVersion = $signature['php'];
                $this->opcodeBuildSignature = (string) $stdout;
                return $this->opcodeBuildExtensionArgs = $extensionArgs;
            }
            if ($stderr !== false && trim($stderr) !== '') {
                $this->opcodeBuildProbeError = trim($stderr);
            }
        }
        return null;
    }

    private function getOpcodeBuildExtensionArgs(): array
    {
        $extensionArgs = $this->probeOpcodeBuildExtensionArgs();
        if ($extensionArgs !== null) {
            return $extensionArgs;
        }
        $php = $this->getOpcodeBuildPhpCli();
        $detail = $this->opcodeBuildProbeError === '' ? '' : "\n" . $this->opcodeBuildProbeError;
        $this->error("Embedded opcode generation requires Zend OPcache for the build PHP CLI: {$php}{$detail}");
    }

    protected function canEmbedAnonymousClassOpcode(): bool
    {
        return $this->isBuildModeBin() && !$this->isNanoMode()
            && !$this->isIosTarget() && !$this->isAndroidTarget() && !$this->isWasiTarget()
            && $this->probeOpcodeBuildExtensionArgs() !== null;
    }

    private function startOpcodeProgress(string $label, int $total): ?Progressbar
    {
        if ($this->noProgress || $total === 0) {
            return null;
        }
        $progress = new Progressbar();
        $progress->barStyle([AnsiTerminal::FG_GREEN])
            ->percentageStyle([AnsiTerminal::TEXT_BOLD])
            ->labelStyle([AnsiTerminal::FG_CYAN])
        ;
        $progress->renderInPlace(0, $total, $label);
        return $progress;
    }

    private function updateOpcodeProgress(
        ?Progressbar $progress,
        string $label,
        int $completed,
        int $total,
        ?string $file = null,
    ): void {
        if ($progress !== null) {
            $progress->renderInPlace($completed, $total, $label);
        } elseif ($this->noProgress && ($file !== null || $completed % 100 === 0 || $completed === $total)) {
            $percent = (int) ceil($completed / $total * 100);
            $detail = $file === null ? '' : " {$file}";
            $this->output("[{$completed}/{$total}] {$percent}% {$label}{$detail}", 'white');
        }
    }

    /** Embed OPcache's file-cache bytes for scripts left to ZendVM. */
    private function genEmbeddedOpcodeTable(): array
    {
        $output = $this->getBuildDir() . '/embedded-opcodes-' . $this->targetName . '.cc';
        $this->migrateEmbeddedOpcodeCaches();
        $files = array_values(array_unique($this->embeddedOpcodeFiles));
        sort($files, SORT_STRING);

        $blobs = [];
        $phpVersion = PHP_VERSION;
        if ($files !== []) {
            ['blobs' => $blobs, 'phpVersion' => $phpVersion] = $this->generateEmbeddedOpcodeBlobs($files);
        }

        $sources = $this->emitEmbeddedArchiveSources($output, $files, $blobs, $phpVersion);
        $this->removeLegacyAnonymousArtifacts();
        return $sources;
    }

    private function migrateEmbeddedOpcodeCaches(): void
    {
        foreach (glob($this->getBuildDir() . '/opcache-*') ?: [] as $legacyCache) {
            if (is_dir($legacyCache)) {
                $this->moveLegacyBuildCache(
                    $legacyCache,
                    $this->getBuildDir() . '/cache/opcache/' . basename($legacyCache),
                );
            }
        }
        $this->moveLegacyBuildCache(
            $this->getBuildDir() . '/compile-embedded-opcodes.php',
            $this->getBuildDir() . '/cache/opcache/compile-embedded-opcodes.php',
        );
    }

    /** @param list<string> $files @return array{blobs: array<string, string>, phpVersion: string} */
    private function generateEmbeddedOpcodeBlobs(array $files): array
    {
        if ($this->isIosTarget() || $this->isAndroidTarget() || $this->isWasiTarget()) {
            throw new \RuntimeException('Embedded opcodes require a native PHP build host matching the target');
        }
        $this->output('Generating embedded opcodes for ' . count($files) . ' PHP files', 'lightBlue');

        $generator = new EmbeddedOpcodeGenerator(
            $this->getBuildDir(),
            $this->targetName,
            $this->getOpcodeBuildPhpCli(),
            $this->getOpcodeBuildExtensionArgs(),
            $this->opcodeBuildPhpVersion,
            $this->opcodeBuildSignature,
            $this->climate->arguments->defined('force'),
        );
        $batch = $generator->prepare($files, $this->anonymousOpcodeKeys);
        $this->reportEmbeddedOpcodeCache($batch);

        $progress = $this->startOpcodeProgress('Opcodes', $batch->pendingCount());
        $blobs = $generator->compile(
            $batch,
            fn (string $file) => $this->climate->out(
                'Skipping non-executable embedded PHP file: ' . $file,
            ),
            fn (int $completed, int $total, string $file) => $this->updateOpcodeProgress(
                $progress,
                'Opcodes',
                $completed,
                $total,
                $this->noProgress ? $file : null,
            ),
        );
        if ($progress !== null) {
            echo PHP_EOL;
        }
        return ['blobs' => $blobs, 'phpVersion' => $this->opcodeBuildPhpVersion];
    }

    private function reportEmbeddedOpcodeCache(EmbeddedOpcodeBatch $batch): void
    {
        if ($batch->vendorCount() !== 0) {
            $vendorHits = $batch->vendorHits();
            $vendorSkips = $batch->skippedVendorCount();
            $this->output(
                'Vendor opcode cache: ' . $vendorHits . ' reused, '
                . ($batch->vendorCount() - $vendorHits - $vendorSkips) . ' to generate'
                . ($vendorSkips === 0 ? '' : ', ' . $vendorSkips . ' skipped'),
                'lightBlue',
            );
        }
        if ($batch->anonymousCount() !== 0) {
            $anonymousHits = $batch->anonymousHits();
            $this->output(
                'Anonymous opcode cache: ' . $anonymousHits . ' reused, '
                . ($batch->anonymousCount() - $anonymousHits) . ' to generate',
                'lightBlue',
            );
        }
    }

    /**
     * @param list<string> $opcodeFiles
     * @param array<string, string> $blobs
     * @return list<string>
     */
    private function emitEmbeddedArchiveSources(
        string $output,
        array $opcodeFiles,
        array $blobs,
        string $phpVersion,
    ): array {
        $archive = $this->getBuildDir() . '/cache/embedded/embedded-files-' . $this->targetName . '.bin';
        $this->moveLegacyBuildCache(
            $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.bin',
            $archive,
        );
        $sources = [$output];
        $embeddedArchive = EmbeddedArchive::empty($archive);
        if ($this->embeddedFiles !== [] || $opcodeFiles !== []) {
            $embeddedArchive = (new EmbeddedArchiveBuilder())->build(
                $archive,
                $this->embeddedFiles,
                $blobs,
                $this->anonymousOpcodeKeys,
            );
            if ($this->isWindows()) {
                $this->embeddedArchiveFile = $archive;
            } else {
                $assembly = $this->getBuildDir() . '/embedded-files-' . $this->targetName . '.S';
                $quotedArchive = json_encode($archive, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $section = $this->isMacos() ? '__TEXT,__const' : '.rodata';
                $symbol = $this->isMacos()
                    ? '_typephp_embedded_archive_start' : 'typephp_embedded_archive_start';
                $asmCode = "# archive-sha256: {$embeddedArchive->hash}\n.section {$section}\n.globl {$symbol}\n.p2align 4\n{$symbol}:\n.incbin {$quotedArchive}\n";
                $this->writeFile($assembly, $asmCode);
                $this->generatedProjectSources[$assembly] = true;
                $sources[] = $assembly;
            }
            $this->output(
                'Packed ' . count($embeddedArchive->fileIndex) . ' files and '
                    . count($embeddedArchive->opcodeIndex) . ' opcode blobs',
                'green',
            );
        }

        $this->writeFile(
            $output,
            (new EmbeddedTableRenderer())->render(
                $embeddedArchive,
                $phpVersion,
                $this->isWindows(),
                !$this->isSapiBuild(),
                $this->sapiEntryFile,
            ),
        );
        $this->generatedProjectSources[$output] = true;
        return $sources;
    }

    private function removeLegacyAnonymousArtifacts(): void
    {
        foreach (glob($this->getBuildDir() . '/anonymous-*.json') ?: [] as $legacyManifest) {
            unlink($legacyManifest);
        }
        foreach (glob($this->getBuildDir() . '/anonymous-' . $this->targetName . '-*.php') ?: [] as $legacySource) {
            unlink($legacySource);
        }
    }

    public function getFiles(string $path): array
    {
        $this->applyPhpVersionCommandLineArgument();
        $projectPath = realpath($path);
        if ($projectPath === false) {
            $this->error("path not exists: {$path}");
        }
        $files = $this->discoverProjectFiles($projectPath);

        // Command-line values have the highest precedence and therefore apply
        // only after a YAML project has loaded all included configuration.
        $this->applyCommandLineArguments();
        $this->validateLoadedProjectConfiguration();
        if ($this->sapiEntryFile !== null) {
            $files = array_values(array_diff($files, [$this->sapiEntryFile]));
        }
        $files = $this->excludeGeneratedLibraryStub($files);
        return $this->filterIgnoredFiles($files);
    }

    /** @return list<string> */
    private function discoverProjectFiles(string $path): array
    {
        if (is_dir($path)) {
            // Directory mode: no YAML parsing
            $files = $this->getFilesFromDir($path);
            $this->setTargetName(basename($path));
            $this->sourceDirs[] = $path;
            return $files;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === 'yml' || $extension === 'yaml') {
            return $this->parseProjectYaml($path);
        }
        if ($extension === 'php') {
            // Single-file mode: no YAML parsing
            $this->setTargetName(FileScanner::getFileName($path));
            $this->sourceDirs[] = dirname($path);
            return [$path];
        }
        $this->error('Unsupported file type: ' . $path);
    }

    private function validateLoadedProjectConfiguration(): void
    {
        if ($this->sapiConfigured && !$this->isBuildModeBin()) {
            $this->error('`sapi` is only supported with `mode: bin`');
        }
        if ($this->isPhpBuilderBuild() && !$this->isBuildModeBin()) {
            $this->error('`php-builder` is only supported with `mode: bin`');
        }
        if ($this->isSapiBuild() && !$this->isPhpBuilderBuild()) {
            $this->error('The cli and fpm SAPIs require `php-builder`');
        }
        if ($this->hasSapi('cli') && $this->sapiEntryConfiguredPath === null) {
            $this->error('`sapi` containing cli requires an `entry` PHP file');
        }
        if ($this->sapiEntryConfiguredPath !== null && !$this->hasSapi('cli')) {
            $this->climate->out('`entry` is ignored because `sapi` does not contain cli');
            $this->sapiEntryFile = null;
            $this->sapiEntryConfiguredPath = null;
            $this->sapiEntryConfiguredValue = null;
        }
        if ($this->sapiEntryConfiguredPath !== null) {
            $entry = realpath($this->sapiEntryConfiguredPath);
            if ($entry === false || !is_file($entry)) {
                $value = $this->sapiEntryConfiguredValue ?? $this->sapiEntryConfiguredPath;
                $this->error('Entry file does not exist: `' . $value . '`');
            }
            if (!FileScanner::isPhpFile($entry) || str_ends_with($entry, '.stub.php')) {
                $this->error('`entry` must select an executable PHP file');
            }
            $this->sapiEntryFile = $entry;
            if (!in_array($this->sapiEntryFile, $this->embeddedFiles, true)) {
                $this->embeddedFiles[] = $this->sapiEntryFile;
                sort($this->embeddedFiles, SORT_STRING);
            }
            if (!in_array($this->sapiEntryFile, $this->embeddedPhpFiles, true)) {
                $this->embeddedPhpFiles[] = $this->sapiEntryFile;
                sort($this->embeddedPhpFiles, SORT_STRING);
            }
        }
        if ($this->embeddedFiles !== [] && !$this->isBuildModeBin() && !$this->isSapiBuild()) {
            $this->error('`embedded-files` requires `mode: bin`');
        }
        if ($this->embeddedFiles !== [] && !$this->isSapiBuild()) {
            $this->getOpcodeBuildExtensionArgs();
        }
        if ($this->climate->arguments->defined('force')) {
            $this->clearIncrementalBuildCache();
        }
        $this->validateProjectObjectFiles();
    }

    /** @param list<string> $files @return list<string> */
    private function excludeGeneratedLibraryStub(array $files): array
    {
        // The generated public import stub is an output artifact, not an input
        // of the library that produced it. Exclude a previous build's copy when
        // a project scans its output directory recursively.
        if (!$this->isBuildModeLib()) {
            return $files;
        }
        $generatedStub = realpath($this->getLibraryImportStubFile());
        if ($generatedStub === false) {
            return $files;
        }
        return array_values(array_filter(
            $files,
            static fn (string $file): bool => realpath($file) !== $generatedStub,
        ));
    }

    public function prepare(string $path): array
    {
        $files = $this->getFiles($path);
        $this->prepareBuildEnvironment();
        $preparedKey = $this->preparedProjectKey($files);
        if (!$this->restorePreparedProject($preparedKey)) {
            $files = $this->preprocessProjectFiles($files, $preparedKey);
        }
        return $this->finalizePreparedProject($files);
    }

    private function prepareBuildEnvironment(): void
    {
        $this->prepareRuntimeDependencies();
        $this->validateCompilerToolchain();
        $this->reportBuildLibraryWarnings();
    }

    private function prepareRuntimeDependencies(): void
    {
        // Source-composed Nano does not consume the host PHP/PHPX runtime.
        // Windows Nano deliberately leaves nanoMode=false and therefore keeps
        // this original DLL/import-library validation path.
        if ($this->isNanoMode()) {
            return;
        }

        $this->offerPhpBuilderForMissingEmbedRuntime();

        if ($this->isPhpBuilderBuild()) {
            $this->preparePhpBuilderEnvironment();
            $this->getOpcodeBuildExtensionArgs();
            return;
        }

        $platform = $this->getPlatform();
        $phpDir = $this->getPhpDir();

        if (!$platform instanceof Wasi) {
            $this->validatePhpRuntimeMinimum($phpDir);
        }

        if ($platform instanceof Linux) {
            try {
                (new LibPhpxInstaller())->ensure($this->getPhpxDir(), $phpDir);
            } catch (\Throwable $e) {
                $this->error('Unable to build libphpx.so: ' . $e->getMessage());
            }
        }

        // Pre-check the phpx library only at the PHP script entry (bin/tpc.php):
        // a missing library fails immediately rather than surfacing later during
        // file processing/compilation. The compiled tpc executable has libphpx
        // loaded by the dynamic linker before entering main(), so checking here
        // is neither needed nor possible.
        if ($this->compilerRuntime->sourceEntry && !($platform instanceof Wasi)) {
            $this->validatePhpxLibrary();
        }
    }

    private function offerPhpBuilderForMissingEmbedRuntime(): void
    {
        if ($this->isPhpBuilderBuild()
            || !$this->isBuildModeBin()
            || !$this->hasSapi('embed')
            || (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin')
        ) {
            return;
        }

        try {
            $this->getPlatform()->detectPhpLibs($this->getPhpDir());
            return;
        } catch (\RuntimeException $exception) {
            $detail = $exception->getMessage();
        }

        $console = new InteractiveConsole();
        if (!$console->isInteractive()) {
            $this->error(
                "The host PHP embed library is missing: {$detail}. "
                . 'Run tpc in an interactive terminal to enable php-builder, or pass '
                . '--php-builder to inherit the compiler runtime settings',
            );
        }

        $console->write('The host PHP installation does not provide an Embed SAPI library.');
        if (!$console->confirm('Build a private PHP runtime from php-src with php-builder?', true)) {
            $this->error('The embed SAPI requires a host libphp library or `php-builder`');
        }

        $this->phpBuilderEnabled = true;
        $this->phpBuilderZts = PHP_ZTS;
        $this->phpBuilderDebug = PHP_DEBUG;
        $this->phpBuilderExtensions = [];
        $console->write('php-builder enabled; required extensions will be collected automatically.');
    }

    private function reportBuildLibraryWarnings(): void
    {
        // shell_exec and define are already called directly via php::fn::, so no
        // dynamic symbol table is needed

        // All Windows build modes depend on the PHPX import library and runtime.
        // Other platforms only run the existing checks in embedded build mode.
        if (!$this->isNanoMode() && !$this->isPhpBuilderBuild()
            && ($this->isBuildModeEmbed() || $this->getPlatform() instanceof Windows)) {
            foreach ($this->getPlatform()->getBuildLibraryWarnings(
                $this->getPhpDir(),
                $this->getPhpxDir(),
                $this->buildMode,
                $this->compilerRuntime->sourceEntry,
            ) as $message) {
                if (!empty($message['error'])) {
                    $detail = $message['error'];
                    if (!empty($message['info'])) {
                        $detail .= "\n" . $message['info'];
                    }
                    $this->error($detail);
                }
                $this->climate->out($message['warning']);
                if (!empty($message['info'])) {
                    $this->climate->info($message['info']);
                }
            }
        }
    }

    /** @param list<string>|null $sourceDependencies */
    private function preparePhpBuilderEnvironment(?array $sourceDependencies = null): void
    {
        $this->validatePhpBuilderCompatibility(
            $this->phpVersion,
            $this->phpBuilderZts,
            $this->phpBuilderDebug,
        );
        try {
            $composerDependencies = SapiExtensionRequirements::fromEmbeddedVendorFiles(
                $this->embeddedFiles,
            );
            $requiredExtensions = SapiExtensionRequirements::merge(
                $this->phpBuilderExtensions,
                $this->extensionDependencies,
                $composerDependencies,
                $sourceDependencies ?? [],
            );
        } catch (\Throwable $exception) {
            $this->error('Unable to read SAPI extension requirements: ' . $exception->getMessage());
        }
        if ($this->sapiPhpBuildDirectory !== null
            && array_diff($requiredExtensions, $this->sapiEnabledExtensions) === []
        ) {
            return;
        }
        try {
            $phpBuildProgress = null;
            $lastLineProgress = -1;
            $lastLineProgressLabel = '';
            $builder = new SapiPhpBuilder(
                $this->getPhpxDir(),
                fn (string $message) => $this->output($message, 'lightBlue'),
                $this->downloadProxy,
                function (
                    int $completed,
                    int $total,
                    string $detail,
                    bool $finished,
                ) use (&$phpBuildProgress, &$lastLineProgress, &$lastLineProgressLabel): void {
                    if ($total === 0) {
                        return;
                    }
                    $isStage = preg_match(
                        '/^(?:Building|Finishing|Linking|Generating|Installing|PHP runtime)/',
                        $detail,
                    ) === 1;
                    $label = $isStage ? $detail : 'Building PHP';
                    if ($this->noProgress) {
                        if ($completed !== $lastLineProgress || $label !== $lastLineProgressLabel || $finished) {
                            $percent = min(100, (int) floor($completed / $total * 100));
                            $suffix = $detail === '' || $isStage
                                ? ''
                                : ' ' . $detail;
                            $this->output("[{$completed}/{$total}] {$percent}% {$label}{$suffix}", 'white');
                            $lastLineProgress = $completed;
                            $lastLineProgressLabel = $label;
                        }
                        return;
                    }
                    if ($phpBuildProgress === null) {
                        $phpBuildProgress = new Progressbar();
                        $phpBuildProgress->width(30)
                            ->barStyle([AnsiTerminal::FG_GREEN])
                            ->percentageStyle([AnsiTerminal::TEXT_BOLD])
                            ->labelStyle([AnsiTerminal::FG_CYAN])
                        ;
                    }
                    // A dynamic "(31s)" suffix shifts the bar on every digit
                    // boundary, and shorter stage names leave bytes from the
                    // previous frame behind. Keep the TTY layout fixed; the
                    // elapsed heartbeat remains visible with --no-progress.
                    $ttyLabel = preg_replace('/ \(\d+s\)$/', '', $label) ?? $label;
                    $phpBuildProgress->renderInPlace($completed, $total, str_pad($ttyLabel, 26));
                    if ($finished) {
                        echo PHP_EOL;
                        $phpBuildProgress = null;
                        $lastLineProgress = -1;
                        $lastLineProgressLabel = '';
                    }
                },
                CompilerToolchain::fromBackend($this->getCompilerBackend()),
            );
            $runtime = $builder->prepare(
                $this->phpVersion,
                $this->sapiTargets,
                min(8, max(1, $this->maxJob)),
                $requiredExtensions,
                $this->phpBuilderZts,
                $this->phpBuilderDebug,
            );
        } catch (\Throwable $exception) {
            $this->error('Unable to prepare self-contained PHP SAPI runtime: ' . $exception->getMessage());
        }

        if ($this->sapiPhpPrefix !== $runtime->prefix) {
            // Extension discovery can select another private runtime. Its
            // OPcache arguments and build signature must be probed again.
            $this->opcodeBuildChecked = false;
            $this->opcodeBuildExtensionArgs = null;
            $this->opcodeBuildProbeError = '';
            $this->opcodeBuildPhpVersion = '';
            $this->opcodeBuildSignature = '';
        }
        $this->sapiPhpSourceDirectory = $runtime->sourceDirectory;
        $this->sapiPhpBuildDirectory = $runtime->buildDirectory;
        $this->sapiPhpPrefix = $runtime->prefix;
        $this->sapiPhpxArchive = $runtime->phpxArchive;
        $this->sapiRuntimeArchives = $runtime->sapiArchives;
        $this->sapiEnabledExtensions = $runtime->enabledExtensions;
        $this->isPhpZts = $this->phpBuilderZts;
        putenv('PHP_HOME=' . $runtime->prefix);
        $_ENV['PHP_HOME'] = $runtime->prefix;
        $this->validatePhpRuntimeMinimum($runtime->prefix);
        $this->output(
            'Using private PHP ' . $runtime->version . ' runtime from ' . $runtime->buildDirectory,
            'green',
        );
    }

    private function validatePhpBuilderCompatibility(string $version, bool $zts, bool $debug): void
    {
        $targetMinor = implode('.', array_slice(explode('.', $version), 0, 2));
        $compilerMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $mismatches = [];
        if ($targetMinor !== $compilerMinor) {
            $mismatches[] = "PHP major/minor (compiler: {$compilerMinor}, target: {$targetMinor})";
        }
        if ($zts !== PHP_ZTS) {
            $mismatches[] = 'ZTS (compiler: ' . (PHP_ZTS ? 'on' : 'off')
                . ', target: ' . ($zts ? 'on' : 'off') . ')';
        }
        if ($debug !== PHP_DEBUG) {
            $mismatches[] = 'DEBUG (compiler: ' . (PHP_DEBUG ? 'on' : 'off')
                . ', target: ' . ($debug ? 'on' : 'off') . ')';
        }
        if ($mismatches !== []) {
            $this->error(
                '`php-builder` must match the compiler runtime: ' . implode('; ', $mismatches) . '. '
                . 'Run tpc with a matching PHP build or change --php-version / php-builder.zts / php-builder.debug.',
            );
        }
    }

    /** @param array{php: string, zts: bool, debug: bool} $signature */
    private function validatePhpBuilderRuntimeSignature(array $signature): void
    {
        $this->validatePhpBuilderCompatibility($signature['php'], $signature['zts'], $signature['debug']);
        if ($signature['php'] !== PHP_VERSION) {
            $this->output(
                'Warning: php-builder PHP release version ' . $signature['php']
                . ' differs from compiler PHP ' . PHP_VERSION . '; continuing. '
                . 'PHP_VERSION constants use the compiler runtime values.',
                'yellow',
            );
        }
    }

    /** @param list<string> $files @return list<string> */
    private function preprocessProjectFiles(array $files, string $preparedKey): array
    {
        $warningsBefore = $this->preprocessingWarningCount;
        $inputCount = count($files);
        $this->discoverNativeClassDeclarations($files);
        // Analyze and preprocess the PHP files
        foreach ($files as $k => $file) {
            if (FileScanner::isPhpFile($file)) {
                try {
                    $this->prepareFile($file);
                } catch (Unsupported $e) {
                    $this->output(' unsupported syntax: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    $this->registerUnsupportedSapiFile($file);
                    unset($files[$k]);
                } catch (SyntaxError $e) {
                    $this->output(' syntax error: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                }
            }
        }
        // Trait declarations can only be flattened after the complete source
        // set has been prepared: a consuming class may precede its Trait file.
        // Complete the declaration graph before any body is converted.
        $this->composeTraitDeclarations(array_values($files));
        // Global slots are shared by every translation unit. Fix any Native
        // pointer ABI now, after declarations are known and before the first
        // per-file C++ body is generated.
        $this->discoverNativeGlobalObjects(array_values($files));
        if (count($files) === $inputCount && $this->preprocessingWarningCount === $warningsBefore) {
            $this->storePreparedProject($preparedKey);
        }
        return array_values($files);
    }

    /** @param list<string> $files @return list<string> */
    private function finalizePreparedProject(array $files): array
    {
        $files = $this->getSortedFiles($files);
        $this->embeddedOpcodeFiles = array_values(array_diff($this->embeddedPhpFiles, $files));
        $this->initializeIncrementalCompilation($files);
        return $files;
    }

    protected function validateCompilerToolchain(): void
    {
        $backend = $this->getCompilerBackend();
        $compilerCommand = $backend->getCompilerCommand();
        if (!CompilerFactory::isCommandExecutable($compilerCommand)) {
            $program = CompilerFactory::getCommandProgram($compilerCommand);
            $this->error(
                "C/C++ compiler executable not found: {$program}\n" .
                "Configured compiler command: {$compilerCommand}\n" .
                'Install a supported compiler, or select one with --compiler, or set `cpp-compiler` in project.yml.'
            );
        }

        $linkerCommand = $backend->getLinkerCommand();
        if ($linkerCommand !== $compilerCommand && !CompilerFactory::isCommandExecutable($linkerCommand)) {
            $program = CompilerFactory::getCommandProgram($linkerCommand);
            $this->error(
                "Linker executable not found: {$program}\n" .
                "Configured linker command: {$linkerCommand}\n" .
                'Install the required linker or update compiler configuration.'
            );
        }
    }

    /** Validate the selected headers/libphp independently of --php-version. */
    protected function validatePhpRuntimeMinimum(string $phpDir): void
    {
        $versionId = null;
        $headers = [
            $phpDir . '/include/php/main/php_version.h',
            $phpDir . '/include/main/php_version.h',
        ];
        foreach ($headers as $header) {
            if (!is_file($header)) {
                continue;
            }
            $contents = file_get_contents($header);
            if (is_string($contents) && preg_match('/^#define\s+PHP_VERSION_ID\s+(\d+)/m', $contents, $matches)) {
                $versionId = (int) $matches[1];
                break;
            }
        }

        if ($versionId === null) {
            $phpConfig = $phpDir . '/bin/php-config';
            if (is_executable($phpConfig)) {
                $value = shell_exec(escapeshellarg($phpConfig) . ' --vernum 2>/dev/null');
                if (is_string($value) && ctype_digit(trim($value))) {
                    $versionId = (int) trim($value);
                }
            }
        }

        if ($versionId !== null && $versionId < 80400) {
            $version = intdiv($versionId, 10000) . '.' . intdiv($versionId % 10000, 100);
            $this->error("TypePHP requires libphp 8.4 or later; selected PHP installation is {$version}: {$phpDir}");
        }
    }

    protected function shouldIgnoreFile(string $file): bool
    {
        foreach ($this->ignorePaths as $ignorePath) {
            if ($file === $ignorePath) {
                return true;
            }
            if (is_dir($ignorePath) && str_starts_with($file, rtrim($ignorePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function filterIgnoredFiles(array $files): array
    {
        $filteredFiles = [];
        $seenRealPaths = [];
        foreach ($files as $file) {
            if ($this->shouldIgnoreFile($file)) {
                continue;
            }

            // Ignore rules describe the path used to reach a source. Resolve
            // identity only after those rules have selected the surviving
            // aliases, then compile each physical file once.
            $identity = realpath($file) ?: $file;
            if (isset($seenRealPaths[$identity])) {
                continue;
            }
            $seenRealPaths[$identity] = true;
            $filteredFiles[] = $file;
        }

        return $filteredFiles;
    }

    public function convert(array $files): array
    {
        $this->compilationStatistics->begin();
        $previousSplitSetting = $this->splitTranslationUnitsEnabled;
        $this->splitTranslationUnitsEnabled = true;
        $previousPhase = null;
        try {
            $this->composeTraitDeclarations($files);
            $previousPhase = $this->enterCompilerPhase(self::PHASE_CONVERT);
            $this->initializeProjectConversion($files);
            $conversion = $this->convertProjectFiles($files);
            if ($this->isPhpBuilderBuild()) {
                // Function and class ownership is known only after lowering all
                // project bodies. Upgrade the cached PHP runtime before code is
                // compiled if those bodies introduced another extension.
                $this->preparePhpBuilderEnvironment($this->resolveExtensionDependencies());
            }
            return $this->finalizeProjectConversion($conversion);
        } finally {
            $this->splitTranslationUnitsEnabled = $previousSplitSetting;
            if ($previousPhase !== null) {
                $this->restoreCompilerPhase($previousPhase);
            }
            $this->compilationStatistics->finish();
        }
    }

    /** @param list<string> $files */
    private function initializeProjectConversion(array $files): void
    {
        // Persistent IDs must be hydrated before any unchanged translation
        // unit or declaration header is reused.
        $this->getStableIdRegistry();
        // Declaration constants are lowered before bodies so cache IDs are
        // assigned exclusively in the convert phase.
        $declarationFiles = $this->getDeclarationInputFiles($files);
        $this->finalizeDeclarationExpressions($declarationFiles);
        $this->finalizeRequestArrayDefaultMetadata();
        $this->initializeDeclarationHeaderFiles($files);
        $this->restoreCleanIncrementalMetadata($files);

        // Import stubs contribute Zend metadata but have no ordinary PHP body.
        foreach ($declarationFiles as $file) {
            if ($this->isStubFile($file) && $this->shouldRegeneratePhpFile($file)) {
                $this->genStubFile($file);
            }
        }
    }

    /** @param list<string> $files */
    private function convertProjectFiles(array $files): ProjectConversion
    {
        $sourceFiles = [];
        $validSourceCount = 0;
        foreach ($files as $key => $file) {
            try {
                $generated = $this->convertProjectFile($file);
                if ($generated === null) {
                    continue;
                }
                $validSourceCount++;
                array_push($sourceFiles, ...$generated);
            } catch (Unsupported $error) {
                $this->reportUnsupportedProjectFile($file, $error);
                unset($files[$key]);
            }
        }
        return new ProjectConversion(array_values($files), $sourceFiles, $validSourceCount);
    }

    /** @return list<string>|null */
    private function convertProjectFile(string $file): ?array
    {
        if (FileScanner::isPhpFile($file)) {
            return $this->convertPhpProjectFile(realpath($file) ?: $file);
        }
        return FileScanner::isNativeSourceFile($file) ? [$file] : null;
    }

    /** @return list<string> */
    private function convertPhpProjectFile(string $file): array
    {
        $manifest = $this->anonymousManifestPath($file);
        $legacyManifest = $this->legacyAnonymousManifestPath($file);
        $this->removeEmptyAnonymousManifest($manifest);
        $this->removeEmptyAnonymousManifest($legacyManifest);

        $shouldRegenerate = $this->shouldRegeneratePhpFile($file);
        if (!$shouldRegenerate && !$this->anonymousManifestNeedsRefresh($file, $manifest, $legacyManifest)) {
            return $this->restoreCachedPhpProjectFile($file, $manifest);
        }
        return $this->regeneratePhpProjectFile($file, $manifest, $legacyManifest);
    }

    private function legacyAnonymousManifestPath(string $file): string
    {
        return $this->getBuildDir() . '/anonymous-'
            . substr(hash('sha256', $file), 0, 20) . '.json';
    }

    private function removeEmptyAnonymousManifest(string $manifest): void
    {
        if (is_file($manifest) && file_get_contents($manifest) === '[]') {
            unlink($manifest);
        }
    }

    private function anonymousManifestNeedsRefresh(
        string $file,
        string $manifest,
        string $legacyManifest,
    ): bool {
        if (is_file($legacyManifest)) {
            return true;
        }
        if (is_file($manifest)) {
            return !$this->canEmbedAnonymousClassOpcode();
        }
        return preg_match('/new\s+class\b/', (string) file_get_contents($file)) === 1
            && $this->canEmbedAnonymousClassOpcode();
    }

    /** @return list<string> */
    private function restoreCachedPhpProjectFile(string $file, string $manifest): array
    {
        if (is_file($manifest)) {
            $this->restoreAnonymousManifest($file);
        }
        $sourceFiles = [];
        if ($this->incrementalTranslationUnitWasEmitted($file)) {
            $cppFile = $this->getCppFile($file);
            $this->registerGeneratedProjectSource($cppFile);
            $sourceFiles[] = $cppFile;
            array_push($sourceFiles, ...$this->getRegisteredSplitTranslationUnits($file));
        }
        $this->climate->darkGray('[cached] ' . $this->getRelativePath($file));
        return $sourceFiles;
    }

    /** @return list<string> */
    private function regeneratePhpProjectFile(
        string $file,
        string $manifest,
        string $legacyManifest,
    ): array {
        $statisticsBefore = $this->compilationStatistics->all();
        $this->currentAnonymousFiles = [];
        // Dirty dependencies require code generation, but byte-identical output
        // keeps its timestamp so native compilation can still hit its cache.
        $cppFile = $this->convertFile($file);
        $this->storeAnonymousManifest($manifest);
        if (is_file($legacyManifest)) {
            unlink($legacyManifest);
        }
        $this->recordIncrementalConversion(
            $file,
            $cppFile !== null,
            $this->compilationStatistics->delta($statisticsBefore),
        );
        if ($cppFile === null) {
            return [];
        }
        return [$cppFile, ...$this->getRegisteredSplitTranslationUnits($file)];
    }

    private function storeAnonymousManifest(string $manifest): void
    {
        if ($this->currentAnonymousFiles === []) {
            if (is_file($manifest)) {
                unlink($manifest);
            }
            return;
        }
        $this->writeFile($manifest, json_encode(
            array_values(array_unique($this->currentAnonymousFiles)),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return list<string> */
    private function getRegisteredSplitTranslationUnits(string $file): array
    {
        $parts = $this->getSplitTranslationUnits($file);
        foreach ($parts as $part) {
            $this->registerGeneratedProjectSource($part);
        }
        return $parts;
    }

    private function reportUnsupportedProjectFile(string $file, Unsupported $error): void
    {
        echo ' unsupported syntax: ' . $error->getMessage() . "\n";
        echo ' skip: ' . $file . "\n";
        if ($this->isSapiBuild()) {
            $this->registerUnsupportedSapiFile($file);
        } elseif (in_array($file, $this->embeddedPhpFiles, true)
            && !in_array($file, $this->embeddedOpcodeFiles, true)) {
            $this->embeddedOpcodeFiles[] = $file;
        }
    }

    private function registerUnsupportedSapiFile(string $file): void
    {
        if (!$this->isSapiBuild()) {
            return;
        }
        $file = realpath($file) ?: $file;
        if (!in_array($file, $this->embeddedPhpFiles, true)) {
            $this->embeddedPhpFiles[] = $file;
        }
        if (!in_array($file, $this->embeddedOpcodeFiles, true)) {
            $this->embeddedOpcodeFiles[] = $file;
        }
    }

    /** @return list<string> */
    private function finalizeProjectConversion(ProjectConversion $conversion): array
    {
        $files = $conversion->files();
        $this->finalizeIncrementalConversionMetadata($files);
        // Trait and interface inputs may emit no standalone translation unit,
        // but at least one supported input must participate in the project.
        if (!$conversion->hasValidSources()
            && !($this->isSapiBuild() && $this->embeddedOpcodeFiles !== [])) {
            $this->stop('No valid source file found');
        }

        if ($this->isBuildModeLib() && !$this->isWasiTarget()) {
            $this->genLibraryImportStub($files);
        }
        $this->genDeclarationHeaders($files);

        $sourceFiles = $conversion->sourceFiles();
        array_push($sourceFiles, ...$this->genClassArrayConstantLifecycleSources());
        $sourceFiles[] = $this->genExtension();
        if ($this->isSapiBuild()) {
            $sourceFiles[] = $this->genSapiInternalFunctions();
        }
        if ($this->isBuildModeEmbed() && !$this->isNanoMode()
            && ($this->isSapiBuild() || $this->embeddedFiles !== [] || $this->embeddedOpcodeFiles !== [])) {
            array_push($sourceFiles, ...$this->genEmbeddedOpcodeTable());
        }
        if ($this->isNanoMode() && $this->isBuildModeBin()) {
            $sourceFiles[] = $this->genNanoEntrypoint();
        }
        $this->getStableIdRegistry()->flush();
        $this->saveIncrementalCompilationState($files);
        return $sourceFiles;
    }
}
