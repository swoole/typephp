# C++ Namespace, Prefix, and Symbol ABI Rules

This document is the internal C++ naming convention for TypePHP, PHPX, and TypePHP-generated code. It solves the following problems:

- Distinguishing TypePHP runtime logic, PHPX ZendAPI wrappers, project-private implementations, and user PHP symbols;
- Preventing framework helpers from generating the same C++ symbols as user-defined PHP functions or class methods;
- Clarifying which names are part of the stable ABI and which names are only for internal use within a single generated project;
- Providing a unified naming decision for adding helpers, caches, entry functions, and generated symbols.

## 1. Overall Rules

| Naming Domain | Meaning | Typical Form | Visibility Scope | ABI Property |
| --- | --- | --- | --- | --- |
| `typephp_` | TypePHP-specific runtime or compiled-artifact support logic | `typephp_call_parent_constructor()` | TypePHP/PHPX runtime | Internal or explicitly exported ABI |
| `php::` | C++ wrappers for PHP runtime capabilities such as ZendAPI, zval, HashTable, and call frames | `php::deindirect()` | PHPX C++ API | PHPX API |
| `typephp_project_<project>` | The private C++ namespace of a single compiled project | `namespace typephp_project_tpc` | Current generated project | Non-public ABI |
| `php_` | C++ callable symbols mapped from user PHP functions and class methods | `php_app__user__save()` | Visible to the linker | TypePHP/stub callable ABI |

Core constraints:

1. Do not add new global framework `php_*` helpers.
2. Capabilities that are unrelated to TypePHP and only wrap ZendAPI must be placed in `namespace php`.
3. Logic that is unique to TypePHP and needs to be called across generated files uses the `typephp_` prefix.
4. Data and functions that serve only one compiled project go into the `typephp_project_<project>` namespace.
5. Global `php_*` callable names are reserved for the compiled ABI of user PHP declarations.

## 2. `typephp_`: TypePHP-specific Logic

`typephp_` indicates that the API's semantics are defined by TypePHP and are not a general-purpose C++ wrapper of ZendAPI. Common scenarios include:

- TypePHP property read/write rules;
- TypePHP construction, cloning, and parent method call chains;
- Runtime support for TypePHP compile-time Attributes;
- TypePHP-specific runtime logic such as Native Class and Property Hook;
- Initialization and shutdown entry points of the TypePHP embed runtime.

Examples:

```cpp
typephp_call_parent_constructor(object, constructor, args);
typephp_call_parent_clone(object, clone_method);
typephp_install_property_handlers(class_entry, handlers);
typephp_write_property_scoped(object, member, value, scope);
TYPEPHP_RUNTIME_INIT(project)(argc, argv);
```

### 2.1 Usage Boundaries

- This prefix is the TypePHP internal C/C++ name space and does not represent PHP user functions.
- When adding an API, use a complete, recognizable snake_case name; do not use overly broad names such as `typephp_call()`.
- Functions used in only one `.cc` file should additionally be marked `static` or placed in an anonymous namespace.
- When crossing dynamic library boundaries, use the corresponding export macro; helpers that do not need to be exported should not widen symbol visibility.
- Do not use `typephp_` merely because the code is in `typephp_helper.h`; the criterion is whether the semantics are TypePHP-specific.

### 2.2 Positive and Negative Examples

```cpp
// Correct: the constructor chain semantics are TypePHP-specific.
typephp_call_parent_constructor(object, constructor, args);

// Incorrect: this only materializes an INDIRECT zval into a plain value and is not TypePHP-specific.
typephp_deindirect(value);

// Correct: generic Zend value wrapping belongs to PHPX.
php::deindirect(value);
```

## 3. `php::`: C++ Wrappers for ZendAPI

`namespace php` is provided by PHPX to wrap Zend's C API, macros, raw pointers, and manual resource management into a type-safe, RAII-friendly C++ API.

This naming domain contains two categories of capabilities:

