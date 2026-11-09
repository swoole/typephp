# PHP Incompatibility Classification

This document classifies TypePHP AOT incompatibilities by their cause and
expected future direction.

The goal is to distinguish:

- Hard limits that should not be promised as fully compatible PHP behavior.
- Intentional TypePHP language rules.
- Features that are implementable but not supported yet.
- Partial support where the current behavior is known to differ from PHP.

The main compatibility checklist remains
[INCOMPATIBLE_PHP_FEATURES.md](INCOMPATIBLE_PHP_FEATURES.md). This document
explains how those items should be interpreted.

## Categories

### Hard Limit

The feature conflicts with the current AOT execution model, or exact PHP
compatibility would require unreasonable runtime mirroring, heavy dynamic state,
or semantics that are not naturally visible from compiled C++ frames.

This does not always mean "theoretically impossible", but TypePHP should not
promise full PHP compatibility for these cases.

### Intentional Rule

The feature could be implemented, but TypePHP intentionally rejects or restricts
it to keep the AOT model explicit, predictable and optimizable.

These are language or product rules, not missing implementation work.

### Pending

The feature is technically implementable and should be described as currently
unsupported, not as impossible.

These items usually need better IR, symbol binding, runtime helpers, or codegen
lowering.

### Partial

The feature exists but does not fully match standard PHP behavior in all edge
cases.

These items should be documented with the exact boundary.

## Hard Limits and Non-Promised Compatibility

| Feature | Classification | Reason |
|---|---|---|
| Caller-context capture by `eval()` / `include` / `require` | Intentional Rule / Hard Limit | Only self-contained PHP code is supported. No capture or synchronization of TypePHP `$this`, scope, visibility privileges, locals, parameters, references, call stack, or other AOT execution state is promised. Native dispatch and property hoisting must not be disabled to emulate that context. |
| Standard PHP variable deletion semantics for `unset($nativeTypedVar)` | Hard Limit / Intentional Rule | Native typed locals are C++ variables, not entries in a PHP symbol table. They cannot be deleted like PHP zval variables. |
| Fully standard uninitialized / `unset()` semantics for fixed native typed object properties | Hard Limit / Intentional Rule | Fixed native property storage conflicts with PHP's dynamic property state, uninitialized state and unset behavior. |
| `Closure::bind()` with static closures accessing private members across AOT/native boundaries | Hard Limit / Partial | This depends on Zend closure scope, private visibility checks and AOT native method/property access. Partial support may be possible, but complete equivalence is difficult. |
| Non-UTF-8 source files | Intentional Rule | Other encodings could be converted before compilation, but TypePHP requires UTF-8 to keep parsing and generated code deterministic. |
| `declare(encoding=...)` values other than `UTF-8` | Intentional Rule | Same reason as source file encoding. |

## Intentional TypePHP Rules

