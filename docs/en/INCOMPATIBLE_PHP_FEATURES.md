# AOT/PHP Incompatibility List

This document lists only the key areas in which the current AOT compiler is
incompatible with or more restrictive than standard PHP.

## Program structure

- Executable statements are not allowed at global scope; only static constructs
  such as declarations, `use`, `declare`, and constant definitions are allowed.
- Function declarations are not allowed inside functions or methods.
- Named class declarations are not allowed inside functions or methods.
- Binary mode must define a global `main()`.
- `main()` may either take no parameters or have the signature
  `(int $argc, array $argv)`.
- `main()` must return `void`.

## Declarations and types

- Variable variables `$$var` are not supported.
- PHP 8.5 `#[NoDiscard]` is not supported yet.
- The PHP 8.5 `(void)` cast is supported for explicitly discarding a value; the
  operand is still evaluated and its side effects are preserved, and the cast
  cannot be used in value contexts such as assignments, returns, arguments, or
  conditions.
- Support for PHP 8.5 `clone()` / clone-with requires the linked `libphp` to be
  version 8.5 or later. Public and dynamic properties, private/protected/readonly
  properties, property hooks, call ordering, error propagation, and callable
  paths are all covered by PHPT tests.
- PHP 8.4 property hooks are compiled into AOT getters/setters and register the
  corresponding Zend hook metadata; direct property reads/writes, Reflection,
  and object iteration are all supported. Taking a reference to a hooked
  property is currently not supported.
- Interface property hooks do not currently support an explicitly declared
  `set` parameter; use the implicit setter value parameter.
- PHP 8.4 Reflection Lazy Objects cannot be used with TypePHP AOT classes. AOT
  classes are registered as persistent internal classes, and Zend's
  `zend_object_make_lazy()` explicitly rejects internal classes. Zend PHP user
  classes loaded dynamically at runtime are not subject to this restriction.
- `private(set)` and `protected(set)` asymmetric property visibility is
  supported, including constructor property promotion. Zend-backed objects
  perform the scope check through the PHP 8.4+ class-level object handler and
  preserve the promoted / set-visibility / implicit-final reflection flags;
  Native objects enforce the equivalent scope rules through compile-time access
  checks.
- Final properties declared through constructor promotion are supported, but
  TypePHP requires an explicit `public`, `protected`, or `private` modifier;
  PHP 8.5's implicitly public form, `final int $value`, is not accepted. As a
  TypePHP extension, this syntax is independent of the PHP source-syntax version
  supported by the linked `libphp` and remains available when using a PHP 8.4
  `libphp.so`.
- TypePHP forbids attributes on global or namespaced constant declarations; PHP
  8.5 global constant attributes are out of scope. Class constant attributes are
  not affected by this restriction.
- A `.stub.php` file only declares Zend ABI symbols supplied by external C++ and
  must not use `#[Native]` on its classes. Native Class object layouts must be
  generated and owned by the TypePHP compiler.
- Returning by reference from closures or arrow functions is not supported.
- PHP 8.5 `static function` expressions in global constants, class constants,
  parameter defaults, or property defaults are not supported yet. Closures
  nested inside initializer expressions are likewise rejected at compile time.
- `__construct()` may not have a return value.
- A parameter with a default value may not appear before a required parameter
  (PHP permits this legacy pattern but treats the former parameter as required).
- By-reference variadic parameters `&...$args` are supported for ordinary
  functions and methods whose signature is known at compile time, including
  direct, named, and unpacked arguments. A by-reference variadic declaration on
  a dynamic Closure is not supported.
- Union, intersection, and nullable types are still represented as `mixed/any`
  in C++, but the static analysis phase uses known expression types to reject
  definitely incompatible arguments, return values, and property assignments
  ahead of time; dynamic values still retain their runtime type checks.
- Once a local variable's type has been statically inferred as a concrete native
  type, reassigning it to an incompatible type within the same scope is not
  supported.

## declare

- `declare(ticks=...)` is not supported.
- `declare(encoding=...)` accepts only `UTF-8`.
- TypePHP always uses strict typing. `declare(strict_types=1)` is accepted but redundant; `strict_types=0` is rejected.
- No other `declare` directives are supported.

