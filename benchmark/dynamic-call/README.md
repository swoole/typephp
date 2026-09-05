# Dynamic call benchmark

This benchmark compares direct calls with the runtime callable forms handled
by PHPX. It deliberately separates stable call sites from alternating and
megamorphic sites. TypePHP's function-call cache keeps one name inline and
promotes polymorphic sites to a request-local table. Its method-call cache is
monomorphic and disables itself after observing a different class or name.

It also measures dynamic method names with a stable receiver, alternating
method names, and a fixed method name on changing receiver classes. Those
cases require a class-entry guard in addition to a callable-name guard.

The `named_method_dynamic_receiver*` cases specifically exercise
`Variant::call(const Variant &, ...)`: the PHP method name is fixed, while the
receiver's concrete class is hidden behind an `object` value. The monomorphic
cases measure the cacheable path with zero and one argument; the polymorphic
case guards against optimizing one runtime class as though it were static.

The `scoped_*` cases exercise private/protected dynamic calls that must resolve
with the compiled method's lexical scope. They are kept separate because a
scoped cache must guard both the target callable and its calling scope.

The `static_*_dynamic` cases exercise direct `$class::fixedMethod()`,
`Class::$method()`, and `$class::$method()` syntax. PHPX resolves the class and
method independently through Zend's public class handlers, avoiding a
temporary `"Class::method"` callable string. Dynamic static dispatch is not
cached: only a source-level fixed class is lowered to a reusable class entry.
The corresponding `*_alternating` cases model route-like inputs where the
class or method changes at the same call site.

The monomorphic string-call cases cover zero, one, two, and four positional
arguments. This separates callable-cache lookup cost from argument
materialization cost. Fixed positional arguments are emitted as a contiguous
`std::array<php::Variant, N>` and passed through PHPX without constructing the
dynamic `php::Args` vector. Calls containing argument unpacking continue to
use `php::Args`/`php::Array` because their final size is only known at runtime.

Run it from the repository root against a release PHP/PHPX build:

```bash
PHPX_HOME=../phpx PHP_BIN=/opt/php-8.5-nts/bin/php php benchmark/dynamic-call/run.php
```

The TypePHP binary is built with `-O3` and LTO. `PHP_BIN` selects both the Zend
PHP baseline and, by default, the PHP executable used to run the compiler.
`TPC_PHP_BIN` may override the latter, but the runner rejects different PHP
versions, ZTS/debug modes, or integer widths. PHPX must also be a Release build
for that same PHP ABI. Add `--skip-build` to reuse the binary or
`--case=<name>` to measure one workload while profiling.

Reported values are the best of five rounds after two warm-up rounds. The
checksum must match between PHP and TypePHP; absolute timing is intentionally
not used as a pass/fail condition.