| Feature | Classification | Reason |
|---|---|---|
| No executable statements in global scope | Intentional Rule | A global execution block could be generated, but it complicates initialization order, side effects and include-like behavior. TypePHP requires executable code to be under functions or methods. |
| Binary mode requires global `main()` | Intentional Rule | This defines the binary entry ABI. |
| `main()` only accepts no parameters or `(int $argc, array $argv)` | Intentional Rule | Keeps the entry ABI explicit and stable. |
| `main()` must return `void` | Intentional Rule | An integer exit-code convention could be added later, but current TypePHP rules reject return values. |
| TypePHP is always strict; `strict_types=0` is rejected | Intentional Rule | Per-file weak typing conflicts with TypePHP's fixed storage and static type guarantees. `strict_types=1` remains accepted as a redundant compatibility directive. |
| Default parameter before required parameter | Intentional Rule | PHP allows this legacy pattern but ignores the default. TypePHP rejects it to avoid misleading declarations. |
| Child class overriding parent private property | Intentional Rule / Pending if dynamicized | PHP stores private properties by declaring class. TypePHP native/fixed layouts make this expensive. Rejecting it keeps property layout predictable. |
| `__construct()` return value | Intentional Rule | PHP constructors should not return values. TypePHP rejects this explicitly. |
| Reassigning a statically inferred native local to an incompatible type | Intentional Rule | This is the cost of native type optimization. Use dynamic zval variables when PHP-style type changes are required. |
| Native `std::int` overflow and integer division behavior | Intentional Rule | Native numeric types trade PHP compatibility for performance and C++ storage. |
| `__CLASS__` outside class context and `__TRAIT__` outside trait context | Intentional Rule | PHP returns an empty string for legacy compatibility. TypePHP rejects this as a clearer rule. |
| Strict function argument counts | Intentional Rule | Non-variadic functions reject extra arguments. `func_get_args()` does not implicitly make a function variadic. |
| Reserved keyword methods such as `toArray()` | Intentional Rule | Conversion keywords are resolved before ordinary object methods to keep conversion lowering static and predictable. |
| Zero-initialized fixed typed property slots | Intentional Rule / Partial | Native fixed-layout slots use their type's zero value instead of preserving every Zend uninitialized-property transition. |
| Structural mutation of `std` containers during `foreach` | Intentional Rule | Native C++ iterators may be invalidated by append, insertion, erase or whole-container replacement. TypePHP rejects these operations inside the active loop while allowing non-structural element updates. |
| Automatic reference inference for dynamic calls | Intentional Rule | A runtime callable may resolve to a function, method, or Closure unknown to the compiler. TypePHP does not mirror callable signatures at runtime; callers must use `std::ref()` / `toRef()` explicitly. |
| By-reference variadic parameters on dynamic Closures | Intentional Rule | Supporting `&...` here would require signature-aware runtime argument packing. Statically resolved ordinary functions and methods support `&...`; dynamic Closures do not. |

## Implementable but Currently Unsupported

| Feature | Classification | Implementation Direction |
|---|---|---|
| PHP 8.5 `#[NoDiscard]` | Pending | Preserve PHP's warning behavior and timing consistently for native direct calls and Zend runtime fallbacks. TypePHP's compile-time `#[MustUse]` remains a separate feature. |
| Variable variables (`$$var`) | Pending | Add a function-local symbol table mirror for dynamic locals, and disable or synchronize native locals that escape into dynamic lookup. |
| Closure or arrow function returning by reference | Pending | Closure metadata and wrappers must preserve return-by-reference and emit `ReturnRef`. |
| PHP 8.5 closures in constants, parameter defaults or property defaults | Pending | Use context-aware runtime initializers: cache constants and property defaults per request, create parameter defaults per omitted call, and never place request-local zvals in persistent MINIT storage. |
| Reference assignment from complex static property expressions | Pending | Static property reference targets need complete lowering and lifetime handling. |
| Calls with unpack plus trailing named arguments staying native | Pending | Normalize and reorder call arguments in IR before native-call selection. |
| Dynamic `parent::method()` name | Pending | Needs runtime parent method lookup with correct call scope. |
| Private typed property access on cloned objects through variables | Pending / Partial | Requires a complete declaring-class-aware access resolver. |
| `echo` with assignment expressions | Pending | Requires expression lowering that preserves evaluation order and returns the assigned value. |
| Nested `match` expressions in arm conditions | Pending | Requires recursive match lowering and temporary value ordering. |
| `foreach` by-reference value targets beyond simple variables | Pending | Requires explicit lvalue/reference target modeling. |
| `foreach` list destructuring with by-reference items | Pending | Requires destructuring assignment to preserve references for selected list elements. |
| Dynamic `ClassName::class` | Pending | Runtime class-name resolution can be used when the class expression is dynamic. |
| `static::class` in runtime contexts | Pending / Partial | Runtime contexts can use called-class lookup. True compile-time constant contexts should remain unsupported. |
| Dynamic property chains, class names, function names and callbacks in native-optimized paths | Partial | Supported through a Zend runtime fallback. Native dispatch is only an optimization; automatic by-reference argument conversion remains unsupported. |
| First-class callable stored in nullable `Closure` typed property | Pending / Partial | Requires stable runtime lifetime, refcount and typed-property write handling. |
| Static analysis of union, intersection and nullable types | Pending optimization | Requires a real union/intersection type lattice instead of treating these as `mixed/any` during static analysis. |

