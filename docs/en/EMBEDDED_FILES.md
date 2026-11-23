# Embedding PHP Dependencies in an Executable

`embedded-files` packages Composer dependencies, PHP fallback files, and
runtime resources into a TypePHP executable. It addresses these deployment
problems:

- a third-party library cannot be compiled by TypePHP AOT;
- Composer autoload must still load that library lazily;
- the release host should not run `composer install` or carry a separate
  `vendor` directory;
- read-only configuration, templates, certificates, or similar resources must
  ship with the application.

During the build, TypePHP converts PHP files that were not compiled through
`sources` into OPcache opcodes and stores every selected file in the executable.
At runtime, `require`, `require_once`, and Composer autoload load PHP scripts
from the in-memory opcode table. Other read-only files are served by the
in-memory file table.

## Recommended setup

Keep `embedded-files` disabled for development so builds use the workspace
`vendor` directory directly. Reuse the common configuration through YAML
`include`, and enable embedding only for release builds.

`project.yml`:

```yaml
name: myapp
mode: bin
build-dir: build

sources:
  - app.php
  - src

ignore:
  - src/legacy
```

`project-release.yml`:

```yaml
include: project.yml
optimize: 2

embedded-files:
  - vendor
  - resources/config
```

Build the release:

```bash
composer install --no-dev --classmap-authoritative
tpc project-release.yml
```

The project-root `app.php` starts Composer autoload in the usual way:

```php
<?php

function main(): void
{
    require_once __DIR__ . '/vendor/autoload.php';

    $application = new App\Application();
    $application->run();
}
```

The resulting executable can run without the project source tree or a disk
`vendor` directory:

```bash
mkdir -p /tmp/myapp-release
cp myapp /tmp/myapp-release/
cd /tmp/myapp-release
./myapp
```

Here, standalone means that PHP CLI, Composer installation, and PHP project
files are no longer needed. The executable still has the native dependencies
selected by its build, such as `libphp`, PHPX, and other shared libraries.
Ship those dependencies in the release package or use a supported static-link
configuration.

## Relationship between `sources`, `ignore`, and `embedded-files`

The three settings have separate responsibilities:

| Setting | Purpose |
|---|---|
| `sources` | Select PHP/C/C++ files translated to C++ and native machine code. |
| `ignore` | Remove files from the `sources` scan result. |
| `embedded-files` | Package files verbatim and generate opcodes for PHP files that were not successfully compiled by AOT. |

A file may be selected by both `sources` and `embedded-files`:

- a successfully AOT-compiled PHP file uses its native implementation and
  does not receive a duplicate opcode blob;
- a PHP file excluded by `ignore`, or rejected because it uses unsupported AOT
  syntax, enters the opcode table;
- `ignore` does not remove files from `embedded-files`;
- `.stub.php` files remain raw API declarations and never receive executable
  opcodes.

This lets the application enter `sources` while the complete `vendor` tree is
embedded. AOT-compatible code continues to run as machine code, and ZendVM
executes the remaining dependencies lazily.

```yaml
sources:
  - src
  - vendor/acme/optimized-package/src

embedded-files:
  - vendor
```

There is no separate exclusion list for `embedded-files`. List the required
subdirectories or files instead of their common parent when some content must
not be packaged.

## Configuration syntax

The setting is explicitly enabled in a YAML project and must be a list. Each
entry may be a file, a directory, or a conditional path. Relative paths use the
outermost project YAML directory as their base.

```yaml
embedded-files:
  - vendor
  - resources/app.json
  - path: resources/windows
    if: PHP_OS_FAMILY == "Windows"
  - path: resources/php85
    if: PHP_VERSION_ID >= 80500
```

Directories are scanned recursively and all regular files are archived. JSON,
YAML, templates, and other non-PHP resources retain their original bytes.

Read embedded resources through their original paths:

```php
$config = file_get_contents(__DIR__ . '/resources/app.json');
```

Embedded files are read-only. Logs, caches, uploads, and databases belong in a
separate runtime data directory. Do not rely on `glob()` or directory iteration
over an embedded directory; read resources through known file paths.

## Build requirements

`embedded-files` is available only for a regular `mode: bin` build. It is not
available for `ext`, `lib`, Nano, WASI, iOS, or Android targets.

The build host needs:

1. `php` or `php.exe` matching the target `libphp`;
2. Zend OPcache loadable by that CLI;
3. the same full PHP version, ZTS/NTS mode, Debug mode, and integer width as
   the final runtime.

