# AOT Compiler Type System Guide

## ⚠️ Important Notice

**Compiler hard dependency**: the TypePHP compiler depends strongly on the keyword methods such as `toArray`, `toInt` and `toFloat`. The type system can only be established through these methods, so **their type contract cannot be changed at the PHP code level**. For example, when the compiler sees `toArray()` it knows the expression is always `php::Array`. A class may implement `toInt`/`toFloat`/`toString`/`toBool`/`toArray` itself only with the exact keyword signature; see [Universal Methods](UNIVERSAL_METHODS.md).

**The AOT compiler supports 6 native/high-precision types**:

Since TypePHP 0.8, inferred `int`, `float`, and `bool` locals use native
`php::Int`, `php::Float`, and `php::Bool` storage by default. The old
`use native_types` directive has been removed. Native locals are never
silently promoted to `php::Var` later.

Use `use varint_types` only for a file that needs Zend PHP integer widening
semantics. It boxes inferred integers, but not floats or booleans. Use
`std::any($value)` when only one value must be dynamic or reference-capable.

### Basic Native Types
1. ✅ `std::int` - Native integer type (zend_long, 8 bytes)
2. ✅ `std::float` - Native floating-point type (double, 8 bytes)
3. ✅ `std::bool` - Native boolean type (bool, 1 byte)

### High-Precision Numeric Types
4. ✅ `std::bigInt` - Arbitrary-precision integer (based on GMP `mpz_class`)
5. ✅ `std::decimal` - 50-digit decimal number (based on libmpdec)
6. ✅ `std::bigFloat` - 256-bit high-precision floating-point number (based on MPFR, outputs 64 significant digits)

---

## Object Property Types Are Fixed

The AOT compiler requires object properties to always maintain the type they were declared with throughout their entire lifecycle. Unlike the PHP interpreter, AOT does not allow changing an already-declared property type to another type through runtime operations.

Pay particular attention to `unset($obj->prop)` and assigning `null` on fixed-value-type properties:

```php
<?php

class User {
    public int $id = 0;
    public Profile $profile;
    public ?Profile $optionalProfile = null;
}

$user = new User();
unset($user->id); // ❌ AOT does not allow relying on this semantics
$user->id = null; // ❌ This likewise changes the int property to null

unset($user->profile); // ✅ Object properties may enter the null/unset state
$user->profile = null; // ❌ This property does not accept null
$user->optionalProfile = null; // ✅ Nullable object properties accept null
```

In PHP, `unset($obj->prop)` lets an object property detach from its current value state, subsequently behaving as an uninitialized or null state; assigning `null` to a fixed-value-type property also changes the value state to null. From the AOT type system's perspective, this is equivalent to changing the property from its declared `int`, `float`, `bool`, `string`, `array` to a `null`/uninitialized state. The AOT compiler does not allow these fixed-value-type properties to change type, so a property is always of its declared type.

Concrete class object properties use static object type rules: a non-null assignment must satisfy the `is-a` relationship, so assigning a subclass object to a base-class property is allowed, while assigning an unrelated object or a base-class object to a subclass property is not. Non-nullable properties can be `unset()`, but cannot be assigned `null`.

For a named class `T`, `?T` and `T|null` retain the ordinary typed-object representation (`php::Object`) and class information in parameters, return values, inferred locals and properties. The handle itself can hold null. Native method calls use the ordinary typed-object path without an extra nullable-receiver check; PHPX protects property access and dynamic method calls. `?->` still short-circuits on null, including argument evaluation. Declaration boundaries continue to validate the permitted class and nullability. Nullable scalar types and other unions retain dynamic storage; by-reference nullable object parameters retain the Zend reference ABI.

Known typed-object arguments and returns do not repeat class/nullability checks already established by static analysis or their input boundary. `T` and `?T` use the same generated native-call path. A conversion from `?T` to non-nullable `T` retains a runtime check when the compiler cannot prove the value is non-null; dynamic inputs retain their required boundary validation.

