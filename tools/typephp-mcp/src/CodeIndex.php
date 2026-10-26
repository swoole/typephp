<?php

/**
 * Live code index over examples/, tests/std/ and src/.
 *
 * Line-based grep with enclosing-declaration detection: the compiler's own
 * symbol model lives in src/ and is not importable here, so signatures are
 * recovered textually. Deterministic, no cache, bounded work.
 *
 * The dirs-aware methods (searchCode, getSymbol, getStubApi, getExample) accept
 * an optional list of absolute directories. When provided, the tool searches
 * THOSE directories instead of the bundled aot-compiler repo, so a user can
 * point it at their own TypePHP project plus its Composer-installed framework
 * (e.g. vendor/swoole/typephp). Package dirs (vendor/node_modules) are NOT
 * skipped in that mode, because the framework lives there.
 */
final class CodeIndex
{
    private const MAX_FILES = 3000;

    /**
     * Per-file scan cap. Files larger than this are scanned up to the cap and
     * reported as truncated - never silently skipped, so a symbol that exists
     * in a large file (e.g. src/Translator.php, ~400 KB) is still found and
     * the caller learns that coverage was partial.
     */
    private const SCAN_FILE_MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Global scan budget per call (bytes read across all files). Bounds the
     * cost of one search on huge trees; hitting it reports coverage limits
     * instead of scanning forever.
     */
    private const SCAN_BUDGET_BYTES = 64 * 1024 * 1024;

    private const MAX_DEPTH = 32;

    // Always ignored: VCS / build / cache junk, never source.
    private const ALWAYS_SKIP = ['.git', 'build', '.phpunit.cache', '.idea'];
    // Only ignored when scanning the bundled repo (default roots), not when the
    // caller passes explicit dirs (the framework ships inside vendor/).
    private const PKG_SKIP = ['vendor', 'node_modules'];

    private RepoRoot $root;

    /**
     * @var array<int, string> Additional search roots auto-detected from the
     *   client's open workspace (via MCP roots) and/or TYPEPHP_MCP_PROJECT_ROOT:
     *   TypePHP project directories plus their installed vendor/swoole/typephp.
     *   When no explicit dirs are given, these are MERGED into the default
     *   search set (with vendor/node_modules NOT skipped), so the tools also
     *   cover the user's own project. Explicit dirs always replace this.
     */
    private array $autoDirs = [];

    public function __construct(RepoRoot $root)
    {
        $this->root = $root;
    }

    public function setAutoDirs(array $dirs): void
    {
        $this->autoDirs = $dirs;
    }

    public function searchCode(string $query, int $limit, array $dirs = []): string
    {
        $query = trim($query);
        if ($query === '') {
            return 'Empty query.';
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $userDirs = $this->validateDirs($dirs);
        $userMode = $userDirs !== [];
        $roots = $userDirs;

        if ($userMode) {
            $skip = [];
            $keep = $userDirs;
        } else {
            $skip = [$this->root->examplesPath(), $this->root->stdlibTestsPath(), $this->root->srcPath()];
            $keep = $this->autoDirs;
        }

        $files = $this->collectFilesFrom($skip, $keep);
        $hits = [];
        $unscanned = [];
        $scannedBytes = 0;
        foreach ($files as $file) {
            if (count($hits) >= $limit) {
                break;
            }
            if ($scannedBytes >= self::SCAN_BUDGET_BYTES) {
                $unscanned[] = 'scan budget of ' . self::SCAN_BUDGET_BYTES . ' bytes exhausted';
                break;
            }
            $read = $this->readLines($file);
            $scannedBytes += $read['bytes'];
            if ($read['truncated']) {
                $unscanned[] = $this->displayPath($roots, $file, $userMode)
                    . ' (truncated at ' . self::SCAN_FILE_MAX_BYTES . ' bytes)';
            }
            $lines = $read['lines'];
            $perFile = 0;
            foreach ($lines as $index => $line) {
                if (!TextUtil::contains($line, $query)) {
                    continue;
                }
                $relative = $this->displayPath($roots, $file, $userMode);
                $declaration = $this->enclosingDeclaration($lines, (int) $index);
                $hits[] = $relative . ':' . ($index + 1) . ($declaration !== '' ? '  in ' . $declaration : '');
                $perFile++;
                if ($perFile >= 3 || count($hits) >= $limit) {
                    break;
                }
            }
        }
        if (count($hits) === 0) {
            return 'No code matches for: ' . $query . self::coverageNote($unscanned);
        }
        return TextUtil::clip(implode("\n", $hits) . self::coverageNote($unscanned), 4000);
    }

    public function getSymbol(string $name, array $dirs = []): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Invalid symbol name (expected [A-Za-z_][A-Za-z0-9_\\]* or Class::method): (empty)';
        }

