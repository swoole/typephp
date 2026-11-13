# AOT 编译期函数与关键词方法

本文档记录 AOT 编译器专有的编译期函数、关键词方法和相关构造入口。它们不是标准 PHP 语法的一部分，普通 PHP 运行时只能依赖 `src/polyfills.php` 提供的兼容占位。

## 全局函数名

TypePHP 不再为编译器指令保留任何全局函数名。编译期 API 最终只占用两个全局类符号：
`Type::*` 仅用于扩展方法等元数据中的类型表示，`std::*` 承载 TypePHP 内置函数。
对象类型断言使用 `std::object()` 或等价的 `toObject()` 关键词方法。

遵循 PHP 规则，`std` / `Type` 类名以及 `std` 方法名不区分大小写；`Type::*`
成员是类常量，其常量名仍区分大小写。

## 关键词方法

当前内置关键词方法共 13 个。

| 名称 | 等价行为 | 说明 |
| --- | --- | --- |
| `toAny()` | `std::any($receiver)` | 返回接收者本身，但类型降级为 `mixed/any`。 |
| `toRef()` | `std::ref($receiver)` | 返回接收者引用；参数限制与 `std::ref()` 一致。 |
| `toObject()` | `php::toObject($receiver)` | 可带目标类参数，执行对象转换/检查。 |
| `instanceOf($class)` | `$receiver instanceof $class` | 检查对象所属类型，返回 bool；适用于 typed object、普通 object 和 mixed 中的对象。 |
| `toInt()` | `php::toInt($receiver)` | 转为 native int 表达式。 |
| `toFloat()` | `php::toFloat($receiver)` | 转为 native float 表达式。 |
| `toString()` | `php::toString($receiver)` | 转为字符串表达式。 |
| `toBool()` | `php::toBool($receiver)` | 转为 bool 表达式。 |
| `toArray()` | `php::toArray($receiver)` | 转为数组表达式。 |
| `toStream()` | `php::toStream($receiver)` | 转为 stream 表达式。 |
| `toBigInt()` | `php::BigInt::newInstance($receiver)` | 构造 BigInt。 |
| `toBigFloat()` | `php::BigFloat::newInstance($receiver)` | 构造 BigFloat。 |
| `toDecimal()` | `php::Decimal::newInstance($receiver)` | 构造 Decimal。 |

约束：

- `toAny()`、`toRef()` 不接受参数。
- `toRef()` 只适用于可取引用的接收者。
- 关键词方法优先于普通方法和 universal method 分派。

`instanceOf()` 接受一个类名参数，支持类名字符串、动态类名表达式和 `ClassName::class`，
也支持命名参数 `class:`。方法名不区分大小写，不能被用户类、接口或 Trait 重新声明，
也不能通过 first-class callable 语法导出。它复用 `instanceof` 运算符的 lowering，
不会调用对象的实际方法或 `__call()`。

```php
$typed->instanceOf(User::class);
$object->instanceOf($className);
$nullable->instanceOf(User::class);  // 接收者为 null 时返回 false
$nullable?->instanceOf(User::class); // 接收者为 null 时返回 null，且不计算参数

if ($value->instanceOf(User::class)) {
    // 已知类的正向分支沿用 instanceof 的静态类型收窄。
    $value->method();
}
```

接收者和类名表达式各计算一次，并保留从左到右的求值顺序。Native Class 沿用现有
`instanceof` 的静态解析限制，使用 `ClassName::class` 时可在编译期判断类型关系；
不会把 Native 指针传入 Zend。

## `std::` 编译期入口

当前 `std::` 编译期入口共 17 个。

