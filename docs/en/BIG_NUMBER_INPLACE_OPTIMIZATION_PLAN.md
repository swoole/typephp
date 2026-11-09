# In-Place Optimization Plan for High-Precision Types

## 1. Background

TypePHP currently implements `BigInt`, `BigFloat`, and `Decimal` as PHPX `Box` objects stored in Zend resources. High-precision operations use an immutable result interface, for example:

```cpp
target = php::BigInt::mul(target, rhs);
```

Even when the PHP source uses compound assignment:

```php
$target *= $rhs;
```

The compiler still generates code that "creates a new result and reassigns". Taking BigInt as an example, a single multiplication currently typically requires:

1. Create a new `BigInt` Box.
2. Register a new Zend resource.
3. Initialize a new `mpz_t`.
4. Allocate GMP limb storage for the computed result.
5. Move-assign the new resource to the target variable.
6. Destruct the old resource, Box, and underlying numeric storage.

In scenarios such as loops, accumulation, factorials, and monetary aggregation, these overheads grow linearly with the number of operations:

```php
for ($i = 0; $i < $count; $i++) {
    $value = $value * 1000;
}
```

The underlying objects of GMP, MPFR, and mpdecimal are not immutable; all three support in-place operations where the output overlaps the input. The current immutable behavior comes from PHPX's high-precision API, not from a limitation of the underlying math libraries.

This document provides the implementation plan, semantic constraints, phased plan, and acceptance criteria for in-place operations on high-precision types.

## 2. Optimization Goals

### 2.1 Primary Goals

- Reuse existing objects for uniquely-held BigInt, BigFloat, and Decimal Boxes.
- Reuse GMP limb, MPFR mantissa, and mpdecimal coefficient storage as much as possible.
- Eliminate the result Box and Zend resource temporary objects in compound assignments.
- Use `php::Int` or `php::Var` directly for native RHS values such as integers, avoiding the construction of high-precision RHS Boxes.
- Fuse the safe `$x = $x {op} $rhs` pattern into an in-place operation.
- Preserve PHP's value semantics, reference semantics, evaluation order, and exception behavior.
- Automatically fall back to the current immutable implementation in unsafe or unprovably-safe scenarios.

### 2.2 Non-Goals

- The first phase does not optimize complex lvalues such as array elements, dynamic properties, and property hooks.
- It does not rely on whole-program alias analysis to guarantee correctness.
- It does not modify GMP, MPFR, or mpdecimal third-party source code.
- It does not turn all ordinary binary expressions into mutable computations.
- It does not change the implicit conversion rules between different high-precision types.

## 3. Underlying Library Capabilities

| Type | Underlying object | In-place operation | Memory reuse characteristics |
|---|---|---|---:|
| BigInt | GMP `mpz_t` / `mpz_class` | Supported | Reuses limb when capacity is sufficient, grows only when the result grows |
| BigFloat | MPFR `mpfr_t` | Supported | Currently fixed 256-bit precision, ordinary operations can usually keep reusing the mantissa |
| Decimal | mpdecimal `mpd_t` / `decimal::Decimal` | Supported | Can reuse the coefficient; the library itself provides `operator+=` and other in-place interfaces |

Typical in-place calls are as follows:

```cpp
mpz_mul(dst, dst, rhs);
mpfr_mul(dst, dst, rhs, MPFR_RNDN);
mpd_qmul(dst, dst, rhs, context, &status);
```

mpdecimal's C++ wrapper already provides:

```cpp
Decimal::operator+=
Decimal::operator-=
Decimal::operator*=
Decimal::operator/=
Decimal::operator%=
```

Therefore the technical bottleneck lies mainly in PHP Box sharing semantics, exception safety, and the compiler's evaluation order, rather than in the math libraries themselves.

## 4. Language Semantics That Must Be Preserved

### 4.1 Copy-on-write

A high-precision value may be shared by multiple PHP variables through the same Box:

```php
$a = std::bigInt(10);
$b = $a;
$a *= 2;
```

The result must be:

```text
$a = 20
$b = 10
```

The shared Box must not be modified directly. PHPX must check the Zend resource reference count before operating:

- Resource uniquely held: modify the original Box directly.
- Resource shared: copy the Box, bind the target variable to the copy, then modify the copy.

Runtime copy-on-write is the last line of defense for correctness. Compiler static analysis only reduces unnecessary checks and identifies fusable expressions; it cannot replace the runtime check.

### 4.2 PHP References

In the following scenario, two variables point to the same PHP reference container:

```php
$a = std::bigInt(10);
$b =& $a;
$a *= 2;
```