## Calls and references

- `exit(message: $value)` is available as a TypePHP named-argument extension; it
  enters the same exit path as the positional form `exit($value)`.
- TypePHP uses strict argument-count rules: non-variadic functions do not accept
  extra arguments beyond the declared signature, and `func_get_args()` does not
  implicitly relax the signature.
- Reference parameters and write-back semantics are supported for ordinary
  functions, ordinary methods, and native direct calls with known signatures;
  do not mistakenly describe the compiler's internal cross-trait dynamic-dispatch
  limitation as "TypePHP does not support reference parameters".
- Closures and arrow functions support fixed by-reference parameters. Because a
  Closure invocation is dynamically dispatched, the caller must still mark
  reference arguments explicitly with `std::ref()` / `toRef()`; Zend callbacks
  use the generated Closure arginfo automatically.
- Reference assignment cannot create a reference from a complex static-property
  expression.
- Calls whose argument signature cannot be determined at compile time — dynamic
  calls, closure calls, and the like — cannot convert reference parameters
  automatically; `std::ref()` or the equivalent keyword method `toRef()` must be
  used explicitly.
- `std::ref()` / `toRef()` only accept variables, array elements, or object
  properties.
- Fixed `int`, `float`, `bool`, `string`, and `array` locals support a restricted
  native-reference model. A one-time top-level binding such as `$alias =& $value`
  becomes a C++ `T&`, and an exact `int/string/float/bool/array &$arg` on a
  statically resolved TypePHP call also uses `T&` without boxing or allocating a
  Zend reference. Rebinding, conditional/loop-local first binding, `unset`,
  returning the local by reference, or storing the reference in a
  property/array/global is rejected because the C++ reference may not escape or
  change its target. Closure `use (&$value)` is an explicit degradation boundary:
  before parsing the function body, the compiler marks the local for `php::Var`
  storage starting at its first assignment, so an escaping Closure can retain a
  normal Zend reference. A strongly typed parameter keeps its original call ABI
  and is copied into a same-named local `php::Var` slot at function entry.
- Dynamic calls and Closure calls still require explicit `std::ref()` / `toRef()`.
  TypePHP creates a call-scoped Zend reference, validates its type on write-back,
  and reports an error if dynamic code retains it beyond the call. Code requiring
  unrestricted PHP reference identity should initialize the local with
  `std::any()` and use the existing `php::Var`/`php::Ref` path.
- Ordinary object, resource/stream, and high-precision locals also degrade to
  `php::Var` when captured by reference. Native-object and `std`-container locals
  cannot safely discard their compile-time storage layouts and still cannot be
  captured this way. These values already have
  handle/reference-like semantics, while rebinding their statically typed local
  slot would weaken the type system. Typed object/static properties remain
  reference-capable because Zend attaches property type sources; PHP array
  elements remain dynamic reference-capable slots.
- A fixed `int`, `float`, `bool`, `string`, or `array` Native Class property may
  be passed directly to an exactly matching reference parameter on a statically
  resolved call. This is a call-scoped C++ `T&`, not a PHP reference: `=&`,
  `std::ref()`, dynamic calls, reference returns, and other escaping forms remain
  forbidden for fixed Native properties. Only a Native property explicitly
  declared `any` supports the ordinary dynamic PHP reference model.
- A call that uses argument unpacking followed by named arguments falls back to
  dynamic dispatch and cannot use the native call path.

## Object model

- Reserved keyword methods such as `toInt()`, `toString()`, and `toArray()` are
  resolved before ordinary object methods; an application method of the same
  name that takes arguments is not called with ordinary object-method semantics.
- `toAny()` and `toRef()` are non-overridable TypePHP keyword methods, and
  ordinary class-like declarations must not define methods with these names
  (method names are case-insensitive, per PHP rules). A Native class may only
  explicitly define a `toAny()` conversion method returning `mixed/any`; no
  implicit conversion is provided. Native classes do not support `toRef()`.
