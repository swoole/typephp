# Swoole thread compatibility

This suite compares interpreted PHP with TypePHP extension (`ext`), embedded
executable (`bin`), and executable with an embedded resource (`bin-embedded`)
builds. It requires PHP 8.4/8.5 ZTS, Swoole with thread support, and PHPX with the
thread lifecycle and immutable literal fixes described in the
[recorded platform results](RESULTS.md). Set `PHPX_HOME` to that fixed checkout
until those fixes are available from the installed dependency.

Each successful mode creates nine worker requests in three rounds, with three
workers released together by a barrier. It verifies:

- 54 snapshots, half executed through compiled callbacks in Swoole coroutines;
- isolation of compiled globals, function statics, native roots and call caches;
- interpreted classes/functions declared independently in each worker request;
- internal class/method calls, dynamic calls, captured closures and exceptions;
- shared Queue/Map/Atomic objects passed through compiled calls;
- exactly nine native-root destructors during worker request shutdown;
- unchanged main-request state after all workers have joined.

`bin-embedded` additionally reads an embedded text file in the main request and
every worker after removing its private disk copy. This checks that each request
installs its own file stream wrapper. The PHPX state uses `THREAD_LOCAL`, which
expands to thread-local storage only for ZTS builds.

Queue arrays are normalized from `Swoole\Thread\Map` before strict comparison.
Worker errors are recorded in a trace; the parent joins every worker before
checking their exit statuses. A subprocess timeout catches startup deadlocks.
An `ok` message alone is insufficient: the process must also exit successfully.

## Running

From the repository root, the interpreted control requires no native build:

```sh
php bin/test-swoole-thread.php --modes=control
```

Run native modes only inside the platform's protected build launcher, following
[the repository resource rules](../../../AGENTS.md). The runner fixes TypePHP's
compiler concurrency at `--job 1` by default; it does **not** establish an OS
memory limit or a cross-session build lock itself. Dedicated CI runners set
`PHP_COMPILER_JOBS` to their available CPU count, which the runner forwards to
TypePHP. On user-managed machines configure `CMAKE_BUILD_PARALLEL_LEVEL=1`,
`PHP_COMPILER_JOBS=1` and `CARGO_BUILD_JOBS=1` in the enclosing launcher. For GNU
Make set `MAKEFLAGS=-j1` and `MFLAGS=-j1`; NMake rejects these flags, so clear
them and use serial NMake with MSVC `/MP1`. Inspect existing builds and memory first, and retain
the Linux user-wide `flock` for the whole build tree. On Windows use a Job Object
memory limit covering the launcher and its descendants. Do not launch multiple
modes or platform builds concurrently.

Inside that protected session:

```sh
PHPX_HOME=/path/to/phpx PHP_HOME=/path/to/php-zts \
  php bin/test-swoole-thread.php --modes=control,ext,bin,bin-embedded \
  --artifacts=build/swoole-thread-full
```

The default compiler entry loads this checkout's source, including when
`vendor/` is shared with another checkout. `--compiler=./tpc` selects an existing
compiler instead. `--cxx=clang++` selects the C++ backend; Windows Clang selects
`lld-link` automatically. The Windows LLVM/MSVC-header environment can be
prepared with [set-llvm-env.ps1](../../../tests/windows/clang/set-llvm-env.ps1).

Swoole must be enabled through PHP's normal INI configuration, including for
worker requests and the embedded executable. A `php -d extension=swoole` option
on the runner is not inherited by its subprocesses. Configure `PHPRC` and
`PHP_INI_SCAN_DIR` if a private runtime is needed. On Windows ensure the DLL
search path selects the PHPX DLL corresponding to the linked import library.

Each mode retains build/run/trace logs and `results.json`. A failed mode stops
the run. Inspect the failure before explicitly selecting remaining modes; use
a different artifact directory for diagnostic variants so the original evidence
is preserved. Missing ZTS/thread support is a failure, not a passing skip.

Two optional diagnostics narrow failures; their results are not full passes:

```sh
php bin/test-swoole-thread.php --modes=ext,bin --no-literal-strings \
  --artifacts=build/swoole-thread-no-literals
php bin/test-swoole-thread.php --modes=ext,bin --without-exceptions \
  --artifacts=build/swoole-thread-no-exceptions
```

`--without-exceptions` disables the throw/catch assertion only; the report
records `exceptions=false`. `--no-literal-strings` forwards the existing
compiler option and records `literal_strings=false`.

## Executable entrypoint

`bin-main.php` guards application startup with
`Swoole\Thread::getInfo()['is_main_thread']`. Worker RINIT happens before Swoole
sets `Thread::getArguments()`, so testing whether arguments are null is not a
safe entrypoint guard. Worker scripts intentionally call compiled exported
functions without including their original PHP source. Falling back to the
interpreted implementation would hide missing compiled symbols.
