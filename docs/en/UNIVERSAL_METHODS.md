# AOT Compiler Universal Methods Tutorial

This tutorial comprehensively introduces the AOT compiler's **Universal Methods** system—a mechanism for zero-overhead method calls on native types using the `$value->method()` syntax.

> ⚠️ **Compiler hard dependency**: the TypePHP compiler depends strongly on the keyword methods such as `toArray`, `toInt` and `toFloat`. The type system can only be established through these methods, so they are built into the compiler and **cannot be overridden or replaced at the PHP code level**. For example, when the compiler sees `toArray()` it knows the expression is always `php::Array`; if user PHP code could override it, the result type would become unknown.

## Table of Contents

1. [What Are Universal Methods](#1-what-are-universal-methods)
2. [Design Principles](#2-design-principles)
3. [Supported Types Overview](#3-supported-types-overview)
4. [Int Integer Methods](#4-int-integer-methods)
5. [Float Floating-Point Methods](#5-float-floating-point-methods)
6. [Bool Boolean Methods](#6-bool-boolean-methods)
7. [String Methods](#7-string-methods)
8. [Array Methods](#8-array-methods)
9. [Stream Methods](#9-stream-methods)
10. [Big* High-Precision Type Methods](#10-big-high-precision-type-methods)
11. [Method Chaining](#11-method-chaining)
12. [Mutable vs Immutable Methods](#12-mutable-vs-immutable-methods)
13. [Var Type Method Lookup](#13-var-type-method-lookup)
14. [Extension Methods: Auto Discovery](#14-extension-methods-auto-discovery)
15. [Complete Examples](#15-complete-examples)

---

## 1. What Are Universal Methods

Universal Methods allow you to call methods directly on variables of PHP native types, just like calling methods on objects. At compile time, the compiler translates these method calls into the corresponding C function or C++ method calls, **generating zero-overhead native code**.

```php
// Call methods directly on a string
$s = "hello world";
echo $s->length();          // → strlen($s) → 11
echo $s->upper();           // → strtoupper($s) → "HELLO WORLD"
echo $s->substr(0, 5);      // → substr($s, 0, 5) → "hello"

// Call methods directly on an array
$arr = [1, 3, 5, 7, 9];
echo $arr->count();         // → count($arr) → 5
echo $arr->contains(3);     // → in_array(3, $arr) → true
$arr->push(11);             // → array_push($arr, 11)

// Call methods directly on an integer
$a = 100;
$a->add(50);                // → a += 50 → 150
echo $a->toString();        // → "150"

// Call methods directly on high-precision types
$big = std::bigInt("12345678901234567890");
echo $big->mul(2)->toString();  // → "24691357802469135780"
```

> **Key idea**: All universal method calls are fully resolved into direct function calls at compile time. There is no vtable lookup, reflection, or runtime type checking—the generated C++ code is exactly equivalent to hand-written C function calls.

---

## 2. Design Principles

### 2.1 Compile-Time Resolution

Each universal method call goes through the following steps at compile time:

1. **Type inference**: The compiler infers the type of the receiver (Int / Float / String / Array / Stream / BigInt / Decimal / BigFloat / Var)
2. **Method lookup**: Look up the method definition in that type's method table
3. **Argument validation**: Check that the number of arguments is within the `min_args` ~ `max_args` range
4. **Code generation**: Generate the corresponding C/C++ call code based on the handler type

### 2.2 Handler Types

The universal methods system supports five internal handlers, each suited to different scenarios:

| Handler | Description | Example |
|---------|------|------|
| `calc_op` | Directly generates C++ operator code (Int/Float only) | `$a->add(1)` → `a + 1` |
| `php_fn` | Calls a PHP standard library function | `$s->length()` → `strlen(s)` |
| `cpp_fn` | Calls a `phpx` C++ static method | `$big->add(1)` → `BigInt::add($big, 1)` |
| `direct_method` | Calls a Variant C++ member method | `$s->equals("x")` → `s.equals("x")` |
| `convert_fn` | Type conversion | `$a->toFloat()` → `php::toFloat(a)` |
| `php_fn_ref` | Calls a PHP function and modifies the original value by reference (Array only) | `$arr->push(1)` → `array_push($arr, 1)` |
| `direct_method_mutate` | Calls a mutating Variant member method | `$arr->set(0, 1)` → `arr.set(0, 1)` |

---

## 3. Supported Types Overview

| Type | Method Count | Main Categories |
|------|---------|---------|
| **Int** | 26 | Arithmetic, math functions, type conversion |
| **Float** | 26 | Arithmetic, math functions, trigonometric functions, type conversion |
| **Bool** | 2 | Type conversion |
| **String** | 70+ | String operations, search, encoding, Hash, multibyte, serialization |
| **Array** | 50+ | CRUD, sorting, traversal, set operations, serialization |
| **Stream** | 30+ | Read/write, seeking, locking, Socket, filters |
| **BigInt** | 14 | Arithmetic, comparison, conversion, GCD |
| **Decimal** | 11 | Arithmetic, comparison, conversion |
| **BigFloat** | 10 | Arithmetic, comparison, conversion |

---

## 4. Int Integer Methods

### 4.1 Arithmetic Operations (Return Int)

```php
$a = 100;

$a->add(50);        // a += 50  → 150
$a->sub(30);        // a -= 30  → 70
$a->mul(2);         // a *= 2   → 200
$a->div(4);         // a /= 4   → 25
$a->mod(7);         // a %= 7   → 2

$a->inc();          // a++  → 101
$a->dec();          // a--  → 100
```

> **Mutability**: `add`/`sub`/`mul`/`div`/`mod`/`inc`/`dec` **modify the original variable** (directly operating on the C++ native `int64_t`), while also returning the new value. This differs from the immutable semantics of the Big* types.

```php
$a = 100;
$b = $a->add(50);  // $a becomes 150, $b is also 150 (returns the new value)
```

> **Native semantics**: arithmetic on a native `int`/`float` compiles to plain C++ operators and does **not** follow PHP rules. For example `div(0)`/`mod(0)` raise SIGFPE for `int`, `int` overflow wraps (undefined behavior for signed types) instead of promoting to float, and `float` division by zero yields `inf`/`nan` instead of throwing `DivisionByZeroError`. Use `var int` (or `var float`) when PHP semantics such as exceptions or overflow promotion are required.

### 4.2 Math Functions

```php
$a = -100;

$a->abs();          // abs($a)         → 100
$a->ceil();         // ceil($a)        → -100.0 (float)
$a->floor();        // floor($a)       → -100.0 (float)
$a->round();        // round($a)       → -100.0 (float)
$a->sqrt();         // sqrt($a)        → NaN (no real square root for a negative number)
$a->pow(3);         // $a ** 3         → -1000000
$a->log();          // log($a)         → NaN
$a->log10();        // log10($a)       → NaN
$a->exp();          // exp($a)         → 3.72e-44 (float)

$a->max(50);        // max($a, 50)     → 50
$a->min(50);        // min($a, 50)     → -100
```

> **Note**: `ceil`, `floor`, and `round` return a **Float type** (standard PHP behavior). `pow` returns a **Var type** (because the result of exponentiation may overflow).

### 4.3 Trigonometric Functions

```php
$a = 0;

$a->sin();          // sin(0)     → 0.0
$a->cos();          // cos(0)     → 1.0
$a->tan();          // tan(0)     → 0.0
$a->asin();         // asin(0)    → 0.0
$a->acos();         // acos(1)    → 0.0
$a->atan();         // atan(0)    → 0.0
$a->atan2(1);       // atan2(0,1) → 0.0
$a->deg2rad();      // deg2rad(0) → 0.0
$a->rad2deg();      // rad2deg(0) → 0.0
```

### 4.4 Type Conversion

```php
$a = 42;

$a->toFloat();      // (float) $a  → 42.0
$a->toString();     // (string) $a → "42"
$a->toBool();       // (bool) $a   → true
```

### 4.5 Complete Method List

| Method | Args | Return Type | Description |
|------|------|---------|------|
| `add($x)` | 1 | Int | Addition (modifies the original value) |
| `sub($x)` | 1 | Int | Subtraction (modifies the original value) |
| `mul($x)` | 1 | Int | Multiplication (modifies the original value) |
| `div($x)` | 1 | Int | Division (modifies the original value) |
| `mod($x)` | 1 | Int | Modulo (modifies the original value) |
| `inc()` | 0 | Int | Increment (modifies the original value) |
| `dec()` | 0 | Int | Decrement (modifies the original value) |
| `abs()` | 0 | Int | Absolute value |
| `ceil()` | 0 | Float | Round up |
| `floor()` | 0 | Float | Round down |
| `round()` | 0-2 | Float | Round to nearest |
| `sqrt()` | 0 | Float | Square root |
| `pow($x)` | 1 | Var | Exponentiation |
| `log()` | 0-1 | Float | Natural logarithm |
| `log10()` | 0 | Float | Base-10 logarithm |
| `exp()` | 0 | Float | Exponential of e |
| `sin()` | 0 | Float | Sine |
| `cos()` | 0 | Float | Cosine |
| `tan()` | 0 | Float | Tangent |
| `asin()` | 0 | Float | Arcsine |
| `acos()` | 0 | Float | Arccosine |
| `atan()` | 0 | Float | Arctangent |
| `atan2($x)` | 1 | Float | Two-argument arctangent |
| `deg2rad()` | 0 | Float | Convert degrees to radians |
| `rad2deg()` | 0 | Float | Convert radians to degrees |
| `max($x)` | 1 | Int/Float | Maximum value |
| `min($x)` | 1 | Int/Float | Minimum value |
| `toFloat()` | 0 | Float | Convert to float |
| `toString()` | 0 | String | Convert to string |
| `toBool()` | 0 | Bool | Convert to boolean |

---

## 5. Float Floating-Point Methods

Float's method set is almost identical to Int's, but the return type is Float (`ceil`/`floor`/`round`/trigonometric functions, etc. are still Float).

```php
$f = 3.14;

$f->add(1.0);       // f += 1.0  → 4.14
$f->sub(1.0);       // f -= 1.0  → 2.14
$f->mul(2.0);       // f *= 2.0  → 6.28
$f->div(2.0);       // f /= 2.0  → 1.57

$f->abs();          // abs(3.14) → 3.14
$f->sqrt();         // sqrt(3.14) → 1.772...
$f->sin();          // sin(3.14)  → 0.00159...
$f->round(2);       // round(3.14, 2) → 3.14

// Float-specific conversions
$f->toInt();        // (int) $f   → 3
$f->toString();     // (string) $f → "3.14"
$f->toBool();       // (bool) $f  → true
```

The main differences between Float and Int methods:
- Int has `mod($x)`, Float does not (floating-point modulo is meaningless)
- Arithmetic methods operate directly on C++ `double`, with performance identical to hand-written C code

---

## 6. Bool Boolean Methods

The Bool type has only two conversion methods:

```php
$b = true;

$b->toInt();        // (int) $b   → 1
$b->toString();     // (string) $b → "1"
```

---

## 7. String Methods

String has the richest method set, covering most string operation needs in daily development.

### 7.1 Basic Operations

```php
$s = "hello world";

$s->length();           // strlen($s)         → 11
$s->isEmpty();          // empty($s)          → false
$s->upper();            // strtoupper($s)     → "HELLO WORLD"
$s->lower();            // strtolower($s)     → "hello world"
$s->upperFirst();       // ucfirst($s)        → "Hello world"
$s->lowerFirst();       // lcfirst($s)        → "hello world"
$s->upperWords();       // ucwords($s)        → "Hello World"

$s->trim();             // trim($s)           → "hello world"
$s->lTrim();            // ltrim($s)          → "hello world"
$s->rTrim();            // rtrim($s)          → "hello world"
$s->trim(" \t\n\r");    // trim($s, chars)
```

### 7.2 Search and Comparison

```php
$s = "hello world";

// Checking
$s->startsWith("hello");    // str_starts_with(...)    → true
$s->endsWith("world");      // str_ends_with(...)      → true
$s->contains("lo wo");      // str_contains(...)       → true
$s->compare("hello");       // strcmp(...)             → >0
$s->iCompare("HELLO");      // strcasecmp(...)         → 0
$s->isNumeric();            // is_numeric(...)         → false

// Position lookup
$s->indexOf("world");       // strpos($s, "world")     → 6
$s->lastIndexOf("o");       // strrpos($s, "o")        → 7
$s->iIndexOf("WORLD");      // stripos($s, "WORLD")    → 6
$s->iLastIndexOf("O");      // strripos($s, "O")       → 7

// Content lookup
$s->find("world");          // strstr($s, "world")     → "world"
$s->iFind("WORLD");         // stristr($s, "WORLD")    → "world"
$s->lastCharIndexOf("o");   // strrchr($s, "o")        → "orld"
```

### 7.3 Substring and Replacement

```php
$s = "hello world";

// Substring
$s->substr(0, 5);           // substr($s, 0, 5)        → "hello"
$s->substr(6);              // substr($s, 6)           → "world"

// Counting
$s->substrCount("l");       // substr_count($s, "l")   → 3
$s->wordCount();            // str_word_count($s)      → 2

// Replacement
$s->replace("hello", "hi");        // str_replace("hello", "hi", $s)
$s->iReplace("HELLO", "hi");       // str_ireplace(...)
$s->substrReplace("hi", 0, 5);     // substr_replace($s, "hi", 0, 5)
$s->stripTags();                   // strip_tags($s)
$s->stripTags("<br><p>");          // strip_tags($s, tags)
```

### 7.4 Splitting and Joining

```php
$s = "hello world";

// Split
$words = $s->split(" ");            // explode(" ", $s) → ["hello", "world"]
$words->count();                    // 2

// Join (operates on an array)
$words->join(", ");                 // implode(", ", $words) → "hello, world"

// Repeat
$s->repeat(3);                      // str_repeat($s, 3) → "hello worldhello worldhello world"

// Pad
$s->pad(20, "-");                   // str_pad($s, 20, "-")
```

### 7.5 Encoding and Escaping

```php
$s = "hello world & <test>";

$s->htmlEntityEncode();             // htmlentities($s)
$s->htmlEntityDecode();             // html_entity_decode($s)
$s->htmlSpecialCharsEncode();       // htmlspecialchars($s)
$s->htmlSpecialCharsDecode();       // htmlspecialchars_decode($s)

$s->urlEncode();                    // urlencode($s)       → "hello+world+%26+%3Ctest%3E"
$s->urlDecode();                    // urldecode($s)
$s->rawUrlEncode();                 // rawurlencode($s)
$s->rawUrlDecode();                 // rawurldecode($s)

$s->addSlashes();                   // addslashes($s)
$s->stripSlashes();                 // stripslashes($s)
$s->addCSlashes("A..z");            // addcslashes($s, "A..z")
$s->stripCSlashes();                // stripcslashes($s)

$s->base64Encode();                 // base64_encode($s)
$s->base64Decode();                 // base64_decode($s)
```

### 7.6 Hash and Checksum

```php
$s = "hello";

$s->md5();          // md5($s)       → "5d41402abc4b2a76b9719d911017c592"
$s->sha1();         // sha1($s)      → "aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d"
$s->crc32();        // crc32($s)     → 907060870
$s->hash("sha256"); // hash("sha256", $s) → "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
$s->hashCode();     // C++ std::hash → hash value (int)
```

### 7.7 Regular Expression Matching

```php
$s = "hello world";

// match(pattern) → returns the match array
$result = $s->match("/hello/");

// matchAll(pattern) → returns all match results
$results = $s->matchAll("/[a-z]+/");
```

### 7.8 Serialization

```php
$s = '{"name":"John","age":30}';

$data = $s->jsonDecode();           // json_decode($s, true)  → ["name" => "John", ...]
$obj = $s->jsonDecodeToObject();    // json_decode($s)        → stdClass

$s = 'a:3:{i:0;s:3:"foo";i:1;s:3:"bar";i:2;s:3:"baz";}';
$arr = $s->unserialize();           // unserialize($s)        → ["foo", "bar", "baz"]
```

### 7.9 Multibyte Strings (mbstring)

Methods with the `mb` prefix correspond to PHP's `mb_*` function family:

```php
$s = "你好世界";

$s->mbLength();                     // mb_strlen($s)          → 4
$s->mbUpper();                      // mb_strtoupper($s)
$s->mbLower();                      // mb_strtolower($s)
$s->mbSubstr(0, 2);                 // mb_substr($s, 0, 2)    → "你好"
$s->mbIndexOf("世界");              // mb_strpos($s, "世界")  → 2
$s->mbFind("世");                   // mb_strstr($s, "世")
$s->mbDetectEncoding();             // mb_detect_encoding($s)
$s->mbConvertEncoding("UTF-8");     // mb_convert_encoding($s, "UTF-8")
$s->mbConvertCase(MB_CASE_TITLE);   // mb_convert_case($s, MB_CASE_TITLE)
$s->mbTrim();                       // mb_trim($s)
$s->mbLTrim();                      // mb_ltrim($s)
$s->mbRTrim();                      // mb_rtrim($s)
```

### 7.10 C++ Native Methods

The following methods directly call C++ member functions of `phpx::Variant`, with no corresponding PHP function:

```php
$s = "hello";

$s->equals("hello");        // C++ String.equals() — value comparison
$s->append(" world");       // C++ String.append() — append (modifies the original value)
```

---

## 8. Array Methods

Array methods are divided into **read-only methods** and **mutating methods**. Mutating methods directly modify the original array variable.

### 8.1 Basic Information

```php
$arr = [1, 3, 5, 7, 9];

$arr->count();          // count($arr)         → 5
$arr->isEmpty();        // empty($arr)         → false
$arr->isList();         // array_is_list($arr) → true
```

### 8.2 CRUD

```php
$arr = [1, 2, 3];

// Mutating methods (modify the original array)
$arr->push(4);              // array_push($arr, 4)          → [1,2,3,4]
$arr->push(5, 6, 7);        // supports multiple arguments
$arr->pop();                // array_pop($arr)              → returns 7
$arr->shift();              // array_shift($arr)            → returns 1
$arr->unshift(0);           // array_unshift($arr, 0)       → [0,...]
$arr->set(0, 100);          // C++ Array.set(0, 100)        → [100,...]
$arr->del(0);               // C++ Array.del(0)             → removes index 0
$arr->clean();              // C++ Array.clean()            → []

// Read-only methods
$arr = ['a' => 1, 'b' => 2, 'c' => 3];
$arr->get('a');             // C++ Array.get('a')           → 1
$arr->keyExists('a');       // array_key_exists('a', $arr)  → true
$arr->contains(2);          // in_array(2, $arr)            → true
$arr->search(2);            // array_search(2, $arr)        → "b"
```

### 8.3 Traversal and Aggregation

```php
$arr = [1, 3, 5, 7, 9];

$arr->sum();                // array_sum($arr)       → 25
$arr->product();            // array_product($arr)   → 945
$arr->all(fn($v) => ...);   // array_all($arr, fn)
$arr->any(fn($v) => ...);   // array_any($arr, fn)

$arr->map(fn($v) => $v * 2);// array_map(fn, $arr)   → [2,6,10,14,18]
$arr->reduce(fn($c, $v) => $c + $v, 0);  // array_reduce($arr, fn, 0)
$arr->filter(fn($v) => $v > 5);          // array_filter($arr, fn)
$arr->walk(fn(&$v) => $v *= 2);          // array_walk($arr, fn)
```

### 8.4 Sorting

```php
$arr = [3, 1, 4, 1, 5, 9];

$arr->sort();               // sort($arr)            → [1,1,3,4,5,9]
$arr->sortDesc();           // rsort($arr)           → [9,5,4,3,1,1]
$arr->keySort();            // ksort($arr)           → sort by key
$arr->valueSort();          // asort($arr)           → sort by value (preserving keys)
```

All sorting methods **modify the original array**.

### 8.5 Set Operations

```php
$a = [1, 2, 3, 4, 5];
$b = [4, 5, 6, 7, 8];

$a->diff($b);               // array_diff($a, $b)   → [1,2,3]
$a->intersect($b);          // array_intersect(...)  → [4,5]
$a->merge($b);              // array_merge($a, $b)   → [1,2,3,4,5,4,5,6,7,8]
$a->unique();               // array_unique($a)      → [1,2,3,4,5]
$a->flip();                 // array_flip($a)        → {1:0, 2:1, 3:2, ...}
$a->reverse();              // array_reverse($a)     → [5,4,3,2,1]
$a->replace($b);            // array_replace($a, $b)
$a->values();               // array_values($a)      → re-index
$a->combine($keys);         // array_combine($keys, $a)
$a->fillKeys($value);       // array_fill_keys($a, $value)
```

> Methods such as `diff`, `intersect`, `merge`, and `replace` support **variadic arguments**: `$a->merge($b, $c, $d)` can merge multiple arrays at once.

### 8.6 Extraction and Slicing

```php
$arr = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];

$arr->keys();               // array_keys($arr)         → ['a','b','c','d','e']
$arr->slice(1, 3);          // array_slice($arr, 1, 3) → ['b'=>2, 'c'=>3, 'd'=>4]
$arr->chunk(2);             // array_chunk($arr, 2)    → [[1,2],[3,4],[5]]
$arr->column('name');       // array_column($arr, 'name')
$arr->splice(1, 3, [6,7]);  // array_splice($arr, 1, 3, [6,7]) — modifies the original array
$arr->rand(2);              // array_rand($arr, 2)

$arr->keyFirst();           // array_key_first($arr) → "a"
$arr->keyLast();            // array_key_last($arr)  → "e"
$arr->find(fn($v) => $v > 3);  // array_find($arr, fn)
```

### 8.7 String-Related

```php
$arr = ["hello", "world"];

$arr->join(", ");           // implode(", ", $arr) → "hello, world"
$arr->replaceStr("hello", "hi");    // str_replace("hello", "hi", $arr)
$arr->iReplaceStr("HELLO", "hi");   // str_ireplace(...)
```

### 8.8 Serialization

```php
$arr = ["name" => "John", "age" => 30];

$arr->serialize();          // serialize($arr)      → "a:2:{...}"
$arr->marshal();            // serialize($arr) — alias
$arr->jsonEncode();         // json_encode($arr)    → '{"name":"John","age":30}'
```

### 8.9 Type Conversion

```php
$arr = [1, 2, 3];

$arr->toInt();              // (int) non-empty array → 1
$arr->toFloat();            // (float) non-empty array → 1.0
$arr->toBool();             // (bool) non-empty array → true
$arr->toString();           // → "Array"
```

### 8.10 Complete Method Category Table

| Category | Methods |
|------|------|
| Basic information | `count`, `isEmpty`, `isList` |
| CRUD | `push`, `pop`, `shift`, `unshift`, `set`, `get`, `del`, `clean`, `keyExists`, `contains`, `search` |
| Traversal & aggregation | `sum`, `product`, `all`, `any`, `map`, `reduce`, `filter`, `walk` |
| Sorting | `sort`, `sortDesc`, `keySort`, `valueSort` |
| Set operations | `diff`, `diffAssoc`, `diffKey`, `intersect`, `intersectAssoc`, `merge`, `unique`, `flip`, `reverse`, `replace`, `values`, `combine`, `fillKeys` |
| Extraction & slicing | `keys`, `slice`, `chunk`, `column`, `splice`, `rand`, `keyFirst`, `keyLast`, `find` |
| String | `join`, `replaceStr`, `iReplaceStr`, `countValues`, `pad` |
| Serialization | `serialize`, `marshal`, `jsonEncode` |
| Type conversion | `toInt`, `toFloat`, `toBool`, `toString` |

---

## 9. Stream Methods

The Stream type represents a file handle or network connection. Obtain it via functions such as `fopen()`.

### 9.1 Read and Write

```php
$fp = fopen("test.txt", "w+");

$fp->write("hello world\n");      // fwrite($fp, "hello world\n") → number of bytes written
$fp->write("more data", 4);       // fwrite($fp, "more data", 4)  → writes only 4 bytes

$fp->seek(0);                     // fseek($fp, 0)   → back to the beginning
$content = $fp->read(1024);       // fread($fp, 1024)
$content = $fp->getContents();    // stream_get_contents($fp)

$char = $fp->getChar();           // fgetc($fp)      → reads one character
$line = $fp->getLine();           // fgets($fp)      → reads one line
$line = $fp->getLine(1024);       // fgets($fp, 1024)
$line = $fp->getRecord(1024, "\n"); // stream_get_line($fp, 1024, "\n")
```

### 9.2 Metadata and Status

```php
$fp->tell();                // ftell($fp)           → current position
$fp->eof();                 // feof($fp)            → whether at EOF
$fp->stat();                // fstat($fp)           → file status array
$fp->getMetaData();         // stream_get_meta_data($fp)
$fp->isLocal();             // stream_is_local($fp)
$fp->isTTY();               // stream_isatty($fp)
```

### 9.3 Control Operations

```php
$fp->truncate(0);           // ftruncate($fp, 0)    → truncates the file
$fp->sync();                // fsync($fp)           → sync to disk
$fp->dataSync();            // fdatasync($fp)       → sync data
$fp->close();               // fclose($fp)          → close

// Locking
$fp->lock(LOCK_EX);         // flock($fp, LOCK_EX)
$fp->lock(LOCK_SH);         // flock($fp, LOCK_SH)
$fp->lock(LOCK_UN);         // flock($fp, LOCK_UN)

// Buffer settings
$fp->setBlocking(true);     // stream_set_blocking($fp, true)
$fp->setChunkSize(8192);    // stream_set_chunk_size($fp, 8192)
$fp->setReadBuffer(8192);   // stream_set_read_buffer($fp, 8192)
$fp->setWriteBuffer(8192);  // stream_set_write_buffer($fp, 8192)
$fp->setTimeout(30);        // stream_set_timeout($fp, 30)
$fp->supportsLock();        // stream_supports_lock($fp)
```

### 9.4 Socket Operations

```php
// Server
$server = stream_socket_server("tcp://0.0.0.0:8080");
$client = $server->accept();             // stream_socket_accept($server)
$client->accept(30);                     // timeout of 30 seconds

// Information
$client->getSocketName(true);            // stream_socket_get_name — remote address
$server->getSocketName(false);           // stream_socket_get_name — local address

// Data
$client->sendTo("hello", 0, $addr);      // stream_socket_sendto(...)
$client->recvFrom(1024);                 // stream_socket_recvfrom(...)
$client->recvFrom(1024, 0, $addr);       // with address

// Control
$client->enableCrypto(true);             // stream_socket_enable_crypto → enables TLS
$client->shutdown(STREAM_SHUT_RDWR);     // stream_socket_shutdown(...)

// Filters
$fp->appendFilter("string.toupper");     // stream_filter_append(...)
$fp->prependFilter("string.tolower");    // stream_filter_prepend(...)
```

### 9.5 Stream Copy

```php
$src = fopen("source.txt", "r");
$dst = fopen("dest.txt", "w");

$src->copy($dst);                    // stream_copy_to_stream($src, $dst)
$src->copy($dst, 4096);              // specify buffer size
```

---

## 10. Big* High-Precision Type Methods

### 10.1 BigInt Methods

```php
$a = std::bigInt("12345678901234567890");

// Arithmetic (each returns a new BigInt, leaving the original unchanged)
$b = $a->add(1);        // $a + 1
$c = $a->sub(1);        // $a - 1
$d = $a->mul(2);        // $a * 2
$e = $a->div(10);       // $a / 10
$f = $a->mod(1000000);  // $a % 1000000
$g = $a->pow(3);        // $a ** 3

// Unary methods
$h = $a->neg();         // -$a
$i = $a->abs();         // abs($a)

// Special methods
$j = $a->gcd(15);       // gcd($a, 15)

// Comparison
$cmp = $a->cmp(100);    // -1/0/1

// Type conversion
$a->toString();         // → "12345678901234567890"
$a->toInt();            // → int (may truncate)
$a->toFloat();          // → float (may lose precision)
```

| Method | Args | Return | Description |
|------|------|------|------|
| `add($x)` | 1 | BigInt | Addition |
| `sub($x)` | 1 | BigInt | Subtraction |
| `mul($x)` | 1 | BigInt | Multiplication |
| `div($x)` | 1 | BigInt | Integer division |
| `mod($x)` | 1 | BigInt | Modulo |
| `pow($x)` | 1 | BigInt | Exponentiation |
| `neg()` | 0 | BigInt | Negation |
| `abs()` | 0 | BigInt | Absolute value |
| `gcd($x)` | 1 | BigInt | Greatest common divisor |
| `cmp($x)` | 1 | Int | Comparison |
| `toString()` | 0 | String | Convert to string |
| `toInt()` | 0 | Int | Convert to integer |
| `toFloat()` | 0 | Float | Convert to float |

### 10.2 Decimal Methods

```php
$d = std::decimal("123.456");

$d->add(std::decimal("50.25"));  // Addition
$d->sub(std::decimal("50.25"));  // Subtraction
$d->mul(2);                      // Multiplication
$d->div(3);                      // Division
$d->mod(std::decimal("5.0"));    // Modulo
$d->neg();                       // Negation
$d->abs();                       // Absolute value
$d->cmp(std::decimal("100"));    // Comparison
$d->toString();                  // → "123.456"
$d->toInt();                     // → 123
$d->toFloat();                   // → 123.456 (double)
```

### 10.3 BigFloat Methods

```php
$bf = std::bigFloat(3.14159265);

$bf->add(1.0);          // Addition
$bf->sub(1.0);          // Subtraction
$bf->mul(2.0);          // Multiplication
$bf->div(2.0);          // Division
$bf->neg();             // Negation
$bf->abs();             // Absolute value
$bf->cmp(3.0);          // Comparison → >0
$bf->toString();        // Convert to string
$bf->toInt();           // → 3
$bf->toFloat();         // → 3.14159265
```

> **Immutability**: Unlike Int/Float, all methods of the Big* types **return new values** and do not modify the original variable. See [Section 12](#12-mutable-vs-immutable-methods).

---

## 11. Method Chaining

The return type of a universal method is known at compile time, so methods can be chained directly:

```php
// String method chaining
$result = "  Hello World!  "
    ->trim()
    ->lower()
    ->substr(0, 5)
    ->upper();
echo $result;  // "HELLO"

// Int method chaining
$result = 100
    ->add(50)     // 150 (modifies the original variable)
    ->mul(3)      // 450
    ->sub(100)    // 350
    ->toString();
echo $result;  // "350"

// BigInt method chaining (immutable, returns new values)
$result = std::bigInt("100")
    ->add(std::bigInt(50))
    ->mul(std::bigInt(3))
    ->toString();
echo $result;  // "450"

// Cross-type method chaining
$sum = "123456789012345678901234567890"
    ->length();     // String.length() → Int
echo $sum;  // 30

// Method chaining + final conversion
$result = std::bigInt("99999999999999999999")
    ->add(std::bigInt(1))
    ->toString();
echo "100000000000000000000 = " . $result;
```

> **Note**: Each step of an Int/Float chain **modifies the original variable**. If you need to keep an intermediate value, save it in a temporary variable first. Big* types do not have this problem because they are immutable.

---

## 12. Mutable vs Immutable Methods

Universal methods of different types behave differently with respect to mutability:

### 12.1 Mutable Methods (Modify the Original Value)

**Int and Float** arithmetic methods operate directly on C++ native variables and **modify the original value**:

```php
$a = 100;
$b = $a->add(50);   // This both modifies $a (becomes 150) and returns the new value to $b
echo $a;   // 150 — has been modified
echo $b;   // 150

$a->inc();          // $a becomes 151
$a->dec();          // $a becomes 150
```

**Array** mutating methods **modify the original array**:

```php
$arr = [1, 2, 3];

$arr->push(4);      // modifies $arr → [1,2,3,4]
$arr->pop();        // modifies $arr → [1,2,3]
$arr->sort();       // modifies $arr → [1,2,3]
$arr->set(0, 100);  // modifies $arr → [100,2,3]
$arr->clean();      // modifies $arr → []
```

### 12.2 Immutable Methods (Return New Values)

All methods of **BigInt, Decimal, and BigFloat** **do not modify the original value** and return a newly created object:

```php
$a = std::bigInt(100);
$b = $a->add(50);   // $a is still 100, $b is 150

$a = std::bigInt(100);
$a->add(50);        // The return value is discarded! $a is still 100
```

Most **String** methods also return new values:

```php
$s = "hello";
$upper = $s->upper();  // $s is still "hello", $upper is "HELLO"
```

**Exception**: `String.append()` is a mutating method (`direct_method_mutate`) and modifies the original string:

```php
$s = "hello";
$s->append(" world");  // $s becomes "hello world"
```

### 12.3 Summary of Mutable Methods

| Handler type | Affected types | Examples |
|-------------|---------|------|
| `calc_op` | Int, Float | `add`, `sub`, `mul`, `div`, `mod`, `inc`, `dec` |
| `direct_method_mutate` | String, Array | `append`, `set`, `del`, `clean` |
| `php_fn_ref` | Array | `push`, `pop`, `shift`, `unshift`, `sort`, `sortDesc`, `splice`, `walk` |

---

## 13. Var Type Method Lookup

When a variable's type is `Var` (the generic PHP type), the compiler searches the method tables in the following order:

```
String → Array → Int → Float → Bool → Stream → BigInt → Decimal → BigFloat
```

Once a matching method name is found, it generates the call code for that type. If not found, it falls back to dynamic method calls (via the ZendVM).

```php
// The type of $x is Var (from a function return value, array extraction, etc.)
$x = some_func_returning_var();

// The compiler searches in order:
// 1. First looks for length in the String method table → found! → strlen($x)
echo $x->length();

// 2. First looks for contains in the String method table → found! → str_contains($x, ...)
echo $x->contains("test");
```

> **Note**: If multiple types have a method with the same name, the first matched type takes precedence. For example, `toInt` exists in multiple types—the lookup stops at the first matching type.

---

## 14. Extension Methods: Auto Discovery

In addition to the built-in universal methods, the compiler also supports **custom extension methods**. As long as a PHP function following the naming convention is defined in the current project, the compiler automatically discovers it as a universal method.

### 14.1 Naming Convention

Format: `{type_prefix}_{snake_case_method_name}`

| Type | Prefix | Example |
|------|------|------|
| Int | `int_` | `int_is_prime` → `$a->isPrime()` |
| Float | `float_` | `float_normalize` → `$f->normalize()` |
| Bool | `bool_` | `bool_toggle` → `$b->toggle()` |
| String | `str_` | `str_capitalize` → `$s->capitalize()` |
| Array | `array_` | `array_flatten` → `$arr->flatten()` |
| Stream | `stream_` | `stream_rewind` → `$fp->rewind()` |
| BigInt | `bigint_` | `bigint_is_probable_prime` → `$a->isProbablePrime()` |
| Decimal | `decimal_` | `decimal_round_to` → `$d->roundTo()` |
| BigFloat | `bigfloat_` | `bigfloat_truncate` → `$bf->truncate()` |

### 14.2 Implementation Example

```php
<?php

/**
 * Extension method: determine whether an Int is prime
 * Naming: type prefix int_ + snake_case method name is_prime
 */
function int_is_prime(int $n): bool {
    if ($n < 2) return false;
    for ($i = 2; $i * $i <= $n; $i++) {
        if ($n % $i == 0) return false;
    }
    return true;
}

/**
 * Extension method: flatten an array
 * Naming: type prefix array_ + snake_case method name flatten
 */
function array_flatten(array $arr): array {
    $result = [];
    array_walk_recursive($arr, function($v) use (&$result) {
        $result[] = $v;
    });
    return $result;
}

function main(): void {
    // Auto discovery: int_is_prime → $n->isPrime()
    $n = 97;
    if ($n->isPrime()) {
        echo "$n is prime\n";
    }

    // Auto discovery: array_flatten → $arr->flatten()
    $nested = [[1, 2], [3, [4, 5]], 6];
    $flat = $nested->flatten();
    var_dump($flat);  // [1, 2, 3, 4, 5, 6]
}
?>
```

### 14.3 Notes

- The function's **first parameter** is the receiver, passed in automatically from the method call
- The return type of an extension method is fixed to `Var`
- Define the function before calling it—the compiler discovers functions during the analysis phase and uses them during the conversion phase
- A method's camelCase naming is **automatically converted to snake_case**: `isPrime` → `is_prime`, `flattenNestedArray` → `flatten_nested_array`

---

## 15. Complete Examples

### 15.1 String Processing Pipeline

```php
<?php

function main(): void {
    $raw = "  <h1>Hello World!</h1>  \n";

    $processed = $raw
        ->trim()                        // strip surrounding whitespace
        ->stripTags()                   // strip HTML tags
        ->lower()                       // convert to lowercase
        ->upperWords();                 // capitalize the first letter of each word

    echo "原始: " . $raw->jsonEncode() . "\n";
    echo "处理: " . $processed . "\n";
    echo "长度: " . $processed->length() . "\n";
    echo "是否为数字: " . (int)$processed->isNumeric() . "\n";
}
?>
```

### 15.2 Array Data Processing

```php
<?php

function main(): void {
    $data = [5, 2, 8, 1, 9, 3, 7];

    echo "原始: " . $data->join(", ") . "\n";

    // Sorting
    $data->sort();
    echo "排序: " . $data->join(", ") . "\n";

    // Statistics
    echo "数量: " . $data->count() . "\n";
    echo "求和: " . $data->sum() . "\n";
    echo "最小值: " . $data->get(0) . "\n";
    echo "最大值: " . $data->get($data->count() - 1) . "\n";
    echo "是否包含 5: " . (int)$data->contains(5) . "\n";

    // Filter and map
    $even = $data->filter(function($v) { return $v % 2 == 0; });
    echo "偶数: " . $even->values()->join(", ") . "\n";

    $doubled = $data->map(function($v) { return $v * 2; });
    echo "翻倍: " . $doubled->join(", ") . "\n";

    // Serialization
    echo "JSON: " . $data->jsonEncode() . "\n";
}
?>
```

### 15.3 High-Precision Calculation and Chaining

```php
<?php

function main(): void {
    // Factorial of a large integer (using compound assignment for brevity)
    $n = 50;
    $result = std::bigInt(1);
    for ($i = 2; $i <= $n; $i++) {
        $result *= $i;
    }
    $digits = strlen($result->toString());
    echo "{$n}! has {$digits} digits\n";

    // Method chaining: BigInt arithmetic + conversion
    $big = std::bigInt(1000);
    $val = $big->mul(3)->add(200)->sub(50)->div(10)->toString();
    echo "1000 * 3 + 200 - 50 / 10 = {$val}\n";

    // Decimal financial calculation
    $price = std::decimal("19.99");
    $qty = 5;
    $taxRate = std::decimal("0.08");
    $total = $price * $qty * ($taxRate->add(std::decimal(1)));
    echo "总价: " . $total->toString() . "\n";

    // BigFloat scientific calculation
    $pi = std::bigFloat("3.14159265358979323846");
    $area = $pi * 100;
    echo "圆面积: " . $area->toString() . "\n";
    echo "取整: " . $area->toInt() . "\n";
}
?>
```

### 15.4 File Processing

```php
<?php

function main(): void {
    // Write to the file
    $fp = fopen("data.txt", "w+");
    $fp->write("Line 1\n");
    $fp->write("Line 2\n");
    $fp->write("Line 3\n");

    // Go back to the beginning and read
    $fp->seek(0);
    while (!$fp->eof()) {
        $line = $fp->getLine();
        if ($line !== false) {
            echo $line->trim();
            echo " (长度: " . $line->trim()->length() . ")\n";
        }
    }

    $fp->close();
}
?>
```

---

## Further Reading

- **High-precision type tutorial**: [`docs/HIGH_PRECISION_TYPES.md`](HIGH_PRECISION_TYPES.md)
- **Type system specification**: [`docs/NATIVE_TYPES.md`](NATIVE_TYPES.md)
- **Universal methods implementation**: [`src/Parser/UniversalMethodCall.php`](../src/Parser/UniversalMethodCall.php)
- **Integration tests**:
  - [`tests/compiler/string_method/`](../tests/compiler/string_method/) — String universal methods tests
  - [`tests/compiler/array_method/`](../tests/compiler/array_method/) — Array universal methods tests
  - [`tests/compiler/stream_method/`](../tests/compiler/stream_method/) — Stream universal methods tests
  - [`tests/compiler/bigint/`](../tests/compiler/bigint/) — BigInt universal methods tests
  - [`tests/compiler/decimal/`](../tests/compiler/decimal/) — Decimal universal methods tests
