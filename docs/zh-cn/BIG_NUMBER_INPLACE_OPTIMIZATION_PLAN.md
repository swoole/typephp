# 高精度类型原地运算优化方案

## 1. 背景

TypePHP 当前将 `BigInt`、`BigFloat` 和 `Decimal` 实现为存放在 Zend resource 中的 PHPX `Box`。高精度运算采用不可变结果接口，例如：

```cpp
target = php::BigInt::mul(target, rhs);
```

即使 PHP 源码使用复合赋值：

```php
$target *= $rhs;
```

编译器仍会生成“创建新结果并重新赋值”的代码。以 BigInt 为例，当前一次乘法通常需要：

1. 创建新的 `BigInt` Box。
2. 注册新的 Zend resource。
3. 初始化新的 `mpz_t`。
4. 为计算结果分配 GMP limb 存储。
5. 将新 resource 移动赋值给目标变量。
6. 析构旧 resource、Box 和底层数值存储。

在循环、累计、阶乘、金额聚合等场景中，这些开销会随运算次数线性增长：

```php
for ($i = 0; $i < $count; $i++) {
    $value = $value * 1000;
}
```

GMP、MPFR 和 mpdecimal 的底层对象并不是不可变对象，三者都支持输出与输入重叠的原地运算。当前不可变行为来自 PHPX 的高精度 API，而不是底层数学库的限制。

本文给出高精度类型原地运算的实现方案、语义约束、分阶段计划和验收标准。

## 2. 优化目标

### 2.1 主要目标

- 对唯一持有的 BigInt、BigFloat 和 Decimal Box 复用现有对象。
- 尽可能复用 GMP limb、MPFR mantissa 和 mpdecimal coefficient 存储。
- 消除复合赋值中的结果 Box 和 Zend resource 临时对象。
- 对整数等原生 RHS 直接使用 `php::Int` 或 `php::Var`，避免构造 RHS 高精度 Box。
- 将安全的 `$x = $x {op} $rhs` 融合为原地运算。
- 保持 PHP 的值语义、引用语义、求值顺序和异常行为。
- 不安全或无法证明安全的场景自动回退到当前不可变实现。

### 2.2 非目标

- 第一阶段不优化数组元素、动态属性、属性 Hook 等复杂左值。
- 不依赖全程序别名分析才能保证正确性。
- 不修改 GMP、MPFR 或 mpdecimal 第三方源码。
- 不把所有普通二元表达式改为可变计算。
- 不改变不同高精度类型之间的隐式转换规则。

## 3. 底层库能力

| 类型 | 底层对象 | 原地操作 | 内存复用特征 |
|---|---|---:|---|
| BigInt | GMP `mpz_t` / `mpz_class` | 支持 | 容量足够时复用 limb，结果增长时才扩容 |
| BigFloat | MPFR `mpfr_t` | 支持 | 当前固定 256-bit 精度，普通运算通常可持续复用 mantissa |
| Decimal | mpdecimal `mpd_t` / `decimal::Decimal` | 支持 | 可复用 coefficient，库本身提供 `operator+=` 等原地接口 |

典型原地调用如下：

```cpp
mpz_mul(dst, dst, rhs);
mpfr_mul(dst, dst, rhs, MPFR_RNDN);
mpd_qmul(dst, dst, rhs, context, &status);
```

mpdecimal 的 C++ 包装已经提供：

```cpp
Decimal::operator+=
Decimal::operator-=
Decimal::operator*=
Decimal::operator/=
Decimal::operator%=
```

因此技术瓶颈主要在 PHP Box 的共享语义、异常安全和编译器求值顺序，而不是数学库本身。

## 4. 必须保持的语言语义

### 4.1 Copy-on-write

高精度值可能被多个 PHP 变量共享同一个 Box：

```php
$a = std::bigInt(10);
$b = $a;
$a *= 2;
```

结果必须是：

```text
$a = 20
$b = 10
```

不能直接修改共享 Box。PHPX 必须在运算前检查 Zend resource 引用计数：

- resource 唯一持有：直接修改原 Box。
- resource 被共享：复制 Box，将目标变量绑定到副本，再修改副本。

运行时 copy-on-write 是正确性的最后防线。编译器静态分析只负责减少不必要的检查和识别可融合表达式，不能替代运行时检查。

### 4.2 PHP 引用

以下场景中两个变量指向同一个 PHP 引用容器：

```php
$a = std::bigInt(10);
$b =& $a;
$a *= 2;
```