1. PHP values and runtime objects, such as `php::Var`, `php::Str`, `php::Array`, and `php::Object`;
2. Safe wrappers of ZendAPI, such as symbol lookup, scope management, value conversion, object creation, and invocation.

Examples:

```cpp
php::Var value;
php::Array arguments;

auto plain = php::deindirect(value);
auto called_ce = php::getCalledCe(this_);
auto scope = php::getCallableScope(function, this_);
auto create_object = php::getCreateObjectFn(class_entry);
auto globals = php::globalsArray();
```

### 3.1 When to Use `php::`

Place into `namespace php` when the following conditions are met:

- The API is meaningful to any PHPX C++ caller;
- The API's behavior can be fully explained by Zend/PHP runtime semantics;
- The API does not depend on TypePHP AST, compile-time Attributes, or TypePHP-specific language rules;
- The API's main purpose is to hide Zend macros, raw `zval *`, reference counting, or exception checking.

### 3.2 Forbidding Global `php_*` Helpers

The following legacy forms are forbidden:

```cpp
php::Var php_deindirect(const php::Var &value);
php::Str php_get_called_class(php::Object &this_);
zend_class_entry *php_get_called_ce(php::Object &this_);
auto php_get_create_object_fn(zend_class_entry *ce);
```

They must be written as:

```cpp
namespace php {

Var deindirect(const Var &value);
Str getCalledClass(Object &this_);
zend_class_entry *getCalledCe(Object &this_);
auto getCreateObjectFn(zend_class_entry *ce);

}  // namespace php
```

The reason is that users can legitimately declare:

```php
function deindirect(mixed $value): mixed {}
function get_called_ce(): string {}
function get_create_object_fn(): string {}
```

These PHP functions generate `php_deindirect`, `php_get_called_ce`, and
`php_get_create_object_fn`. If PHPX also defines same-named helpers globally, conflicts may occur at the declaration, overload resolution, or linking stage.

### 3.3 Naming Style

The PHPX C++ API uses the existing camelCase style:

```cpp
php::getCalledClass();
php::getClassEntrySafe();
php::getPersistentCache();
php::stdCreateObject();
```

Do not mechanically preserve Zend's snake_case names as global C++ names. Lower-level calls can continue to use the original Zend API, such as `zend_objects_new()`, but the wrapper layer exposed to generated code should use `php::`.

## 4. `typephp_project_<project>`: Project-private Namespace

Each TypePHP compiled project has an independent C++ namespace:

```text
typephp_project_<target-name>
```

For example, if the project name is `tpc`:

```cpp
namespace typephp_project_tpc {
    // Project-private generated state and helpers.
}
```

The `-` and `*` in the project name are converted to `_`, and the remaining characters must satisfy the compiler's target identifier validation. The distinct `typephp_project_` prefix prevents generated namespaces from colliding with global `typephp_*` runtime helpers. It also keeps the final C++ namespace valid when the project name starts with a digit.

### 4.1 Content That Should Go into This Namespace

- The literal string table and `get_str()`;
- The class/function/property cache tables and their accessor functions;
- Global variable storage of the current project;
- Class entries, object handlers, and default property templates;
- Module entry and MINIT/RINIT/RSHUTDOWN auxiliary state;
- Functions such as `module_init()` and `module_clean()` that are called only inside the generated extension file;
- Project-level generated state such as the Python module cache.

Illustration:

```cpp
namespace typephp_project_demo {

static php::Str literal_strings[] = {
    php::Str{"hello"},
};

php::Str &get_str(uint32_t index) {
    return literal_strings[index];
}

static THREAD_LOCAL zend_class_entry *class_map[8];

zend_class_entry *get_class(int id, const php::Str &name) {
    // Resolve and cache a symbol owned by this project.
}

static void module_init() {
    // Initialize this project's generated state.
}

}  // namespace typephp_project_demo
```

### 4.2 Visibility and ABI

