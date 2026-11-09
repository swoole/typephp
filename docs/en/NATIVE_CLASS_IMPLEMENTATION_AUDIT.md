# Native Class Implementation Acceptance Matrix

> Audit date: 2026-08-17  
> This document records the requirements, implementation entry points, and direct verification evidence for the `#[Native]` object model. It is the implementation-acceptance attachment for
> [NATIVE_CLASS_OBJECT.md](NATIVE_CLASS_OBJECT.md) and does not replace the semantic design document.

## 1. Acceptance principles

Every capability must simultaneously have:

1. a clear language boundary;
2. a locatable compiler or PHPX implementation;
3. direct evidence in positive PHPT, negative PHPUnit, or PHPX C++ unit tests.

Code alone, documentation alone, or "no failure currently observed" does not count as complete. Native Objects have no Zend representation, so any cross-boundary behavior that cannot be statically proven safe must be rejected before generating C++.

## 2. Object model and code generation

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| `#[Native]` used only on named classes | `NativeClassAttributeLowering`, `NativeClassSupportTrait` | `testRejectsNativeAttributeOnInterface/Trait/Enum/AnonymousClass` | Verified |
| No Zend class/object handlers registered | Native struct, descriptor, and free-function generation path | `clone-and-zend-invisible.phpt`, Reflection negative tests | Verified |
| Methods keep the `php_*` free-function ABI | Native method/virtual thunk generation path | `basic.phpt`, `chained-call.phpt` | Verified |
| Statically resolvable `new NativeClass()` uses the Native Heap | `CompilerBase::parseNew()`, `php::nativeConstruct()` | `basic.phpt`, `construction-gc-roots.phpt` | Verified |
| `new (expression)()` stays ordinary PHP dynamic instantiation | `parseNew()` enters the Native branch only for `Node\\Name` | `testLeavesDynamicClassExpressionsToTheOrdinaryPhpPath` | Verified |
| A Native object itself cannot serve as a dynamic class target | `assertNotNativeObjectDynamicClassTarget()` | dynamic new/static call/class constant negative tests | Verified |
| All unsupported usages terminate at compile time | Native boundary checks, type compatibility checks | 131 `NativeClassValidationTest` items | Verified |

## 3. Properties and fixed layout

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| All properties must declare types | Native field validation | `testRejectsUntypedProperty` | Verified |
| bool/int/float use fixed value fields | Native field C++ type mapping | `basic.phpt`, `numeric-properties.phpt` | Verified |
| string/array/object/typed object/Stream/mixed usable as fields | Native PHPX field mapping, write checks | `phpx-properties.phpt`, `stream-property.phpt`, `composite-property-types.phpt` | Verified |
| BigInt/BigFloat/Decimal usable as fields | high-precision field mapping and trace/destroy | `high-precision-properties.phpt` | Verified |
| Native type fields hold raw pointers and can form cyclic types | struct forward declaration, descriptor trace | `mutual-reference-types.phpt`, `gc-cycle.phpt` | Verified |
| Fields without explicit initialization use deterministic zero values | Native field initializer | `zero-values.phpt` | Verified |
| Property writes keep the declared type | Native property assignment validation | composite, stream, and multiple negative PHPUnit | Verified |
| Only `any` properties may take PHP references | Native property reference lowering | `any-property-reference.phpt` and mixed/fixed property negative tests | Verified |
| `unset()` not supported on Native properties | property unset validator | `testRejectsUnsetOnNativeObjectProperties` | Verified |
| readonly properties not supported | Native declaration validator | `testRejectsReadonlyPropertyUntilNativeWriteStateIsImplemented` | Verified |
| Box/Std Container cannot be embedded in fields | Native field validator | Box/Std Container property negative tests | Verified |

