# AOT 编译器类型系统说明

## ⚠️ 重要提示

**AOT 编译器支持 6 种原生/高精度类型**:

从 TypePHP 0.8 起，推断出的 `int`、`float`、`bool` 局部变量默认分别使用
`php::Int`、`php::Float`、`php::Bool` 原生存储。原有 `use native_types` 已移除，
编译器也绝不会在后续流程中把原生局部变量静默提升为 `php::Var`。

只有文件确实需要 Zend PHP 的整数扩展语义时才使用 `use varint_types`。存储映射
只装箱推断出的普通整数，float 和 bool 仍保持原生存储；该文件模式还影响算术
分派（包括浮点除法）、常量诊断、类型检查和优化，完整范围见
[varint_types 当前行为与实现边界](VARINT_TYPES.md)。只有单个值需要动态或引用
语义时，使用 `std::any($value)`。

### 基础原生类型
1. ✅ `std::int` - 原生整数类型 (zend_long, 8 字节)
2. ✅ `std::float` - 原生浮点类型 (double, 8 字节)
3. ✅ `std::bool` - 原生布尔类型 (bool, 1 字节)

### 高精度数值类型
4. ✅ `std::bigInt` - 任意精度整数 (基于 GMP `mpz_class`)
5. ✅ `std::decimal` - 50 位十进制数 (基于 libmpdec)
6. ✅ `std::bigFloat` - 256 bit 高精度浮点数 (基于 MPFR，输出 64 位有效数字)

---

## 对象属性类型是固定的

AOT 编译器要求对象属性在整个生命周期内始终保持声明时的类型。与 PHP 解释器不同，AOT 不允许通过运行时操作把一个已声明类型的属性改成其他类型。

尤其需要注意固定值类型属性上的 `unset($obj->prop)` 和赋值 `null`：

```php
<?php

class User {
    public int $id = 0;
    public Profile $profile;
}

$user = new User();
unset($user->id); // ❌ AOT 不允许依赖这种语义
$user->id = null; // ❌ 这同样会把 int 属性改成 null

unset($user->profile); // ✅ 对象属性可进入 null/unset 状态
$user->profile = null; // ✅ 对象属性可显式设置为 null
```

在 PHP 中，`unset($obj->prop)` 可以让对象属性脱离当前值状态，后续表现为未初始化或空值状态；对固定值类型属性赋值 `null` 也会把值状态改成空值。从 AOT 的类型系统角度看，这等价于把属性从声明的 `int`、`float`、`bool`、`string`、`array` 改变为 `null`/未初始化状态。AOT 编译器不允许这些固定值类型属性改变类型，因此属性永远是声明时的类型。

具体类对象属性使用静态对象类型规则：非空赋值必须满足 `is-a` 关系，因此允许把子类对象赋给基类属性，不允许把无继承关系的对象或基类对象赋给子类属性。非 nullable 属性可以 `unset()`，但不能赋值为 `null`。

正确做法：

- 不要对 `int`、`float`、`bool`、`string`、`array` 固定值类型对象属性使用 `unset()` 或赋值 `null`。
- 如果属性业务上可能为空，应显式声明为可空类型，例如 `public ?int $id = null;`，并用赋值表达状态变化。
- 如果对象属性声明为具体类名，非空赋值必须使用声明类本身，不要依赖 PHP 的子类兼容赋值语义。
- 如果属性需要保存任意 PHP 值，应声明为可变类型/通用类型，而不是声明为原生类型后再尝试 `unset()` 或写入其他类型。

---

## 🎯 `toObject()` 关键词方法

### 使用场景

当从数组、函数返回值等来源获取对象时，变量会丢失类型上下文信息。此时使用 `toObject()` 关键词方法断言对象类型。

### 基本语法

```php
<?php
// 接收者是待检查值，参数是编译期可确定的类名
$obj = $array['object']->toObject(ClassName::class);
```

### 典型场景

#### 场景一：从数组提取对象

```php
<?php
$data = [
    'user' => new User(),
    'product' => new Product(),
];

// ❌ 错误：类型丢失
$user = $data['user'];  // AOT 无法推断类型

// ✅ 正确：使用 toObject() 声明类型
$user = $data['user']->toObject(User::class);
$product = $data['product']->toObject(Product::class);
```

#### 场景二：函数返回对象

```php
<?php
function get_object() {
    return new stdClass();
}

// ❌ 类型丢失
$obj = get_object();

// ✅ 使用 toObject() 声明
$obj = get_object()->toObject(stdClass::class);
```

