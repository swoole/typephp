# AOT 与 PHP 不兼容特性清单

本文档只记录当前 AOT 编译器与标准 PHP 不兼容或受限的关键特性。

## 程序结构

- 全局作用域不允许可执行语句；只允许声明、`use`、`declare`、常量定义等静态结构。
- 函数和方法内部不允许声明函数。
- 函数和方法内部不允许声明具名类。
- 二进制模式必须定义全局 `main()`。
- `main()` 只允许无参数，或 `(int $argc, array $argv)`。
- `main()` 必须返回 `void`。

## 声明与类型

- 不支持可变变量 `$$var`。
- 暂不支持 PHP 8.5 `#[NoDiscard]`。
- 支持 PHP 8.5 `(void)` 显式丢弃语句；操作数仍会求值并保留副作用，不能在赋值、返回、参数或条件等值上下文中使用。
- PHP 8.5 `clone()` / clone-with 依赖实际链接的 `libphp` 版本不低于 8.5。公开、动态、private/protected/readonly 和 property hook 属性，以及调用顺序、错误传播和 callable 路径均有 PHPT 覆盖。
- PHP 8.4 property hooks 会编译为 AOT getter/setter，并注册对应的 Zend hook 元数据；直接属性读写、Reflection 和对象遍历均受支持。当前不支持对 hook 属性取引用。
- Interface property hook 暂不支持为 `set` 显式声明参数；应使用隐式的 setter value 参数。
- PHP 8.4 Reflection Lazy Object 不能用于 TypePHP AOT 类。AOT 类以 persistent internal class 注册，而 Zend 的 `zend_object_make_lazy()` 明确拒绝 internal class；运行时动态加载的 ZendPHP user class 不受此限制。
- 支持 `private(set)` 与 `protected(set)` 非对称属性可见性，包括 constructor property promotion；Zend-backed 对象通过 PHP 8.4+ 类级 object handler 执行作用域检查，并保留 promoted/set visibility/implicit final 反射标志；Native 对象通过编译期访问检查执行同等作用域规则。
- 支持 final constructor property promotion，但 TypePHP 要求同时显式声明 `public`、`protected` 或 `private`；不接受 PHP 8.5 的 `final int $value` 隐式 public promotion 写法。该语法作为 TypePHP 扩展不受所链接 `libphp` 的源码语法版本限制，使用 PHP 8.4 `libphp.so` 时仍然可用。
- TypePHP 禁止在全局或命名空间常量声明上使用 attributes；PHP 8.5 global constant attributes 不在支持范围内。class constant attributes 不受此限制。
- `.stub.php` 只声明由外部 C++ 提供的 Zend ABI 符号，禁止对其中的类使用 `#[Native]`；Native Class 的对象布局必须由 TypePHP 编译器生成并持有。
- 不支持闭包或箭头函数按引用返回。
- 暂不支持 PHP 8.5 在全局常量、类常量、参数默认值或属性默认值中使用 `static function`；初始化表达式内嵌套的闭包同样会在编译期被拒绝。
- `__construct()` 不允许返回值。
- 参数默认值不允许出现在必填参数之前（`PHP`允许，但会直接丢弃此默认参数）。
- 已知编译期签名的普通函数和方法支持引用可变参数 `&...$args`，包括直接参数、命名参数和参数展开；动态 Closure 暂不支持声明引用可变参数。
- 联合类型、交叉类型、`nullable` 类型仍以 `mixed/any` 作为 C++ 表示，但静态阶段会利用已知表达式类型提前拒绝确定不兼容的参数、返回值和属性赋值；动态值仍保留运行时 type check。
- 局部变量类型一旦被静态推断为具体 native 类型，不支持在同一作用域内重新赋值为不兼容类型。

## declare

- 不支持 `declare(ticks=...)`。
- `declare(encoding=...)` 只允许 `UTF-8`。
- TypePHP 始终使用严格类型。`declare(strict_types=1)` 可兼容接受但没有作用；`strict_types=0` 会被拒绝。
- 不支持其他 `declare` 指令。

## 调用与引用

- `exit(message: $value)` 可作为 TypePHP named-argument 扩展使用；它与位置参数 `exit($value)` 进入同一退出路径。
- TypePHP 使用严格参数数量规则：非 variadic 函数不接受声明范围之外的额外参数；`func_get_args()` 不会隐式放宽签名。
- 已知签名的普通函数、普通方法和 native 直调支持引用参数及写回；不要把编译器内部跨 Trait 动态分派的限制误写成“TypePHP 不支持引用参数”。
- 闭包和箭头函数支持固定引用参数。Closure 调用属于动态分派，调用方仍须通过 `std::ref()` / `toRef()` 显式标记引用参数；由 Zend 发起 callback 时则会自动使用编译器生成的 Closure arginfo。
- 引用赋值不支持从复杂静态属性表达式建立引用。
- 动态调用、闭包调用等编译期无法确定参数签名的调用，不能自动转换引用参数；需要显式使用 `std::ref()` 或等价关键词方法 `toRef()`。
- `std::ref()` / `toRef()` 只接受变量、数组元素或对象属性。
- 固定 `int`、`float`、`bool`、`string`、`array` 局部变量支持受限的原生引用模型。
  `$alias =& $value` 这类函数顶层的一次性绑定会生成 C++ `T&`；静态可解析的 TypePHP
  调用中，精确的 `int/string/float/bool/array &$arg` 同样直接传递 `T&`，不装箱也不
  创建 Zend reference。由于 C++ 引用不能改绑或逃逸，条件/循环内首次绑定、重新绑定、
  `unset`、按引用返回局部变量，以及把引用保存到属性、数组或全局槽位均会在编译期拒绝。
  Closure 的 `use (&$value)` 是一个显式退化边界：编译器会在解析函数体前发现捕获，并从
  第一次赋值开始把该局部变量保存为 `php::Var`，使逃逸闭包能够持有正常的 Zend reference。
  强类型参数保持原调用 ABI，并在函数入口复制到同名的 `php::Var` 本地槽位。