Correct practices:

- Do not use `unset()` or assign `null` to fixed-value-type object properties of type `int`, `float`, `bool`, `string`, or `array`.
- If a property may be empty in business terms, explicitly declare it as a nullable type, e.g. `public ?int $id = null;`, and use assignment to express state changes.
- If an object property is declared as a concrete class name, a non-null assignment must satisfy the declared class, including compatible subclasses.
- If a property needs to hold arbitrary PHP values, declare it as a variable/general type rather than declaring it as a native type and then attempting `unset()` or writing another type into it.

---

## 🎯 The `toObject()` Keyword Method

### Use Cases

When obtaining objects from sources such as arrays or function return values, variables lose their type context information. Use the `toObject()` keyword method to assert the object's class.

### Basic Syntax

```php
<?php
// The receiver is the value to check. The argument is a compile-time class name.
$obj = $array['object']->toObject(ClassName::class);
```

### Typical Scenarios

#### Scenario 1: Extracting an Object from an Array

```php
<?php
$data = [
    'user' => new User(),
    'product' => new Product(),
];

// ❌ Wrong: type is lost
$user = $data['user'];  // AOT cannot infer the type

// ✅ Correct: use toObject() to declare the type
$user = $data['user']->toObject(User::class);
$product = $data['product']->toObject(Product::class);
```

#### Scenario 2: A Function Returns an Object

```php
<?php
function get_object() {
    return new stdClass();
}

// ❌ Type is lost
$obj = get_object();

// ✅ Use toObject() to declare
$obj = get_object()->toObject(stdClass::class);
```

#### Scenario 3: The Factory Pattern

```php
<?php
class Factory {
    public function create($type) {
        switch ($type) {
            case 'user':
                return new User();
            case 'product':
                return new Product();
            default:
                throw new InvalidArgumentException("Invalid type");
        }
    }
}

$factory = new Factory();

// ✅ Explicitly specify the returned object type
$user = $factory->create('user')->toObject(User::class);
$product = $factory->create('product')->toObject(Product::class);
```

### Notes

⚠️ **A literal string must be used**:

```php
<?php
// ✅ Correct: literal class name
$obj = $value->toObject(MyClass::class);

// ❌ Wrong: variable class name (cannot be analyzed at compile time)
$className = 'MyClass';
$obj = $value->toObject($className);  // Compile error

// ❌ Wrong: constant class name (may not be resolvable at compile time)
const CLASS_NAME = 'MyClass';
$obj = $value->toObject(CLASS_NAME);  // May fail
```

The receiver may be any supported value expression:

```php
<?php
// ✅ Correct: variable expression
$obj = $array['key']->toObject(MyClass::class);
$obj = $object->property->toObject(MyClass::class);
$obj = get_object()->toObject(MyClass::class);

// ✅ Legal, but redundant because the expression already has the exact type
$obj = (new MyClass())->toObject(MyClass::class);  // Not needed
```

### Performance Impact

- ✅ `toObject()` is a TypePHP keyword method
- ✅ It provides the compiler with the target class
- ✅ It emits a PHPX object conversion/type check for values whose runtime class is not statically proven

### Differences from std:: Types

| Feature | std::int/float/bool | std::object/toObject |
|------|---------------------|--------|
| **Purpose** | Numeric/boolean type optimization | Object type declaration |
| **Performance** | ⚡ High performance (native type) | 🐢 Standard (ZVAL) |
| **Memory** | 8B/1B | Pointer (16B+) |
| **Timing** | Runtime optimization | Compile-time lowering plus runtime check when needed |
| **Syntax** | `std::int(value)` | `std::object($value, ClassName::class)` or `$value->toObject(ClassName::class)` |

---

## ❌ Unsupported Types

The following types **do not** use native types and still use ZVAL:

- ❌ `std::string` - strings use ZVAL (php::Str)
- ❌ `std::array` - arrays use ZVAL (php::Array)
- ❌ Objects remain ZVAL-backed (`php::Object`); `std::object()` restores class information but does not introduce native object storage
- ❌ All other types - use ZVAL (php::Var)