## 4. Identity, nullability, and call ABI

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| `$a = $b` only copies the pointer and shares object identity | Native pointer local representation | `parameter-semantics.phpt` | Verified |
| Native parameters and returns must explicitly declare concrete classes | call argument/return boundary validation | untyped/mixed/interface parameter and return negative tests | Verified |
| Ordinary Native parameters are non-null; only `?Class` may be null | function entry/return checks | `non-null-parameter.phpt`, `nullable-signatures.phpt`, `return-nullability.phpt` | Verified |
| `&` forbidden on Native parameters, returns, and variables | reference boundary validation | reference parameter/return/assignment/function/method negative tests | Verified |
| Native variadic, union/intersection signatures not supported | signature validation | variadic/union/null-union negative tests | Verified |
| `unset($object)`/`$object = null` only clear the current pointer slot | Native root slot lowering | `unset-alias.phpt` | Verified |
| `===`/`!==` and `match` use pointer identity | Native identity lowering | `strict-identity.phpt`, `match-identity.phpt` | Verified |
| ternary/match/coalesce choose the nearest common Native base class for sibling subclasses | `getCommonNativeObjectClass()`, selection pointer cast | `value-selection.phpt`, cross-file global discovery tests | Verified |
| Conditional expressions check for non-null pointer without calling `toBool()` | Native condition lowering | `conditions.phpt` | Verified |
| Loose comparison, arithmetic, bitwise, increment/decrement, compound writes, and switch forbidden | operator validators | corresponding PHPUnit negative tests | Verified |
| `isset`/`empty`/`is_null`/nullsafe keep the typed pointer | Native selection/nullsafe lowering | `isset-empty.phpt`, `is-null.phpt`, `nullsafe.phpt` | Verified |
| Call arguments strictly evaluated left-to-right and precisely rooted at safe points | Native call argument materialization | `call-argument-roots.phpt`, `constructor-argument-roots.phpt` | Verified |

## 5. Class language capabilities

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| Single inheritance, abstract, and limited virtual dispatch | Native C++ inheritance/virtual slot adapters | `abstract-method.phpt`, `polymorphic-clone.phpt`, `virtual-signature-variance.phpt` | Verified |
| public/private/protected checked at compile time | Native member resolution | `method-visibility.phpt` and inaccessible method/constant negative tests | Verified |
| Traits compiled as ordinary Native members after injection | existing Trait AST injection + Native member generation | `trait-inheritance-interface.phpt` | Verified |
| Interfaces are compile-time contracts only and cannot become value representations | interface contract validator | `internal-interface.phpt`, `interface-property-hooks.phpt`, and interface escape negative tests | Verified |
| Compile-time resolvable `instanceof` folds | Native instanceof lowering | `instanceof.phpt`, dynamic instanceof negative tests | Verified |
| Getter/Setter annotations generate direct calls | annotation lowering + Native method path | `generators.phpt` | Verified |
| Property Hooks support only direct get/set | Native hook lowering | `property-hooks.phpt`, `property-hook-native-object.phpt`, and indirect operation negative tests | Verified |
| `clone` preserves dynamic subclass, PHPX COW, and shallow object semantics | Native clone descriptor/thunk, `php::nativeClone()` | clone PHPT series, `clone-phpx-fields.phpt` | Verified |
| `__construct` called only by `new` | Native construction path, explicit-call checks | construction PHPT series, explicit constructor negative tests | Verified |
| `__destruct` executed at most once by GC, derived-to-base along the inheritance chain | Native finalizer chain | destructor/finalizer/lifecycle PHPT series | Verified |
| `__invoke` and `__toString` use a deterministic Native Call | Native magic method allow-list | `magic-methods.phpt` | Verified |
| Dynamic magic methods, variable property/method names not supported | Native magic/dynamic access deny-list | dynamic magic, variable method/property negative tests | Verified |
| `toArray/toString/toInt/toFloat/toBool/toObject` require a real method, zero parameters, and an exact return type | Native keyword method resolution | `keyword-conversions.phpt`, `testNativeObjectToObjectKeywordUsesDeclaredNativeMethod`, and signature negative tests | Verified |
| `count($obj)` specialized only when implementing Countable | Native count optimizer | `keyword-conversions.phpt`, count-without-countable negative tests | Verified |
| `ArrayAccess` direct syntax maps to Native `offset*()` methods | Native array access lowering | `array-access.phpt` | Verified |
| Native `ArrayAccess` forbids indirect modification and references | writable-chain/reference validators | ArrayAccess compound/increment/nested/property/reference/coalesce negative tests | Verified |
| Native `Iterator` foreach maps to protocol methods, preserving PHP call order | Native foreach lowering | `iterator.phpt` | Verified |
| `IteratorAggregate` routes Native Iterator vs PHP Traversable | aggregate return-type lowering | `iterator.phpt` | Verified |
| Native foreach does not enumerate properties and forbids by-reference traversal | interface/reference validators | foreach negative PHPUnit | Verified |