结果必须是 `$a` 和 `$b` 同时为 20。原地 API 必须通过 `Variant::unwrap_ptr()` 操作引用中的实际 zval；发生 copy-on-write 时，应更新引用容器中的值，而不是重新绑定 PHPX 包装对象。

### 4.3 RHS 与目标变量别名

必须正确处理：

```php
$a *= $a;
```

建议原地接口将目标以引用传递、RHS 以值传递：

```cpp
BigInt::mulAssign(Variant &target, Variant rhs);
```

如果 RHS 与 target 共享同一 resource，RHS 的临时引用计数会使 copy-on-write 走复制分支。这可能错失一次原地机会，但能自然保证正确性。后续可增加“RHS 与 target 是同一 Box”的专门路径。

### 4.4 求值顺序

下面两段代码不能在所有情况下直接视为等价：

```php
$x = $x * changeValue($x);
$x *= changeValue($x);
```

RHS 可能重新赋值、按引用修改或通过闭包捕获修改 `$x`。C++ 函数参数求值顺序也不能用来替代 PHP 求值规则。

编译器必须遵循以下规则：

- 对真正的 `AssignOp` 使用现有有序操作数和副作用捕获机制。
- 对 `$x = $x {op} $rhs`，仅在 RHS 不会写入或逃逸 `$x` 时融合。
- 无法证明安全时使用当前“计算新结果后赋值”路径。
- 如果为了保持顺序必须保存旧 `$x`，该临时值会增加引用计数，此时应允许运行时自动回退 copy-on-write。

### 4.5 复杂左值

以下表达式不能在第一阶段改写：

```php
$array[getIndex()] = $array[getIndex()] * 2;
$object->value = $object->value * 2;
$object->hooked = $object->hooked * 2;
```

原因包括：

- 下标表达式可能执行两次。
- Getter、Setter 或属性 Hook 的调用次数可能改变。
- 动态属性读写可能触发魔术方法。
- 左值本身可能带有副作用。

第一阶段只支持简单局部变量。复杂左值在后续阶段通过“只求值一次的 writable target”抽象单独设计。

### 4.6 异常安全

当前不可变实现先计算新结果，成功后才赋值，因此异常发生时目标变量不变：

```php
$value = std::decimal('10');

try {
    $value /= 0;
} catch (DivisionByZeroError $e) {
}

echo $value; // 仍然是 10
```

原地实现必须保持这一行为。

- BigInt：在修改前检查除数、模数、指数等错误条件。
- BigFloat：在修改前检查除零和当前 API 明确定义的错误条件。
- Decimal：`context.raise(status)` 可能在底层结果已经写入后抛出异常，需要事务式提交或回滚机制。
- 内存分配失败也不能将目标留在部分修改状态。

### 4.7 Resource identity

高精度 Box 当前以 resource 暴露，`get_resource_id()` 和严格比较可能观察 resource identity。原地运算会让唯一持有的变量保持 resource id，而当前不可变实现会生成新的 resource id。

实施前需要明确以下契约之一：

1. 高精度类型是值类型，resource identity 属于内部实现细节，不保证运算前后变化。
2. 必须保持当前 resource identity 变化，此时只能复用底层数值存储并重新封装 resource，收益会降低。

推荐采用方案 1，并在高精度类型文档中明确：用户应比较数值，不应依赖内部 resource id。共享变量的值语义仍由 copy-on-write 严格保证。

## 5. PHPX 设计

### 5.1 显式原地 API

不建议给通用 `Variant` 增加高精度运算符重载。应在各高精度类型上增加明确接口：

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

BigFloat、Decimal 使用同样的命名方式。BigInt 还应覆盖位运算和移位：

```cpp
bitAndAssign
bitOrAssign
bitXorAssign
bitShiftLeftAssign
bitShiftRightAssign
```

接口返回 `Variant &`，使复合赋值仍可作为表达式使用：

```php
$result = ($value *= 2);
```

如果实际生成代码对引用返回处理不方便，可以同时提供 statement-only 的 `void` 快速路径，但不能牺牲赋值表达式语义。

### 5.2 Box 唯一化工具

在 PHPX 内部提供复用的 C++17 helper，而不是在三种类型中重复 Zend resource 逻辑：

```cpp
template <typename T>
T *separateBoxForWrite(Variant &target);
```

职责包括：