## Type Mapping Table

| PHP Type Declaration | C++ Type | Underlying Implementation | Memory | Performance | Status |
|------------|---------|---------|------|------|------|
| `int` | `php::Int` | `zend_long` | 8B | ⚡ High performance | ✅ Native |
| `float` | `php::Float` | `double` | 8B | ⚡ High performance | ✅ Native |
| `bool` | `php::Bool` | `bool` | 1B | ⚡ High performance | ✅ Native |
| `bigInt` | `php::Var` (Box\<BigInt\>) | `mpz_class` (GMP) | ~32B+ | 🐢 Standard | ✅ Boxed |
| `decimal` | `php::Var` (Box\<Decimal\>) | `decimal::Decimal` (libmpdec) | ~64B+ | 🐢 Standard | ✅ Boxed |
| `bigFloat` | `php::Var` (Box\<BigFloat\>) | `mpfr_t` (MPFR) | ~32B+ | 🐢 Standard | ✅ Boxed |
| `string` | `php::Str` | `zend_string*` | Pointer | 🐢 Standard | ❌ ZVAL |
| `array` | `php::Array` | `zval*` | Pointer | 🐢 Standard | ❌ ZVAL |
| `object` | `php::Object` | `zend_object*` | Pointer | 🐢 Standard | ❌ ZVAL |
| `mixed`/no declaration | `php::Var` | `zval` | 16B | 🐢 Standard | ❌ ZVAL |

## Declaration Method Comparison

| Type | C++ Implementation | Declaration Method | Memory | Performance | Status |
|------|---------|---------|------|------|------|
| **int** | `php::Int` | `std::int(value)`<br>`function foo(int $x)` | 8B | ⚡ High performance | ✅ Native |
| **float** | `php::Float` | `std::float(value)`<br>`function foo(float $x)` | 8B | ⚡ High performance | ✅ Native |
| **bool** | `php::Bool` | `std::bool(value)`<br>`function foo(bool $x)` | 1B | ⚡ High performance | ✅ Native |
| **bigInt** | `php::Var` (Box\<BigInt\>) | `std::bigInt(value)` | ~32B+ | 🐢 Standard | ✅ Boxed |
| **decimal** | `php::Var` (Box\<Decimal\>) | `std::decimal(value)` | ~64B+ | 🐢 Standard | ✅ Boxed |
| **bigFloat** | `php::Var` (Box\<BigFloat\>) | `std::bigFloat(value)` | ~32B+ | 🐢 Standard | ✅ Boxed |
| **string** | `php::Str` | None<br>`function foo(string $x)` | Pointer | 🐢 Standard | ❌ ZVAL |
| **array** | `php::Array` | None<br>`function foo(array $x)` | Pointer | 🐢 Standard | ❌ ZVAL |
| **object** | `php::Object` | None<br>`function foo(object $x)` | Pointer | 🐢 Standard | ❌ ZVAL |
| **mixed** | `php::Var` | None<br>`function foo($x)` | 16B | 🐢 Standard | ❌ ZVAL |

## Performance Differences

### Native Types (High Performance)
```php
function calculate(int $a, int $b): int {
    return $a + $b;  // Uses native types, 100-300x performance improvement
}
```

### ZVAL Types (Standard Performance)
```php
function process(string $name, array $data) {
    // Uses ZVAL, standard PHP performance
    echo $name;
    print_r($data);
}
```

## Usage Recommendations

### ✅ Scenarios Recommended for Native Types
- Numerically intensive computation
- Loop counters
- Recursive algorithms
- Performance-critical paths

### ⚠️ Scenarios Using ZVAL
- String processing
- Array operations
- Object operations
- General business logic

---

## High-Precision Numeric Types: BigInt / Decimal / BigFloat

The AOT compiler supports three high-precision numeric types for computations that exceed int64/double precision.