Check the build PHP before compiling:

```bash
php -r 'var_dump(PHP_VERSION, PHP_ZTS, extension_loaded("Zend OPcache"), function_exists("opcache_compile_file"));'
```

TypePHP probes OPcache with `-n` and tries the standard extension locations.
A standalone `tpc` without the matching PHP CLI and OPcache cannot build an
`embedded-files` project.

Opcode generation uses an isolated build-only INI that hides internal function
declarations, keeping the OPcache serializer and non-disableable language
constructs available. This keeps embedded calls bound by name instead
of storing process-local frameless-handler indices, which may differ between
CLI and embed SAPIs even with the same PHP version. The generated program keeps
its normal internal functions; AOT C++ code generation is unchanged. Existing
vendor and anonymous-class opcode caches from the older policy are rebuilt.

OPcache is only the build-time serializer. The generated program does not need:

- the OPcache extension;
- PHP CLI;
- a Composer installation;
- disk copies of `vendor/autoload.php` or any other embedded file.

Embedded opcodes are tied to the complete PHP version. If the runtime PHP does
not match the PHP that generated the opcodes, the program reports the mismatch
at startup instead of executing incompatible bytecode.

## Build output and logs

A first build prints messages similar to:

```text
embedded-files: found 3012 files (2886 PHP)
Generating embedded opcodes for 2886 PHP files
Vendor opcode cache: 0 reused, 2886 to generate
Packed 3012 files and 2886 opcode blobs
```

A later build can reuse Composer vendor opcodes:

```text
Vendor opcode cache: 2886 reused, 0 to generate
Packed 3012 files and 2886 opcode blobs
```

Opcode blobs, the archive, and object files live under `build-dir/cache`.
Generated `embedded-opcodes-<name>.cc` contains only readable index code; large
file contents are not expanded into C++ arrays.

A PHP file that OPcache cannot compile and is not expected to be required is
reported as `Skipping non-executable embedded PHP file`; its original bytes are
still archived. If the application can load that file, fix the build error
instead of ignoring the message.

## Vendor opcode cache

Persistent opcode caching is enabled only for a directory named `vendor` whose
root contains `autoload.php`. Its key includes:

- the vendor root path and directory mtime;
- PHP CLI and OPcache binary signatures;
- PHP version, ZTS/Debug mode, and integer width.

Other `embedded-files` directories regenerate their opcodes on every build
because the compiler has no reliable invalidation boundary for them.

Editing an existing nested file does not necessarily change the vendor root
mtime. Use `--force` when this happens:

```bash
tpc project-release.yml --force
```

Composer `install` or `update` normally rebuilds root entries and autoload
files, but a release build should still use `--force` after manual vendor
changes or whenever a cached result is questionable.

## Performance and release guidance

Embedding a large vendor tree increases the executable size, linker input, and
process startup cost. That fixed cost is visible in PHPT, unit tests, and local
workflows that start many short-lived processes. Recommended practice:

- use `project.yml` without `embedded-files` for development and tests;
- enable it only in `project-release.yml`;
- keep the same `build-dir` to reuse opcode, archive, and object caches;
- reserve `--force` for vendor changes that did not invalidate the cache.

Composer autoload remains lazy. ZendVM executes a vendor opcode only when code
first requests the corresponding class; embedding the complete vendor tree
does not execute every PHP file during process startup.

## Frequently asked questions

### Why is Composer autoload still required?

`embedded-files` changes where files are stored and how PHP scripts are
compiled. It does not replace Composer's class map and PSR-4 rules. Require the
same `vendor/autoload.php`; the autoloader and later class files are loaded from
the executable's memory tables.

### Can only `vendor/autoload.php` be embedded?

No. The autoload file contains loading rules, while the actual package files
must also be present. Embed the complete `vendor` directory in normal projects.

### Does the runtime host need OPcache?

No. PHPX provides the decoding and execution integration linked into the
program. The OPcache extension is needed only to generate opcodes at build time.

### Does this protect PHP source code?

Do not treat it as encryption or source protection. The archive includes the
original bytes of selected files to support ordinary file reads. Someone who
can analyze the executable may still extract them.

### What if vendor code depends on another PHP extension?

`embedded-files` packages PHP files; it does not embed implementations such as
`curl` or `pdo_mysql`. Declare them through `ext-deps` or
`extension-dependencies` and provide them in the target runtime.

```yaml
ext-deps:
  - curl
  - pdo_mysql
```
