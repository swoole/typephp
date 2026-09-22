<?php
require dirname(__DIR__, 2) . '/phpunit/bootstrap.php';
if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php run.php <existing temporary project directory>\n");
    exit(1);
}
$root = realpath($argv[1]);
$files = [];
for ($i = 0; $i < 300; ++$i) {
    $source = "<?php\n";
    for ($j = 0; $j < 20; ++$j) {
        $source .= "function f_{$i}_{$j}(int \$value = 1): int { return \$value; }\n";
    }
    if ($i === 0) {
        $source .= "function main(): void {}\n";
    }
    $file = "$root/file_$i.php";
    file_put_contents($file, $source);
    $files[] = $file;
}
$translator = $compiler = TypePhp\CompilerTest::create($root);
$compiler->setTargetName('declaration_benchmark');
(new ReflectionMethod($compiler, 'setBuildDir'))->invoke($compiler, "$root/build");
$compiler->addFiles($files);
foreach ($files as $file) {
    $compiler->prepareFile($file);
}
$compiler->convert($files);
$generate = new ReflectionMethod($compiler, 'genDeclarationHeaders');
$samples = [];
for ($i = 0; $i < 6; ++$i) {
    $start = hrtime(true);
    $generate->invoke($compiler, $files);
    if ($i > 0) {
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
}
$hashes = [];
foreach (glob("$root/build/include/*decl.h") as $file) {
    $hashes[basename($file)] = hash_file('sha256', $file);
}
ksort($hashes);
echo "BENCHMARK_RESULT=" . json_encode(['ms' => $samples, 'headers' => count($hashes), 'sha256' => hash('sha256', json_encode($hashes))]) . "\n";
