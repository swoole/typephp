# Unified Type Annotation Design

Status: a combined record of existing container behavior and target design. The `StdArray` annotation is implemented; `StdFunc` and `StdArgInfo` are deferred.

## Terminology and responsibilities

`StdArray`, `StdVector`, `StdMap`, `StdOrderedMap`, `StdList`, `StdDict`, and `StdFunc` are collectively **type annotations**. They describe TypePHP static contracts, rather than runtime validators or compile-time functions that create containers.

- `std::vector(...)`, `std::list(...)`, etc. are compile-time value factories.
- `#[StdVector(...)]`, `#[StdList(...)]`, etc. annotate parameter or property contracts.
- Proposed nested `new StdList(...)` expressions describe types; they do not create arrays.
- `new StdArgInfo(...)` describes one function parameter: its value type, Nullable, reference mode, Optional, Variadic, and default value.

See [StdFunc and StdArgInfo](STD_FUNC_DESIGN.md), [typed PHP arrays](TYPED_ARRAYS.md), and [C++ containers](STD_CONTAINERS.md).

## Type and storage models

| Type annotation | Value factory | Storage and passing | Contract |
|---|---|---|---|
| `StdArray(T, sizeOrDimensions)` | `std::array(T, N)` or existing nested factories | PHPX Box containing fixed-size C++ template instances | Leaf type, full shape, no holes |
| `StdVector(T)` | `std::vector(T[, size])` | PHPX Box containing a C++ template instance | Contiguous integer indices and element type |
| `StdMap(K, V)` | `std::map(K, V)` | PHPX Box containing a C++ hash map | Key and value types |
| `StdOrderedMap(K, V)` | `std::orderedMap(K, V)` | PHPX Box containing a C++ ordered map | Key and value types |
| `StdList(T)` | `std::list(T)` | Ordinary PHP array with COW | Integer keys, value type, append permitted |
| `StdDict(K, V)` | `std::dict(K, V)` | Ordinary PHP array with COW | Key and value types, explicit keys required |
| `StdFunc(R, args)` | Existing function or closure value | Proposed signature-bearing callable | Parameters, result, references, and omission rules |

Both the `std::array` container and its `StdArray` annotation are implemented. The annotation declares parameter/property contracts; it does not create values.

Every value factory also accepts one non-empty PHP array initializer: `std::array($values)`, `std::vector($values)`, `std::map($values)`, `std::orderedMap($values)`, `std::list($values)`, and `std::dict($values)`. The compiler infers the contract from arbitrary key/value expressions whose static types are known. Types must match exactly; keys must be definite Int or Str values, and a var/any/mixed key or value is rejected. StdArray additionally infers and validates a rectangular shape. Empty arrays must use an explicit-type factory.

Similar type arguments do not make storage models interchangeable. A Box is not a PHP array, and annotations do not change PHP's native type system.

## Declarations and compatible PHP types

```php
class State
{
    #[StdVector(Type::Int)] public box $values;
    #[StdList(MyUser::class)] public array $users = [];
    #[StdDict(Type::Str, Type::Int)] public $counts = [];
}

function append(#[StdList(Type::Int)] array &$items): void
{
    $items[] = 42;
}
```

An annotation supplies the full contract. The PHP type may be omitted or name compatible storage:

- `box` for `StdVector`, `StdMap`, and `StdOrderedMap`.
- An omitted PHP type or `box` for `StdArray`, not PHP `array` storage.
- `array` for `StdList` and `StdDict`.
- A `callable` parameter for proposed `StdFunc`; nullable callback bindings require compatible nullable declarations, as specified in its design.

Explicit `mixed`, `any`, and incompatible PHP types are rejected. `Type::Any` as a container element type is a separate concept and does not permit `mixed $parameter`.

A declaration has one primary type annotation; duplicates and conflicting container kinds are rejected. Existing container parameter/property declarations do not support nullable types or unions. Proposed `StdArgInfo` modifiers do not retroactively enable these declaration features.

