# EXT/LIB/FPM integration tests

The [bootstrap signature regression](bootstrap-signatures/README.md) checks
the compiler's real diagnostic interface before request-time autoloading.

Swoole Thread has a separate [three-platform compatibility suite](swoole-thread/README.md)
and [recorded results](swoole-thread/RESULTS.md), including interpreted controls,
extension builds and embedded executables.

This suite lives below `.github` because repository test fixtures with a `.php`
suffix are intentionally ignored below `tests/`. It protects build-mode
boundaries rather than duplicating the PHP syntax coverage in `tests/compiler`.

- `ext/lifecycle` builds two real Zend extensions, loads both orders through
  CLI, and uses opposite orders for `php -S` and PHP-FPM. The long-running hosts
  alternate implementations of the same request-local class and function,
  while both extensions also call an internal class and method. This protects
  per-module cache isolation, shared PHPX lifecycle handling, request cache
  cleanup, and persistent cache reuse across repeated RINIT/RSHUTDOWN cycles.
- `lib` builds two provider libraries, consumes both generated
  `@import-library` stubs from one TypePHP binary, links all three artifacts,
  and runs the consumer. The providers deliberately contain an identically
  named private helper with different implementations to protect hidden-symbol
  isolation. Both modes include throwing `main()` declarations to verify that
  only bin mode executes the entrypoint.
- `fpm/globals` builds a standalone FPM binary using php-builder, removes the
  embedded include from disk and replaces the primary script with a failing
  placeholder. With `auto_globals_jit=1`, it checks `$_SERVER`, `$_ENV`, and
  `$_REQUEST`, their FastCGI/environment values, preservation of mutations
  across embedded includes, and isolation across three requests in one worker.

Run from the repository root:

```sh
PHPX_HOME=../phpx php bin/run-integration-tests.php \
  --compiler=./tpc \
  --php="$(command -v php)" \
  --php-fpm="$(php-config --prefix)/sbin/php-fpm"
```

Successful runs remove their temporary build tree. On failure the generated
C++ sources, shared libraries, server configuration, and logs remain under
`build/integration-*` for CI artifact collection. Pass `--keep` to retain a
successful build as well. `--suite=ext` and `--suite=lib` can isolate one mode
while debugging; the default is `--suite=all`.

The standalone FPM regression is opt-in because it builds a private PHP runtime
with php-builder (the default `all` suite retains its EXT/LIB requirements):

```sh
PHPX_HOME=../phpx php bin/run-integration-tests.php \
  --compiler=./bin/tpc.php --suite=fpm
```

This test requires no separately installed `php-fpm` binary. It selects PHP 8.5
NTS by default; add `--fpm-php-version=8.4` to exercise that decoder instead.