### Underlying C++ Libraries

| Type | C++ Library | Key Header Files |
|------|--------|----------|
| **BigInt** | GMP (`libgmp-dev`) | `<gmpxx.h>`, `phpx_big_int.h` |
| **Decimal** | libmpdec (`libmpdec-dev`) | `<decimal.hh>`, `phpx_decimal.h` |
| **BigFloat** | MPFR (`libmpfr-dev`) | `<mpfr.h>`, `phpx_big_float.h` |

BigInt, Decimal, and BigFloat all inherit from `php::Box` and are stored inside `php::Variant`. They are "boxed types" that are not directly mapped to C++ scalars like Int/Float, so they differ in declaration and operation.

### Declaration and Construction

```php
// Construct a BigInt from an integer literal
$a = std::bigInt(100);
$b = std::bigInt("123456789012345678901234567890");  // Very long integer string

// Construct a Decimal from a string (to avoid floating-point precision loss)
$c = std::decimal("123.456");
$d = std::decimal(42);  // Can also be constructed from an int

// Construct a BigFloat from an int / float / string
$e = std::bigFloat(100.5);
$f = std::bigFloat(42);
$g = std::bigFloat("3.14159265358979323846");
```

> **Important**: `std::bigInt()` / `std::decimal()` / `std::bigFloat()` are **compile-time functions** that directly construct the corresponding types in the generated C++ code, with no runtime function-call overhead.

### Arithmetic Operators

BigInt supports `+`, `-`, `*`, `/`, `%`, and `**`; Decimal supports the first five except `**`; BigFloat supports `+`, `-`, `*`, `/`. The compiler maps them to static method calls.

```php
$a = std::bigInt(100);
$b = std::bigInt(200);

$sum = $a + $b;     // → php::BigInt::add($a, $b)
$diff = $a - $b;    // → php::BigInt::sub($a, $b)
$prod = $a * $b;    // → php::BigInt::mul($a, $b)
$quot = $a / $b;    // → php::BigInt::div($a, $b)
$mod = $a % $b;     // → php::BigInt::mod($a, $b)
$pow = $a ** 3;     // → php::BigInt::pow($a, 3)

// Unary negation
$neg = -$a;         // → php::BigInt::neg($a)
```

**Type promotion**: Big* can be mixed with safe ordinary scalars in operations; different Big* types must not be implicitly mixed and must first be explicitly converted. See "Binary Operation Type Promotion Rules" below for details.

**BigInt division**: `BigInt / BigInt` returns BigInt in `parseBinaryOp` (integer division, same as PHP int semantics). If high-precision division is needed, convert the operands to Decimal first or use the Decimal result of `BigInt::div`.

### Comparison Operators

All standard comparison operators are available: `<`, `>`, `<=`, `>=`, `==`, `!=`, `<=>` (spaceship).

```php
$a = std::bigInt(100);
$b = 200;

echo (int)($a < $b);    // → php::BigInt::cmp($a, $b) < 0
echo (int)($a > $b);    // → php::BigInt::cmp($a, $b) > 0
echo (int)($a == 100);  // → php::BigInt::cmp($a, 100) == 0
echo (int)($a <=> $b); // → php::BigInt::cmp($a, $b)
```

C++ implementation: comparison results are obtained via `php::BigInt::cmp()` / `php::Decimal::cmp()` / `php::BigFloat::cmp()`, which return a negative/zero/positive int representing less than/equal to/greater than.

### Universal Methods

BigInt, Decimal, and BigFloat support calling a series of zero-cost abstraction methods via the `$value->method()` syntax.

#### BigInt Methods

