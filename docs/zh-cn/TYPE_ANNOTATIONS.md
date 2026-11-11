# 类型注解统一设计

状态：现有容器实现与目标设计的统一记录；`StdArray` 类型注解已经实现，`StdFunc` / `StdArgInfo` 已暂缓。

## 术语与职责

`StdArray`、`StdVector`、`StdMap`、`StdOrderedMap`、`StdList`、`StdDict`、`StdFunc` 统一称为“类型注解”。它们描述 TypePHP 静态类型契约，而不是运行时验证器，也不是创建容器的编译期函数。

- `std::vector(...)`、`std::list(...)` 等是创建值的编译期函数。
- `#[StdVector(...)]`、`#[StdList(...)]` 等是声明参数或属性契约的类型注解。
- `new StdList(...)` 等在拟议的函数签名中是嵌套类型描述，不创建数组。
- `new StdArgInfo(...)` 是函数参数描述，只负责值类型、Nullable、引用、Optional、Variadic 和默认值。

详细分工见 [StdFunc 与 StdArgInfo](STD_FUNC_DESIGN.md)、[强类型 PHP 数组](TYPED_ARRAYS.md)、[C++ 容器](STD_CONTAINERS.md) 和 [现有容器参数实现](STD_CONTAINER_PARAMETER_ATTRIBUTES.md)。

## 类型模型与存储

| 类型注解 | 创建值 | 存储与传递 | 核心契约 |
|---|---|---|---|
| `StdArray(T, sizeOrDimensions)` | `std::array(T, N)` 或现有嵌套工厂 | PHPX Box，具体 C++ 定长模板实例 | 叶子类型、完整维度、禁止空洞 |
| `StdVector(T)` | `std::vector(T[, size])` | PHPX Box，具体 C++ 模板实例 | 连续整数索引、元素类型 |
| `StdMap(K, V)` | `std::map(K, V)` | PHPX Box，C++ 哈希映射 | 键和值类型 |
| `StdOrderedMap(K, V)` | `std::orderedMap(K, V)` | PHPX Box，C++ 有序映射 | 键和值类型 |
| `StdList(T)` | `std::list(T)` | 普通 PHP array，保留 COW | 整数键、值类型、允许追加 |
| `StdDict(K, V)` | `std::dict(K, V)` | 普通 PHP array，保留 COW | 键和值类型、必须显式提供键 |
| `StdFunc(R, args)` | 已有函数或闭包值 | 拟议的带签名 callable | 参数、返回值、引用与缺省调用规则 |

`std::array` 和 `StdArray` 类型注解均已实现。后者声明参数或属性契约，不创建容器值。

所有创建值的工厂还支持单个非空 PHP array 初始化参数：`std::array($values)`、`std::vector($values)`、`std::map($values)`、`std::orderedMap($values)`、`std::list($values)`、`std::dict($values)`。编译器从任意可静态定型的 key/value 表达式推导契约；类型必须完全一致，key 只能是确定的 Int 或 Str，key/value 为 var/any/mixed 时拒绝。StdArray 另外推导并验证矩形维度；空数组必须改用显式类型工厂。

不同存储模型不能因为类型参数相似就互换。Box 不等于 PHP array；类型注解也不改变 PHP 原生类型系统。

## 声明与 PHP 类型兼容

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

类型注解提供完整契约；PHP 类型可以省略，或者只声明兼容的存储类型：

- `StdVector` / `StdMap` / `StdOrderedMap`：`box`。
- `StdArray`：省略 PHP 类型或声明为 `box`，不使用 PHP `array` 存储类型。
- `StdList` / `StdDict`：`array`。
- 拟议 `StdFunc`：`callable` 参数；回调本身可空时需要相容的 Nullable 声明，见专项设计。

显式 `mixed`、`any` 和其他不兼容类型不能与这些类型注解组合。`Type::Any` 作为容器元素类型是另一个概念，不能据此允许 `mixed $parameter`。

同一个声明只能有一个主类型注解，不能重复或叠加不同容器种类。现有容器参数/属性类型不支持 Nullable、联合类型；`StdArgInfo` 的修饰能力属于新的函数签名设计，不能倒推为现有声明语法已经支持。

## C++ 容器的索引与生命周期