#### 场景三：工厂模式

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

// ✅ 明确指定返回的对象类型
$user = $factory->create('user')->toObject(User::class);
$product = $factory->create('product')->toObject(Product::class);
```

### 注意事项

⚠️ **必须使用字面量字符串**:

```php
<?php
// ✅ 正确：字面量类名
$obj = $value->toObject(MyClass::class);

// ❌ 错误：变量类名（编译期无法分析）
$className = 'MyClass';
$obj = $value->toObject($className);  // 编译错误

// ❌ 错误：常量类名（编译期可能无法解析）
const CLASS_NAME = 'MyClass';
$obj = $value->toObject(CLASS_NAME);  // 可能失败
```

接收者可以是受支持的任意值表达式：

```php
<?php
// ✅ 正确：variable 表达式
$obj = $array['key']->toObject(MyClass::class);
$obj = $object->property->toObject(MyClass::class);
$obj = get_object()->toObject(MyClass::class);

// ✅ 合法，但表达式已经具备精确类型，因此没有必要
$obj = (new MyClass())->toObject(MyClass::class);  // 不需要
```

### 性能影响

- ✅ `toObject()` 是 TypePHP 关键词方法
- ✅ 为编译器提供目标类信息
- ✅ 当运行时类型不能被静态证明时，生成 PHPX 对象转换/类型检查

### 与 std:: 类型的区别

| 特性 | std::int/float/bool | std::object/toObject |
|------|---------------------|--------|
| **用途** | 数值/布尔类型优化 | 对象类型声明 |
| **性能** | ⚡ 高性能（原生类型） | 🐢 标准（ZVAL） |
| **内存** | 8B/1B | 指针（16B+） |
| **时机** | 运行时优化 | 编译期降级，必要时运行时检查 |
| **语法** | `std::int(值)` | `std::object($value, ClassName::class)` 或 `$value->toObject(ClassName::class)` |

---

## ❌ 不支持的类型

以下类型**不使用**原生类型，仍然使用 ZVAL:

- ❌ `std::string` - 字符串使用 ZVAL (php::Str)
- ❌ `std::array` - 数组使用 ZVAL (php::Array)
- ❌ 对象仍使用 ZVAL（`php::Object`）；`std::object()` 只恢复类信息，不引入原生对象存储
- ❌ 其他所有类型 - 使用 ZVAL (php::Var)

## 类型映射表

| PHP 类型声明 | C++ 类型 | 底层实现 | 内存 | 性能 | 状态 |
|------------|---------|---------|------|------|------|
| `int` | `php::Int` | `zend_long` | 8B | ⚡ 高性能 | ✅ 原生 |
| `float` | `php::Float` | `double` | 8B | ⚡ 高性能 | ✅ 原生 |
| `bool` | `php::Bool` | `bool` | 1B | ⚡ 高性能 | ✅ 原生 |
| `bigInt` | `php::Var` (Box\<BigInt\>) | `mpz_class` (GMP) | ~32B+ | 🐢 标准 | ✅ 装箱 |
| `decimal` | `php::Var` (Box\<Decimal\>) | `decimal::Decimal` (libmpdec) | ~64B+ | 🐢 标准 | ✅ 装箱 |
| `bigFloat` | `php::Var` (Box\<BigFloat\>) | `mpfr_t` (MPFR) | ~32B+ | 🐢 标准 | ✅ 装箱 |
| `string` | `php::Str` | `zend_string*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `array` | `php::Array` | `zval*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `object` | `php::Object` | `zend_object*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `mixed`/无声明 | `php::Var` | `zval` | 16B | 🐢 标准 | ❌ ZVAL |

## 声明方式对比

