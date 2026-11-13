# AOT 编译器通用方法（Universal Methods）使用教程

本教程全面介绍 AOT 编译器的**通用方法（Universal Methods）**系统——一种在原生类型上通过 `$value->method()` 语法进行零开销方法调用的机制。

> ⚠️ **编译器强依赖**：当前 TypePHP 编译器对 `toArray`、`toInt`、`toFloat` 等关键词方法是强依赖，只有依靠这些方法才能建立类型系统，因此它们由编译器内置，**无法在 PHP 代码层被覆盖或替换**。典型例子：编译器看到 `toArray()` 就知道表达式一定是 `php::Array` 类型；若被 PHP 用户代码覆盖，结果类型就变成未知。

## 目录

1. [什么是通用方法](#1-什么是通用方法)
2. [设计原理](#2-设计原理)
3. [支持的类型一览](#3-支持的类型一览)
4. [Int 整型方法](#4-int-整型方法)
5. [Float 浮点型方法](#5-float-浮点型方法)
6. [Bool 布尔型方法](#6-bool-布尔型方法)
7. [String 字符串方法](#7-string-字符串方法)
8. [Array 数组方法](#8-array-数组方法)
9. [Stream 流方法](#9-stream-流方法)
10. [Big* 高精度类型方法](#10-big-高精度类型方法)
11. [方法链式调用](#11-方法链式调用)
12. [可变方法 vs 不可变方法](#12-可变方法-vs-不可变方法)
13. [Var 类型的方法查找](#13-var-类型的方法查找)
14. [扩展方法：自动发现](#14-扩展方法自动发现)
15. [完整示例](#15-完整示例)

---

## 1. 什么是通用方法

通用方法（Universal Methods）允许你在 PHP 原生类型的变量上直接调用方法，就像在对象上调用一样。编译器在编译时将这些方法调用翻译为对应的 C 函数或 C++ 方法调用，**生成零开销的本地代码**。

```php
// 字符串上直接调用方法
$s = "hello world";
echo $s->length();          // → strlen($s) → 11
echo $s->upper();           // → strtoupper($s) → "HELLO WORLD"
echo $s->substr(0, 5);      // → substr($s, 0, 5) → "hello"

// 数组上直接调用方法
$arr = [1, 3, 5, 7, 9];
echo $arr->count();         // → count($arr) → 5
echo $arr->contains(3);     // → in_array(3, $arr) → true
$arr->push(11);             // → array_push($arr, 11)

// 整数上直接调用方法
$a = 100;
$a->add(50);                // → a += 50 → 150
echo $a->toString();        // → "150"

// 高精度类型上直接调用方法
$big = std::bigInt("12345678901234567890");
echo $big->mul(2)->toString();  // → "24691357802469135780"
```

> **关键理念**：所有通用方法调用在编译时全部消解为直接函数调用。不存在虚表查找、反射、或运行时类型检查——生成的 C++ 代码与手写 C 函数调用完全等价。

---

## 2. 设计原理

### 2.1 编译期消解

每个通用方法调用在编译时经过以下步骤：

1. **类型推断**：编译器推断调用者（receiver）的类型（Int / Float / String / Array / Stream / BigInt / Decimal / BigFloat / Var）
2. **方法查找**：在该类型的方法表中查找方法定义
3. **参数校验**：检查参数个数是否在 `min_args` ~ `max_args` 范围内
4. **代码生成**：根据 handler 类型生成对应的 C/C++ 调用代码

与 PHP 一致，方法名不区分大小写：`$x->TOARRAY()` 就是关键词方法 `toArray()`，`$s->STARTSWITH('a')` 就是 `startsWith()`，`MethodsFor` 扩展方法（包括 `*` 关键词扩展）也按同样规则匹配。`MethodsFor('*')` 提供者不能声明与内置关键词方法同名（`toInt`、`toArray`、`instanceOf` 等）的 public 方法，否则编译报错。静态书写的方法名会在查找前被规范化；动态方法名（`$x->$name()`）是运行时调用，不会解析为关键词方法。

### 2.2 Handler 类型

通用方法系统支持五种内部 handler，各自适用于不同的场景：

| Handler | 说明 | 示例 |
|---------|------|------|
| `calc_op` | 直接生成 C++ 运算符代码（仅 Int/Float） | `$a->add(1)` → `a + 1` |
| `php_fn` | 调用 PHP 标准库函数 | `$s->length()` → `strlen(s)` |
| `cpp_fn` | 调用 `phpx` C++ 静态方法 | `$big->add(1)` → `BigInt::add($big, 1)` |
| `direct_method` | 调用 Variant 的 C++ 成员方法 | `$s->equals("x")` → `s.equals("x")` |
| `convert_fn` | 类型转换 | `$a->toFloat()` → `php::toFloat(a)` |
| `php_fn_ref` | 调用 PHP 函数并通过引用修改原值（仅 Array） | `$arr->push(1)` → `array_push($arr, 1)` |
| `direct_method_mutate` | 调用 Variant 的变异成员方法 | `$arr->set(0, 1)` → `arr.set(0, 1)` |

### 2.3 参数形式

内置通用方法只接受位置参数，唯一例外是 handler 为 `php_fn` / `php_fn_ref` 的方法：
它们对应真实的 PHP 函数，支持使用该函数形参名的**命名参数**，例如
`$s->replace(replace: '+', search: '-')`、`$s->pad(8, pad_type: STR_PAD_LEFT)`。

- 接收者占用底层函数自己的一个形参，不能被命名。
- 被跳过的可选参数使用函数的默认值补齐。
- 各操作数仍按源码书写顺序求值，与 PHP 一致。
- 参数展开（`...$args`）以及其他 handler 上的命名参数会导致编译错误。

---

## 3. 支持的类型一览

| 类型 | 方法数量 | 主要分类 |
|------|---------|---------|
| **Int** | 26 | 算术、数学函数、类型转换 |
| **Float** | 26 | 算术、数学函数、三角函数、类型转换 |
| **Bool** | 2 | 类型转换 |
| **String** | 70+ | 字符串操作、搜索、编码、Hash、多字节、序列化 |
| **Array** | 50+ | 增删改查、排序、遍历、集合运算、序列化 |
| **Stream** | 30+ | 读写、定位、锁、Socket、过滤 |
| **BigInt** | 14 | 算术、比较、转换、GCD |
| **Decimal** | 11 | 算术、比较、转换 |
| **BigFloat** | 10 | 算术、比较、转换 |

---

## 4. Int 整型方法

### 4.1 算数运算（返回 Int）

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

> **可变性**：`add`/`sub`/`mul`/`div`/`mod`/`inc`/`dec` **会修改原变量**（直接操作 C++ 原生 `int64_t`），同时返回新值。这与 Big* 类型的不可变语义不同。

```php
$a = 100;
$b = $a->add(50);  // $a 变为 150，$b 也是 150（返回新值）
```

> **原生语义**：native `int`/`float` 的算术直接编译为 C++ 运算符，**不遵循 PHP 规则**。例如 `int` 的 `div(0)`/`mod(0)` 会触发 SIGFPE，`int` 溢出按 C++ 处理（有符号溢出为未定义行为），不会提升为 float；`float` 除零得到 `inf`/`nan`，不会抛出 `DivisionByZeroError`。需要 PHP 语义（抛异常、溢出提升）时，请使用 `var int`（或 `var float`）。

### 4.2 数学函数

```php
$a = -100;

$a->abs();          // abs($a)         → 100
$a->ceil();         // ceil($a)        → -100.0 (float)
$a->floor();        // floor($a)       → -100.0 (float)
$a->round();        // round($a)       → -100.0 (float)
$a->sqrt();         // sqrt($a)        → NaN（负数无平方根）
$a->pow(3);         // $a ** 3         → -1000000
$a->log();          // log($a)         → NaN
$a->log10();        // log10($a)       → NaN
$a->exp();          // exp($a)         → 3.72e-44 (float)

$a->max(50);        // max($a, 50)     → 50
$a->min(50);        // min($a, 50)     → -100
```

> **注意**：`ceil`、`floor`、`round` 返回 **Float 类型**（PHP 标准行为）。`pow` 返回 **Var 类型**（因为幂运算结果可能溢出）。

### 4.3 三角函数

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

### 4.4 类型转换

```php
$a = 42;

$a->toFloat();      // (float) $a  → 42.0
$a->toString();     // (string) $a → "42"
$a->toBool();       // (bool) $a   → true
```

### 4.5 完整方法列表

| 方法 | 参数 | 返回类型 | 说明 |
|------|------|---------|------|
| `add($x)` | 1 | Int | 加法（修改原值） |
| `sub($x)` | 1 | Int | 减法（修改原值） |
| `mul($x)` | 1 | Int | 乘法（修改原值） |
| `div($x)` | 1 | Int | 除法（修改原值） |
| `mod($x)` | 1 | Int | 取模（修改原值） |
| `inc()` | 0 | Int | 自增（修改原值） |
| `dec()` | 0 | Int | 自减（修改原值） |
| `abs()` | 0 | Int | 绝对值 |
| `ceil()` | 0 | Float | 向上取整 |
| `floor()` | 0 | Float | 向下取整 |
| `round()` | 0-2 | Float | 四舍五入 |
| `sqrt()` | 0 | Float | 平方根 |
| `pow($x)` | 1 | Var | 幂运算 |
| `log()` | 0-1 | Float | 自然对数 |
| `log10()` | 0 | Float | 以 10 为底的对数 |
| `exp()` | 0 | Float | e 的指数 |
| `sin()` | 0 | Float | 正弦 |
| `cos()` | 0 | Float | 余弦 |
| `tan()` | 0 | Float | 正切 |
| `asin()` | 0 | Float | 反正弦 |
| `acos()` | 0 | Float | 反余弦 |
| `atan()` | 0 | Float | 反正切 |
| `atan2($x)` | 1 | Float | 二参数反正切 |
| `deg2rad()` | 0 | Float | 角度转弧度 |
| `rad2deg()` | 0 | Float | 弧度转角度 |
| `max($x)` | 1 | Int/Float | 取最大值 |
| `min($x)` | 1 | Int/Float | 取最小值 |
| `toFloat()` | 0 | Float | 转浮点 |
| `toString()` | 0 | String | 转字符串 |
| `toBool()` | 0 | Bool | 转布尔 |

---

## 5. Float 浮点型方法

Float 的方法集与 Int 几乎完全相同，但返回类型为 Float（`ceil`/`floor`/`round`/三角函数等仍为 Float）。

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

// Float 特有的转换
$f->toInt();        // (int) $f   → 3
$f->toString();     // (string) $f → "3.14"
$f->toBool();       // (bool) $f  → true
```

Float 与 Int 方法的主要区别：
- Int 有 `mod($x)`，Float 没有（浮点取模无意义）
- 算术方法直接操作 C++ `double`，性能与手写 C 代码一致

---

## 6. Bool 布尔型方法

Bool 类型仅有两个转换方法：

```php
$b = true;

$b->toInt();        // (int) $b   → 1
$b->toString();     // (string) $b → "1"
```

---

## 7. String 字符串方法

String 拥有最丰富的方法集，涵盖日常开发的大部分字符串操作需求。

### 7.1 基本操作

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

### 7.2 搜索与比较

```php
$s = "hello world";

// 判断
$s->startsWith("hello");    // str_starts_with(...)    → true
$s->endsWith("world");      // str_ends_with(...)      → true
$s->contains("lo wo");      // str_contains(...)       → true
$s->compare("hello");       // strcmp(...)             → >0
$s->iCompare("HELLO");      // strcasecmp(...)         → 0
$s->isNumeric();            // is_numeric(...)         → false

// 位置查找
$s->indexOf("world");       // strpos($s, "world")     → 6
$s->lastIndexOf("o");       // strrpos($s, "o")        → 7
$s->iIndexOf("WORLD");      // stripos($s, "WORLD")    → 6
$s->iLastIndexOf("O");      // strripos($s, "O")       → 7

// 内容查找
$s->find("world");          // strstr($s, "world")     → "world"
$s->iFind("WORLD");         // stristr($s, "WORLD")    → "world"
$s->lastCharIndexOf("o");   // strrchr($s, "o")        → "orld"
```

### 7.3 截取与替换

```php
$s = "hello world";

// 截取
$s->substr(0, 5);           // substr($s, 0, 5)        → "hello"
$s->substr(6);              // substr($s, 6)           → "world"

// 统计
$s->substrCount("l");       // substr_count($s, "l")   → 3
$s->wordCount();            // str_word_count($s)      → 2

// 替换
$s->replace("hello", "hi");        // str_replace("hello", "hi", $s)
$s->iReplace("HELLO", "hi");       // str_ireplace(...)
$s->substrReplace("hi", 0, 5);     // substr_replace($s, "hi", 0, 5)
$s->stripTags();                   // strip_tags($s)
$s->stripTags("<br><p>");          // strip_tags($s, tags)
```

### 7.4 拆分与连接

```php
$s = "hello world";

// 拆分
$words = $s->split(" ");            // explode(" ", $s) → ["hello", "world"]
$words->count();                    // 2

// 连接（在数组上操作）
$words->join(", ");                 // implode(", ", $words) → "hello, world"

// 重复
$s->repeat(3);                      // str_repeat($s, 3) → "hello worldhello worldhello world"

// 填充
$s->pad(20, "-");                   // str_pad($s, 20, "-")
```

### 7.5 编码与转义

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

### 7.6 Hash 与校验

```php
$s = "hello";

$s->md5();          // md5($s)       → "5d41402abc4b2a76b9719d911017c592"
$s->sha1();         // sha1($s)      → "aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d"
$s->crc32();        // crc32($s)     → 907060870
$s->hash("sha256"); // hash("sha256", $s) → "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
$s->hashCode();     // C++ std::hash → 哈希值 (int)
```

### 7.7 正则匹配

```php
$s = "hello world";

// match(pattern) → 返回匹配数组
$result = $s->match("/hello/");

// matchAll(pattern) → 返回所有匹配结果
$results = $s->matchAll("/[a-z]+/");
```

### 7.8 序列化

```php
$s = '{"name":"John","age":30}';

$data = $s->jsonDecode();           // json_decode($s, true)  → ["name" => "John", ...]
$obj = $s->jsonDecodeToObject();    // json_decode($s)        → stdClass

$s = 'a:3:{i:0;s:3:"foo";i:1;s:3:"bar";i:2;s:3:"baz";}';
$arr = $s->unserialize();           // unserialize($s)        → ["foo", "bar", "baz"]
```

### 7.9 多字节字符串（mbstring）

以 `mb` 前缀开头的方法对应 PHP 的 `mb_*` 函数族：

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

### 7.10 C++ 原生方法

以下方法直接调用 `phpx::Variant` 的 C++ 成员函数，无 PHP 函数对应：

```php
$s = "hello";

$s->equals("hello");        // C++ String.equals() —— 值比较
$s->append(" world");       // C++ String.append() —— 追加（修改原值）
```

---

## 8. Array 数组方法

Array 方法分为**只读方法**和**变异方法**两类。变异方法会直接修改原数组变量。

### 8.1 基本信息

```php
$arr = [1, 3, 5, 7, 9];

$arr->count();          // count($arr)         → 5
$arr->isEmpty();        // empty($arr)         → false
$arr->isList();         // array_is_list($arr) → true
```

### 8.2 增删改查

```php
$arr = [1, 2, 3];

// 变异方法（修改原数组）
$arr->push(4);              // array_push($arr, 4)          → [1,2,3,4]
$arr->push(5, 6, 7);        // 支持多个参数
$arr->pop();                // array_pop($arr)              → 返回 7
$arr->shift();              // array_shift($arr)            → 返回 1
$arr->unshift(0);           // array_unshift($arr, 0)       → [0,...]
$arr->set(0, 100);          // C++ Array.set(0, 100)        → [100,...]
$arr->del(0);               // C++ Array.del(0)             → 删除索引 0
$arr->clean();              // C++ Array.clean()            → []

// 只读方法
$arr = ['a' => 1, 'b' => 2, 'c' => 3];
$arr->get('a');             // C++ Array.get('a')           → 1
$arr->keyExists('a');       // array_key_exists('a', $arr)  → true
$arr->contains(2);          // in_array(2, $arr)            → true
$arr->search(2);            // array_search(2, $arr)        → "b"
```

### 8.3 遍历与聚合

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

### 8.4 排序

```php
$arr = [3, 1, 4, 1, 5, 9];

$arr->sort();               // sort($arr)            → [1,1,3,4,5,9]
$arr->sortDesc();           // rsort($arr)           → [9,5,4,3,1,1]
$arr->keySort();            // ksort($arr)           → 按键排序
$arr->valueSort();          // asort($arr)           → 按值排序（保持键）
```

所有排序方法都会**修改原数组**。

### 8.5 集合运算

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
$a->values();               // array_values($a)      → 重索引
$a->combine($keys);         // array_combine($keys, $a)
$a->fillKeys($value);       // array_fill_keys($a, $value)
```

> `diff`、`intersect`、`merge`、`replace` 等方法支持**可变参数**：`$a->merge($b, $c, $d)` 可以一次合并多个数组。

### 8.6 提取与切片

```php
$arr = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];

$arr->keys();               // array_keys($arr)         → ['a','b','c','d','e']
$arr->slice(1, 3);          // array_slice($arr, 1, 3) → ['b'=>2, 'c'=>3, 'd'=>4]
$arr->chunk(2);             // array_chunk($arr, 2)    → [[1,2],[3,4],[5]]
$arr->column('name');       // array_column($arr, 'name')
$arr->splice(1, 3, [6,7]);  // array_splice($arr, 1, 3, [6,7]) —— 修改原数组
$arr->rand(2);              // array_rand($arr, 2)

$arr->keyFirst();           // array_key_first($arr) → "a"
$arr->keyLast();            // array_key_last($arr)  → "e"
$arr->find(fn($v) => $v > 3);  // array_find($arr, fn)
```

### 8.7 字符串相关

```php
$arr = ["hello", "world"];

$arr->join(", ");           // implode(", ", $arr) → "hello, world"
$arr->replaceStr("hello", "hi");    // str_replace("hello", "hi", $arr)
$arr->iReplaceStr("HELLO", "hi");   // str_ireplace(...)
```

### 8.8 序列化

```php
$arr = ["name" => "John", "age" => 30];

$arr->serialize();          // serialize($arr)      → "a:2:{...}"
$arr->marshal();            // serialize($arr) —— 别名
$arr->jsonEncode();         // json_encode($arr)    → '{"name":"John","age":30}'
```

### 8.9 类型转换

```php
$arr = [1, 2, 3];

$arr->toInt();              // (int) 非空数组 → 1
$arr->toFloat();            // (float) 非空数组 → 1.0
$arr->toBool();             // (bool) 非空数组 → true
$arr->toString();           // → "Array"
```

### 8.10 完整方法分类表

| 分类 | 方法 |
|------|------|
| 基本信息 | `count`, `isEmpty`, `isList` |
| 增删改查 | `push`, `pop`, `shift`, `unshift`, `set`, `get`, `del`, `clean`, `keyExists`, `contains`, `search` |
| 遍历聚合 | `sum`, `product`, `all`, `any`, `map`, `reduce`, `filter`, `walk` |
| 排序 | `sort`, `sortDesc`, `keySort`, `valueSort` |
| 集合运算 | `diff`, `diffAssoc`, `diffKey`, `intersect`, `intersectAssoc`, `merge`, `unique`, `flip`, `reverse`, `replace`, `values`, `combine`, `fillKeys` |
| 提取切片 | `keys`, `slice`, `chunk`, `column`, `splice`, `rand`, `keyFirst`, `keyLast`, `find` |
| 字符串 | `join`, `replaceStr`, `iReplaceStr`, `countValues`, `pad` |
| 序列化 | `serialize`, `marshal`, `jsonEncode` |
| 类型转换 | `toInt`, `toFloat`, `toBool`, `toString` |

---

## 9. Stream 流方法

Stream 类型表示文件句柄或网络连接。通过 `fopen()` 等函数获取。

### 9.1 读写

```php
$fp = fopen("test.txt", "w+");

$fp->write("hello world\n");      // fwrite($fp, "hello world\n") → 写入字节数
$fp->write("more data", 4);       // fwrite($fp, "more data", 4)  → 只写 4 字节

$fp->seek(0);                     // fseek($fp, 0)   → 回到开头
$content = $fp->read(1024);       // fread($fp, 1024)
$content = $fp->getContents();    // stream_get_contents($fp)

$char = $fp->getChar();           // fgetc($fp)      → 读取一个字符
$line = $fp->getLine();           // fgets($fp)      → 读取一行
$line = $fp->getLine(1024);       // fgets($fp, 1024)
$line = $fp->getRecord(1024, "\n"); // stream_get_line($fp, 1024, "\n")
```

### 9.2 元数据与状态

```php
$fp->tell();                // ftell($fp)           → 当前位置
$fp->eof();                 // feof($fp)            → 是否 EOF
$fp->stat();                // fstat($fp)           → 文件状态数组
$fp->getMetaData();         // stream_get_meta_data($fp)
$fp->isLocal();             // stream_is_local($fp)
$fp->isTTY();               // stream_isatty($fp)
```

### 9.3 控制操作

```php
$fp->truncate(0);           // ftruncate($fp, 0)    → 截断文件
$fp->sync();                // fsync($fp)           → 同步到磁盘
$fp->dataSync();            // fdatasync($fp)       → 同步数据
$fp->close();               // fclose($fp)          → 关闭

// 锁
$fp->lock(LOCK_EX);         // flock($fp, LOCK_EX)
$fp->lock(LOCK_SH);         // flock($fp, LOCK_SH)
$fp->lock(LOCK_UN);         // flock($fp, LOCK_UN)

// 缓冲设置
$fp->setBlocking(true);     // stream_set_blocking($fp, true)
$fp->setChunkSize(8192);    // stream_set_chunk_size($fp, 8192)
$fp->setReadBuffer(8192);   // stream_set_read_buffer($fp, 8192)
$fp->setWriteBuffer(8192);  // stream_set_write_buffer($fp, 8192)
$fp->setTimeout(30);        // stream_set_timeout($fp, 30)
$fp->supportsLock();        // stream_supports_lock($fp)
```

### 9.4 Socket 操作

```php
// 服务端
$server = stream_socket_server("tcp://0.0.0.0:8080");
$client = $server->accept();             // stream_socket_accept($server)
$client->accept(30);                     // 超时 30 秒

// 信息
$client->getSocketName(true);            // stream_socket_get_name —— 远程地址
$server->getSocketName(false);           // stream_socket_get_name —— 本地地址

// 数据
$client->sendTo("hello", 0, $addr);      // stream_socket_sendto(...)
$client->recvFrom(1024);                 // stream_socket_recvfrom(...)
$client->recvFrom(1024, 0, $addr);       // 带地址

// 控制
$client->enableCrypto(true);             // stream_socket_enable_crypto → 启用 TLS
$client->shutdown(STREAM_SHUT_RDWR);     // stream_socket_shutdown(...)

// 过滤器
$fp->appendFilter("string.toupper");     // stream_filter_append(...)
$fp->prependFilter("string.tolower");    // stream_filter_prepend(...)
```

### 9.5 流拷贝

```php
$src = fopen("source.txt", "r");
$dst = fopen("dest.txt", "w");

$src->copy($dst);                    // stream_copy_to_stream($src, $dst)
$src->copy($dst, 4096);              // 指定缓冲区大小
```

---

## 10. Big* 高精度类型方法

### 10.1 BigInt 方法

```php
$a = std::bigInt("12345678901234567890");

// 算术（均返回新 BigInt，原值不变）
$b = $a->add(1);        // $a + 1
$c = $a->sub(1);        // $a - 1
$d = $a->mul(2);        // $a * 2
$e = $a->div(10);       // $a / 10
$f = $a->mod(1000000);  // $a % 1000000
$g = $a->pow(3);        // $a ** 3

// 一元方法
$h = $a->neg();         // -$a
$i = $a->abs();         // abs($a)

// 特殊方法
$j = $a->gcd(15);       // gcd($a, 15)

// 比较
$cmp = $a->cmp(100);    // -1/0/1

// 类型转换
$a->toString();         // → "12345678901234567890"
$a->toInt();            // → int（可能截断）
$a->toFloat();          // → float（可能丢精度）
```

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `add($x)` | 1 | BigInt | 加法 |
| `sub($x)` | 1 | BigInt | 减法 |
| `mul($x)` | 1 | BigInt | 乘法 |
| `div($x)` | 1 | BigInt | 整数除法 |
| `mod($x)` | 1 | BigInt | 取模 |
| `pow($x)` | 1 | BigInt | 幂运算 |
| `neg()` | 0 | BigInt | 取负 |
| `abs()` | 0 | BigInt | 绝对值 |
| `gcd($x)` | 1 | BigInt | 最大公约数 |
| `cmp($x)` | 1 | Int | 比较 |
| `toString()` | 0 | String | 转字符串 |
| `toInt()` | 0 | Int | 转整数 |
| `toFloat()` | 0 | Float | 转浮点 |

### 10.2 Decimal 方法

```php
$d = std::decimal("123.456");

$d->add(std::decimal("50.25"));  // 加法
$d->sub(std::decimal("50.25"));  // 减法
$d->mul(2);                      // 乘法
$d->div(3);                      // 除法
$d->mod(std::decimal("5.0"));    // 取模
$d->neg();                       // 取负
$d->abs();                       // 绝对值
$d->cmp(std::decimal("100"));    // 比较
$d->toString();                  // → "123.456"
$d->toInt();                     // → 123
$d->toFloat();                   // → 123.456 (double)
```

### 10.3 BigFloat 方法

```php
$bf = std::bigFloat(3.14159265);

$bf->add(1.0);          // 加法
$bf->sub(1.0);          // 减法
$bf->mul(2.0);          // 乘法
$bf->div(2.0);          // 除法
$bf->neg();             // 取负
$bf->abs();             // 绝对值
$bf->cmp(3.0);          // 比较 → >0
$bf->toString();        // 转字符串
$bf->toInt();           // → 3
$bf->toFloat();         // → 3.14159265
```

> **不可变性**：与 Int/Float 不同，Big* 类型的所有方法都**返回新值**，不修改原变量。参见 [第 12 节](#12-可变方法-vs-不可变方法)。

---

## 11. 方法链式调用

通用方法的返回值类型是编译时已知的，因此可以直接链式调用：

```php
// 字符串链式调用
$result = "  Hello World!  "
    ->trim()
    ->lower()
    ->substr(0, 5)
    ->upper();
echo $result;  // "HELLO"

// Int 链式调用
$result = 100
    ->add(50)     // 150（修改原变量）
    ->mul(3)      // 450
    ->sub(100)    // 350
    ->toString();
echo $result;  // "350"

// BigInt 链式调用（不可变，返回新值）
$result = std::bigInt("100")
    ->add(std::bigInt(50))
    ->mul(std::bigInt(3))
    ->toString();
echo $result;  // "450"

// 跨类型链式调用
$sum = "123456789012345678901234567890"
    ->length();     // String.length() → Int
echo $sum;  // 30

// 链式调用 + 最终转换
$result = std::bigInt("99999999999999999999")
    ->add(std::bigInt(1))
    ->toString();
echo "100000000000000000000 = " . $result;
```

> **注意**：Int/Float 的链式调用每步**都会修改原变量**。如果需要保留中间值，先用临时变量保存。Big* 类型不存在此问题，因为它们是不可变的。

---

## 12. 可变方法 vs 不可变方法

不同类型的通用方法在可变性上有不同行为：

### 12.1 可变方法（修改原值）

**Int 和 Float** 的算术方法直接操作 C++ 原生变量，会**修改原值**：

```php
$a = 100;
$b = $a->add(50);   // 这一步既修改了 $a（变为 150），又返回新值给 $b
echo $a;   // 150 —— 已被修改
echo $b;   // 150

$a->inc();          // $a 变为 151
$a->dec();          // $a 变为 150
```

**Array** 的变异方法会**修改原数组**：

```php
$arr = [1, 2, 3];

$arr->push(4);      // 修改 $arr → [1,2,3,4]
$arr->pop();        // 修改 $arr → [1,2,3]
$arr->sort();       // 修改 $arr → [1,2,3]
$arr->set(0, 100);  // 修改 $arr → [100,2,3]
$arr->clean();      // 修改 $arr → []
```

### 12.2 不可变方法（返回新值）

**BigInt、Decimal、BigFloat** 的所有方法都**不修改原值**，返回新创建的对象：

```php
$a = std::bigInt(100);
$b = $a->add(50);   // $a 依然是 100，$b 是 150

$a = std::bigInt(100);
$a->add(50);        // 返回值被丢弃！$a 依然是 100
```

**String** 的大部分方法也返回新值：

```php
$s = "hello";
$upper = $s->upper();  // $s 依然是 "hello"，$upper 是 "HELLO"
```

**例外**：`String.append()` 是变异方法（`direct_method_mutate`），会修改原字符串：

```php
$s = "hello";
$s->append(" world");  // $s 变为 "hello world"
```

### 12.3 可变方法汇总

| Handler 类型 | 影响类型 | 示例 |
|-------------|---------|------|
| `calc_op` | Int, Float | `add`, `sub`, `mul`, `div`, `mod`, `inc`, `dec` |
| `direct_method_mutate` | String, Array | `append`, `set`, `del`, `clean` |
| `php_fn_ref` | Array | `push`, `pop`, `shift`, `unshift`, `sort`, `sortDesc`, `splice`, `walk` |

---

## 13. Var 类型的方法查找

当变量的类型为 `Var`（通用 PHP 类型）时，编译器会按以下顺序依次在方法表中查找：

```
String → Array → Int → Float → Bool → Stream → BigInt → Decimal → BigFloat
```

一旦找到匹配的方法名，就生成对应类型的调用代码。如果找不到，则退化为动态方法调用（通过 ZendVM）。

```php
// $x 的类型是 Var（来自函数返回值、数组提取等）
$x = some_func_returning_var();

// 编译器按顺序查找：
// 1. 先在 String 方法表中找 length → 找到！→ strlen($x)
echo $x->length();

// 2. 先在 String 方法表中找 contains → 找到！→ str_contains($x, ...)
echo $x->contains("test");
```

> **注意**：如果多个类型有同名方法，先匹配到的类型优先。例如 `toInt` 在多个类型中都存在——查找会停在第一个匹配的类型上。

---

## 14. 扩展方法：自动发现

除了内置的通用方法外，编译器还支持**自定义扩展方法**。只要在当前项目中定义了命名符合约定的 PHP 函数，编译器就会自动将其发现为通用方法。

### 14.1 命名约定

格式：`{类型前缀}_{snake_case方法名}`

| 类型 | 前缀 | 示例 |
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

### 14.2 实现示例

```php
<?php

/**
 * 扩展方法：判断 Int 是否为素数
 * 命名：类型前缀 int_ + snake_case 方法名 is_prime
 */
function int_is_prime(int $n): bool {
    if ($n < 2) return false;
    for ($i = 2; $i * $i <= $n; $i++) {
        if ($n % $i == 0) return false;
    }
    return true;
}

/**
 * 扩展方法：数组扁平化
 * 命名：类型前缀 array_ + snake_case 方法名 flatten
 */
function array_flatten(array $arr): array {
    $result = [];
    array_walk_recursive($arr, function($v) use (&$result) {
        $result[] = $v;
    });
    return $result;
}

function main(): void {
    // 自动发现：int_is_prime → $n->isPrime()
    $n = 97;
    if ($n->isPrime()) {
        echo "$n is prime\n";
    }

    // 自动发现：array_flatten → $arr->flatten()
    $nested = [[1, 2], [3, [4, 5]], 6];
    $flat = $nested->flatten();
    var_dump($flat);  // [1, 2, 3, 4, 5, 6]
}
?>
```

### 14.3 注意事项

- 函数**第一个参数**是接收者（receiver），从方法调用中自动传入
- 扩展方法的返回类型固定为 `Var`
- 需要先定义函数再调用——编译器在分析阶段发现函数，转换阶段使用它们
- 方法的驼峰命名会**自动转为下划线命名**：`isPrime` → `is_prime`，`flattenNestedArray` → `flatten_nested_array`

---

## 15. 完整示例

### 15.1 字符串处理管道

```php
<?php

function main(): void {
    $raw = "  <h1>Hello World!</h1>  \n";

    $processed = $raw
        ->trim()                        // 去头尾空白
        ->stripTags()                   // 去 HTML 标签
        ->lower()                       // 转小写
        ->upperWords();                 // 首字母大写

    echo "原始: " . $raw->jsonEncode() . "\n";
    echo "处理: " . $processed . "\n";
    echo "长度: " . $processed->length() . "\n";
    echo "是否为数字: " . (int)$processed->isNumeric() . "\n";
}
?>
```

### 15.2 数组数据处理

```php
<?php

function main(): void {
    $data = [5, 2, 8, 1, 9, 3, 7];

    echo "原始: " . $data->join(", ") . "\n";

    // 排序
    $data->sort();
    echo "排序: " . $data->join(", ") . "\n";

    // 统计
    echo "数量: " . $data->count() . "\n";
    echo "求和: " . $data->sum() . "\n";
    echo "最小值: " . $data->get(0) . "\n";
    echo "最大值: " . $data->get($data->count() - 1) . "\n";
    echo "是否包含 5: " . (int)$data->contains(5) . "\n";

    // 过滤与映射
    $even = $data->filter(function($v) { return $v % 2 == 0; });
    echo "偶数: " . $even->values()->join(", ") . "\n";

    $doubled = $data->map(function($v) { return $v * 2; });
    echo "翻倍: " . $doubled->join(", ") . "\n";

    // 序列化
    echo "JSON: " . $data->jsonEncode() . "\n";
}
?>
```

### 15.3 高精度计算与链式调用

```php
<?php

function main(): void {
    // 大整数阶乘（使用复合赋值，更简洁）
    $n = 50;
    $result = std::bigInt(1);
    for ($i = 2; $i <= $n; $i++) {
        $result *= $i;
    }
    $digits = strlen($result->toString());
    echo "{$n}! 有 {$digits} 位数字\n";

    // 方法链：BigInt 算术 + 转换
    $big = std::bigInt(1000);
    $val = $big->mul(3)->add(200)->sub(50)->div(10)->toString();
    echo "1000 * 3 + 200 - 50 / 10 = {$val}\n";

    // Decimal 金融计算
    $price = std::decimal("19.99");
    $qty = 5;
    $taxRate = std::decimal("0.08");
    $total = $price * $qty * ($taxRate->add(std::decimal(1)));
    echo "总价: " . $total->toString() . "\n";

    // BigFloat 科学计算
    $pi = std::bigFloat("3.14159265358979323846");
    $area = $pi * 100;
    echo "圆面积: " . $area->toString() . "\n";
    echo "取整: " . $area->toInt() . "\n";
}
?>
```

### 15.4 文件处理

```php
<?php

function main(): void {
    // 写入文件
    $fp = fopen("data.txt", "w+");
    $fp->write("Line 1\n");
    $fp->write("Line 2\n");
    $fp->write("Line 3\n");

    // 回到开头并读取
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

## 进一步阅读

- **高精度类型教程**：[`docs/HIGH_PRECISION_TYPES.md`](HIGH_PRECISION_TYPES.md)
- **类型系统规范**：[`docs/NATIVE_TYPES.md`](NATIVE_TYPES.md)
- **通用方法实现**：[`src/Parser/UniversalMethodCall.php`](../src/Parser/UniversalMethodCall.php)
- **集成测试**：
  - [`tests/compiler/string_method/`](../tests/compiler/string_method/) — String 通用方法测试
  - [`tests/compiler/array_method/`](../tests/compiler/array_method/) — Array 通用方法测试
  - [`tests/compiler/stream_method/`](../tests/compiler/stream_method/) — Stream 通用方法测试
  - [`tests/compiler/bigint/`](../tests/compiler/bigint/) — BigInt 通用方法测试
  - [`tests/compiler/decimal/`](../tests/compiler/decimal/) — Decimal 通用方法测试