- `std::array` 长度固定，索引必须在 `[0, N)`。已知整数字面量索引进行编译期检查；其他索引仍由运行时 `safeIndex()` 检查。
- `std::vector` 长度可变，不做编译期长度追踪。索引读写均检查 `[0, size)`；`$v[size] = ...` 不是追加，`$v[] = ...` 才是追加。
- array/vector 严格禁止空洞；`unset($v[$i])` 重置默认元素，不移除位置。
- map/orderedMap 的键只支持 Int 或 Str（String 为别名），访问遵循现有 C++ 容器规则，不能套用 PHP dict 的数字字符串键规则。
- 现有 Box 参数入口检查 Box、容器种类和元素类型，恢复具体 C++ 引用；不复制整个容器或逐项转换元素。修改内容对调用者可见。
- 现有 Box 参数不支持引用、可变参数、默认值和属性提升；Native 对象不能借此跨越 Box 边界。
- 容器迭代与结构修改继续受既有静态限制及运行时别名保护约束。

## StdArray：使用维度数组描述嵌套

**特殊性备注：StdArray 的类型注解方式与其他容器不同。** StdVector/StdList 只描述元素类型，StdMap/StdOrderedMap/StdDict 描述 key/value 类型；StdArray 必须同时描述叶子元素类型和固定形状，其第二个实参是长度或维度数组，不是 key 类型或另一个容器类型。只有 StdArray 支持结构性嵌套，因此不能直接复用其他容器的类型实参数量及解析规则；声明检查、类型匹配、缓存和 stub 均需保留维度信息。

不使用递归 `new StdArray(new StdArray(...), ...)` 描述，也不允许以其他容器类型作为叶子类型。声明为：

```php
function process(#[StdArray(Type::Int, 100)] box $values): void {}
function matrix(#[StdArray(Type::Int, [100, 200, 8])] box $values): void {}
```

第二个实参为一个长度，或者非空维度数组。维度按外层到内层排列，`[100, 200, 8]` 表示 `100 × 200 × 8`，其访问形式是 `$values[$i][$j][$k]`，各层分别检查 100、200、8 的边界。

- `StdArray(T, 100)` 与 `StdArray(T, [100])` 规范化为相同类型。
- 保存叶子类型、解析后的类名及统一外到内的 `dimensions`；类型比较包含维度数量、顺序和每层长度，不比较元素总数来判断相等。
- 维度必须是编译期整数字面量，不能使用动态变量、常量表达式或函数调用，不能为负数；零长度维度与 C++ `std::array<T, 0>` 一致。编译器检查总元素数和估算字节数溢出。
- 只有 StdArray 支持结构性嵌套；多维类型降低为现有嵌套 C++ `StdArray`，不修改底层存储。现有创建值的嵌套 `std::array(...)` 工厂语法保持不变。
- 每消费一层索引，剩余维度组成子数组类型，例如三维容器的 `$values[$i]` 为 `StdArray(T, [200, 8])`。
- 简化声明语法不消除子数组所有权问题。本次实现只恢复完整 StdArray 参数；共享借用、子数组参数、引用赋值和子数组裸指针传递仍需独立生命周期设计，不因维度数组语法自动开放。
- 嵌套元素默认初始化必须递归保留完整形状。如果后续允许 Optional 的 StdArray，缺省值是该形状的默认初始化容器，不是普通 `[]`；创建、类元素初始化与生命周期仍需验证。

StdArray 参数入口校验 Box、容器种类、叶子类型和完整形状，然后恢复具体 C++ 引用；属性保存同一契约，但属性读取仍遵循下文的现有恢复边界。声明缓存和 library stub 保留维度。子数组作为独立参数的传导尚未开放。

## StdList / StdDict 的键和值

强类型 PHP 数组的约束主要在静态阶段建立，不增加运行时强类型数组对象，也不修改 PHP HashTable。显式 `toStdList()` / `toStdDict()` 转换使用 PHPX 的 `toTypedArray()` 逐项校验来源数组。