The result must be that both `$a` and `$b` become 20. The in-place API must operate on the actual zval inside the reference through `Variant::unwrap_ptr()`; when copy-on-write occurs, it should update the value in the reference container rather than rebinding the PHPX wrapper object.

### 4.3 RHS and Target Variable Aliasing

The following must be handled correctly:

```php
$a *= $a;
```

It is recommended that the in-place interface take the target by reference and the RHS by value:

```cpp
BigInt::mulAssign(Variant &target, Variant rhs);
```

If the RHS shares the same resource as the target, the RHS's temporary reference count will cause copy-on-write to take the copy branch. This may miss an in-place opportunity, but it naturally guarantees correctness. A dedicated path for "RHS and target are the same Box" can be added later.

### 4.4 Evaluation Order

The following two pieces of code cannot be treated as equivalent in all cases:

```php
$x = $x * changeValue($x);
$x *= changeValue($x);
```

The RHS may reassign, modify by reference, or modify `$x` through closure capture. C++ function argument evaluation order cannot be used to replace PHP's evaluation rules either.

The compiler must follow these rules:

- Use the existing ordered-operand and side-effect capture mechanism for true `AssignOp`.
- For `$x = $x {op} $rhs`, fuse only when the RHS does not write to or escape `$x`.
- Use the current "compute new result then assign" path when safety cannot be proven.
- If the old `$x` must be saved to preserve ordering, that temporary increases the reference count, and runtime copy-on-write fallback should be allowed automatically.

### 4.5 Complex Lvalues

The following expressions must not be rewritten in the first phase:

```php
$array[getIndex()] = $array[getIndex()] * 2;
$object->value = $object->value * 2;
$object->hooked = $object->hooked * 2;
```

Reasons include:

- The subscript expression may execute twice.
- The number of calls to getters, setters, or property hooks may change.
- Dynamic property reads/writes may trigger magic methods.
- The lvalue itself may have side effects.

The first phase only supports simple local variables. Complex lvalues are designed separately in later phases through a "single-evaluation writable target" abstraction.

### 4.6 Exception Safety

The current immutable implementation computes the new result first and only assigns after success, so the target variable remains unchanged when an exception occurs:

```php
$value = std::decimal('10');

try {
    $value /= 0;
} catch (DivisionByZeroError $e) {
}

echo $value; // still 10
```

The in-place implementation must preserve this behavior.

- BigInt: Check error conditions such as the divisor, modulus, and exponent before modifying.
- BigFloat: Check division-by-zero and error conditions explicitly defined by the current API before modifying.
- Decimal: `context.raise(status)` may throw after the underlying result has already been written, requiring a transactional commit or rollback mechanism.
- Memory allocation failure must also not leave the target in a partially-modified state.

### 4.7 Resource identity

High-precision Boxes are currently exposed as resources, and `get_resource_id()` and strict comparison may observe resource identity. In-place operations keep the resource id for uniquely-held variables, whereas the current immutable implementation generates a new resource id.

One of the following contracts must be clarified before implementation:

1. High-precision types are value types; resource identity is an internal implementation detail and is not guaranteed to remain unchanged across operations.
2. The current resource identity change must be preserved, in which case only the underlying numeric storage can be reused and the resource must be rewrapped, reducing the benefit.

Option 1 is recommended, and the high-precision type documentation should make it explicit: users should compare values and should not rely on internal resource ids. The value semantics of shared variables are still strictly guaranteed by copy-on-write.

## 5. PHPX Design

### 5.1 Explicit In-Place API

It is not recommended to add high-precision operator overloading to the generic `Variant`. Explicit interfaces should be added to each high-precision type:

```cpp
class BigInt {
  public:
    static Variant &addAssign(Variant &target, Variant rhs);
    static Variant &subAssign(Variant &target, Variant rhs);
    static Variant &mulAssign(Variant &target, Variant rhs);
    static Variant &divAssign(Variant &target, Variant rhs);
    static Variant &modAssign(Variant &target, Variant rhs);
};
```

BigFloat and Decimal use the same naming convention. BigInt should also cover bitwise operations and shifts:

```cpp
bitAndAssign
bitOrAssign
bitXorAssign
bitShiftLeftAssign
bitShiftRightAssign
```

The interface returns `Variant &`, so that compound assignment can still be used as an expression:

```php
$result = ($value *= 2);
```

If the actual generated code is inconvenient to handle the reference return, a statement-only `void` fast path can be provided at the same time, but the assignment expression semantics must not be sacrificed.

### 5.2 Box Uniqueness Utility

Provide a reusable C++17 helper inside PHPX instead of duplicating Zend resource logic across the three types:

```cpp
template <typename T>
T *separateBoxForWrite(Variant &target);
```

Responsibilities include:

1. Dereference indirect/reference zvals.
2. Verify that target is the target Box type.
3. Check the Zend resource reference count.
4. Return the original Box when uniquely held.
5. Copy the Box when shared, and update the target through `Variant` assignment semantics.
6. Preserve typed reference checks and exception propagation.

All three Boxes must support correct copying:

- BigInt: copy the `mpz_class`.
- BigFloat: initialize at the source precision and copy the `mpfr_t`.
- Decimal: copy the `decimal::Decimal`.

### 5.3 RHS Extraction

The in-place interface should accept `Variant rhs` directly and reuse the existing operand extractor:

- `php::Int` is converted directly to an underlying integer operand.
- `php::Var` checks its actual type at runtime.
- When already a Box of the same type, read the underlying value directly.
- Strings, floats, and different high-precision types continue to follow the current conversion restrictions.

The generated code should prioritize:

```cpp
php::BigInt::mulAssign(value, 1000L);
php::Decimal::mulAssign(value, factor);
```

Avoid:

```cpp
php::BigInt::mulAssign(value, php::toBigInt(1000L));
php::Decimal::mulAssign(value, php::toDecimal(1000L));
```

For Decimal's integer RHS, mpdecimal's `_i64`/`_u64` interfaces can be used further to avoid constructing a temporary `decimal::Decimal`:

```cpp
mpd_qmul_i64(result, left, rhs, context, &status);
```

### 5.4 BigInt Implementation Strategy

BigInt prioritizes true in-place operations:

```cpp
Variant &BigInt::mulAssign(Variant &target, Variant rhs) {
    BigIntOperand right;
    // Extract and validate the RHS first.
    // Then perform copy-on-write on target.
    // Finally call mpz_mul(dst, dst, right).
    return target;
}
```

All recoverable error checks, such as division by zero, modulo by zero, and illegal shift amounts, must be completed before modifying. GMP capacity growth is managed internally; the original limb storage is reused when capacity is sufficient.

### 5.5 BigFloat Implementation Strategy

BigFloat currently uniformly uses `BIG_FLOAT_DEFAULT_PRECISION`, which is suitable for direct in-place operations:

```cpp
mpfr_mul(dst, dst, rhs, MPFR_RNDN);
```

If per-object precision is supported in the future, the relationship between the non-in-place result precision and the compound-assignment target precision must be specified, and tests for objects of different precisions must be added.

### 5.6 Decimal Implementation Strategy

Decimal is implemented in two steps.

The first step uses exception-safe transactional commit:

```cpp
decimal::Decimal temporary;
uint32_t status = 0;
mpd_qmul(temporary.get(), current.getconst(), rhs, context, &status);
context.raise(status);
current = std::move(temporary);
```

This approach can eliminate the result Box and Zend resource, but still creates an underlying Decimal temporary object.

The second step evaluates true in-place operations:

- Complete explicit checks such as division-by-zero before modifying.
- Identify which status/trap values may throw after the operation.
- Provide backup/rollback for operations that may throw, or only perform in-place when it can be proven that no trap will be triggered.
- Run dedicated tests for Overflow, InvalidOperation, DivisionByZero, and simulated allocation failure.

"Target value partially modified after an exception" must not be accepted for the sake of performance.

## 6. Compiler Design

### 6.1 True Compound Assignment

First modify the existing Big* `AssignOp` generation path:

```php
$value *= $rhs;
```

From:

```cpp
value = php::BigInt::mul(value, rhs);
```

To:

```cpp
php::BigInt::mulAssign(value, rhs);
```

Support matrix:

| Type | First-phase operators |
|---|---|
| BigInt | `+= -= *= /= %= &= |= ^= <<= >>=` |
| BigFloat | `+= -= *= /=` |
| Decimal | `+= -= *= /= %=` |

### 6.2 Ordinary Assignment Fusion

Identify the following AST:

```php
$x = $x {op} $rhs;
```

Fuse only when all of the following conditions are met:

- The lvalue is a simple named variable.
- The left operand of the binary expression is the same variable.
- The variable's static type is BigInt, BigFloat, or Decimal.
- The operator is in the corresponding type's supported list.
- The RHS does not contain an assignment to, a reference acquisition of, or a known by-reference argument passing of the target variable.
- The RHS does not contain dynamic calls or other escape paths that cannot be safely analyzed, or existing side-effect analysis proves safety. `eval/include/require` do not capture AOT state and must not independently block fusion; analyze their source/path expression side effects and preserve execution order.
- The current expression context can correctly receive the in-place interface's return value.