1. 解引用 indirect/reference zval。
2. 校验 target 是目标 Box 类型。
3. 检查 Zend resource 引用计数。
4. 唯一持有时返回原 Box。
5. 共享时复制 Box，并通过 `Variant` 赋值语义更新目标。
6. 保持 typed reference 检查和异常传播。

三种 Box 都必须具备正确复制能力：

- BigInt：复制 `mpz_class`。
- BigFloat：按源精度初始化并复制 `mpfr_t`。
- Decimal：复制 `decimal::Decimal`。

### 5.3 RHS 提取

原地接口应直接接受 `Variant rhs`，复用现有 operand extractor：

- `php::Int` 直接转换为底层整数操作数。
- `php::Var` 在运行时检查其实际类型。
- 已经是同类 Box 时直接读取底层值。
- 字符串、浮点数和不同高精度类型继续遵循当前转换限制。

生成代码应优先为：

```cpp
php::BigInt::mulAssign(value, 1000L);
php::Decimal::mulAssign(value, factor);
```

避免：

```cpp
php::BigInt::mulAssign(value, php::toBigInt(1000L));
php::Decimal::mulAssign(value, php::toDecimal(1000L));
```

对于 Decimal 的整数 RHS，可进一步使用 mpdecimal 的 `_i64`/`_u64` 接口，避免构造临时 `decimal::Decimal`：

```cpp
mpd_qmul_i64(result, left, rhs, context, &status);
```

### 5.4 BigInt 实现策略

BigInt 优先实现真正原地操作：

```cpp
Variant &BigInt::mulAssign(Variant &target, Variant rhs) {
    BigIntOperand right;
    // 先提取并验证 RHS。
    // 再执行 target 的 copy-on-write。
    // 最后调用 mpz_mul(dst, dst, right)。
    return target;
}
```

必须在修改前完成所有可恢复错误检查，例如除零、模零和非法移位量。GMP 扩容由其内部管理；容量足够时会复用原 limb 存储。

### 5.5 BigFloat 实现策略

BigFloat 当前统一使用 `BIG_FLOAT_DEFAULT_PRECISION`，适合直接原地运算：

```cpp
mpfr_mul(dst, dst, rhs, MPFR_RNDN);
```

如果未来支持每对象精度，需要规定非原地结果精度与复合赋值目标精度的关系，并加入不同精度对象测试。

### 5.6 Decimal 实现策略

Decimal 分两步实施。

第一步采用异常安全的事务式提交：

```cpp
decimal::Decimal temporary;
uint32_t status = 0;
mpd_qmul(temporary.get(), current.getconst(), rhs, context, &status);
context.raise(status);
current = std::move(temporary);
```

该方案可以消除结果 Box 和 Zend resource，但仍会创建一个底层 Decimal 临时对象。

第二步评估真正原地运算：

- 修改前完成除零等显式检查。
- 确认哪些 status/trap 可能在操作后抛出。
- 为可能抛出的操作提供备份/回滚，或仅在能够证明不会触发 trap 时原地执行。
- 对 Overflow、InvalidOperation、DivisionByZero 和模拟分配失败做专项测试。

不能为了性能接受“异常后目标值已被部分修改”。

## 6. 编译器设计

### 6.1 真正的复合赋值

首先修改现有 Big* `AssignOp` 生成路径：

```php
$value *= $rhs;
```

从：

```cpp
value = php::BigInt::mul(value, rhs);
```

改为：

```cpp
php::BigInt::mulAssign(value, rhs);
```

支持矩阵：

| 类型 | 第一阶段操作符 |
|---|---|
| BigInt | `+= -= *= /= %= &= |= ^= <<= >>=` |
| BigFloat | `+= -= *= /=` |
| Decimal | `+= -= *= /= %=` |

### 6.2 普通赋值融合

识别以下 AST：

```php
$x = $x {op} $rhs;
```

只有同时满足以下条件才融合：

- 左值是简单命名变量。
- 二元表达式左操作数是同一个变量。
- 变量静态类型是 BigInt、BigFloat 或 Decimal。
- 操作符在对应类型的支持列表中。
- RHS 不包含对目标变量的赋值、引用获取或已知按引用传参。
- RHS 不包含无法安全分析的动态调用或其他逃逸路径；或者现有副作用分析明确证明安全。`eval/include/require` 不捕获 AOT 状态，不应仅因其存在禁止融合；仍需分析源码或路径表达式自身的副作用并保留执行顺序。
- 当前表达式上下文可以正确接收原地接口的返回值。

以下场景第一阶段不融合：