- list 是整数键 PHP 数组，允许负数、稀疏键和空洞，不是连续序列；支持 `[]` 追加。
- dict 的键只能声明为 Int 或 Str，必须显式提供键，包括整数键 dict 也不允许追加。
- list 与整数键 dict 的存储相同，但类型契约不同，不能作为参数互换。
- 非 `var/any` 键必须与声明类型匹配，否则编译错误，不隐式转换。
- `var/any` 键生成 PHPX 内部 `toExactInt` / `toExactString` 严格取值检查；生成辅助调用可通过现有 `php::toIntExact` / `php::toStringExact` 封装，不向用户暴露新关键词方法。用户显式转换仍使用 `toInt()` / `toString()`。
- 字符串 dict 保留 PHP 数字字符串键的底层规范化行为；`foreach` 取 key 时强转回 Str，保证遍历变量类型明确，不要求底层 HashTable 永远使用字符串键。
- 值支持 PHP 值类型和非 Native 的 `MyUser::class`。值写入遵循静态赋值兼容规则；`Type::Any` 值保留动态类型，不自动信任任意 `var` 为具体类型。
- `foreach` 的 key/value 类型明确：list key 为 Int，dict key 为其声明类型，value 为声明值类型。

## 类型传导、参数与别名

局部 list/dict 的普通赋值传导契约并保持 PHP COW；引用赋值传导相同契约及别名关系。向 TypePHP 函数或方法传递时，目标必须有同种、同 key/value 类型的类型注解；按引用传递也必须匹配。

静态可解析且无钩子的 `StdList` 或 `StdDict` 属性在直接读取下标时使用声明的元素类型。按值 `foreach` 会把声明的键和值类型传给存储类型兼容的简单局部变量。属性可以被替换，所以读取时只校验当前元素，无需扫描整个数组。缺失的键可用 `isset` 或 `??` 处理。按值复制整个属性仍得到普通 PHP 数组，保留写时复制。

目标设计要求属性读取同样传导契约，例如：

```php
$box = $obj->values; // 目标：继承 StdVector(Type::Int)
```

当前不能把这段目标行为当作完整实现：属性已保存类型元数据，但 Box 属性到局部容器的自动恢复、持有者生命周期、动态替换检查，以及 PHP-array 属性的封闭写入保护仍需实现和验收。不得直接借用一个可能被替换或销毁的属性所持 C++ 容器引用。

Box 内容共享与普通 PHP 数组 COW 不同；赋值、参数传递、引用不能统一降低为一种复制策略。

## 动态 PHP 安全边界

目标是不让动态代码破坏静态契约，而不是在每次读值时重新扫描整个数组。

- 强类型 PHP 数组允许经审查的只读 array 内置函数和 TypePHP 读取操作，例如 `array_search`、`count`。
- 禁止 `array_push`、排序等动态修改，以及可能借引用或 callback 修改数组的路径；“返回值只读”不等于“调用无副作用”。
- 禁止 `std::ref()`、元素引用、动态引用传递等逃逸。不能通过一个普通 callable 或函数参数类型 `array` 恢复可信的 list/dict 契约。
- 只读操作的数组结果通常是普通 PHP 数组，除非另外证明并传播结果契约。
- TypePHP 禁止显式取得静态可解析的强类型数组属性或其元素的引用。注解仍允许替换整个属性，也无法阻止动态代码通过任意 Zend 对象修改数组，因此读取时要先校验元素再使用其声明类型。
- `StdFunc` 中出现 list/dict 不豁免这些规则；回调目标及桥接路径必须保留契约，不能直接走会丢失元数据的普通 Zend Bridge。

历史 `ArrayDef` 已删除，统一使用 `StdList` / `StdDict`。其旧空洞限制不再适用于 PHP 数组模型，也没有删除独立的 C++ array/vector 边界检查。

## 编译器元数据与验收

契约使用规范化结构，至少包含种类、key/value 类型和解析后的类名；声明缓存不保存某次构建临时分配的类型 ID。声明、参数、属性、局部变量、导出 library stub 和依赖失效应共享同一契约语义。

新增嵌套描述对象通过白名单 AST 解析，不执行用户函数或构造函数。容器值、类型描述和 PHP 原生类型必须在 IR 与诊断中区分。

验收至少覆盖兼容/冲突 PHP 类型、种类不匹配、类类型、动态键严格检查、字符串 dict 遍历、COW 与引用别名、array/vector 边界、只读与动态修改边界、属性替换和生命周期、缓存及 stub 往返。尚未完成的属性保护不能因新增文档而标记为已实现。