| Method | Parameters | Return Type | C++ Implementation | Description |
|------|------|---------|---------|------|
| `add($x)` | 1 | BigInt | `BigInt::add()` | Addition |
| `sub($x)` | 1 | BigInt | `BigInt::sub()` | Subtraction |
| `mul($x)` | 1 | BigInt | `BigInt::mul()` | Multiplication |
| `div($x)` | 1 | BigInt | `BigInt::div()` | Integer division |
| `mod($x)` | 1 | BigInt | `BigInt::mod()` | Modulo |
| `pow($x)` | 1 | BigInt | `BigInt::pow()` | Exponentiation |
| `neg()` | 0 | BigInt | `BigInt::neg()` | Negation |
| `abs()` | 0 | BigInt | `BigInt::abs()` | Absolute value |
| `gcd($x)` | 1 | BigInt | `BigInt::gcd()` | Greatest common divisor |
| `cmp($x)` | 1 | Int | `BigInt::cmp()` | Comparison |
| `toString()` | 0 | Str | `BigInt::toString()` | Convert to string |
| `toInt()` | 0 | Int | `BigInt::toInt()` | Convert to integer; throws ArithmeticError on overflow |
| `toFloat()` | 0 | Float | `BigInt::toFloat()` | Convert to float (may lose precision) |

```php
$a = std::bigInt("12345678901234567890");
echo $a->toString();    // "12345678901234567890"
echo $a->add(1)->toString();  // "12345678901234567891"
echo $a->abs()->toString();   // "12345678901234567890"
echo $a->gcd(15)->toInt();    // 15
```

#### Decimal Methods

| Method | Parameters | Return Type | C++ Implementation | Description |
|------|------|---------|---------|------|
| `add($x)` | 1 | Decimal | `Decimal::add()` | Addition |
| `sub($x)` | 1 | Decimal | `Decimal::sub()` | Subtraction |
| `mul($x)` | 1 | Decimal | `Decimal::mul()` | Multiplication |
| `div($x)` | 1 | Decimal | `Decimal::div()` | Division |
| `mod($x)` | 1 | Decimal | `Decimal::mod()` | Modulo |
| `neg()` | 0 | Decimal | `Decimal::neg()` | Negation |
| `abs()` | 0 | Decimal | `Decimal::abs()` | Absolute value |
| `cmp($x)` | 1 | Int | `Decimal::cmp()` | Comparison |
| `toString()` | 0 | Str | `Decimal::toString()` | Convert to string |
| `toInt()` | 0 | Int | `Decimal::toInt()` | Truncate to integer |
| `toFloat()` | 0 | Float | `Decimal::toFloat()` | Convert to float (about 15 digits precision) |

```php
$d = std::decimal("123.456");
echo $d->toInt();         // 123
echo $d->mul(2)->toString();  // "246.912"
echo $d->div(3)->toString();  // "41.152"
```

#### BigFloat Methods

| Method | Parameters | Return Type | C++ Implementation | Description |
|------|------|---------|---------|------|
| `add($x)` | 1 | BigFloat | `BigFloat::add()` | Addition |
| `sub($x)` | 1 | BigFloat | `BigFloat::sub()` | Subtraction |
| `mul($x)` | 1 | BigFloat | `BigFloat::mul()` | Multiplication |
| `div($x)` | 1 | BigFloat | `BigFloat::div()` | Division |
| `neg()` | 0 | BigFloat | `BigFloat::neg()` | Negation |
| `abs()` | 0 | BigFloat | `BigFloat::abs()` | Absolute value |
| `cmp($x)` | 1 | Int | `BigFloat::cmp()` | Comparison |
| `toString()` | 0 | Str | `BigFloat::toString()` | Convert to string |
| `toInt()` | 0 | Int | `BigFloat::toInt()` | Truncate to integer |
| `toFloat()` | 0 | Float | `BigFloat::toFloat()` | Convert to double (about 15 digits precision) |

```php
$bf = std::bigFloat(3.14159265);
echo $bf->mul(2)->toString();      // "6.2831853..."
echo $bf->div(2)->toFloat();       // 1.570796325
echo $bf->cmp(3.0);                // > 0
```

### Type Conversion