```php
$x = 2 - $x;
$x = $x * ($x = 2);
$x = $x * dynamicCall();
$array[$key] = $array[$key] * 2;
$object->value = $object->value * 2;
```

对交换律操作的 `$x = $rhs + $x` 或 `$x = $rhs * $x` 优化放到后续阶段，避免扩大第一版范围。

### 6.3 失败回退

优化必须是可选 codegen 路径：

```text
可安全原地运算 -> emit *Assign()
无法证明安全   -> emit 当前 new-result 路径
```

任何类型不确定、复杂左值、引用逃逸或副作用分析失败都不能导致编译错误，只应失去该项优化。

### 6.4 与 SSA/优化器的关系

初始版本可在 `AssignOpTrait` 和普通赋值解析中做局部 AST 匹配，不依赖完整 SSA。

后续可由 SSA 提供：

- 目标变量是否存在别名。
- RHS 是否写入目标变量。
- 变量是否逃逸到动态调用或引用。
- 是否能静态证明 Box 唯一持有。

即使 SSA 证明唯一，PHPX 运行时 copy-on-write 检查仍建议保留，除非有严格的逃逸证明和专项测试。

## 7. 分阶段实施计划

### 阶段 0：基线与观测

- 添加高精度 Box/resource 创建计数的测试辅助设施。
- 建立 BigInt、BigFloat、Decimal 循环运算 benchmark。
- 记录当前 wall time、Box 数量、resource 数量和底层分配次数。
- 固化当前别名、引用、异常和 resource identity 行为。

交付物：基准报告和行为测试，不改变生成代码。

### 阶段 1：原生 RHS 快速路径

- BigInt 运算直接接受 `php::Int`。
- BigFloat 运算直接接受 `php::Int`、`php::Float`。
- Decimal 运算直接接受 `php::Int` 和实际为 int 的 `php::Var`。
- Decimal 整数路径优先使用 `mpd_q*_i64`。
- 消除编译器为 RHS 创建的高精度 Box。

交付物：RHS 不再出现不必要的 `toBigInt()`、`toBigFloat()`、`toDecimal()`。

### 阶段 2：PHPX copy-on-write 基础设施

- 实现 `separateBoxForWrite<T>()`。
- 为三类 Box 完善复制测试。
- 覆盖普通变量、共享变量、PHP 引用、indirect zval 和 RHS 同 Box。
- 明确 resource identity 契约。

交付物：独立 PHPX 单元测试，不修改编译器生成路径。

### 阶段 3：BigInt 与 BigFloat 复合赋值

- 实现 BigInt `*Assign()` 方法族。
- 实现 BigFloat `*Assign()` 方法族。
- 修改真正的 PHP `AssignOp` 生成代码。
- 保留不安全路径回退。
- 运行 PHPX 全量测试、编译器全量 PHPUnit、相关 PHPT 和自举编译。

交付物：`$x *= $rhs` 等语法使用真正原地操作。

### 阶段 4：普通赋值融合

- 识别简单局部变量 `$x = $x {op} $rhs`。
- 实现目标变量写入/逃逸检查。
- 对纯字面量和纯变量 RHS 优先启用。
- 为有副作用 RHS 保留旧路径。

交付物：问题描述中的常见写法无需用户手动改成复合赋值。

### 阶段 5：Decimal 事务式原地接口

- 实现 Decimal `*Assign()` API。
- 先使用“底层临时结果 + 成功后提交”。
- 使用 `_i64` 快速路径优化整数 RHS。
- 覆盖所有 Decimal trap 和异常后的目标值。

交付物：消除 Decimal 结果 Box/resource，保持强异常安全。

### 阶段 6：Decimal 真正原地计算

- 按操作符分析可能触发的 status/trap。
- 对可证明安全的操作直接使用目标 `mpd_t`。
- 对高风险操作保留事务式路径。
- 通过 benchmark 判断复杂度是否值得。

交付物：Decimal 常见累计运算复用 coefficient 存储。

### 阶段 7：复杂左值与进一步优化

- 设计只求值一次的 writable target 抽象。
- 评估数组元素、静态属性、普通属性的支持。
- 属性 Hook、魔术方法和动态属性默认不启用，除非能严格保持调用次数和顺序。
- 评估交换律表达式融合和 SSA 唯一性证明。

## 8. 测试计划

### 8.1 PHPX 单元测试

每种类型、每个操作符至少覆盖：

