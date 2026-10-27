<?php

// Prepare a same-version, ABI-compatible embed runtime for the Linux ASan job.
// The packaged PHP 8.4 loader passes RTLD_DEEPBIND to dlopen(), which ASan
// rejects before any test code runs. Only that loader flag is patched.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('This runtime preparation entry is for dedicated CI runners only');
}
require dirname(__DIR__, 2) . '/bin/bootstrap.php';

use TypePhp\Build\OfficialPhpSource;
use TypePhp\Build\SapiExtensionConfiguration;
use TypePhp\Installer\PhpBuildConfiguration;

$directory = $argv[1];
$host = json_decode(file_get_contents($directory . '/host.json'), true, flags: JSON_THROW_ON_ERROR);
$source = (new OfficialPhpSource(
    $directory . '/download',
    static fn(string $line) => print($line . "\n"),
))->prepare($host['version']);
$options = SapiExtensionConfiguration::configureOptions($source, $host['extensions']);
// Retain core feature switches which may affect public Zend layouts.
foreach (PhpBuildConfiguration::parsePhpConfigOptions(file_get_contents($directory . '/host-configure.txt')) as $option) {
    if (preg_match('/^--(?:enable|disable)-(?:zend-signals|zend-max-execution-timers|dtrace)(?:=|$)/', $option)) {
        $options[] = $option;
    }
}
$options = PhpBuildConfiguration::derivePhpBuilder(
    $options, $directory . '/install', ['cli', 'embed'], (bool) $host['zts'], (bool) $host['debug'],
);
$options = array_map(static fn(string $option): string =>
    $option === '--enable-embed=static' ? '--enable-embed=shared' : $option, $options);
file_put_contents($directory . '/source-path', $source);
file_put_contents($directory . '/configure-args', implode("\0", $options) . "\0");