        // Accept Class::method qualifiers and namespaced names (Foo\Bar).
        $method = null;
        $class = $name;
        $separator = strrpos($name, '::');
        if ($separator !== false) {
            $class = substr($name, 0, $separator);
            $method = substr($name, $separator + 2);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                return 'Invalid method name (expected [A-Za-z_][A-Za-z0-9_]*): ' . $method;
            }
        }
        $class = ltrim($class, '\\');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            return 'Invalid symbol name (expected [A-Za-z_][A-Za-z0-9_\\\\]* or Class::method): ' . $name;
        }
        $namespace = '';
        $shortName = $class;
        $lastSlash = strrpos($class, '\\');
        if ($lastSlash !== false) {
            $namespace = substr($class, 0, $lastSlash);
            $shortName = substr($class, $lastSlash + 1);
        }

        $userDirs = $this->validateDirs($dirs);
        $userMode = $userDirs !== [];
        $roots = $userDirs;

        if ($userMode) {
            $skip = [];
            $keep = $userDirs;
        } else {
            $skip = [$this->root->srcPath(), $this->root->examplesPath(), $this->root->stdlibTestsPath()];
            $keep = $this->autoDirs;
        }

        $files = $this->collectFilesFrom($skip, $keep);
        $hits = [];
        $unscanned = [];
        $scannedBytes = 0;
        foreach ($files as $file) {
            if (count($hits) >= 15) {
                break;
            }
            if ($scannedBytes >= self::SCAN_BUDGET_BYTES) {
                $unscanned[] = 'scan budget of ' . self::SCAN_BUDGET_BYTES . ' bytes exhausted';
                break;
            }
            $read = $this->readLines($file);
            $scannedBytes += $read['bytes'];
            if ($read['truncated']) {
                $unscanned[] = $this->displayPath($roots, $file, $userMode)
                    . ' (truncated at ' . self::SCAN_FILE_MAX_BYTES . ' bytes)';
            }
            $lines = $read['lines'];
            if ($method === null) {
                $hits = array_merge($hits, $this->findDeclaration($lines, $shortName, $namespace, $file, $roots, $userMode));
            } else {
                $hits = array_merge($hits, $this->findClassMethod($lines, $shortName, $method, $namespace, $file, $roots, $userMode));
            }
            if (count($hits) >= 15) {
                break;
            }
        }
        if (count($hits) === 0) {
            return 'No symbol found for: ' . $name
                . ($userMode ? ' (searched provided dirs)' : ' (searched src/, examples/, tests/std/)')
                . self::coverageNote($unscanned);
        }
        return TextUtil::clip(implode("\n", $hits) . self::coverageNote($unscanned), 4000);
    }

    /**
     * Find function/class/interface/trait/enum/const declarations of a short
     * name, honoring the file's namespace context.
     *
     * @param array<int, string> $lines
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function findDeclaration(array $lines, string $shortName, string $namespace, string $file, array $roots, bool $userMode): array
    {
        $pattern = '/^\s*(?:(?:public|private|protected)\s+)?(?:(?:static|final|abstract|readonly)\s+)*'
            . '(?:function|class|interface|trait|enum)\s+' . preg_quote($shortName, '/') . '\b/';
        $constPattern = '/^\s*const\s+' . preg_quote($shortName, '/') . '\b/';
        $namespaces = self::namespacesPerLine($lines);
        $hits = [];
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) !== 1 && preg_match($constPattern, $line) !== 1) {
                continue;
            }
            if ($namespace !== '' && $namespaces[$index] !== $namespace) {
                continue;
            }
            $signature = trim($line);
            if (strlen($signature) > 200) {
                $signature = TextUtil::clip($signature, 200);
            }
            $hits[] = $this->displayPath($roots, $file, $userMode) . ':' . ($index + 1) . '  ' . $signature;
            if (count($hits) >= 15) {
                break;
            }
        }
        return $hits;
    }

    /**
     * Find a method inside a class body: locate the class declaration (with
     * namespace context) and scan its brace-delimited body for the method.
     *
     * @param array<int, string> $lines
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function findClassMethod(array $lines, string $className, string $methodName, string $namespace, string $file, array $roots, bool $userMode): array
    {
        $classPattern = '/^\s*(?:(?:abstract|final|readonly)\s+)?class\s+' . preg_quote($className, '/') . '\b/';
        $methodPattern = '/^\s*(?:(?:public|private|protected)\s+)?(?:(?:static|final|abstract)\s+)*function\s+' . preg_quote($methodName, '/') . '\b/';
        $namespaces = self::namespacesPerLine($lines);
        $hits = [];
        foreach ($lines as $index => $line) {
            if (preg_match($classPattern, $line) !== 1) {
                continue;
            }
            if ($namespace !== '' && $namespaces[$index] !== $namespace) {
                continue;
            }
            $end = self::classBodyEnd($lines, $index);
            for ($i = $index + 1; $i < $end; $i++) {
                if (preg_match($methodPattern, $lines[$i]) !== 1) {
                    continue;
                }
                $signature = trim($lines[$i]);
                if (strlen($signature) > 200) {
                    $signature = TextUtil::clip($signature, 200);
                }
                $hits[] = $this->displayPath($roots, $file, $userMode) . ':' . ($i + 1) . '  ' . $signature
                    . '  (in class ' . $className . ')';
                break;
            }
            if (count($hits) >= 15) {
                break;
            }
        }
        return $hits;
    }

    /**
     * Index the namespace context of every line: the file-level namespace
     * (`namespace X;`) applies until the next namespace declaration; braced
     * blocks (`namespace X { ... }`) apply only inside their braces. Textual
     * heuristic - good enough for the common single-namespace layout.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function namespacesPerLine(array $lines): array
    {
        $result = array_fill(0, count($lines), '');
        $current = '';
        $braceDepth = 0;
        $blockDepth = -1;
        foreach ($lines as $index => $line) {
            $result[$index] = $current;
            if (preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/', $line, $matches) === 1) {
                $current = $matches[1];
                $blockDepth = substr(rtrim($line), -1) === '{' ? $braceDepth : -1;
            }
            $braceDepth += substr_count($line, '{') - substr_count($line, '}');
            if ($blockDepth >= 0 && $braceDepth <= $blockDepth) {
                $current = '';
                $blockDepth = -1;
            }
        }
        return $result;
    }

    /**
     * Index of the line where the class body opened at $classIndex ends.
     *
     * Naive brace counting is not robust here: braces inside strings and
     * comments unbalance the count (src/Translator.php closes "early" around
     * line 2090 by that measure). Instead, the body ends at the next
     * top-level declaration at or before the class's own indentation -
     * methods and properties are always more indented, and the class's
     * closing brace alone does not match a declaration pattern.
     *
     * @param array<int, string> $lines
     */
    private static function classBodyEnd(array $lines, int $classIndex): int
    {
        $classLine = $lines[$classIndex];
        $indent = strlen($classLine) - strlen(ltrim($classLine));
        $count = count($lines);
        for ($i = $classIndex + 1; $i < $count; $i++) {
            $trimmed = ltrim($lines[$i]);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === '/') {
                continue;
            }
            $lineIndent = strlen($lines[$i]) - strlen($trimmed);
            if ($lineIndent > $indent) {
                continue;
            }
            if (preg_match('/^(?:(?:abstract|final|readonly)\s+)?(?:class|interface|trait|enum|function)\s|^namespace\s|^use\s|^const\s|^declare\s/', $trimmed) === 1) {
                return $i;
            }
        }
        return $count;
    }

    /**
     * @param array<int, string> $unscanned
     */
    private static function coverageNote(array $unscanned): string
    {
        if ($unscanned === []) {
            return '';
        }
        return "\nCoverage: " . count($unscanned) . ' item(s) not fully scanned: '
            . implode('; ', $unscanned);
    }

    public function getExample(string $name, array $dirs = []): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            return 'Invalid example name (expected [A-Za-z0-9._-]): ' . $name;
        }
        $userDirs = $this->validateDirs($dirs);
        if ($userDirs === []) {
            $filePath = $this->root->examplesPath() . '/' . $name . '.php';
            if (is_file($filePath)) {
                return $this->renderFile($filePath, 4000);
            }
            $dirPath = $this->root->examplesPath() . '/' . $name;
            if (is_dir($dirPath)) {
                return $this->renderExampleDir($name, $dirPath);
            }
            foreach ($this->autoDirs as $base) {
                $fp = $base . '/' . $name . '.php';
                if (is_file($fp)) {
                    return $this->renderFile($fp, 4000, $base);
                }
                $dp = $base . '/' . $name;
                if (is_dir($dp)) {
                    return $this->renderExampleDir($name, $dp, $base);
                }
            }
            return 'Example not found: ' . $name . "\nUse list_stdlib or search_docs to discover names; examples live in examples/.";
        }
        foreach ($userDirs as $base) {
            $filePath = $base . '/' . $name . '.php';
            if (is_file($filePath)) {
                return $this->renderFile($filePath, 4000, $base);
            }
            $dirPath = $base . '/' . $name;
            if (is_dir($dirPath)) {
                return $this->renderExampleDir($name, $dirPath, $base);
            }
        }
        return 'Example not found in provided dirs: ' . $name;
    }

    public function getStubApi(string $path, array $dirs = []): string
    {
        $userDirs = $this->validateDirs($dirs);
        $userMode = $userDirs !== [];
        if ($path === '') {
            $lines = [];
            if ($userMode) {
                foreach ($this->collectStubFiles($userDirs, false) as $file) {
                    $lines[] = $this->displayPath($userDirs, $file, true);
                }
            } else {
                foreach ($this->collectStubFiles([$this->root->path()], true) as $file) {
                    $lines[] = $this->displayPath([], $file, false);
                }
                foreach ($this->autoDirs as $dir) {
                    $visited = [];
                    foreach ($this->collectDir($dir, false, 0, $visited) as $file) {
                        if (str_ends_with($file, '.stub.php')) {
                            $lines[] = $this->displayPath([], $file, false);
                        }
                    }
                }
            }
            if (count($lines) === 0) {
                return 'No .stub.php files found.';
            }
            return TextUtil::clip(implode("\n", $lines), 4000);
        }
        if ($userMode) {
            $resolved = $this->resolveInsideAny($userDirs, $path);
        } else {
            $resolved = $this->root->resolveInside($path);
            if ($resolved === null || !is_file($resolved)) {
                // Default mode also resolves paths against auto-detected
                // project dirs, so a project stub can be read by relative path.
                $resolved = $this->resolveInsideAny($this->autoDirs, $path);
            }
        }
        if ($resolved === null || !is_file($resolved)) {
            return 'Stub file not found'
                . ($userMode ? ' inside the provided dirs' : ' inside the repository or detected project')
                . ': ' . $path;
        }
        if (!str_ends_with($resolved, '.stub.php')) {
            return 'Not a .stub.php file: ' . $path;
        }
        return $this->renderFile($resolved, 4000);
    }

    public function listStdlib(string $category): string
    {
        $base = $this->root->stdlibTestsPath();
        if (!is_dir($base)) {
            return 'tests/std not found.';
        }
        if ($category === '') {
            $entries = scandir($base);
            if (!is_array($entries)) {
                return 'tests/std is not readable.';
            }
            $lines = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) {
                    continue;
                }
                $tests = $this->phptFiles($base . '/' . $entry);
                $lines[] = $entry . ' (' . count($tests) . ' tests)';
            }
            sort($lines);
            $lines[] = '';
            $lines[] = 'std:: surface docs: docs/zh-cn/STD_CONTAINERS.md, HIGH_PRECISION_TYPES.md,'
                . ' COMPILE_TIME_FUNCTIONS.md, UNIVERSAL_METHODS.md';
            return implode("\n", $lines);
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $category) !== 1) {
            return 'Invalid category name: ' . $category;
        }
        $dir = $base . '/' . $category;
        if (!is_dir($dir)) {
            return 'Unknown stdlib category: ' . $category . "\nCall list_stdlib without a category to list them.";
        }
        $lines = [];
        foreach ($this->phptFiles($dir) as $test) {
            $title = $this->phptTitle($test);
            $lines[] = basename($test) . ($title !== '' ? ' - ' . $title : '');
            if (count($lines) >= 80) {
                break;
            }
        }
        if (count($lines) === 0) {
            return 'No tests in category: ' . $category;
        }
        return TextUtil::clip(implode("\n", $lines), 4000);
    }

    /**
     * Validate and normalize a caller-supplied dirs list.
     *
     * Returns [] when no dirs were provided (caller should use default roots).
     * Throws when dirs were provided but none resolve to an existing directory.
     * Drops a directory that is contained within another listed directory to
     * avoid redundant traversal.
     *
     * @param array<int|string, mixed> $dirs
     * @return array<int, string>
     */
    private function validateDirs(array $dirs): array
    {
        if (!is_array($dirs) || $dirs === []) {
            return [];
        }
        $valid = [];
        foreach ($dirs as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $real = realpath($entry);
            if (is_string($real) && is_dir($real)) {
                // Normalize to forward slashes so displayPath()/resolveInsideAny()
                // prefix checks are portable across Windows and Linux.
                $valid[] = str_replace('\\', '/', $real);
            }
        }
        if ($valid === []) {
            throw new RuntimeException('dirs: none of the provided paths are existing directories.');
        }
        sort($valid);
        $kept = [];
        foreach ($valid as $candidate) {
            $contained = false;
            foreach ($kept as $already) {
                if ($candidate === $already || str_starts_with($candidate . '/', $already . '/')) {
                    $contained = true;
                    break;
                }
            }
            if (!$contained) {
                $kept[] = $candidate;
            }
        }
        return $kept;
    }

    /**
     * Display path for a hit: relative to the matching root in user mode,
     * otherwise relative to the bundled repo root (unchanged default output).
     *
     * @param array<int, string> $roots
     */
    private function displayPath(array $roots, string $file, bool $userMode): string
    {
        if ($userMode) {
            foreach ($roots as $root) {
                $prefix = $root . '/';
                if (str_starts_with($file, $prefix)) {
                    return substr($file, strlen($prefix));
                }
            }
        } else {
            // Default mode: also strip an auto-detected project root so user
            // project files show a readable relative path, not an absolute one.
            foreach ($this->autoDirs as $root) {
                $prefix = $root . '/';
                if (str_starts_with($file, $prefix)) {
                    return substr($file, strlen($prefix));
                }
            }
        }
        return $this->root->relativeFrom($file);
    }

    /**
     * Resolve a repo-relative path against any of the given roots, rejecting
     * traversal and paths escaping every root.
     *
     * @param array<int, string> $roots
     */
    private function resolveInsideAny(array $roots, string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        foreach ($roots as $root) {
            // realpath the root so the prefix comparison is case/separator
            // consistent with the candidate (Windows canonicalizes d: -> D:).
            $rootReal = realpath($root);
            if (!is_string($rootReal)) {
                continue;
            }
            $rootReal = str_replace('\\', '/', $rootReal);
            $candidate = $rootReal . '/' . ltrim($relative, '/');
            $real = realpath($candidate);
            if (is_string($real) && is_file($real)) {
                $real = str_replace('\\', '/', $real);
                if (str_starts_with($real, $rootReal . '/')) {
                    return $real;
                }
            }
        }
        return null;
    }

    /**
     * Collect files from two root groups: $skip (official repo, vendor skipped)
     * and $keep (user projects, vendor NOT skipped). Used so the auto-merged
     * project roots are scanned in full while the bundled repo still ignores
     * its own vendor/.
     *
     * @param array<int, string> $skip
     * @param array<int, string> $keep
     * @return array<int, string>
     */
    private function collectFilesFrom(array $skip, array $keep): array
    {
        $files = [];
        $visited = [];
        foreach ($skip as $dir) {
            foreach ($this->collectDir($dir, true, 0, $visited) as $file) {
                $files[] = $file;
            }
            if (count($files) >= self::MAX_FILES) {
                break;
            }
        }
        foreach ($keep as $dir) {
            foreach ($this->collectDir($dir, false, 0, $visited) as $file) {
                $files[] = $file;
            }
            if (count($files) >= self::MAX_FILES) {
                break;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function collectDir(string $dir, bool $skipPackages, int $depth, array $visited): array
    {
        $result = [];
        if ($depth > self::MAX_DEPTH || !is_dir($dir)) {
            return $result;
        }
        // Canonicalize for dedup: symlink loops and overlapping roots must not
        // be traversed twice (the returned paths keep their original spelling
        // so displayPath() prefix matching is unchanged).
        $real = realpath($dir);
        if (!is_string($real) || $real === '') {
            return $result;
        }
        $key = strtolower(str_replace('\\', '/', $real));
        if (isset($visited[$key])) {
            return $result;
        }
        $visited[$key] = true;
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return $result;
        }
        foreach ($entries as $entry) {
            if (count($result) >= self::MAX_FILES) {
                break;
            }
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, self::ALWAYS_SKIP, true)) {
                continue;
            }
            if ($skipPackages && in_array($entry, self::PKG_SKIP, true)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $nested = $this->collectDir($path, $skipPackages, $depth + 1, $visited);
                foreach ($nested as $file) {
                    $result[] = $file;
                }
            } elseif (str_ends_with($entry, '.php')) {
                $result[] = $path;
            }
        }
        return $result;
    }

    /**
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function collectStubFiles(array $roots, bool $skipPackages): array
    {
        $files = [];
        $visited = [];
        foreach ($roots as $root) {
            foreach ($this->collectDir($root, $skipPackages, 0, $visited) as $file) {
                if (str_ends_with($file, '.stub.php')) {
                    $files[] = $file;
                }
            }
        }
        return $files;
    }

    /**
     * Read a file line by line in 1 MiB chunks. Files larger than
     * SCAN_FILE_MAX_BYTES are scanned up to the cap and flagged truncated so
     * the caller can report coverage instead of silently pretending the
     * file does not exist (the previous 256 KiB cutoff made get_symbol miss
     * real symbols in src/Translator.php).
     *
     * @return array{lines: array<int, string>, bytes: int, truncated: bool}
     */
    private function readLines(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return ['lines' => [], 'bytes' => 0, 'truncated' => false];
        }
        $lines = [];
        $buffer = '';
        $total = 0;
        $truncated = false;
        while (!feof($handle)) {
            if ($total >= self::SCAN_FILE_MAX_BYTES) {
                $truncated = true;
                break;
            }
            $chunk = fread($handle, min(1048576, self::SCAN_FILE_MAX_BYTES - $total));
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $total += strlen($chunk);
            $buffer .= $chunk;
            $position = strpos($buffer, "\n");
            while ($position !== false) {
                $lines[] = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);
                $position = strpos($buffer, "\n");
            }
        }
        fclose($handle);
        if ($buffer !== '') {
            $lines[] = $buffer;
        }
        return ['lines' => $lines, 'bytes' => $total, 'truncated' => $truncated];
    }

    /**
     * @param array<int, string> $lines
     */
    private function enclosingDeclaration(array $lines, int $index): string
    {
        for ($i = $index; $i >= 0; $i--) {
            if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)?(?:class|interface|trait|enum|function)\s/', $lines[$i]) === 1) {
                return trim($lines[$i]);
            }
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    private function phptFiles(string $dir): array
    {
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return [];
        }
        $files = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.phpt')) {
                $files[] = $dir . '/' . $entry;
            }
        }
        sort($files);
        return $files;
    }

    private function phptTitle(string $path): string
    {
        $lines = $this->readLines($path)['lines'];
        if (count($lines) < 2) {
            return '';
        }
        if (trim($lines[0]) !== '--TEST--') {
            return '';
        }
        return trim($lines[1]);
    }

    private function renderFile(string $path, int $maxBytes, string $base = ''): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            return 'Cannot read: ' . $path;
        }
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $rel = substr($path, strlen($base) + 1);
        } else {
            $rel = $this->root->relativeFrom($path);
        }
        $header = '# ' . $rel . ' (' . strlen($content) . " bytes)\n";
        return TextUtil::clip($header . $content, $maxBytes);
    }

    private function renderExampleDir(string $name, string $dir, string $base = ''): string
    {
        $files = $this->collectDir($dir, $base !== '', 0, []);
        $relOf = function (string $f) use ($base): string {
            if ($base !== '' && str_starts_with($f, $base . '/')) {
                return substr($f, strlen($base) + 1);
            }
            return $this->root->relativeFrom($f);
        };
        $headerDir = ($base !== '' && str_starts_with($dir, $base . '/'))
            ? substr($dir, strlen($base) + 1)
            : $this->root->relativeFrom($dir);
        $lines = ['# ' . $headerDir . '/ (' . count($files) . ' files)'];
        foreach ($files as $file) {
            $lines[] = '  ' . $relOf($file);
            if (count($lines) >= 60) {
                break;
            }
        }
        foreach (['README.md', 'main.php', 'project.yml'] as $entry) {
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if (!is_string($content)) {
                continue;
            }
            $lines[] = '';
            $lines[] = '--- ' . $entry . ' ---';
            $lines[] = TextUtil::clip($content, 1500);
        }
        return TextUtil::clip(implode("\n", $lines), 4000);
    }
}