```php
// BigInt → Decimal (exact)
$big = std::bigInt("12345678901234567890");
$dec = std::decimal($big->toString());
// Or use the built-in conversion directly:
// $dec = $big->toDecimal();  // To be implemented

// Decimal → BigInt (truncates the fractional part)
$d = std::decimal("123.456");
$i = std::bigInt($d->toInt());  // 123

// Int → BigInt
$bi = std::bigInt(42);

// Float → BigFloat
$bf = std::bigFloat(3.14);

// BigInt → BigFloat
$bf2 = std::bigFloat($big->toString());
```

> **Cross-type implicit conversion restrictions**: BigInt, Decimal, and BigFloat cannot be implicitly mixed in operations or comparisons. The compiler reports an error and requires an explicit conversion to the same type first, in order to prevent precision loss and misuse of the underlying Box types.

### C++ API Reference

The Big* types provide the following core functions in the `phpx` library:

```cpp
// Construction
php::Variant php::newBigInt(const std::string &s);
php::Variant php::newBigInt(php::Int v);
php::Variant php::newDecimal(const String &s);
php::Variant php::newDecimal(php::Int v);
php::Variant php::newBigFloat(const String &s);
php::Variant php::newBigFloat(php::Int v);
php::Variant php::newBigFloat(php::Float v);

// BigInt arithmetic (all return Variant)
php::BigInt::add(a, b)   php::BigInt::sub(a, b)   php::BigInt::mul(a, b)
php::BigInt::div(a, b)   php::BigInt::mod(a, b)   php::BigInt::pow(a, b)
php::BigInt::neg(a)      php::BigInt::abs(a)      php::BigInt::gcd(a, b)
php::BigInt::cmp(a, b)

// Decimal arithmetic
php::Decimal::add(a, b)  php::Decimal::sub(a, b)  php::Decimal::mul(a, b)
php::Decimal::div(a, b)  php::Decimal::mod(a, b)
php::Decimal::neg(a)     php::Decimal::abs(a)     php::Decimal::cmp(a, b)

// BigFloat arithmetic
php::BigFloat::add(a, b) php::BigFloat::sub(a, b) php::BigFloat::mul(a, b)
php::BigFloat::div(a, b)
php::BigFloat::neg(a)    php::BigFloat::abs(a)    php::BigFloat::cmp(a, b)

// Type conversion
php::BigInt::toString(a)   php::BigInt::toInt(a)   php::BigInt::toFloat(a)
php::Decimal::toString(a)  php::Decimal::toInt(a)  php::Decimal::toFloat(a)
php::BigFloat::toString(a) php::BigFloat::toInt(a) php::BigFloat::toFloat(a)
```

All static methods receive `Variant` parameters and internally extract the underlying object via `.toBox<BigInt>()` / `.toBox<Decimal>()` / `.toBox<BigFloat>()`. If the parameter type does not match, a runtime error is thrown.

### Very Long Literal Recognition

The AOT compiler pre-scans the PHP source code before parsing and automatically recognizes numeric literals that exceed int64/double precision:

```
\d{19,}                     → automatically converted to std::bigInt("...")
\d+\.\d{16,}               → automatically converted to std::decimal("...")
```

For example, if the source code contains `123456789000000000000000000000000000000000000000000001` (54 digits), the compiler automatically processes it as `std::bigInt("123456789000000000000000000000000000000000000000000001")` without manual wrapping.

---

## Binary Operation Type Promotion Rules

When the AOT compiler executes binary operations such as `+`, `-`, `*`, `/`, `%`, it determines the operation type according to the following priority:

### Rule Priority

```
BigFloat / Decimal / BigInt is involved
  → Only safely promote Int/Float; different Big* types require explicit conversion
  ↓ not matched

Either side is Var
  → Both sides are converted to Var, using ZendVM binary_op functions
  ↓ not matched

Either side is Float
  → Both sides are converted to Float (double)
  ↓ not matched

Both sides are Int
  → Use Int (int64_t)
```

### Rule 1: Var Dominates

