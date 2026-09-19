[English](README.md) | [简体中文](README-CN.md)

<div align="center">

# TypePHP

**A native AOT compiler for PHP**

Compile PHP source code into native machine code ahead of time — producing
native executables, PHP extensions, and shared libraries — while keeping
the PHP syntax you already know.

[![Linux x64](https://github.com/swoole/typephp/actions/workflows/linux-x64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/linux-x64.yml)
[![Linux ARM64](https://github.com/swoole/typephp/actions/workflows/linux-arm64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/linux-arm64.yml)
[![macOS ARM64](https://github.com/swoole/typephp/actions/workflows/macos-arm64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/macos-arm64.yml)
[![Windows x64](https://github.com/swoole/typephp/actions/workflows/windows-build.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/windows-build.yml)
[![PHP 8.4–8.5](https://img.shields.io/badge/PHP-8.4--8.5-777bb4.svg)](https://www.php.net/)
[![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-blue.svg)](LICENSE)

</div>

---

## What is TypePHP?

TypePHP is an Ahead-Of-Time (AOT) compiler that translates PHP source code into
C++ and then into native machine code. Unlike a bytecode cache or a VM, it does
not interpret opcodes at runtime: it generates optimized native binaries that
run directly on the CPU.

It keeps familiar PHP syntax and adds compile-time type information, so the
compiler can emit fast, statically-typed C++ for hot paths. Dynamic PHP values,
internal functions, reflection, and object metadata continue to interoperate
with the Zend runtime through PHPX; user functions are not executed as Zend
opcodes after they have been compiled.

TypePHP is **written entirely in PHP** and is **fully self-hosting**: the `tpc`
compiler binary is built by compiling the compiler's own PHP source code with
TypePHP. The bootstrap chain is pure PHP — no C or C++ glue in the compiler
itself.

TypePHP is under active development. It intentionally supports a defined,
testable subset of PHP rather than claiming drop-in compatibility with every
dynamic PHP program. Read [Compatibility model](#compatibility-model) and the
[incompatible-feature list](docs/en/INCOMPATIBLE_PHP_FEATURES.md) before adopting
it for an existing application.

## How it works

```text
PHP source + .stub.php declarations + optional C/C++ sources
                         │
                         ▼
        parse, validate, and collect declarations
                         │
                         ▼
       lower function bodies and constants to C++17
                         │
                         ▼
       native compiler + reusable object/PCH caches
                         │
                         ▼
 executable | PHP extension | shared library | WASI component
```

The prepare phase builds the complete symbol model without allocating runtime
cache IDs. Constants and declaration defaults retain their AST until the
convert phase, where they are lowered after all project symbols are known.
This two-phase design keeps multi-file and self-hosted builds deterministic.

## Features

- **Self-hosting, written in PHP** — the TypePHP compiler is implemented
  entirely in PHP and bootstraps itself: `tpc` compiles the compiler's own
  source into a native binary.
- **True AOT compilation** — PHP is lowered to C++17, then to native machine
  code. No interpreter, no opcode cache, no JIT warm-up.
- **Three native build modes** — build a native `bin` executable, a loadable
  PHP `ext` extension, or a reusable `lib` shared library from the same codebase.
- **Native type system** — `int`, `float`, and `bool` map directly to C++
  scalar types (`int64_t`, `double`, `bool`) for orders-of-magnitude speedups
  on numeric code.
- **High-precision numerics** — `bigInt` (GMP), `decimal` (libmpdec), and
  `bigFloat` (MPFR), with typed operators and method APIs.
- **Strongly-typed containers** — `std::array`, `std::vector`, `std::map`, and
  `std::orderedMap` with compile-time element types; up to **10×** faster than
  PHP arrays and on par with C++ `std::vector`.
- **Universal methods** — call methods directly on primitives
  (`$s->upper()`, `$arr->contains()`, `$big->mul(2)`); statically-known calls
  are resolved directly at compile time.
- **Mixed C++ / PHP** — call C++ functions from PHP (and vice versa) for
  performance-critical kernels.
- **Compile-time functions & keywords** — `std::any()`, `std::object()`,
  `std::ref()`, `std::expected()`, `std::unexpected()`, plus `toObject()`,
  `toInt()`, `toString()`, `toArray()` and friends.
- **Compile-time safety** — `#[Immutable]` read-only contracts and `StdList` / `StdDict`
  array-shape metadata, checked at compile time with zero runtime cost.
- **Compile-time code generation** — `#[Getter]`, `#[Setter]`, `#[With]`,
  `#[Constructor]`, `#[Printer]`, and `#[Arrayable]` generate type-safe methods
  from property declarations.
- **Modern PHP support** — PHP 8.4 property hooks, asymmetric visibility,
  PHP 8.5 `clone()`-with, and `(void)` discard expressions.
- **Cross-platform, mobile native & WASM** — Linux, Windows, and macOS targets
  for x64 and ARM64; native Android/iOS application development with the
  Android NDK and iOS SDK; plus WASI 0.2 and browser (Jco) output.
- **Python bridge** — generate IDE helpers for Python modules and convert
  Python scripts to TypePHP.

## Why TypePHP?

| | TypePHP AOT | Opcode cache (OPcache) | JIT (PHP 8+) |
|---|---|---|---|
| Compilation target | Native machine code | Bytecode | Machine code (trace) |
| Startup / warm-up | None (already compiled) | Per-process warm-up | JIT warm-up |
| Type-driven optimization | Compile-time, full-program | None | Limited, trace-based |
| Native executable output | Yes | No | No |
| Source code protection | Compiled to machine code | Bytecode (reversible) | Bytecode (reversible) |
| Deterministic performance | Yes | No | No |

**Strengths over plain PHP:**

- **Near-native performance.** Numeric and container-heavy hot paths compile
  down to the same machine code a C++ program would produce. See the
  [benchmark](#benchmark) below.
- **Source protection.** Your source is compiled away — shipped artifacts are
  native binaries, not readable PHP files.
- **Native process entry.** Binary mode starts directly from a native
  executable and does not require the PHP CLI or a separate interpreter
  process. The executable still embeds/links PHPX, `libphp`, and any configured
  native libraries, which must be available in the deployment package.
- **Strong scalar types by default.** Inferred `int`, `float`, and `bool`
  locals use native C++ storage. Use `std::any()` for an individual dynamic
  value, or `use varint_types` when a file requires PHP integer widening.
- **Always-strict calls.** TypePHP never enables PHP's weak scalar coercion;
  `declare(strict_types=1)` is unnecessary.
- **Zend ecosystem interop.** Extension mode loads as a standard PHP extension,
  and projects can call supported internal functions and require other Zend
  extensions explicitly.

## Requirements

- **PHP 8.4 – 8.5** CLI, development headers, and `php-config`
- The matching **PHP embed library** (`libphp.so` or `libphp.dylib`) for binary/shared-library
  builds on Unix-like systems
- **GCC 9+** (or Clang) with **C++17**
- **CMake 3.24+**
- **Composer 2**
- High-precision math libraries: **GMP**, **MPFR** (libmpdec is bundled with PHPX)

```shell
# Ubuntu/Debian
sudo apt install build-essential cmake pkg-config libgmp-dev libmpfr-dev

# RHEL/CentOS/Fedora
sudo dnf install gcc gcc-c++ cmake pkgconf-pkg-config gmp-devel mpfr-devel

# Arch Linux
sudo pacman -S base-devel cmake pkgconf gmp mpfr
```

> GMP powers `bigInt` and MPFR powers `bigFloat`. The `decimal` type is backed
> by libmpdec, which is bundled with PHPX — no separate install required.

Linux x64 is the primary development and full-test CI platform. The compiler
also has Windows, macOS, ARM64, Android `arm64-v8a`, iPhoneOS `arm64`, and WASI
backends; availability of PHP embed, platform SDKs, toolchains, and third-party
libraries still determines which target can be built on a given host. Mobile
apps can implement their UI structure, application state, and business logic
in TypePHP while keeping only a thin platform-native UI bridge. See the
[Android native app example](examples/android-native/) and the
[iOS/macOS native app example](examples/apple-native/).

Native release assets are built with the latest PHP 8.5 ZTS release. TypePHP
publishes Linux x64, Linux ARM64, macOS ARM64, and Windows x64 packages. Native
NTS and 32-bit x86 packages are not provided. Linux and macOS archives contain
the compiler and production Composer dependencies, while the Windows archive
contains the complete matching PHP/PHPX runtime and SDK.

## Installation

### Via Composer

```bash
composer require --dev swoole/typephp
```

Then compile your project:

```bash
vendor/bin/tpc.php project.yml
```

When working inside the TypePHP source repository, use the local entry point
instead:

```bash
bin/tpc.php project.yml
```

### From source

```bash
git clone https://github.com/swoole/typephp.git
cd typephp
composer install
php bin/tpc.php --help
```

`PHPX_HOME` may point to a separate PHPX checkout or installation. `PHP_HOME`
may point to the PHP embed prefix; it must contain `bin/php-config`, PHP headers,
and `lib/libphp.so` on Unix-like systems.

### Building `libphp.so`

Binary and shared-library builds require PHP's `embed` SAPI. If `libphp.so` is
missing on Linux, `tpc.php` can interactively download the PHP source and build
it for you. A PHP extension build resolves Zend symbols from the host SAPI and
must not load a second `libphp`. See
[Automatic libphp.so build](docs/en/LIBPHP_INSTALLER.md).

## Quick Start

Create `hello.php`:

```php
<?php

function main(): void
{
    echo "Hello World!\n";
    var_dump(PHP_VERSION);
    var_dump(php_uname());
}
```

Compile and run it:

```bash
bin/tpc.php hello.php
./hello
```

Example output (the exact PHP version and platform strings depend on the linked
runtime):

```
Hello World!
string(5) "8.x.x"
string(16) "Linux ..."
```

> Binary mode requires a global `main()` function. It may be declared with no
> parameters, or as `main(int $argc, array $argv)` to receive command-line
> arguments, and must return `void`. Top-level executable statements are not
> allowed; executable code belongs in a function or method.

### VM-free Nano executable

Use `--nano` to compile one PHP source file together with PHP Nano and PHPX
sources. The result does not link `libphp` and contains no Zend opcode
interpreter:

```bash
./bin/tpc.php --nano examples/hello.php
./hello
```

By default, the executable is emitted in the directory where `tpc` was invoked.
Normal and Nano builds share the `build` directory for generated code, objects,
and other intermediate files. Use `-o` to select a different output path.

PHP and Composer remain build-time tools. On Linux, macOS, iOS, and Android,
the generated program uses the statically selected Nano runtime and its
file-only stream layer. Native Nano may use C11, C++17, and POSIX.1-2008, but
socket/DNS/network, remote streams, dynamic PHP loading, and process execution
remain unavailable. WASI is a smaller subset; direct calls to APIs missing from
that target are compile-time errors.

On every platform, `--nano` rejects the VM entry paths `eval`, `include`,
`include_once`, `require`, and `require_once`, as well as anonymous classes.

Windows uses a different build backend even when `--nano` is specified: it keeps
the existing host compile/link pipeline and connects to `php.dll` and `phpx.dll`
through their import libraries. It does not load the `swoole/php-nano` or
`swoole/phpx` source manifests, nor append their C/C++ files to project `sources`.
External-command APIs and backtick syntax are still rejected. Those command
functions are also removed from the Zend function table at request startup, so
indirect variable/callback calls cannot bypass the policy.

Except for runtime sources, include directories, compile definitions, and link
inputs, Nano and normal mode share command-line parsing, TypePHP code generation,
parallel scheduling, the compilation progress bar, output path rules, and the
`main(int $argc, array $argv)` argument contract.

## Compilation Modes

TypePHP supports three build modes, selected with `-m` / `--mode`:

| Mode | Flag | Output | Needs `main()` | Typical use |
|---|---|---|---|---|
| Binary | `-m bin` (default) | Executable | Yes | CLI tools, long-running services, standalone apps |
| Extension | `-m ext` | PHP `.so` / `.dll` | No | Loading compiled functions/classes into a PHP SAPI |
| Library | `-m lib` | Shared library plus generated `.stub.php` | No | Reusing a compiled TypePHP API from another project |

```bash
# Binary (default)
bin/tpc.php app.php -o myapp

# PHP extension
bin/tpc.php extension/ -m ext -o my_extension

# Shared library; also generates mylib.stub.php
bin/tpc.php lib/ -m lib -o mylib
```

See [Compilation modes](docs/en/COMPILATION_MODES.md) for details.

## Project configuration

For multi-file projects, keep repeatable build settings in `project.yml`:

```yaml
name: myapp
mode: bin
php-version: "8.5"
optimize: 2
job: 8
build-dir: build
cxx-std: c++17

sources:
  - src
  - cpp-src
  - path: src/php85
    if: PHP_VERSION_ID >= 80500
  - path: src/windows
    if: PHP_OS_FAMILY == "Windows"

# Embed files for ZendVM execution and virtual file reads.
bundled-files:
  - vendor

# Precompiled by the project's external native build.
objects:
  - native/build/startup.o
  - path: native/build/platform.obj
    if: PHP_OS_FAMILY == "Windows"

ignore:
  - src/experimental

include-paths:
  - native/include
defines:
  - FEATURE_FAST_PATH=1
link-paths:
  - native/lib
link-libs:
  - curl

# Zend extension requirements, not native linker libraries.
# `extension-dependencies` is the equivalent long name; do not use both.
ext-deps:
  - pdo_mysql
  - curl
```

Paths are resolved relative to the YAML file. A source entry may be a file or
directory; conditional entries support `PHP_VERSION`, `PHP_VERSION_ID`, and
`PHP_OS_FAMILY`. CLI arguments override their YAML counterparts. Scanning a
source directory descends into symlinked directories, so a dependency installed
by a Composer path repository -- which is a symlink -- is compiled like any
other source; `ignore` excludes it, written as the path that reaches it. Native linker
dependencies belong in `link-libs`; `ext-deps` writes `ZEND_MOD_REQUIRED`
entries so Zend can reject loading when a required PHP extension is missing.
`bundled-files` accepts files or directories with the same conditional syntax.
It is opt-in for embedded binary builds: all listed files are packed into the
binary, and PHP files not successfully compiled from `sources` are stored as
OPcache bytecode. `require` and `require_once` load those scripts through
ZendVM without reading their PHP files from disk. The PHP CLI and OPcache used
to build the blobs must match the target PHP runtime. The resulting binary
does not need Composer installation, vendor files, or an OPcache extension at
runtime: Composer's autoload files are embedded and still resolve classes on
demand. When OPcache is available at build time, anonymous classes use the
same opcode table when their `new class` expression is first executed. Without
`bundled-files`, builds lacking OPcache use embedded PHP code for anonymous
classes instead.
Only a `vendor` directory containing `autoload.php` uses the opcode cache.
Its blobs are reused while the directory mtime and build PHP/OPcache remain
unchanged. All other `bundled-files` files, including files in a `vendor`
directory without `autoload.php`, are regenerated on every build. Directory
mtime does not change when an existing nested file is edited; use `--force`
to regenerate vendor opcodes in that case.
The generic `objects` list adds existing `.o`/`.obj` files directly to the
link step. TypePHP never recompiles these files; the project owns their native
compiler, architecture, flags, and incremental build. Keep native files that
use the common target options in `sources`; use `objects` for separately built
translation units that remain ABI-compatible with the final target. A `-m32`
object cannot be linked into a 64-bit target and requires a project-owned
post-link packaging step after tpc emits its ELF.
Project-wide `cxx-flags`, `c-flags`, `asm-flags`, and `ld-flags` are applied to
C++, C, assembler, and link commands respectively.

The build directory keeps readable generated C++ and headers separate from
internal artifacts. Objects, opcode blobs, binary archives, manifests, linker
response files, and precompiled headers live under `build-dir/cache`. Reusing
the build directory makes incremental builds much faster;
use `--force` only when the reusable PHPX objects must be rebuilt.

See [Compiler CLI](docs/en/COMPILER_CLI.md) for all project keys and command-line
precedence rules.

## Compatibility model

TypePHP follows PHP syntax and runtime behavior where they are compatible with
ahead-of-time compilation, but it also makes several deliberate restrictions:

- global scope is declaration-only; executable statements must be inside a
  function or method;
- binary mode has a strict `main()` signature;
- inferred `int`, `float`, and `bool` values use fixed native storage by
  default and cannot later change to an incompatible type;
- `use varint_types` stores inferred integers in `php::Var` for PHP-compatible
  overflow and division behavior; `std::any()` erases one expression's type;
- statically-known calls and properties are compiled directly, while supported
  dynamic operations use PHPX/Zend runtime fallbacks;
- `.stub.php` files declare C++ or imported-library APIs and must contain empty
  bodies; `#[Native]` classes are not permitted in stub files;
- some highly dynamic reference, declaration, closure, and reflection patterns
  remain intentionally unsupported.

The compatibility boundary is part of the public contract and has both
positive and negative tests. Consult
[Incompatible PHP features](docs/en/INCOMPATIBLE_PHP_FEATURES.md) for the current,
specific list instead of assuming that absence from this README means support.

## Compile-time attributes and code generation

TypePHP consumes its built-in code-generation attributes while lowering the
class. The generated methods retain the declared property types and take part
in the same conflict, inheritance, and final-method checks as explicitly
declared methods.

| Attribute | Target | Generated API |
|---|---|---|
| `#[Getter]` | Instance property, including a promoted property | `public function getName(): T` |
| `#[Setter]` | Mutable instance property, including a promoted property | `public function setName(T $name): void` |
| `#[With]` | Mutable instance property, including a promoted property | `public function withName(T $name): static`; clones the object, updates the clone, and returns it |
| `#[Constructor]` | Declared instance property | Adds the property to a generated public `__construct()` |
| `#[Printer]` | Named class | `public function __toString(): string` |
| `#[Arrayable]` | Named class | `public function toArray(): array` |

```php
<?php

#[Printer(fields: ['id', 'name'])]
#[Arrayable(fields: ['id', 'name'])]
final class User
{
    #[Constructor, Getter, With]
    public int $id;

    #[Constructor, Getter, Setter]
    public string $name = 'guest';
}

function main(): void
{
    $user = new User(7);
    $user->setName('Alice');

    $copy = $user->withId(8);
    echo $user->getId();       // 7
    echo $copy->getId();       // 8
    echo $user;                // User(id=7, name=Alice)
    echo $user->toArray()['name'];
}
```

Without `fields`, `#[Printer]` and `#[Arrayable]` use the class's own public
instance properties. The positional form, such as `#[Arrayable(['id'])]`, is
equivalent to `#[Arrayable(fields: ['id'])]`.

`#[Getter]`, `#[Setter]`, and `#[With]` cannot target static properties or
properties with hooks. `#[Setter]` and `#[With]` additionally reject readonly
properties. `#[Constructor]` cannot be used when the class already declares
`__construct()`, and required constructor properties must precede properties
with defaults. A generated method name that conflicts with a declared or
inherited final method is a compile-time error.

## Examples

### 1. Native types — compile-time numeric speedup

```php
<?php

function fib(int $n): int
{
    if ($n == 1 || $n == 2) {
        return 1;
    }
    return fib($n - 1) + fib($n - 2);
}

function main(int $argc, array $argv): void
{
    $n = (int)$argv[1];
    $begin = microtime(true);
    echo fib($n) . "\n";
    echo "Time: " . (microtime(true) - $begin) . "\n";
}
```

```bash
bin/tpc.php fib.php -O3 -o fib
./fib 30
```

By default, inferred and declared `int` variables become C++ `int64_t`, and
arithmetic compiles to plain CPU instructions instead of ZendVM calls. Add
`use varint_types` only when a file requires PHP's overflow-to-float and
non-integral integer-division behavior.

### 2. High-precision numerics

```php
<?php

function main(): void
{
    // 54-digit integer — automatically detected and stored as bigInt
    $a = std::bigInt("123456789012345678901234567890123456789012345678901234");
    $b = std::bigInt("987654321098765432109876543210987654321098765432109876");

    echo $a->add($b)->toString() . "\n";   // exact, no overflow

    // Exact decimal arithmetic — no binary floating-point error
    $c = std::decimal("0.1")->add(std::decimal("0.2"));
    echo $c->toString() . "\n";            // "0.3"

    // 256-bit floating point
    $pi = std::bigFloat("3.14159265358979323846264338327950288419716939937510");
    echo $pi->mul(2)->toString() . "\n";
}
```

See [High-precision types](docs/en/HIGH_PRECISION_TYPES.md) and
[Native types](docs/en/NATIVE_TYPES.md).

### 3. Strongly-typed containers

```php
<?php

function main(): void
{
    $vector = std::vector(Type::Int);

    $vector[] = 1;
    $vector[] = 2;
    $vector[] = 3;

    $sum = 0;
    foreach ($vector as $value) {
        $sum += $value;
    }

    echo $sum . "\n";       // 6
    echo $vector[1] . "\n"; // 2

    // key-value map with fixed key/value types
    $map = std::orderedMap(Type::String, Type::Int);
    $map["a"] = 1;
    $map["b"] = 2;
}
```

See [Std containers](docs/en/STD_CONTAINERS.md).

### 4. Universal methods

```php
<?php

function main(): void
{
    $s = "hello world";
    echo $s->length() . "\n";       // strlen()
    echo $s->upper() . "\n";        // strtoupper()
    echo $s->substr(0, 5) . "\n";   // substr()

    $arr = [1, 3, 5, 7, 9];
    echo $arr->count() . "\n";      // count()
    var_dump($arr->contains(3));    // in_array()

    $big = std::bigInt("12345678901234567890");
    echo $big->mul(2)->toString() . "\n";
}
```

Method calls on primitives are resolved at compile time into direct C/C++
function calls — no vtable lookup, no reflection, no runtime dispatch. See
[Universal methods](docs/en/UNIVERSAL_METHODS.md).

### 5. Mixed C++ / PHP

Write performance-critical kernels in C++ and call them from PHP:

```cpp
// math.cpp
#include <phpx.h>

using namespace php;

Int php_fast_sum(Int a, Int b) {
    return a + b;
}
```

```php
<?php
// math.stub.php — declares the C++ function signature
function fast_sum(int $a, int $b): int {}
```

```php
<?php
function main(): void
{
    echo fast_sum(3, 4) . "\n";  // 7
}
```

Add `math.cpp`, `math.stub.php`, and the calling PHP source to the same project
configuration. The `php_` C++ symbol prefix is the TypePHP callable ABI; stub
functions provide type metadata only and must not contain an implementation.

See [Mixed C++/PHP](docs/en/MIXED_CPP_PHP.md).

## Benchmark

### PHP language benchmarks (from php-src)

TypePHP runs the official `bench.php` and `micro_bench.php` language
benchmarks that ship with the PHP source tree, compiled with `-O3`:

| Benchmark | Interpreted PHP | TypePHP AOT (`-O3`) | Speedup |
|---|---|---|---|
| `bench.php` (total) | 5.034 s | **0.603 s** | ~8× |
| `micro_bench.php` (total) | 13.045 s | **2.021 s** | ~6.5× |

Both benchmarks measure core PHP language performance — function calls, object
property access, array/hash access, string handling, control flow, and more.
The checked-in workloads are [`benchmark/bench.php`](benchmark/bench.php) and
[`benchmark/micro_bench.php`](benchmark/micro_bench.php). Additional focused
performance regressions live in the same [`benchmark/`](benchmark/) directory.

These numbers are a project measurement snapshot, not a performance guarantee.
PHP version, compiler, CPU, optimization flags, and enabled extensions can all
change the result; compare on the same machine with the same workload before
making deployment decisions.

### std::array vs PHP array

A 10000×100000 element update loop, comparing PHP arrays against TypePHP's
`std::array` and native C++:

| Implementation | Time |
|---|---|
| PHP array (JIT) | 67.6 s |
| `std::array` (TypePHP AOT) | **6.4 s** |
| C++ `std::vector` | 6.2 s |

`std::array` is roughly **10× faster** than PHP arrays and performs
close to the hand-written C++ result in this workload. See the benchmark in
[Std containers](docs/en/STD_CONTAINERS.md).

## Command Line

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

Common usage:

```bash
# Compile a single file
bin/tpc.php app.php

# Optimize and run, passing args to the program after `--`
bin/tpc.php app.php -O3 -r -- --flag value

# Compile a project defined in project.yml
bin/tpc.php project.yml -O2 -j 8

# Build a PHP extension
bin/tpc.php extension/ -m ext -o my_extension

# Only generate C++ (skip compile & link)
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build

# Compile to WASI 0.2
bin/tpc.php --wasm app.php

# Compile for the browser (requires jco)
bin/tpc.php --wasm=browser app.php
```

Key options:

| Option | Description |
|---|---|
| `-O <0-3>` | Optimization level (default `0`) |
| `-d`, `--debug` | Debug build with symbols and source tracking |
| `-o`, `--output <file>` | Output file name |
| `-m`, `--mode <bin\|lib\|ext>` | Build mode (default `bin`) |
| `-r`, `--run` | Run after a successful build |
| `-j`, `--job <num>` | Parallel compile jobs (default `4`) |
| `-f`, `--force` | Rebuild reusable PHPX objects instead of using the cache |
| `--build-dir <dir>` | Directory for generated C++ and intermediates |
| `--dry` | Generate C++ only, skip compile and link |
| `--php-version <8.4\|8.5>` | PHP syntax version to accept |
| `--cxx-std <ver>` | C++ standard (e.g. `c++17`, `c++20`) |
| `--march <arch>` | Target instruction set (e.g. `native`) |
| `--target-platform <triple>` | Cross-compilation target triple |
| `--lto` | Enable link-time optimization |
| `--sanitize <type>` | Enable a sanitizer (e.g. `address`) |
| `--profile` | Enable Linux gperftools profiling |
| `--format` | Format generated C++ with clang-format |
| `--no-literal-strings` | Disable the literal-string table optimization |
| `--no-progress`, `--no-color` | CI-friendly output controls |
| `-I`, `-D`, `-L`, `-l` | Repeatable native include, define, library path, and library options |

Run `bin/tpc.php --help` for the authoritative, up-to-date list. See
[Compiler CLI](docs/en/COMPILER_CLI.md) for details, including Bash completion:

```bash
source <(./tpc --generate-completion=bash)
```

## Troubleshooting

- **`libphp.so` / `libphp.dylib` is missing:** install/build the matching PHP embed SAPI, set
  `PHP_HOME`, or let `bin/tpc.php` offer the interactive Linux installer.
- **PHPX cannot be found:** set `PHPX_HOME` to a PHPX installation containing
  `include/` and `lib/libphpx.so` (or the platform equivalent), then build PHPX
  before compiling the project.
- **Startup crashes or ABI errors:** the PHP headers, `php-config`, `libphp`,
  and loaded extension ABI must agree on the PHP version and ZTS/NTS mode. Do
  not mix artifacts from different PHP builds.
- **Incremental builds are unexpectedly slow:** keep a stable `--build-dir` so
  object and PCH caches can be reused. When an external test runner already
  runs several tests concurrently, avoid multiplying that concurrency by an
  unnecessarily large `tpc -j` value.
- **A project compiles with `bin/tpc.php` but fails with `tpc`:** reproduce with
  the self-hosted compiler. Bootstrap execution can expose dynamic-call or ABI
  paths that the PHP-hosted compiler does not exercise.

## Python bridge

TypePHP ships a Python tool submodule that shares the `tpc` entry point:

```shell
# Generate IDE helpers for Python modules
./tpc --gen-python-helper math
./tpc --gen-python-helper numpy --output-dir .ide-helper

# Convert a Python script to TypePHP
./tpc --convert-python-to-php script.py > script.php
```

See [Python tool submodule](docs/en/python/tools.md).

## Development and testing

Install development dependencies and run the compiler unit suite:

```bash
composer install
PHPX_HOME=/path/to/phpx vendor/bin/phpunit
```

PHPT is the end-to-end suite. Build the self-hosted compiler first and pass it
explicitly to the test runner; using the Zend PHP executable as `--compiler`
does not test the deployed compiler:

```bash
PHPX_HOME=/path/to/phpx php bin/tpc.php project.yml --job 2 --no-progress
php run-tests.php -q -j8 --compiler ./tpc tests/compiler
```

Static analysis and the source-derived coverage matrix are separate checks:

```bash
composer analyse
php bin/analyze-test-coverage.php
php bin/analyze-test-coverage.php \
  --format=markdown --output=build/test-coverage.md --strict
```

The coverage tool reports PHP version × feature × positive compilation ×
runtime semantics × negative diagnostics, plus concrete PHP-parser AST nodes.
It intentionally does not publish a single percentage without an explicit
denominator. See [Test coverage analyzer](docs/en/TEST_COVERAGE_ANALYZER.md).

GitHub Actions runs PHPUnit and self-hosted PHPT on PHP 8.4 and 8.5. Changes to
compiler behavior should add a focused PHPUnit test for internal/code-generation
rules and a PHPT whenever runtime output or diagnostics are observable.

## Documentation

- [Quick Start](docs/en/QUICKSTART.md) — minimal compilation flow
- [Change log](CHANGELOG.md) — breaking changes and pre-1.0 upgrade notes
- [Compilation modes](docs/en/COMPILATION_MODES.md) — `bin`, `ext`, `lib`
- [Compiler CLI](docs/en/COMPILER_CLI.md) — CLI arguments and project config
- [Incompatible PHP features](docs/en/INCOMPATIBLE_PHP_FEATURES.md) — current limits
- [Native types](docs/en/NATIVE_TYPES.md) — native scalar types
- [High-precision types](docs/en/HIGH_PRECISION_TYPES.md) — BigInt / Decimal / BigFloat
- [Std containers](docs/en/STD_CONTAINERS.md) — strongly-typed containers
- [Universal methods](docs/en/UNIVERSAL_METHODS.md) — compile-time method resolution
- [Compile-time functions](docs/en/COMPILE_TIME_FUNCTIONS.md) — `std::any()`, `std::ref()`, `std::expected()`, …
- [Mixed C++/PHP](docs/en/MIXED_CPP_PHP.md) — C++/PHP interop
- [`#[Immutable]`](docs/en/IMMUTABLE.md) — compile-time read-only contracts
- [Typed PHP arrays and type annotations](docs/en/TYPED_ARRAYS.md) — typed array-property contracts
- [Property hooks](docs/en/PROPERTY_HOOKS.md) — PHP 8.4 hook lowering and runtime metadata
- [Object storage models](docs/en/OBJECT_STORAGE_AND_PASSING_MODELS.md) — Zend object, Box, and Native class boundaries
- [Generators](docs/en/YIELD_GENERATOR.md) — generator lowering and lifecycle
- [Test coverage analyzer](docs/en/TEST_COVERAGE_ANALYZER.md) — AST and feature evidence matrix
- [WASI build](docs/en/WASI_BUILD.md) — WASI targets

## Acknowledgements

TypePHP thanks every developer and contributor who has helped build the project.
It also stands on the work of the GCC, Clang/LLVM, MSVC, ISO C++ (WG21), PHP,
PHP-Parser, and many supporting open-source communities. See the full
[Acknowledgements](docs/en/ACKNOWLEDGEMENTS.md).

## License

TypePHP is licensed under the [GNU General Public License v3.0](LICENSE).

## Community

- Repository: <https://github.com/swoole/typephp>
- Copyright © 2026 上海识沃网络科技有限公司 (Swoole)