## C++ container indices and lifetime

- Fixed-size `std::array` indices must be in `[0, N)`. Known integer-literal indices are checked at compile time; other indices use runtime `safeIndex()` checks.
- Variable-length `std::vector` does not track its current length at compile time. Reads and writes check `[0, size)`. `$v[size] = ...` is not append; use `$v[] = ...`.
- Array/vector never contain holes. `unset($v[$i])` resets the element to its default without removing its position.
- Map/orderedMap keys support Int or Str (String is an alias). Their C++ rules are separate from PHP dict numeric-string key normalization.
- Existing Box parameter entry checks the Box, container kind, and element contract, then recovers a concrete C++ reference. It does not copy or convert every element; mutations are visible to the caller.
- Existing Box parameters reject references, variadics, defaults, and property promotion. Native objects cannot cross this Box boundary.
- Existing static iteration restrictions and runtime alias guards continue to protect structural mutations.

## StdArray: dimension arrays describe nesting

**Special case: StdArray uses a different annotation form from other containers.** StdVector/StdList describe an element type, and StdMap/StdOrderedMap/StdDict describe key/value types. StdArray must describe both its leaf element type and fixed shape; the second argument is a length or dimension array, not a key type or another container type. Only StdArray supports structural nesting, so other containers' type-argument counts and parsing rules cannot be reused unchanged. Declaration checks, matching, caches, and stubs must retain dimensions.

Do not use recursive `new StdArray(new StdArray(...), ...)` descriptors or other container types as structural leaf types. Declarations are:

```php
function process(#[StdArray(Type::Int, 100)] box $values): void {}
function matrix(#[StdArray(Type::Int, [100, 200, 8])] box $values): void {}
```

The second argument is one length or a nonempty dimension array, ordered outermost to innermost. `[100, 200, 8]` means `100 × 200 × 8`, accessed as `$values[$i][$j][$k]` with respective bounds of 100, 200, and 8.

- `StdArray(T, 100)` and `StdArray(T, [100])` normalize to the same type.
- Retain the leaf type, resolved class, and outer-to-inner `dimensions`. Equality includes rank, order, and each length, not merely total element count.
- Dimensions are integer literals, never dynamic variables, constant expressions, or function calls. Negative dimensions are invalid; zero-length dimensions follow C++ `std::array<T, 0>`. Total element and estimated-byte calculations check overflow.
- Only StdArray supports structural nesting. Multidimensional contracts lower to existing nested C++ `StdArray` storage. Existing nested `std::array(...)` value-factory syntax stays unchanged.
- Each index consumes one dimension. For example, `$values[$i]` from this three-dimensional container has type `StdArray(T, [200, 8])`.
- Simpler syntax does not solve subarray ownership. This implementation restores complete StdArray parameters only. Shared borrowing, subarray parameters, reference assignment, and raw subarray pointers require separate lifetime design and are not automatically enabled.
- Default initialization recursively preserves the full shape. If Optional StdArray parameters are later enabled, their empty value is a correctly shaped default-initialized container, not ordinary `[]`; allocation, class-element initialization, and lifetime still need validation.

Parameter entry validates the Box, container kind, leaf type, and full shape before recovering the concrete C++ reference. Properties retain the same contract but still follow the existing property-read recovery boundary below. Declaration caches and library stubs retain dimensions. Independent subarray-parameter propagation is not enabled.

## StdList / StdDict keys and values

Typed PHP arrays primarily establish their constraints statically. They introduce no runtime typed-array object and do not change PHP HashTable storage. Explicit `toStdList()` / `toStdDict()` conversion uses PHPX `toTypedArray()` to check each entry of the source array.