The following scenarios are not fused in the first phase:

```php
$x = 2 - $x;
$x = $x * ($x = 2);
$x = $x * dynamicCall();
$array[$key] = $array[$key] * 2;
$object->value = $object->value * 2;
```

Optimization of commutative operations such as `$x = $rhs + $x` or `$x = $rhs * $x` is deferred to later phases to avoid expanding the scope of the first version.

### 6.3 Failure Fallback

The optimization must be an optional codegen path:

```text
Can safely operate in-place -> emit *Assign()
Cannot prove safety        -> emit the current new-result path
```

Any type uncertainty, complex lvalue, reference escape, or side-effect analysis failure must not cause a compilation error; it should only lose that optimization.

### 6.4 Relationship with SSA/Optimizer

The initial version can perform local AST matching in `AssignOpTrait` and ordinary assignment resolution without relying on a complete SSA.

Later, SSA can provide:

- Whether the target variable has aliases.
- Whether the RHS writes to the target variable.
- Whether the variable escapes to dynamic calls or references.
- Whether it can statically prove the Box is uniquely held.

Even if SSA proves uniqueness, the PHPX runtime copy-on-write check is still recommended to be retained, unless there is a strict escape proof and dedicated tests.

## 7. Phased Implementation Plan

### Phase 0: Baseline and Observation

- Add test helper facilities for counting high-precision Box/resource creation.
- Establish benchmarks for BigInt, BigFloat, and Decimal loop operations.
- Record current wall time, Box count, resource count, and underlying allocation count.
- Freeze the current aliasing, reference, exception, and resource identity behavior.

Deliverable: a baseline report and behavior tests, with no change to generated code.

### Phase 1: Native RHS Fast Path

- BigInt operations directly accept `php::Int`.
- BigFloat operations directly accept `php::Int`, `php::Float`.
- Decimal operations directly accept `php::Int` and `php::Var` that is actually an int.
- The Decimal integer path prioritizes `mpd_q*_i64`.
- Eliminate the high-precision Box the compiler creates for the RHS.

Deliverable: no more unnecessary `toBigInt()`, `toBigFloat()`, `toDecimal()` on the RHS.

### Phase 2: PHPX Copy-on-write Infrastructure

- Implement `separateBoxForWrite<T>()`.
- Complete copy tests for the three Box types.
- Cover ordinary variables, shared variables, PHP references, indirect zvals, and RHS being the same Box.
- Clarify the resource identity contract.

Deliverable: standalone PHPX unit tests, with no modification to the compiler generation path.

### Phase 3: BigInt and BigFloat Compound Assignment

- Implement the BigInt `*Assign()` method family.
- Implement the BigFloat `*Assign()` method family.
- Modify the generated code for true PHP `AssignOp`.
- Preserve fallback for unsafe paths.
- Run the full PHPX test suite, full compiler PHPUnit, relevant PHPT, and bootstrap compilation.

Deliverable: syntax such as `$x *= $rhs` uses true in-place operations.

### Phase 4: Ordinary Assignment Fusion

- Identify simple local variables `$x = $x {op} $rhs`.
- Implement target variable write/escape checks.
- Prioritize enabling for pure-literal and pure-variable RHS.
- Preserve the old path for RHS with side effects.

Deliverable: common patterns in the problem description no longer require users to manually convert to compound assignment.

### Phase 5: Decimal Transactional In-Place Interface

- Implement the Decimal `*Assign()` API.
- First use "underlying temporary result + commit on success".
- Optimize integer RHS using the `_i64` fast path.
- Cover all Decimal traps and the target value after exceptions.

Deliverable: eliminate the Decimal result Box/resource while maintaining strong exception safety.

### Phase 6: Decimal True In-Place Computation

- Analyze the status/trap values each operator may trigger.
- Directly use the target `mpd_t` for operations that can be proven safe.
- Preserve the transactional path for high-risk operations.
- Determine through benchmarks whether the complexity is worthwhile.

Deliverable: common Decimal accumulation operations reuse coefficient storage.

### Phase 7: Complex Lvalues and Further Optimizations

- Design a single-evaluation writable target abstraction.
- Evaluate support for array elements, static properties, and ordinary properties.
- Property hooks, magic methods, and dynamic properties are not enabled by default unless the number of calls and ordering can be strictly preserved.
- Evaluate commutative expression fusion and SSA uniqueness proof.

## 8. Test Plan

### 8.1 PHPX Unit Tests

Each type and each operator must at least cover:

