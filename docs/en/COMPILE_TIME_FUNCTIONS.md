# AOT compile-time functions and keyword methods

This document records the compile-time functions, keyword methods, and related construction entry points that are specific to the AOT compiler. They are not part of standard PHP syntax, and an ordinary PHP runtime can only rely on the compatibility stubs provided by `src/polyfills.php`.

## Global function names

TypePHP does not reserve global function names for compiler directives. The
compile-time API occupies two global class symbols: `Type::*` only describes
types for extension-method metadata, while `std::*` contains TypePHP built-in
functions. Object type assertions use `std::object()` or the equivalent
`toObject()` keyword method.

The `std` / `Type` class names and `std` method names are case-insensitive, as
PHP class and method names are. `Type::*` members are class constants, whose
names remain case-sensitive.

## Keyword methods

There are currently 13 built-in keyword methods.

| Name | Equivalent behavior | Description |
| --- | --- | --- |
| `toAny()` | `std::any($receiver)` | Returns the receiver itself, but with the type degraded to `mixed/any`. |
| `toRef()` | `std::ref($receiver)` | Returns a reference to the receiver; parameter restrictions are the same as `std::ref()`. |
| `toObject()` | `php::toObject($receiver)` | May take a target-class parameter, performing object conversion/checking. |
| `instanceOf($class)` | `$receiver instanceof $class` | Returns bool; available to typed objects, ordinary objects, and objects held in mixed values. |
| `toInt()` | `php::toInt($receiver)` | Converts to a native int expression. |
| `toFloat()` | `php::toFloat($receiver)` | Converts to a native float expression. |
| `toString()` | `php::toString($receiver)` | Converts to a string expression. |
| `toBool()` | `php::toBool($receiver)` | Converts to a bool expression. |
| `toArray()` | `php::toArray($receiver)` | Converts to an array expression. |
| `toStream()` | `php::toStream($receiver)` | Converts to a stream expression. |
| `toBigInt()` | `php::BigInt::newInstance($receiver)` | Constructs a BigInt. |
| `toBigFloat()` | `php::BigFloat::newInstance($receiver)` | Constructs a BigFloat. |
| `toDecimal()` | `php::Decimal::newInstance($receiver)` | Constructs a Decimal. |

Constraints:

- `toAny()` and `toRef()` accept no parameters.
- `toRef()` only applies to receivers that can take references.
- Keyword methods take precedence over ordinary methods and universal method dispatch.

`instanceOf()` accepts one class-name argument: a string, a dynamic class-name
expression, or `ClassName::class`. The named argument `class:` is supported.
Its name is case-insensitive and reserved in user classes, interfaces, and
traits; it cannot be exported as a first-class callable. It reuses the
`instanceof` operator lowering without calling an object method or `__call()`.

```php
$typed->instanceOf(User::class);
$object->instanceOf($className);
$nullable->instanceOf(User::class);  // false when the receiver is null
$nullable?->instanceOf(User::class); // null when the receiver is null; arguments are skipped

if ($value->instanceOf(User::class)) {
    // Known class literals retain instanceof's positive-branch type narrowing.
    $value->method();
}
```

The receiver and class-name expression are each evaluated once, from left to
right. Native Classes retain the existing static-resolution restrictions of
`instanceof`; `ClassName::class` allows compile-time type checks without passing
Native pointers to Zend.

## `std::` compile-time entry points

There are currently 17 `std::` compile-time entry points.