| 类型 | C++ 实现 | 声明方式 | 内存 | 性能 | 状态 |
|------|---------|---------|------|------|------|
| **int** | `php::Int` | `std::int(值)`<br>`function foo(int $x)` | 8B | ⚡ 高性能 | ✅ 原生 |
| **float** | `php::Float` | `std::float(值)`<br>`function foo(float $x)` | 8B | ⚡ 高性能 | ✅ 原生 |
| **bool** | `php::Bool` | `std::bool(值)`<br>`function foo(bool $x)` | 1B | ⚡ 高性能 | ✅ 原生 |
| **bigInt** | `php::Var` (Box\<BigInt\>) | `std::bigInt(值)` | ~32B+ | 🐢 标准 | ✅ 装箱 |
| **decimal** | `php::Var` (Box\<Decimal\>) | `std::decimal(值)` | ~64B+ | 🐢 标准 | ✅ 装箱 |
| **bigFloat** | `php::Var` (Box\<BigFloat\>) | `std::bigFloat(值)` | ~32B+ | 🐢 标准 | ✅ 装箱 |
| **string** | `php::Str` | 无<br>`function foo(string $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **array** | `php::Array` | 无<br>`function foo(array $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **object** | `php::Object` | 无<br>`function foo(object $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **mixed** | `php::Var` | 无<br>`function foo($x)` | 16B | 🐢 标准 | ❌ ZVAL |

## 性能差异

### 原生类型（高性能）
```php
function calculate(int $a, int $b): int {
    return $a + $b;  // 使用原生类型，性能提升 100-300 倍
}
```

### ZVAL 类型（标准性能）
```php
function process(string $name, array $data) {
    // 使用 ZVAL，标准 PHP 性能
    echo $name;
    print_r($data);
}
```

## 使用建议

### ✅ 推荐使用原生类型的场景
- 数值密集计算
- 循环计数器
- 递归算法
- 性能关键路径

### ⚠️ 使用 ZVAL 的场景
- 字符串处理
- 数组操作
- 对象操作
- 通用业务逻辑

---

## 高精度数值类型：BigInt / Decimal / BigFloat

AOT 编译器支持三种高精度数值类型，用于处理超出 int64/double 精度的计算。

### 底层 C++ 库

| 类型 | C++ 库 | 关键头文件 |
|------|--------|----------|
| **BigInt** | GMP (`libgmp-dev`) | `<gmpxx.h>`, `phpx_big_int.h` |
| **Decimal** | libmpdec (`libmpdec-dev`) | `<decimal.hh>`, `phpx_decimal.h` |
| **BigFloat** | MPFR (`libmpfr-dev`) | `<mpfr.h>`, `phpx_big_float.h` |

BigInt、Decimal、BigFloat 均继承自 `php::Box`，存储于 `php::Variant` 内部。它们属于"装箱类型"（Boxed Type），不像 Int/Float 那样直接映射为 C++ 标量，因此在声明和运算上有所不同。

### 声明与构造

```php
// 从整数字面量构造 BigInt
$a = std::bigInt(100);
$b = std::bigInt("123456789012345678901234567890");  // 超长整数字符串

// 从字符串构造 Decimal（避免浮点精度丢失）
$c = std::decimal("123.456");
$d = std::decimal(42);  // 也可从 int 构造

// 从 int / float / 字符串构造 BigFloat
$e = std::bigFloat(100.5);
$f = std::bigFloat(42);
$g = std::bigFloat("3.14159265358979323846");
```

> **重要**：`std::bigInt()` / `std::decimal()` / `std::bigFloat()` 是**编译期函数**，在生成的 C++ 代码中直接构造对应类型，无运行时函数调用开销。

### 算术运算符

BigInt 支持 `+`、`-`、`*`、`/`、`%` 和 `**`；Decimal 支持除 `**` 外的前五项；BigFloat 支持 `+`、`-`、`*`、`/`。编译器将它们映射为静态方法调用。

```php
$a = std::bigInt(100);
$b = std::bigInt(200);

$sum = $a + $b;     // → php::BigInt::add($a, $b)
$diff = $a - $b;    // → php::BigInt::sub($a, $b)
$prod = $a * $b;    // → php::BigInt::mul($a, $b)
$quot = $a / $b;    // → php::BigInt::div($a, $b)
$mod = $a % $b;     // → php::BigInt::mod($a, $b)
$pow = $a ** 3;     // → php::BigInt::pow($a, 3)

// 一元取负
$neg = -$a;         // → php::BigInt::neg($a)
```

**类型提升**：Big* 可以和安全的普通标量混合运算；不同 Big* 类型之间不得隐式混合，必须先显式转换。详见下文“二元运算类型提升规则”。

**BigInt 除法**：`BigInt / BigInt` 在 `parseBinaryOp` 中返回 BigInt（整数除法，同 PHP int 语义）。若需要高精度除法，应先将操作数转为 Decimal 或使用 `BigInt::div` 的 Decimal 结果。

### 比较运算符

所有标准比较运算符均可使用：`<`、`>`、`<=`、`>=`、`==`、`!=`、`<=>`（太空船）。

```php
$a = std::bigInt(100);
$b = 200;

echo (int)($a < $b);    // → php::BigInt::cmp($a, $b) < 0
echo (int)($a > $b);    // → php::BigInt::cmp($a, $b) > 0
echo (int)($a == 100);  // → php::BigInt::cmp($a, 100) == 0
echo (int)($a <=> $b); // → php::BigInt::cmp($a, $b)
```

C++ 实现：比较结果通过 `php::BigInt::cmp()` / `php::Decimal::cmp()` / `php::BigFloat::cmp()` 获得，返回负/零/正 int 表示小于/等于/大于。

### 通用方法 (Universal Methods)

BigInt、Decimal、BigFloat 支持通过 `$value->method()` 语法调用一系列零成本抽象方法。

#### BigInt 方法

| 方法 | 参数 | 返回类型 | C++ 实现 | 说明 |
|------|------|---------|---------|------|
| `add($x)` | 1 | BigInt | `BigInt::add()` | 加法 |
| `sub($x)` | 1 | BigInt | `BigInt::sub()` | 减法 |
| `mul($x)` | 1 | BigInt | `BigInt::mul()` | 乘法 |
| `div($x)` | 1 | BigInt | `BigInt::div()` | 整数除法 |
| `mod($x)` | 1 | BigInt | `BigInt::mod()` | 取模 |
| `pow($x)` | 1 | BigInt | `BigInt::pow()` | 幂运算 |
| `neg()` | 0 | BigInt | `BigInt::neg()` | 取负 |
| `abs()` | 0 | BigInt | `BigInt::abs()` | 绝对值 |
| `gcd($x)` | 1 | BigInt | `BigInt::gcd()` | 最大公约数 |
| `cmp($x)` | 1 | Int | `BigInt::cmp()` | 比较 |
| `toString()` | 0 | Str | `BigInt::toString()` | 转字符串 |
| `toInt()` | 0 | Int | `BigInt::toInt()` | 转整数，越界抛出 ArithmeticError |
| `toFloat()` | 0 | Float | `BigInt::toFloat()` | 转浮点 (可能丢精度) |

```php
$a = std::bigInt("12345678901234567890");
echo $a->toString();    // "12345678901234567890"
echo $a->add(1)->toString();  // "12345678901234567891"
echo $a->abs()->toString();   // "12345678901234567890"
echo $a->gcd(15)->toInt();    // 15
```

#### Decimal 方法

| 方法 | 参数 | 返回类型 | C++ 实现 | 说明 |
|------|------|---------|---------|------|
| `add($x)` | 1 | Decimal | `Decimal::add()` | 加法 |
| `sub($x)` | 1 | Decimal | `Decimal::sub()` | 减法 |
| `mul($x)` | 1 | Decimal | `Decimal::mul()` | 乘法 |
| `div($x)` | 1 | Decimal | `Decimal::div()` | 除法 |
| `mod($x)` | 1 | Decimal | `Decimal::mod()` | 取模 |
| `neg()` | 0 | Decimal | `Decimal::neg()` | 取负 |
| `abs()` | 0 | Decimal | `Decimal::abs()` | 绝对值 |
| `cmp($x)` | 1 | Int | `Decimal::cmp()` | 比较 |
| `toString()` | 0 | Str | `Decimal::toString()` | 转字符串 |
| `toInt()` | 0 | Int | `Decimal::toInt()` | 截断取整 |
| `toFloat()` | 0 | Float | `Decimal::toFloat()` | 转浮点 (约 15 位精度) |

```php
$d = std::decimal("123.456");
echo $d->toInt();         // 123
echo $d->mul(2)->toString();  // "246.912"
echo $d->div(3)->toString();  // "41.152"
```

#### BigFloat 方法

| 方法 | 参数 | 返回类型 | C++ 实现 | 说明 |
|------|------|---------|---------|------|
| `add($x)` | 1 | BigFloat | `BigFloat::add()` | 加法 |
| `sub($x)` | 1 | BigFloat | `BigFloat::sub()` | 减法 |
| `mul($x)` | 1 | BigFloat | `BigFloat::mul()` | 乘法 |
| `div($x)` | 1 | BigFloat | `BigFloat::div()` | 除法 |
| `neg()` | 0 | BigFloat | `BigFloat::neg()` | 取负 |
| `abs()` | 0 | BigFloat | `BigFloat::abs()` | 绝对值 |
| `cmp($x)` | 1 | Int | `BigFloat::cmp()` | 比较 |
| `toString()` | 0 | Str | `BigFloat::toString()` | 转字符串 |
| `toInt()` | 0 | Int | `BigFloat::toInt()` | 截断取整 |
| `toFloat()` | 0 | Float | `BigFloat::toFloat()` | 转 double (约 15 位精度) |

```php
$bf = std::bigFloat(3.14159265);
echo $bf->mul(2)->toString();      // "6.2831853..."
echo $bf->div(2)->toFloat();       // 1.570796325
echo $bf->cmp(3.0);                // > 0
```

### 类型转换

```php
// BigInt → Decimal（精确）
$big = std::bigInt("12345678901234567890");
$dec = std::decimal($big->toString());
// 或直接使用内置转换：
// $dec = $big->toDecimal();  // 待实现

// Decimal → BigInt（截断小数部分）
$d = std::decimal("123.456");
$i = std::bigInt($d->toInt());  // 123

// Int → BigInt
$bi = std::bigInt(42);

// Float → BigFloat
$bf = std::bigFloat(3.14);

// BigInt → BigFloat
$bf2 = std::bigFloat($big->toString());
```

> **跨类型隐式转换限制**：BigInt、Decimal、BigFloat 之间不能隐式混合运算或比较。编译器会报错并要求先显式转换为同一类型，这是为了防止精度损失和底层 Box 类型误用。

### C++ API 参考

Big* 类型在 `phpx` 库中提供以下核心函数：

```cpp
// 构造
php::Variant php::newBigInt(const std::string &s);
php::Variant php::newBigInt(php::Int v);
php::Variant php::newDecimal(const String &s);
php::Variant php::newDecimal(php::Int v);
php::Variant php::newBigFloat(const String &s);
php::Variant php::newBigFloat(php::Int v);
php::Variant php::newBigFloat(php::Float v);

// BigInt 算术（均返回 Variant）
php::BigInt::add(a, b)   php::BigInt::sub(a, b)   php::BigInt::mul(a, b)
php::BigInt::div(a, b)   php::BigInt::mod(a, b)   php::BigInt::pow(a, b)
php::BigInt::neg(a)      php::BigInt::abs(a)      php::BigInt::gcd(a, b)
php::BigInt::cmp(a, b)

// Decimal 算术
php::Decimal::add(a, b)  php::Decimal::sub(a, b)  php::Decimal::mul(a, b)
php::Decimal::div(a, b)  php::Decimal::mod(a, b)
php::Decimal::neg(a)     php::Decimal::abs(a)     php::Decimal::cmp(a, b)

// BigFloat 算术
php::BigFloat::add(a, b) php::BigFloat::sub(a, b) php::BigFloat::mul(a, b)
php::BigFloat::div(a, b)
php::BigFloat::neg(a)    php::BigFloat::abs(a)    php::BigFloat::cmp(a, b)

// 类型转换
php::BigInt::toString(a)   php::BigInt::toInt(a)   php::BigInt::toFloat(a)
php::Decimal::toString(a)  php::Decimal::toInt(a)  php::Decimal::toFloat(a)
php::BigFloat::toString(a) php::BigFloat::toInt(a) php::BigFloat::toFloat(a)
```

所有静态方法接收 `Variant` 参数，内部通过 `.toBox<BigInt>()` / `.toBox<Decimal>()` / `.toBox<BigFloat>()` 提取底层对象。若参数类型不匹配，抛出运行时错误。

### 超长字面量识别

AOT 编译器在解析前会对 PHP 源码进行预扫描，自动识别超出 int64/double 精度的数值字面量：

```
\d{19,}                     → 自动转为 std::bigInt("...")
\d+\.\d{16,}               → 自动转为 std::decimal("...")
```

例如源码中写 `123456789000000000000000000000000000000000000000000001`（54 位），编译器自动处理为 `std::bigInt("123456789000000000000000000000000000000000000000000001")`，无需手动包装。

---

## 二元运算类型提升规则

AOT 编译器在执行 `+`、`-`、`*`、`/`、`%` 等二元运算时，按以下优先级确定运算类型：

### 规则优先级

```
BigFloat / Decimal / BigInt 参与
  → 仅安全提升 Int/Float；不同 Big* 类型要求显式转换
  ↓ 未命中

任一边为 Var
  → 两边均转为 Var，使用 ZendVM binary_op 函数
  ↓ 未命中

任一边为 Float
  → 两边均转为 Float (double)
  ↓ 未命中

两边均为 Int
  → 使用 Int (int64_t)
```

### 规则一：Var 主导

当至少一个运算数为 `Var` 时，两边都进入 PHPX/Zend 算术路径，遵循 PHP 的类型
转换语义。`Var` 可以来自 `std::any(...)`、动态运行时值，或声明了
`use varint_types` 的文件中推断出的整数。

```php
use varint_types;
$a = 10;        // Var，存 int(10)
$b = 2.5;       // php::Float（varint_types 的存储映射只装箱推断整数）
$c = $a + $b;   // 两边为 Var → ZendVM 运算 → float(12.5)
```

C++ 代码生成：`int64_t` 和 `double` 值通过 `php::Variant` 的模板构造函数（`phpx.h:557`）隐式转为 `php::Var`，再调用 `Variant::operator+()` → ZendVM 的 `add_function`。

### 规则二：Float 优先于 Int

当两边均为原生类型（默认行为，或在 varint 文件中显式使用 `std::int()` /
`std::float()`）时，Float 优先。仅当两边都是 Int 才使用整数运算。

```php
$a = 10;        // php::Int
$b = 2.5;       // php::Float
$c = $a + $b;   // Float + Float → double 加法

$d = 5;         // php::Int
$e = 3;         // php::Int
$f = $d + $e;   // Int + Int → int64_t 加法
```

> **注意**：原生变量在运算中**不会改变存储类型**。例如 `Int += Float` 的结果仍是
> Int。这种固定类型行为现在是默认规则；需要动态整数扩展时应显式选择
> `use varint_types` 或 `std::any()`。

### 规则三：高精度类型的安全提升

当运算数中包含 `BigInt`、`Decimal` 或 `BigFloat` 时，只对普通标量执行明确且安全的提升。不同 Big* 类型不会按所谓“精度层级”自动转换，因为三者的数值模型不同。

| 左操作数 | 右操作数 | 结果类型 |
|---------|---------|---------|
| BigInt | BigInt | BigInt（`/` 为截断整数除法） |
| BigInt | Decimal | 编译错误，需显式转换 |
| Decimal | Decimal | Decimal |
| BigFloat | BigInt | 编译错误，需显式转换 |
| BigFloat | Decimal | 编译错误，需显式转换 |
| BigFloat | BigFloat | BigFloat |
| BigInt | Int | BigInt |
| BigInt | Float | 编译错误 |
| Decimal | Int | Decimal |
| Decimal | Float | Decimal（float 字面量按源码文本转换；变量需显式转换） |
| BigFloat | Int | BigFloat |
| BigFloat | Float | BigFloat |

### 类型提升完整矩阵

| | Int | Float | Var | BigInt | Decimal | BigFloat |
|------|-----|-------|-----|--------|---------|----------|
| **Int** | Int | Float | Var | BigInt | Decimal | BigFloat |
| **Float** | Float | Float | Var | 错误 | Decimal* | BigFloat |
| **Var** | Var | Var | Var | Var | Var | Var |
| **BigInt** | BigInt | 错误 | Var | BigInt | 错误 | 错误 |
| **Decimal** | Decimal | Decimal* | Var | 错误 | Decimal | 错误 |
| **BigFloat** | BigFloat | BigFloat | Var | 错误 | 错误 | BigFloat |

> `Decimal*`：只允许编译器能够保留原始文本的 float 字面量；float 变量必须先显式转换。Var 行/列仍使用 ZendVM 运行时语义。

### 复合赋值运算符

`+=`、`-=`、`*=`、`/=`、`%=` 等复合赋值运算符遵循相同的类型提升规则，但 RHS 会被转换为 LHS 变量的类型。若 LHS 为 Var，RHS 保持原类型（Var 的 `operator+=` 接管）；若 LHS 为原生类型，RHS 显式转换为该类型。

```php
use varint_types;
$a = 10;        // 该文件启用 varint_types，因此为 Var
$a += 2.5;      // Var::operator+=(float) → ZendVM → $a 变为 float(12.5)

$b = std::int(10); // varint_types 文件中显式声明 php::Int
$b += 2.5;      // int64_t += double → C++ 隐式截断 → $b = 12 (Int)
```

---

**最后更新**: 2026 年 9 月 6 日
**适用版本**: TypePHP 0.8+