- Unique Box in-place update.
- Shared Box triggers copy-on-write.
- PHP references update the same referenced value.
- RHS and target are the same Box.
- Allowed RHS types such as Int, Float, String, and Var.
- Exceptions for illegal RHS types.
- Edge cases such as division by zero, modulo by zero, and negative exponents.
- The target value remains unchanged after an exception.
- Capacity growth triggered by extremely large numbers.
- Multiple consecutive operations.

### 8.2 Compiler PHPUnit

Check the generated code:

- `AssignOp` generates calls such as `BigInt::mulAssign()`.
- `$x = $x * 1000` is fused.
- RHS native integers no longer construct Big* Boxes.
- No fusion when the RHS has side effects.
- Array elements and properties are not fused in the first phase.
- Unsupported operators continue to produce the original FatalError.

### 8.3 PHPT

At least cover:

```php
$a *= 2;
$a = $a * 2;
$b = $a; $a *= 2;
$b =& $a; $a *= 2;
$a *= $a;
$a *= ($factor = 2);
$result = ($a *= 2);
```

And cover for the three high-precision types:

- Positive, negative, and zero values.
- Extreme values and precision boundaries.
- All supported compound assignment operators.
- The lvalue after an exception.
- Consecutive updates in a loop.

### 8.4 Integration Verification

Each phase must at least execute:

```bash
./vendor/bin/phpunit
php run-tests.php tests/compiler/bigint tests/compiler/bignumber tests/compiler/decimal
php bin/tpc.php project.yml
```

PHPX modifications must also run the full PHPX unit test suite.

## 9. Performance Acceptance

Performance tests must at least include:

- Sizes of 1, 4, 16, 64, 256, and 1024 limb/decimal digits.
- RHS being small integers, same-type high-precision values, and dynamic `php::Var`.
- Unique Box and shared Box.
- Loops of 1 thousand, 100 thousand, and 1 million iterations.
- BigInt growth multiplication versus stable-capacity addition.
- BigFloat fixed-precision accumulation.
- Decimal fixed 50-digit precision accumulation.

Functional acceptance criteria:

- Compound assignment of a unique BigInt/BigFloat does not create a result Box/resource per iteration.
- Native RHS does not create a high-precision Box.
- Shared Box correctly triggers copy-on-write.
- All exception paths keep the target value unchanged.
- Bootstrap compilation and full test suites pass.

Performance acceptance is based on baseline data and does not preset unrealistic fixed multiples. At least the following should be reported separately:

- Total elapsed time.
- Box/resource creation counts.
- Underlying memory allocation counts and bytes.
- Peak memory.
- Copy-on-write hit rate and fallback rate.

If an optimization path cannot reduce allocations, or causes clear regression in common non-in-place expressions, the old path should be retained or that sub-optimization should be reverted.

## 10. Risks and Rollback Strategy

Main risks:

- Incorrect Box sharing determination causing other variables to be modified unexpectedly.
- References or indirect zvals being rebound instead of updated.
- RHS side effects changing the evaluation order.
- Decimal target value being polluted after an exception.
- Undocumented changes in resource identity behavior.
- In-place capacity growth failure leaving an invalid underlying object.

Control measures:

- All optimizations are concentrated in a standalone PHPX API and a single compiler codegen branch.
- Fall back to the old implementation when safety cannot be proven.
- Enable incrementally by type and by operator.
- Commit each phase independently, avoiding modifying too many semantics at once.
- Do not remove the existing immutable API until exception, aliasing, and reference tests are complete.

Rollback only requires the compiler to regenerate:

```cpp
target = Type::operation(target, rhs);
```

The original immutable API must be retained throughout the entire migration period.

## 11. Recommended Priority

Considering benefit, complexity, and risk, the recommended order is:

1. BigFloat in-place compound assignment.
2. BigInt in-place compound assignment.
3. BigInt/BigFloat ordinary assignment fusion.
4. Decimal native integer RHS fast path.
5. Decimal transactional `*Assign()`.
6. Decimal true in-place computation.
7. Complex lvalues and SSA enhancements.

BigFloat has fixed precision and is the easiest to stably reuse underlying memory; BigInt has broader applications and its overall benefit may be the largest; Decimal has the most complex exception and trap semantics, and its true in-place modification should be pushed last.

## 12. Final Target Code

For safe simple variables:

```php
$value = $value * 1000;
```

The final generation:

```cpp
php::BigInt::mulAssign(value, 1000L);
```

Runtime:

```text
Unique Box: reuse Box, resource, and underlying storage in place
Shared Box: copy-on-write, then modify the new Box
Unsafe scenario: fall back to the current immutable result implementation
```

This design confines the performance optimization within verifiable boundaries while preserving the consistency of TypePHP with PHP assignment, reference, and exception semantics.