| Name | Purpose | Main limitation |
| --- | --- | --- |
| `std::int($value)` | Explicitly creates a native int expression. | Requires 1 value parameter. |
| `std::float($value)` | Explicitly creates a native float expression. | Requires 1 value parameter. |
| `std::bool($value)` | Explicitly creates a native bool expression. | Requires 1 value parameter. |
| `std::bigInt($value)` | Constructs a BigInt. | Implicit construction from a float variable is not allowed. |
| `std::decimal($value)` | Constructs a Decimal. | A float variable must be converted via string or integer; float literals are handled per the original literal. |
| `std::bigFloat($value)` | Constructs a BigFloat. | Requires 1 value parameter. |
| `std::any([$value])` | Degrades the expression to `mixed/any`; when omitted, the value defaults to `null`. | Native objects and native-object std containers cannot escape through it. |
| `std::object($value, ClassName::class)` | Checks an object and restores its concrete class information. | Requires exactly 2 non-unpacked arguments and a compile-time-resolvable class name. |
| `std::ref($target)` | Explicitly passes a target by reference. | Only accepts variables, array elements, or object properties and is only valid as a call argument wrapper. |
| `std::expected($condition)` | Marks a condition as usually true. | Accepts exactly one non-unpacked argument and returns bool. |
| `std::unexpected($condition)` | Marks a condition as usually false. | Accepts exactly one non-unpacked argument and returns bool. |
| `std::array($type, $size[, ...$sizes])` | Constructs a fixed-size std array. | Can only be used in the top-level scope of the variable's first assignment. |
| `std::vector($type[, $size])` | Constructs a std vector. | Can only be used in the top-level scope of the variable's first assignment. |
| `std::map($keyType, $valueType)` | Constructs a std map. | Can only be used in the top-level scope of the variable's first assignment. |
| `std::orderedMap($keyType, $valueType)` | Constructs a std ordered map. | Can only be used in the top-level scope of the variable's first assignment. |
| `std::list($valueType)` | Creates an integer-key typed PHP array with negative/sparse indices and append. | First assignment of a new function-local variable; strict dynamic keys, no dynamic reference mutation. |
| `std::dict($keyType, $valueType)` | Creates a typed PHP dictionary with `Type::Int` or `Type::Str` keys. | First assignment of a new function-local variable; explicit keys required, no append. |

Lists and dicts retain PHP array storage and copy-on-write. Values require matching static types, while dynamic `any` / `var` keys receive internal strict checks. Only read-only dynamic PHP array calls are allowed, without `std::ref()`. Parameters need exactly matching `StdList` / `StdDict` type annotations, with the PHP type omitted or declared as `array`, never `mixed`; matching native `&` parameters can modify the caller's array. See [Typed PHP Arrays and Type Annotations](TYPED_ARRAYS.md) for examples and boundary details.

## Std container conversion keyword methods

There are currently 6 Std container conversion keyword methods.

| Name | Purpose | Main limitation |
| --- | --- | --- |
| `toStdArray(...)` | Wraps the variable as a std array. | Can only be used in the top-level scope of the variable's first assignment. |
| `toStdVector(...)` | Wraps the variable as a std vector. | Can only be used in the top-level scope of the variable's first assignment. |
| `toStdMap(...)` | Wraps the variable as a std map. | Can only be used in the top-level scope of the variable's first assignment. |
| `toStdOrderedMap(...)` | Wraps the variable as a std ordered map. | Can only be used in the top-level scope of the variable's first assignment. |
| `toStdList($valueType)` | Converts to an integer-key typed PHP array. | An identical contract copies directly; other sources receive strict key and value checks. |
| `toStdDict($keyType, $valueType)` | Converts to a typed PHP dictionary. | Keys must be `Type::Int` or `Type::Str`; other rules match `toStdList()`. |

## Mechanisms not counted in this list

- `$array->any()` is a universal method that maps to PHP `array_any()`, not the `std::any()` compile-time function.
- `Type::*` are compile-time type-description constants, not functions.
- keyword extension methods are a user-defined extension method mechanism and are not part of the fixed built-in compile-time function list.

## Implementation constraints

Compile-time functions should be usable in any legal expression position and maintain consistent semantics across all paths:

- `std::any()` is handled through one lowering entry; assignments, parameters, return values, array elements, and operator subexpressions share the same semantics.
- `std::ref()` / `toRef()` share one reference-wrapper recognizer across argument parsing, SSA, and optimizer paths.
- `std::object($value, ClassName::class)` replaces the removed global `objval()` helper. It is equivalent to `$value->toObject(ClassName::class)`, while allowing ordinary PHP projects to provide a compatible `std::object()` polyfill.
- `std::expected()` / `std::unexpected()` generate `EXPECTED(...)` / `UNEXPECTED(...)` respectively and produce no PHP runtime function call.

Future refactoring goals:

- Establish a unified `CompileTimeFunctionResolver` or equivalent module.
- Reuse the same compile-time function metadata in `parseExpr()` / `detectTypeOfExpr()` / `detectClassOfExpr()` / argument parsing paths.
- Continue unifying reference-wrapper behavior across different expression paths.