- 动态函数、动态方法和 Closure 调用仍须显式使用 `std::ref()` / `toRef()`。TypePHP
  为本次调用建立 Zend reference，返回时校验类型并写回；动态代码若把临时引用保留到
  调用之外会得到明确错误。需要完整 PHP 引用身份时，应以 `std::any()` 初始化局部变量，
  继续使用既有 `php::Var` / `php::Ref` 动态路径。
- 普通 object、resource/stream 和高精度局部变量在被 Closure 按引用捕获时同样退化为
  `php::Var`。Native object 和 `std` 容器不能安全丢弃其编译期存储布局，仍禁止这种捕获；
  Typed Property 仍可取引用，Zend 会附加属性 type source；PHP 数组元素仍是可取引用的
  动态槽位。
- 带 unpack 且尾部追加 named arguments 的调用会退化为动态调用，不能使用 native call。

## 对象模型

- `toInt()`、`toString()`、`toArray()` 等保留关键词方法先于普通对象方法解析；需要参数的同名业务方法不按普通对象方法语义调用。
- `toAny()` 和 `toRef()` 是不可覆盖的 TypePHP 关键词方法，普通 class-like 声明不得定义同名方法（方法名按 PHP 规则大小写不敏感）。Native class 仅可显式定义返回 `mixed/any` 的 `toAny()` 转换方法，不提供隐式转换；Native class 不支持 `toRef()`。
- 固定值类型属性未显式初始化时使用类型零值，不保留 ZendPHP 的完整 uninitialized 状态；因此 `??` 等依赖 uninitialized 状态的表达式可能不同。
- 禁止子类用同名 `private` 属性隐藏父类私有属性；`public` / `protected` 同名声明视为同一个继承 property slot，仍须满足类型、可见性和 `readonly` 兼容性要求。
- typed property 的动态写入路径仍执行 strict type check；右值类型在编译期无法确定时会保留运行时检查，而不会退化为 Zend 弱类型标量转换。
- Native Class 使用独立的固定布局对象模型，不能被当作普通 Zend Object、PHP array key/value 或任意 `mixed` 值使用，并限制动态成员、引用、static/readonly 成员及部分操作符。完整边界参见 [Native Class Object 设计](NATIVE_CLASS_OBJECT.md)。

## 表达式与控制流

- 静态类型明确为 `array` 时，PHP 不支持的算术、位运算及其复合赋值会在编译阶段报错，
  包括 `array + int/float/bool/string/null/resource/object`、`array * array` 等。
  `array + array` 与 `array += array` 使用 PHP 并集规则：保留原有键和顺序，重复键采用左侧值。
  `array + mixed` 等可能合法的组合仍在运行时检查；数组比较和逻辑运算不受此限制。
- 动态维度写入使用 PHPX 的 array/object/string 抽象。当 key 表达式、
  `ArrayAccess` 回调或右值在读写阶段之间重新绑定 container/key 时，不承诺
  复刻 ZendVM 的全部行为。不支持的标量 container 会抛出稳定、统一的 PHPX
  错误，而不是复刻 Zend 的全部转换、废弃警告和诊断细节。
- TypePHP 明确将 null 数组 key 视为追加操作，而不是转换为空字符串 key。
- `match` 的 arm condition 不能是 `match` 表达式。
- `foreach` by reference 的 value 只能是变量。
- `foreach` list destructuring 不支持按引用绑定元素。
- 非 `int/bool` lowering 路径中的非空 `switch` case 必须以 `return`、`break`、`continue`、`exit` 或 `throw` 结束；不要依赖 PHP 的隐式 case fallthrough。当前 `int/bool` native switch 路径仍可保留 C++ fallthrough，因此项目代码应统一显式终止每个非空 case。
- `std::vector`、`std::map`、`std::orderedMap` 在 `foreach` 期间禁止追加、插入、`unset()` 或整体替换；已有元素的非结构性更新仍可使用赋值运算符完成。
- 固定 native typed object property 不允许按 PHP 未初始化语义自由 `unset()`。
- native 类型变量执行 `unset()` 不会产生标准 PHP 的变量删除语义。

## 运行时动态能力

- 普通 Zend 对象和动态类表达式支持运行时 `::class` 与 class constant lookup；Native Class 仍要求相关类目标能够在编译期确定。
- `static::class` 在需要编译期常量类名的位置不支持。
- `__CLASS__` 只允许在 `class` 定义的代码段中使用（`PHP`允许，返回空字符串）。
- `__TRAIT__` 只允许在 `trait` 定义的代码段中使用（`PHP`允许，返回空字符串）。
- 动态属性链、动态类名、动态函数名和动态回调会统一走 Zend runtime fallback，不保证 native 优化；动态调用的引用参数仍需显式使用 `std::ref()` 或 `toRef()`。
- 不支持 `Closure::bind()`、`Closure::bindTo()` 和 `Closure::call()`；闭包不能在 AOT 代码中重新绑定对象或 class scope。
- 所有源文件必须是 `UTF-8` 编码。

## Generator

TypePHP generator 使用 `FiberGenerator` 而不是 Zend `Generator`，因此不能依赖 `instanceof Generator`、`ReflectionGenerator`、Zend Generator 的内部对象布局或完全相同的异常栈。Generator 不支持按引用返回、by-reference yield、按引用 `foreach`、引用参数或 variadic 参数；WASI target 暂不支持 Fiber 和 Generator。完整边界参见 [Generator](YIELD_GENERATOR.md)。
