# PHP builder

For `mode: bin`, TypePHP separates the artifact type from the PHP SAPI:

```yaml
mode: bin
sapi: [embed, cli, fpm]
php-builder:
  extensions: [swoole, mongodb]
  zts: on
```

`sapi` selects one or more process interfaces. `embed` is the default. `cli`
and `fpm` are not modes and always require `php-builder` because a host PHP
installation does not provide the static build inputs needed by these targets.

`php-builder` means that TypePHP builds a private PHP runtime from an official
php-src archive. The result does not depend on the host PHP runtime, but it does
use development libraries supplied by the operating system. The original
php-src cache is left unchanged; external PECL extensions are injected into a
derived source tree. Extension requirements are merged from the explicit
`extensions` list, project sources, YAML dependencies, and embedded Composer
metadata, and then translated to PHP configure options.

Every build starts with `--disable-all`, followed by explicit `--enable-*` /
`--with-*` options for required extensions and their mandatory dependencies.
For example, `pdo_sqlite` also enables PDO, and `dom` also enables libxml;
optional dependencies are not enabled automatically. Extensions such as
mbstring, sockets, and zlib are no longer enabled by default. Add them to
`extensions` or declare them through the project dependencies above when needed.
PHP's unconditional core extensions and OPcache, required by the runtime, remain
available. Builds do not inherit the host PHP configuration or a source tree's
`config.nice` options.

The command-line equivalent is:

```bash
bin/tpc.php project.yml \
  --sapi=embed,cli,fpm \
  --php-builder='extensions: [swoole, mongodb]; zts: on'
```

`zts` and `debug` accept `on` or `off`. When omitted, they use `PHP_ZTS` and
`PHP_DEBUG` from the PHP running the TypePHP compiler. For example,
`php-builder: {}` inherits the host's ZTS/NTS and debug/release build settings.
`php-builder.debug` controls PHP's `--enable-debug` / `--disable-debug`; it is
separate from the top-level `debug` option for C++ debug information.
`php-builder.sapi` is invalid; SAPI selection is always the independent
top-level `sapi` option.

## PHP runtime compatibility

The target PHP must match the PHP running the compiler in the following
settings, or the build fails:

- The major/minor version: for example, `8.4` and `8.5` cannot be mixed.
- ZTS/NTS.
- DEBUG.

These rules apply to both the source `bin/tpc.php` entry and the native `tpc`
executable. TypePHP checks the configuration before downloading or compiling
PHP and verifies the actual runtime when using the target PHP CLI. It does not
automatically switch to another PHP. When the host uses PHP 8.4, set
`--php-version=8.4` or YAML `php-version: '8.4'`; the option still defaults to `8.5`.

A different release (patch) version only produces a warning: for example,
host `8.5.7` and target `8.5.10` can continue building. Compile-time constants
such as `PHP_VERSION` and `PHP_VERSION_ID` still use the compiler runtime's
values. To query the target's actual version, call `phpversion()` or
`constant('PHP_VERSION')` in the program.

## Default Embed behavior

Without `php-builder`, `mode: bin` plus `sapi: embed` preserves the traditional
behavior and links the host Embed library (`libphp.so` or `libphp.dylib`). If it
is unavailable on Linux or macOS, an interactive invocation asks whether to
enable `php-builder`. In a non-interactive environment, choose explicitly:

```bash
bin/tpc.php project.yml \
  --php-builder
```

Declining the prompt leaves no usable Embed runtime and stops the build.

## Cache and proxy

Downloaded sources and private runtimes are cached under `~/.typephp`. Compatible
runtimes are reused across application builds only when extension requirements
and their configure options match. Older caches built without `--disable-all`
are invalidated automatically, so the first build recompiles PHP.
ZTS and DEBUG also participate in cache matching; different configurations
do not share a cached runtime.
`--proxy` is a global network setting rather than a `php-builder` field; PHP and
PECL metadata and archives,
and any other TypePHP network transfers, use the configured HTTP(S) or SOCKS
proxy.

```bash
bin/tpc.php project.yml --proxy=socks5h://127.0.0.1:1080 \
  --php-builder
```