When at least one operand is `Var`, both sides use the PHPX/Zend arithmetic
path and follow PHP type-juggling semantics. A `Var` comes from an expression
such as `std::any(...)`, a dynamic runtime value, or an inferred integer in a
file that declares `use varint_types`.

```php
use varint_types;
$a = 10;        // Var, stores int(10)
$b = 2.5;       // php::Float (varint_types affects only inferred integers)
$c = $a + $b;   // Both sides are Var → ZendVM operation → float(12.5)
```

C++ code generation: `int64_t` and `double` values are implicitly converted to `php::Var` through `php::Variant`'s template constructor (`phpx.h:557`), then `Variant::operator+()` → ZendVM's `add_function` is called.

### Rule 2: Float Takes Precedence over Int

When both sides are native types (the default, or explicitly constructed with
`std::int()` / `std::float()` inside a varint file), Float takes precedence.
Only two Int operands use integer arithmetic.

```php
$a = 10;        // php::Int
$b = 2.5;       // php::Float
$c = $a + $b;   // Float + Float → double addition

$d = 5;         // php::Int
$e = 3;         // php::Int
$f = $d + $e;   // Int + Int → int64_t addition
```

> **Note**: native variables **do not change storage type** during operations.
> For example, `Int += Float` remains Int. This fixed-type behavior is now the
> default; choose `use varint_types` or `std::any()` when dynamic integer
> widening is required.

### Rule 3: Safe Promotion of High-Precision Types

When the operands include `BigInt`, `Decimal`, or `BigFloat`, only explicit and safe promotion is performed on ordinary scalars. Different Big* types are not automatically converted according to some so-called "precision hierarchy", because the three have different numeric models.

| Left Operand | Right Operand | Result Type |
|---------|---------|---------|
| BigInt | BigInt | BigInt (`/` is truncated integer division) |
| BigInt | Decimal | Compile error, explicit conversion required |
| Decimal | Decimal | Decimal |
| BigFloat | BigInt | Compile error, explicit conversion required |
| BigFloat | Decimal | Compile error, explicit conversion required |
| BigFloat | BigFloat | BigFloat |
| BigInt | Int | BigInt |
| BigInt | Float | Compile error |
| Decimal | Int | Decimal |
| Decimal | Float | Decimal (float literals are converted per source text; variables require explicit conversion) |
| BigFloat | Int | BigFloat |
| BigFloat | Float | BigFloat |

### Complete Type Promotion Matrix

| | Int | Float | Var | BigInt | Decimal | BigFloat |
|------|-----|-------|-----|--------|---------|----------|
| **Int** | Int | Float | Var | BigInt | Decimal | BigFloat |
| **Float** | Float | Float | Var | Error | Decimal* | BigFloat |
| **Var** | Var | Var | Var | Var | Var | Var |
| **BigInt** | BigInt | Error | Var | BigInt | Error | Error |
| **Decimal** | Decimal | Decimal* | Var | Error | Decimal | Error |
| **BigFloat** | BigFloat | BigFloat | Var | Error | Error | BigFloat |

> `Decimal*`: only float literals whose original text the compiler can preserve are allowed; float variables must first be explicitly converted. The Var row/column still use ZendVM runtime semantics.

### Compound Assignment Operators

Compound assignment operators such as `+=`, `-=`, `*=`, `/=`, `%=` follow the same type promotion rules, but the RHS is converted to the type of the LHS variable. If the LHS is Var, the RHS keeps its original type (Var's `operator+=` takes over); if the LHS is a native type, the RHS is explicitly converted to that type.

```php
use varint_types;
$a = 10;        // Var because this file selected varint_types
$a += 2.5;      // Var::operator+=(float) → ZendVM → $a becomes float(12.5)

$b = std::int(10); // Explicit php::Int inside a varint_types file
$b += 2.5;      // int64_t += double → C++ implicit truncation → $b = 12 (Int)
```

---

**Last updated**: September 6, 2026
**Applicable version**: TypePHP 0.8+