## 6. GC and lifetime

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| Wren-style precise, non-moving, STW mark-sweep | `phpx/thirdparty/wren-gc`, `native_gc.cc` | PHPX `wren_gc.*` | Verified |
| Raw pointer writes have no RC, no write barrier | Native pointer field/local codegen | generated C++ review, Native PHPT | Verified |
| 16 MiB initial threshold, 1 MiB lower bound, 50% headroom | Wren GC configuration | `wren_gc.uses_stable_native_heap_defaults` | Verified |
| Precise root frames keep the object graph alive | `NativeRootFrame`, generated root slots | PHPX root tests, `gc-cycle.phpt` | Verified |
| Fiber non-LIFO lifetime safety | root frame registry | `fiber-lifetime.phpt`, `fiber-shutdown.phpt`, PHPX Fiber root tests | Verified |
| global/static request roots are thread-local under ZTS | generated globals/root registration | `global-and-static.phpt` under ZTS, PHPX request root tests | Verified |
| RSHUTDOWN clears roots and destroys the heap | `nativeGcRequestShutdown()` | PHPX shutdown tests | Verified |
| A finalizer can resurrect once and is not re-executed afterward | Wren/Native finalization state | `gc-cycle.phpt`, PHPX resurrection tests | Verified |
| Allocation, exceptions, and Zend state safe in finalizers | finalizer queue/exception cleanup | finalizer/lifecycle PHPT, PHPX finalizer tests | Verified |
| Construction or clone failure leaves no dangling object, and escaped objects remain valid | `nativeConstruct()`, `nativeClone()` failure paths | `failed-lifecycle-escape.phpt`, `failed-clone-finalizer.phpt` | Verified |

## 7. ZendVM boundary and containers

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| Native Objects cannot enter PHP array/object property/mixed | escape and boundary validators | corresponding PHPUnit negative tests | Verified |
| Cannot be passed to PHP/ZendVM dynamic functions, Closures, or constructors | call boundary validator | dynamic call, Closure, Zend constructor negative tests | Verified |
| Reflection/WeakReference/serialize/json_encode not supported | facility-specific diagnostics | corresponding PHPUnit negative tests | Verified |
| Generators cannot hold, receive, or yield Native pointers | generator boundary validator | generator series negative tests | Verified |
| Native locals of ordinary functions across Fiber suspend have precise roots | root frame lifecycle | Fiber PHPT | Verified |
| Local Std Containers can hold concrete Native pointers | Std Container Native value mapping/root frame | `std-containers.phpt` | Verified |
| Native Std Containers cannot escape as Zend values, static/global, or closure capture | container escape validation | Std Container series negative PHPUnit | Verified |
| `include`/`eval` do not capture AOT locals or expose Native pointers to the Zend symbol table | No caller-variable export table; dynamic source only supports independent execution | `include-native-scope.phpt` | Verified |

## 8. Project-level analysis

| Requirement | Implementation evidence | Test evidence | Conclusion |
|---|---|---|---|
| Native class forward declarations do not depend on file order | declaration discovery pre-pass | `testDiscoversNativeTypesBeforeCrossFileSignaturePreprocessing` | Verified |
| Global Native slot ABI fixed before any C++ file is generated | `NativeGlobalDiscovery`, `NativeGlobalTypeResolver` | `testDiscoversNativeGlobalSlotBeforeEarlierReaderIsConverted`, actual dual-file build | Verified |
| `global $slot` and statically resolvable `$GLOBALS[...]` use the same Native root slot | literal/constant global slot lowering, request root registration | `global-and-static.phpt`, cross-file Closure/constant `$GLOBALS` fixture | Verified |
| Dynamic `$GLOBALS[$key]` must not carry Native Objects | dynamic Zend boundary validation | `testRejectsNativeObjectStoredThroughDynamicGlobalsKey` | Verified |
| Global slot fixes the first Native type and allows only subclasses or null | global registration/type validation | `global-and-static.phpt`, global type change negative tests | Verified |
| Projects without Native Classes skip the Native global pre-pass | `discoverNativeGlobalObjects()` fast return | source review, full PHPUnit | Verified |

## 9. Current verification commands

```bash
./run-tests.php -j4 --compiler ./tpc tests/compiler/native-class/
vendor/bin/phpunit phpunit/src/NativeClass/NativeClassValidationTest.php
/home/swoole/workspace/aot/phpx/build/bin/phpx-tests \
  --gtest_filter='wren_gc.*:native_gc.*'
```

The results of this Iterator-focused run were: `iterator.phpt` 1/1, Native Class PHPUnit 136/136,
ordinary foreach regression 14/14. The Native Class PHPT directory currently has 71 items; per the current task agreement, the full
tests for that directory and the compiler PHPT suite were not re-run this time, and are left for the next unified regression round.