## Partial Support and Behavioral Differences

| Feature | Classification | Boundary |
|---|---|---|
| `eval()` / `include` / `require`, including once variants | Partial / Intentional Rule | Execute independent PHP code through Zend VM. The only recommended interoperability pattern is returning a static closure and explicitly calling it from TypePHP with arguments. Caller-context capture, implicit local/parameter imports, scope propagation, shared `$GLOBALS` exchange, and reverse interaction with AOT execution state are outside the compatibility contract. |
| Dynamic calls and callbacks | Partial | Zend runtime fallback handles dynamic calls and callbacks. By-reference arguments still need explicit `std::ref()` / `toRef()`, and native-call optimization is not guaranteed. |
| Dynamic properties and dynamic property chains | Partial | Dynamic property reads and writes use the runtime property API; native property optimization is not guaranteed. |
| Native typed properties | Partial / Intentional Rule | Fast native paths may not preserve every PHP dynamic state transition. Unknown or incompatible values can fall back to `setProperty()`. |
| Reflection metadata | Partial | Runtime declarations preserve constructor-promotion and asymmetric-visibility flags; other AOT-specific metadata may still be incomplete. |
| Dynamic dimension assignment | Partial / Intentional Rule | Ordinary array and `ArrayAccess` paths must remain correct, but callback-driven container/key rebinding, deprecated scalar conversions, exact string-offset results, and diagnostic ordering are not promised to mirror ZendVM. A null array key is intentionally treated as append. |

## Self-hosting Compatibility Notes

The compiler itself is a TypePHP program, so an internal refactor can change
how its own calls are lowered even when the PHP source-level API is unchanged.
This is an implementation compatibility boundary, not a new user-facing rule
that reference parameters are unsupported.

| Internal pattern | Status | Boundary and required design |
|---|---|---|
| Statically resolved function or method with by-reference parameters | Supported | Native direct calls preserve reference slots and write-back semantics. This was the path used before the core methods were split into traits. |
| Cross-trait `$this->method()` with a by-reference output parameter | Self-hosting Partial | While compiling a trait body, the final consuming class may be unknown. The call can fall back to `this_.call()` with ordinary `ArgList` values, while the callee wrapper expects `getCallArgByRef()`. This produces a by-reference warning and loses write-back. |
| Cross-trait helper returning a value, tuple array or DTO | Supported / Required internally | Return data explicitly and assign it at the call site. Do not use by-reference output parameters for compiler services that may cross trait boundaries. |
| Moving an existing method into a trait | Requires bootstrap verification | Test both the PHP-source compiler and the newly bootstrapped `tpc`; source-compiler tests alone do not exercise the changed lowering path. |

The observed regression after refactoring followed this exact sequence:

1. Before extraction, calls such as file sorting, captured-statement appending and
   type declaration parsing were statically resolved inside the core class.
2. After extraction, their callers and implementations lived in different
   traits mixed into `Translator`, `CompilerBase` or `Preprocessor`.
3. The self-hosted compiler emitted Zend dynamic method calls for those
   cross-trait edges and passed ordinary values.
4. Callee wrappers still correctly advertised and parsed reference parameters,
   but they could not retroactively turn the caller's value argument into the
   caller's reference slot.
5. The fixes replaced internal output-parameter protocols with explicit return
   values. User-level statically known reference calls remain supported.

## Documentation Rule

When documenting a compatibility difference, use one of these labels:

- `Hard Limit`
- `Intentional Rule`
- `Pending`
- `Partial`

Avoid using only "unsupported" unless the reason is also clear.

Recommended wording:

- "Hard limit: not promised to match PHP exactly."
- "Intentional TypePHP rule."
- "Currently unsupported; implementable in a future compiler/runtime revision."
- "Partially supported with the following boundary."

This distinction helps users know whether they should rewrite code permanently,
wait for future support, or disable native optimization for a specific path.