- A list is an integer-key PHP array, allowing negative keys, sparse keys, and holes. It permits append.
- A dict declares Int or Str keys and requires explicit keys, even for integer-key dicts.
- Lists and integer-key dicts share storage, but not contracts or parameter compatibility.
- Non-var/any keys must match their declared type; mismatches are compilation errors, not implicit conversions.
- Var/any keys use PHPX's internal exact integer/string extraction checks. Generated helpers can use existing `php::toIntExact` / `php::toStringExact` wrappers. Public keyword methods remain `toInt()` / `toString()`; no public `toExactInt()` method is introduced.
- String-key dicts retain PHP numeric-string key normalization in storage. `foreach` converts normalized numeric keys back to Str, keeping the iteration variable's type definite without forcing HashTable keys to remain strings.
- Values support PHP value types and non-Native class contracts such as `MyUser::class`. Writes follow static assignment compatibility. `Type::Any` values remain dynamic; arbitrary var values are not automatically trusted as concrete types.
- Foreach key/value types are definite: Int keys for lists, declared keys for dicts, and the declared value type.

## Propagation, parameters, and aliases

Ordinary local list/dict assignment propagates the contract and preserves PHP COW. Reference assignment propagates the same contract and alias relationship. TypePHP function/method parameters, including reference parameters, require annotations with the same kind and key/value types.

Direct index reads from a statically resolved `StdList` or `StdDict` property without hooks use the declared element type. By-value `foreach` uses the declared key and value types for simple locals with compatible storage. The compiler checks each consumed element because the property can be replaced. It does not scan the array. Use `isset` or `??` for missing keys. A by-value copy of the whole property remains an ordinary PHP array with COW.

The target design also propagates property contracts:

```php
$box = $obj->values; // Target: inherit StdVector(Type::Int).
```

This is not a completed implementation promise. Properties retain type metadata, but automatic Box-property recovery, ownership lifetime, dynamic replacement checks, and closed PHP-array property mutation protection still require implementation and acceptance tests. A local C++ reference must not borrow a container whose property owner may be replaced or destroyed.

Shared Box contents and PHP-array COW are different semantics. Assignment, parameter passing, and reference passing cannot all use one copy strategy.

## Dynamic PHP boundaries

The goal is preventing dynamic code from invalidating a static contract, not rescanning an entire array on each read.

- Typed PHP arrays permit audited read-only array builtins and TypePHP reads, such as `array_search` and `count`.
- Dynamic mutation through `array_push`, sorting, references, or callbacks is forbidden. A read-only result does not prove a call has no mutation side effects.
- `std::ref()`, element references, and dynamic reference escape paths are forbidden. Ordinary callable or unannotated `array` parameters cannot recover a trusted list/dict contract.
- Array results of read-only operations normally remain ordinary PHP arrays unless their result contract is separately proven and propagated.
- TypePHP rejects explicit reference acquisition for statically resolved typed array properties and their elements. The annotations still allow whole-property replacement and cannot prevent mutation through arbitrary dynamic Zend objects. Reads validate elements before using their declared types.
- List/dict descriptors within `StdFunc` grant no exception: both the callback and its bridge must preserve the contract, rather than using an ordinary Zend Bridge that loses metadata.

The historical `ArrayDef` has been removed in favor of `StdList` / `StdDict`. Its old hole restrictions no longer apply to PHP typed arrays. Independent C++ array/vector bounds checks remain intact.

## Metadata and acceptance

Canonical contracts include the kind, key/value types, and resolved class names. Declaration caches must not retain temporary type IDs from a particular build. Declarations, parameters, properties, locals, exported library stubs, and dependency invalidation share the same contract semantics.

Nested descriptors use whitelisted AST parsing, not execution of user functions or constructors. IR and diagnostics distinguish container values, type descriptors, and native PHP types.

Acceptance covers compatible/conflicting PHP types, kind mismatches, classes, strict dynamic keys, string-key iteration, COW and aliases, array/vector bounds, read-only/mutation boundaries, property replacement and lifetime, caching, and stub round trips. Documentation alone must not mark incomplete property protection as implemented.