- Names inside `typephp_project_<project>` are implementation details, not library stub ABI.
- Objects and functions that can be limited to `static` should continue to be marked `static`.
- Generated headers may declare project-internal accessors that must be used across translation units, but must not expose underlying arrays or cache tables.
- External handwritten C++ code must not depend on literal indexes, cache indexes, or project-internal storage names.
- Different TypePHP projects can be linked into the same process, because the same internal short names reside in different project namespaces.

### 4.3 Scope Takes Priority over Name Spelling

Historical generated names may still appear in the project namespace, for example:

```cpp
typephp_project_demo::php_class_entry_App_User
```

Although the member name starts with `php_`, the full symbol resides in `typephp_project_demo`, so it is a project-private implementation rather than the global user callable ABI described in Section 5. New project-internal helpers should prefer short names without `php_`, such as `get_class()`, `get_func()`, and `get_str()`.

## 5. `php_`: The C++ ABI of User PHP Callables

The global `php_` prefix is used by TypePHP to map user-declared PHP functions and class methods into C++ callable symbols. This naming is used by generated code, library stubs, and external C++ implementations alike, so it cannot be changed arbitrarily.

Example:

```php
namespace App;

function greet(string $name): string {}

class User
{
    public function save(): bool {}
}
```

The conceptual C++ symbols are:

```cpp
php::Str php_app__greet(php::Str name);
php::Bool php_app__user__save(php::Object &this_);
```

The rules include:

- Use `php_` to mark "mapped from a PHP declaration";
- PHP namespace, class, and method/function names are combined after normalization;
- `__` is the existing ABI combination separator;
- The first parameter of an instance method is the object `this_`;
- Stubs, libraries, and consumers must use exactly the same mapping rules.

### 5.1 Why Internal Helpers Cannot Use `php_`

The `php_` mapping is not an independent reserved keyword space, but a mechanical ABI of user PHP names. The following user declaration:

```php
function deindirect(mixed $value): mixed {}
```

naturally generates:

```cpp
php::Var php_deindirect(php::Var value);
```

Therefore, if the framework defines a global `php_deindirect()`, it encroaches on the user symbol space. The correct approach is `php::deindirect()`.

### 5.2 Combination Collisions

Because the current ABI uses `__` to combine PHP namespace, class, and callable names, the following two PHP declarations may map to the same C++ symbol:

```php
function App\user__test(): void {}

namespace App;
class User
{
    public function test(): void {}
}
```

The compiler must detect this situation during the preprocessing stage and throw a FatalError; it must not be handled through overriding, link order, or added runtime dispatch. Changing the mapping separator rules would break existing stubs/ABI, so collisions must be resolved by the user through renaming.

### 5.3 Entry Symbol Exceptions

A small number of C ABI/embed entry points are fixed by the generator and do not belong to ordinary user callables. For example:

```cpp
php_<project>_embed_get_module();
typephp_<project>_runtime_init(argc, argv);
typephp_<project>_runtime_shutdown();
```

These are the connection points between the binary/library embed runtime and the current project's module entry. Definitions and references are uniformly generated through
`TYPEPHP_EMBED_GET_MODULE_FUNCTION()`, `TYPEPHP_RUNTIME_INIT_FUNCTION()`,
`TYPEPHP_RUNTIME_SHUTDOWN_FUNCTION()`, and the corresponding symbol macros, in a style consistent with Zend's
`PHP_MINIT_FUNCTION()`/`PHP_MINIT()`. The final symbols contain the project name and must not be used as a general helper naming template.

### 5.4 The Shared Runtime in Multi-extension Processes

TypePHP extensions must not separately compile or statically link a PHPX implementation containing process-level Zend state. The Reflection handler,
`FiberGenerator` class entry, scope, and Property Hook runtime are all provided solely by the shared `libphpx`:

- Host-mode extensions/libraries must link `libphpx.so`, `libphpx.dylib`, or `phpx.dll`, and must not fall back to `libphpx.a`;
- Unix PHP extensions do not link the Embed `libphp.so`; Zend/PHP symbols are provided by the SAPI that loads them;
- macOS extensions use `-undefined dynamic_lookup` to resolve host symbols;
- Binaries and standalone WASI programs can still link statically, because each process or Wasm instance has only one copy of the runtime.