- 唯一 Box 原地更新。
- 共享 Box 触发 copy-on-write。
- PHP 引用更新同一引用值。
- RHS 与 target 为同一 Box。
- Int、Float、String、Var 等允许的 RHS 类型。
- 非法 RHS 类型异常。
- 除零、模零、负指数等边界。
- 异常后目标值不变。
- 极大数导致扩容。
- 连续多次运算。

### 8.2 编译器 PHPUnit

检查生成代码：

- `AssignOp` 生成 `BigInt::mulAssign()` 等调用。
- `$x = $x * 1000` 被融合。
- RHS 原生整数不再构造 Big* Box。
- RHS 有副作用时不融合。
- 数组元素和属性第一阶段不融合。
- 不支持的操作符继续给出原有 FatalError。

### 8.3 PHPT

至少覆盖：

```php
$a *= 2;
$a = $a * 2;
$b = $a; $a *= 2;
$b =& $a; $a *= 2;
$a *= $a;
$a *= ($factor = 2);
$result = ($a *= 2);
```

并为三种高精度类型覆盖：

- 正数、负数、零。
- 极大值和精度边界。
- 所有支持的复合赋值操作符。
- 异常后的左值。
- 循环中的连续更新。

### 8.4 集成验证

每个阶段至少执行：

```bash
./vendor/bin/phpunit
php run-tests.php tests/compiler/bigint tests/compiler/bignumber tests/compiler/decimal
php bin/tpc.php project.yml
```

PHPX 修改还必须执行 PHPX 全量单元测试。

## 9. 性能验收

性能测试至少包含：

- 1、4、16、64、256、1024 个 limb/decimal digit 规模。
- RHS 为小整数、同类高精度值和动态 `php::Var`。
- 唯一 Box 和共享 Box。
- 1 千、10 万、100 万次循环。
- BigInt 增长型乘法与稳定容量加法。
- BigFloat 固定精度累计。
- Decimal 固定 50 位精度累计。

功能验收标准：

- 唯一 BigInt/BigFloat 的复合赋值每次迭代不创建结果 Box/resource。
- 原生 RHS 不创建高精度 Box。
- 共享 Box 正确触发 copy-on-write。
- 所有异常路径保持目标值不变。
- 自举编译和全量测试通过。

性能验收以基线数据为准，不预设不现实的固定倍数。至少应分别报告：

- 总耗时。
- Box/resource 创建次数。
- 底层内存分配次数与字节数。
- 峰值内存。
- copy-on-write 命中率和回退率。

如果某条优化路径不能减少分配，或者导致常见非原地表达式明显退化，应保留旧路径或撤销该子优化。

## 10. 风险与回滚策略

主要风险：

- Box 共享判断错误导致其他变量被意外修改。
- 引用或 indirect zval 被重新绑定而不是更新。
- RHS 副作用改变求值顺序。
- Decimal 异常后目标值被污染。
- resource identity 行为发生未记录变化。
- 原地扩容失败留下非法底层对象。

控制措施：

- 所有优化集中在独立 PHPX API 和单一编译器 codegen 分支。
- 无法证明安全时回退旧实现。
- 分类型、分操作符逐步启用。
- 每个阶段独立提交，避免同时修改过多语义。
- 在完成异常、别名和引用测试前，不删除现有不可变 API。

回滚时只需让编译器重新生成：

```cpp
target = Type::operation(target, rhs);
```

原有不可变 API 在整个迁移期必须保留。

## 11. 推荐优先级

综合收益、复杂度和风险，推荐顺序为：

1. BigFloat 原地复合赋值。
2. BigInt 原地复合赋值。
3. BigInt/BigFloat 普通赋值融合。
4. Decimal 原生整数 RHS 快速路径。
5. Decimal 事务式 `*Assign()`。
6. Decimal 真正原地计算。
7. 复杂左值和 SSA 增强。

BigFloat 固定精度，最容易稳定复用底层内存；BigInt 的应用面更广，整体收益可能最大；Decimal 的异常和 trap 语义最复杂，应最后推进真正原地修改。

## 12. 最终目标代码

对安全的简单变量：

```php
$value = $value * 1000;
```

最终生成：

```cpp
php::BigInt::mulAssign(value, 1000L);
```

运行时：

```text
唯一 Box：原地复用 Box、resource 和底层存储
共享 Box：copy-on-write 后修改新 Box
不安全场景：回退当前不可变结果实现
```

该设计把性能优化限制在可验证的边界内，同时保留 TypePHP 与 PHP 赋值、引用和异常语义的一致性。
