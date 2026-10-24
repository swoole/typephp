#!/usr/bin/env php
<?php

$root = dirname(__DIR__, 3);
require $root . '/bin/bootstrap.php';
// A test snapshot may share vendor/ with another checkout. Always compile
// using the snapshot's TypePhp sources rather than Composer's owning tree.
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'TypePhp\\')) {
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, true, true);
require $root . '/src/polyfills.php';
require $root . '/src/gen_stub.php';
require $root . '/src/compiler.php';
runCompiler($argc, $argv, TypePhp\Build\CompilerRuntime::source($root, $argv[0]));