`src/core/typephp_*.cc` only carries the TypePHP-specific `typephp_*` runtime; `php::` ZendAPI wrappers should be placed in core source files without the
`typephp_` prefix, such as `src/core/scope.cc`.

### 5.5 Class Constant Data Symbols

Array-valued class constants are not callables: each one is emitted as a `php::Var` object inside `typephp_project_<project>` (exported to generated
translation units via `using namespace`), while functions and methods are global-scope callables. To keep the two symbol families disjoint, their spelling
adds a `const__` segment and preserves the constant's original case:

```php
namespace App;

class User
{
    const ITEMS = [1, 2, 3];
}
```

```cpp
// Callable ABI (Section 5): global scope, normalized (lowercased) names.
php::Bool php_app__user__save(php::Object &this_);

// Class-constant data: project namespace, case-sensitive constant name.
namespace typephp_project_<project> {
extern php::Var php_const__app__user__ITEMS;
}
```

Rationale:

- PHP method names are case-insensitive but constant names are case-sensitive; without the `const__` segment a constant and a method whose names differ
  only by case would map to one C++ identifier and fail with MSVC C2872 / g++ "reference is ambiguous" at every use site.
- Preserving the constant's original case keeps two case-distinct constants in one class (`const A` + `const a`) from collapsing onto one symbol.
- The mapping is produced only by `getNativeClassConstName()` in `src/CompilerBase.php`; generated code must never rebuild this spelling by hand.

Regression test:

```text
tests/compiler/const/array-const-method-name-collision.phpt
```

## 6. Name Selection Flow

When adding a C++ API, judge in the following order:

1. **Is it the compiled body of a user PHP function or class method?**
   - Yes: use the existing `php_` callable ABI generator; do not handwrite another mapping.
2. **Does it serve only one current TypePHP project?**
   - Yes: place it in `typephp_project_<project>`, and use `static` or private accessors where possible.
3. **Does it implement TypePHP-specific semantics?**
   - Yes: use the `typephp_` prefix.
4. **Is it only a C++ wrapper of Zend/PHP runtime capabilities?**
   - Yes: place it in `namespace php`, using the PHPX camelCase style.
5. **None of the above?**
   - It should not be arbitrarily added to `typephp_helper.h`; reconfirm its owning module and public API boundary.

## 7. Code Review Checklist

When adding or modifying generated helpers, check:

- [ ] No new global `php_*` helpers in `typephp_helper.h`;
- [ ] ZendAPI wrappers are in `namespace php`;
- [ ] TypePHP-specific logic uses `typephp_`;
- [ ] Project caches and storage are in `typephp_project_<project>`;
- [ ] Project-private tables are not exposed directly via `extern` through generated headers;
- [ ] User callables still use the unified `php_` ABI generator;
- [ ] New names do not collide with user-declarable PHP functions or methods;
- [ ] bin, lib, ext, and WASM builds use the same project name derivation rule;
- [ ] Stub and existing ABI are evaluated together when modifying the public callable mapping;
- [ ] At least one compilation regression test is added for a user function with the same name.

The current related regression tests are:

```text
tests/compiler/basic/helper-symbol-collision.phpt
tests/compiler/const/array-const-method-name-collision.phpt
```

## 8. Main Implementation Locations

| Responsibility | File |
| --- | --- |
| `php_` callable prefix and combination separator | `src/CompilerBase.php` |
| Array class-constant data symbols (`php_const__`) | `src/CompilerBase.php`, `src/Translator.php` |
| Callable combination collision detection | `src/Preprocessor.php` |
| `typephp_project_<project>` generation and project-private tables | `src/Translator.php` |
| TypePHP extension prefix constants | `src/Metadata/Constants.php` |
| PHPX/TypePHP helper classification | `vendor/swoole/phpx/include/typephp_helper.h` |
| Embed module accessor concatenation | `vendor/swoole/phpx/src/misc/typephp_main.cc` |