- Fixed-layout typed properties that are not explicitly initialized use the
  zero value of their type and do not preserve Zend PHP's full uninitialized
  state; expressions such as `??` that depend on the uninitialized state may
  therefore behave differently.
- A subclass may not shadow a parent's private property with a `private`
  property of the same name; `public` / `protected` declarations of the same name
  are treated as the same inherited property slot and must still satisfy the
  type, visibility, and `readonly` compatibility requirements.
- Dynamic writes to typed properties still use strict type checks. When the
  right-hand side cannot be determined at compile time, TypePHP preserves a
  runtime check rather than falling back to Zend weak scalar conversion.
- Native Classes use a separate fixed-layout object model. They cannot be used
  as ordinary Zend Objects, PHP array keys/values, or arbitrary `mixed` values,
  and they restrict dynamic members, references, static/readonly members, and
  several operators. See [Native Class Object Design](NATIVE_CLASS_OBJECT.md)
  for the complete boundary.

## Expressions and control flow

- When an operand is statically known to be an `array`, arithmetic, bitwise
  operations and compound assignments unsupported by PHP are compile-time
  errors, including `array + int/float/bool/string/null/resource/object` and
  `array * array`. `array + array` and `array += array` use PHP union semantics:
  preserve keys and order, and keep the left value for duplicate keys.
  Potentially valid combinations such as `array + mixed` retain runtime checks;
  array comparisons and logical operations remain supported.
- Dynamic dimension writes use PHPX's array/object/string abstractions. Exact
  ZendVM behavior is not promised when a key expression, `ArrayAccess` callback,
  or right-hand side rebinds the container or key between the read and write
  phases. Unsupported scalar containers raise a stable PHPX error instead of
  reproducing every Zend conversion, deprecation, and diagnostic detail.
- TypePHP intentionally treats a null array key as an append operation rather
  than converting it to an empty-string key.
- A `match` arm condition may not itself be a `match` expression.
- The value target in a by-reference `foreach` may only be a variable.
- `foreach` list destructuring does not support binding elements by reference.
- On the non-`int/bool` lowering path, every non-empty `switch` case must end in
  `return`, `break`, `continue`, `exit`, or `throw`; do not rely on implicit PHP
  case fallthrough. The native `int/bool` switch path can currently retain C++
  fallthrough, so project code should terminate every non-empty case explicitly.
- Appending, inserting, `unset()`, and wholesale replacement of `std::vector`,
  `std::map`, and `std::orderedMap` are forbidden during a `foreach`;
  non-structural updates of existing elements can still be done with assignment
  operators.
- Fixed native typed object properties cannot be freely `unset()` with PHP's
  standard uninitialized-property semantics.
- Calling `unset()` on a native-typed variable does not delete the variable as
  it would in standard PHP.

## Runtime dynamic capabilities

- Ordinary Zend objects and dynamic class expressions support runtime `::class`
  and class-constant lookup. Native Classes still require the relevant class
  target to be known at compile time.
- `static::class` is not supported in positions that require a compile-time
  constant class name.
- `__CLASS__` may only be used within a `class` definition (PHP allows
  it elsewhere and returns an empty string).
- `__TRAIT__` may only be used within a `trait` definition (PHP allows
  it elsewhere and returns an empty string).
- Dynamic property chains, dynamic class names, dynamic function names, and
  dynamic callbacks all go through the Zend runtime fallback and are not
  guaranteed to be natively optimized; reference parameters of dynamic calls
  still require an explicit `std::ref()` or `toRef()`.
- `Closure::bind()`, `Closure::bindTo()`, and `Closure::call()` are not
  supported. A closure cannot be rebound to an object or class scope in AOT
  code.
- All source files must be encoded as `UTF-8`.

## Generators

TypePHP generators use `FiberGenerator` rather than Zend `Generator`. Code must
therefore not rely on `instanceof Generator`, `ReflectionGenerator`, Zend
Generator's internal object layout, or identical exception traces. Generators
do not support returning by reference, by-reference yield, by-reference
`foreach`, by-reference parameters, or variadic parameters. Fiber and Generator
are not currently supported by the WASI target. See
[Generators](YIELD_GENERATOR.md) for the complete boundary.
