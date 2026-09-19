# TypePHP Compiler Command Line

## Bash Autocompletion

TypePHP provides Bash completion that is kept in sync with the current compiler arguments. To enable it temporarily in the current terminal:

```shell
source <(./tpc --generate-completion=bash)
```

When developing from the source repository, you can also run `source completions/tpc.bash` directly.

To install it for the current user and have it auto-loaded in subsequent Bash sessions:

```shell
mkdir -p "$HOME/.local/share/bash-completion/completions"
./tpc --generate-completion=bash \
    > "$HOME/.local/share/bash-completion/completions/tpc"
```

If your system does not automatically scan the user completion directory, you can load it in `~/.bashrc`:

```shell
source "$HOME/.local/share/bash-completion/completions/tpc"
```

For a system-wide installation, write the generated output to `/usr/share/bash-completion/completions/tpc`. This operation typically
requires root privileges.

The completion supports build options, WASM profiles, build modes, PHP/C++ versions, sanitizers, input sources,
project YAML, Python source files, and directory arguments. Everything after `--` is treated as arguments of the compiled program itself, and the completer
does not interpret them as `tpc` arguments anymore.

Release packages ship a pre-generated `completions/tpc.bash`. This file is produced by the same generator, with unit
tests ensuring it matches the output of `./tpc --generate-completion=bash`.

This document is kept in sync with `src/Translator.php::showUsage()`. Usage:

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

## Common Examples

```bash
# Compile a single file
bin/tpc.php app.php

# Optimize and run; arguments after `--` are passed to the generated program
bin/tpc.php app.php -O2 -r -- --flag value

# Compile a project configuration
bin/tpc.php project.yml -O2 -j 8

# Generate a PHP extension
bin/tpc.php extension/ -m ext -o my_extension

# Only generate C++, without compiling and linking
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build
```

## Build Options

| Option | Description |
|---|---|
| `-O <0-3>` | Optimization level, default `0`. |
| `-d`, `--debug` | Debug build; disables optimization and adds debug symbols and TypePHP source tracking. |
| `-o`, `--output <file>` | Output file name. |
| `-m`, `--mode <bin|lib|ext>` | Build mode, default `bin`. |
| `-r`, `--run` | Run after a successful build. |
| `-j`, `--job <num>` | Number of parallel compilation jobs, default `4`. |
| `-f`, `--force` | Ignore the phpx misc object cache and force recompilation. |
| `--build-dir <dir>` | Directory for generated C++ and intermediate artifacts. |
| `--dry` | Only generate C++, skipping compilation and linking. |
| `--format` | Run clang-format on the generated code. |
| `--no-progress` | Do not show the progress bar; output progress per file. |
| `--no-color` | Disable colored output. |

`-v` / `--version` only displays the version; it is not a verbose option.

## Target and Toolchain

| Option | Description |
|---|---|
| `--php-version <8.4|8.5>` | Restrict the accepted PHP syntax version, default `8.5`. |
| `--cxx-std <ver>` | C++ standard, e.g. `c++17`, `c++20`. |
| `--march <arch>` | Target instruction set, e.g. `native`, `x86-64-v3`. |
| `--target-platform <triple>` | Cross-compilation target triple. |
| `--lto` | Enable Link Time Optimization. |
| `--sanitize <type>` | Enable a sanitizer, e.g. `address`, `undefined`. |
| `--no-console` | Windows GUI mode hides the console window. |
| `--profile` | Enable the gperftools profiler on Linux and force recompilation of related objects. |

`--php-version` controls the source syntax accepted by the parser and is also used in `project.yml` to select source files based on `PHP_VERSION` / `PHP_VERSION_ID`. It is not responsible for choosing the PHP installation directory to link against.

The minimum runtime version for both TypePHP and PHPX is PHP 8.4. `--php-version` and the actually linked `libphp.so` do not need to match exactly in minor version, but both must be PHP 8.4 or higher.

## C++ Compilation and Link Arguments

These arguments can all be repeated:

```bash
-I /opt/library/include
-D FEATURE_ENABLED=1
-L /opt/library/lib
-l curl
```

Corresponding long options:

- `--include-path`
- `--define`
- `--link-path`
- `--link-lib`

## Project Configuration Precedence

When a `project.yml` is passed, command-line arguments take precedence over same-named settings in the YAML. For the project file format, see the user documentation and the project configuration parser in the code.

### Symlinked source directories

Scanning a source directory descends into symlinked directories, so a dependency
installed by a Composer path repository -- which is installed as a symlink -- is
compiled like any other source. A link pointing at one of its own ancestors does
not recurse, and a file reached through more than one link is compiled once.

Excluding one is the ordinary `ignore` entry, written as the path that reaches
it rather than the path it points at:

```yaml
ignore:
  - vendor/vendor/mylib
```

### Precompiled object files

A project can add object files produced by an external native toolchain as
generic link inputs:

```yaml
objects:
  - build/startup.o
  - path: build/platform.obj
    if: PHP_OS_FAMILY == "Windows"
```

Paths are resolved relative to `project.yml` and accept the same conditions as
`sources`. `tpc` only loads and links `.o`/`.obj` files; it does not recompile
their C, C++, or assembly sources. Native sources using the project's common
options should remain in `sources`; `objects` is intended for separately built
translation units that remain ABI-compatible with the final target. Objects
built with `-m32` cannot be linked into a 64-bit target and need a separate
project-owned packaging step after tpc emits its ELF.
Use `cxx-flags`, `c-flags`, `asm-flags`, and `ld-flags` for project-wide C++,
C, assembler, and linker options.

### PHP Extension Dependencies

When a program depends on other PHP extensions, the required modules can be written into the Zend module dependency table:

```yaml
extension-dependencies:
  - pdo_mysql
  - curl
```

`ext-deps` is an equivalent shorthand name. Only one of these names can be used in a project; using both `extension-dependencies` and `ext-deps` produces a configuration error.

The compiler generates a `ZEND_MOD_REQUIRED` for each entry. Zend checks whether these extensions are loaded when loading the TypePHP module. This setting does not represent native link libraries; C/C++ link dependencies still use `link-libs`.

## Viewing the Authoritative Help

The command-line implementation may continue to evolve; for released versions the actual arguments are determined by the following command:

```bash
bin/tpc.php --help
```

For compatibility boundaries, see [INCOMPATIBLE_PHP_FEATURES.md](INCOMPATIBLE_PHP_FEATURES.md); for build modes, see [COMPILATION_MODES.md](COMPILATION_MODES.md).