| 名称 | 作用 | 主要限制 |
| --- | --- | --- |
| `std::int($value)` | 显式创建 native int 表达式。 | 需要 1 个值参数。 |
| `std::float($value)` | 显式创建 native float 表达式。 | 需要 1 个值参数。 |
| `std::bool($value)` | 显式创建 native bool 表达式。 | 需要 1 个值参数。 |
| `std::bigInt($value)` | 构造 BigInt。 | 不允许从 float 变量隐式构造。 |
| `std::decimal($value)` | 构造 Decimal。 | float 变量需改用字符串或整型；float 字面量会按原始字面量处理。 |
| `std::bigFloat($value)` | 构造 BigFloat。 | 需要 1 个值参数。 |
| `std::any([$value])` | 将表达式降级为 `mixed/any`；省略参数时，初始值为 `null`。 | Native 对象及包含 Native 对象的 std 容器不能通过它逃逸。 |
| `std::object($value, ClassName::class)` | 检查对象并恢复具体类信息。 | 必须传入 2 个非展开参数，且类名必须能在编译期解析。 |
| `std::ref($target)` | 显式以引用方式传递目标。 | 只接受变量、数组元素或对象属性，且仅可作为调用参数的引用包装器。 |
| `std::expected($condition)` | 标记条件通常为真。 | 只接受一个非展开参数并返回 bool。 |
| `std::unexpected($condition)` | 标记条件通常为假。 | 只接受一个非展开参数并返回 bool。 |
| `std::array($type, $size[, ...$sizes])` | 构造固定大小 std array。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::vector($type[, $size])` | 构造 std vector。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::map($keyType, $valueType)` | 构造 std map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::orderedMap($keyType, $valueType)` | 构造 std ordered map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::list($valueType)` | 创建整数键强类型 PHP 数组，支持负数、稀疏索引和追加。 | 新函数局部变量首次赋值；动态键严格检查，禁止动态引用修改。 |
| `std::dict($keyType, $valueType)` | 创建强类型 PHP 字典，键为 `Type::Int` 或 `Type::Str`。 | 新函数局部变量首次赋值；必须显式提供键，不支持追加。 |

list/dict 保留普通 PHP 数组存储和写时复制。值要求静态类型匹配，`any` / `var` 键插入内部严格检查；仅允许只读动态 PHP 数组调用，禁止 `std::ref()`。参数使用完全一致的 `StdList` / `StdDict` 类型注解，PHP 类型可省略或为 `array`，不允许 `mixed`；同类型的原生 `&` 参数可以修改调用方。详细示例及边界见[强类型 PHP 数组与类型注解](TYPED_ARRAYS.md)。

## Std 容器转换关键词方法

当前 Std 容器转换关键词方法共 6 个。

| 名称 | 作用 | 主要限制 |
| --- | --- | --- |
| `toStdArray(...)` | 将变量包装为 std array。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdVector(...)` | 将变量包装为 std vector。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdMap(...)` | 将变量包装为 std map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdOrderedMap(...)` | 将变量包装为 std ordered map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdList($valueType)` | 转为整数键强类型 PHP 数组。 | 同契约来源直接赋值；其他来源逐项严格校验键和值。 |
| `toStdDict($keyType, $valueType)` | 转为强类型 PHP 字典。 | 键类型只能为 `Type::Int` 或 `Type::Str`；其他规则同 `toStdList()`。 |

## 不计入本文清单的机制

- `$array->any()` 是 universal method，映射到 PHP `array_any()`，不是 `std::any()` 编译期函数。
- `Type::*` 是编译期类型描述常量，不是函数。
- keyword extension method 是用户自定义扩展方法机制，不属于固定内置编译期函数清单。

## 实现约束

编译期函数应当在任意合法表达式位置可用，并且在所有路径上保持一致语义：

- `std::any()` 使用统一的降级入口；赋值、参数、返回值、数组元素和运算子表达式共用相同语义。
- `std::ref()` / `toRef()` 在参数解析、SSA 和优化器路径中共用同一个引用包装识别入口。
- 已移除的全局 `objval()` 由 `std::object($value, ClassName::class)` 取代。它与 `$value->toObject(ClassName::class)` 等价，同时允许普通 PHP 项目提供兼容的 `std::object()` polyfill。
- `std::expected()` / `std::unexpected()` 分别生成 `EXPECTED(...)` / `UNEXPECTED(...)`，不产生 PHP 运行时函数调用。

后续重构目标：

- 建立统一的 `CompileTimeFunctionResolver` 或等价模块。
- 在 `parseExpr()` / `detectTypeOfExpr()` / `detectClassOfExpr()` / 参数解析路径中复用同一份编译期函数元信息。
- 继续统一引用包装器在不同表达式路径上的行为。
