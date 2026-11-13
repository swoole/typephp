# TypePHP change log

## 0.10.0

### Keyword methods can be implemented by classes (type contract fixed)

Starting with 0.10.0, method precedence changed: TypePHP-compiled classes, traits and interfaces may now declare `toInt()`,
`toFloat()`, `toString()`, `toBool()` and `toArray()`. Previously the keyword
methods were fully built in: a same-named method was silently ignored for
`toInt`/`toFloat`/`toString`/`toBool`, and only `toArray` was validated.

The contract can never change, because the type system depends on it (for
example `toArray()` is always `php::Array`). The declaration must be `public`,
non-static, take no parameters and return exactly the keyword's type, not
nullable and not by reference; otherwise compilation fails (this now also
covers interfaces and Native classes for all five names, and the check is
case-insensitive). When the receiver's static class declares such a method,
the call is an ordinary method call and takes precedence over the built-in
conversion. For `toString()` the order is `toString()`, `__toString()`, then
the default conversion.

For `mixed`/`any`/`object` receivers, `toInt`/`toFloat`/`toString`/`toBool` check at runtime (new phpx `typephp_keyword_to_*`): only an object whose class declares the method is dispatched to it, with its signature checked; every other value, including `null`, uses the direct Zend conversion. `php::toInt()` and casts such as `(int)$obj` are unchanged.

`php::toArray()` uses the same shared signature check. The names `toStream`, `toBigInt`, `toBigFloat`, `toDecimal`, `toObject`, `toStdList` and `toStdDict` can no longer be declared by ordinary classes (compile error; Native classes keep their explicit conversion methods). A class loaded at runtime that declares them is silently ignored by the dynamic path.

Extension methods (`MethodsFor`) are bound to the receiver's static class. If a compiled subclass declares a real method with the same name, the extension is still called (as before), but the compiler now emits a warning because this is easy to misread. Subclasses loaded at runtime are not detected.

Object extension calls now check the receiver at runtime (except `$this` and `new`): a null receiver throws PHP's `Call to a member function x() on null` Error instead of passing null to the extension.

String/stream/array methods whose PHP function may return `false` (`base64Decode`, `mbDetectEncoding`, `mbConvertEncoding`, `jsonEncode`, and the stream `write/read/stat/tell/getChar/getLine/getContents/copy/getSocketName/recvFrom/sendTo/getRecord`) now throw a `TypeError` instead of silently coercing `false` (new phpx `php::toExactString/Int/Array()`). `STREAM::enableCrypto()` now returns `var` (`true`, `false` or `0`) instead of a misleading `int`.

`int`/`float` `add/sub/mul/div/mod()`, `max()`, `min()`, `abs()` and (for `float`) `pow()` are now native: the result has the receiver's type (PHP's functions may return the other numeric type) and the argument must have that type (`float` also accepts `int`); `int` `abs(PHP_INT_MIN)` wraps like C++. A `var` argument is checked at runtime (new phpx `php::toExactInt/Float()`) and throws a `TypeError` instead of being coerced; strings are rejected at compile time. `jsonDecodeToObject()` throws a `TypeError` unless the decoded value is an object (new phpx `php::toExactObject()`).

`toAny`, `toRef` and `instanceOf` stay reserved as before. Code that already declared one of the five customizable names with a different signature must now match the keyword contract or rename the method.

---

## 0.8.0

### Breaking: native scalar storage is now the default

`use native_types` has been removed. Inferred `int`, `float`, and `bool`
locals now use `php::Int`, `php::Float`, and `php::Bool` by default. Their
storage type is fixed and the compiler never promotes one of these locals to
`php::Var` merely because a later operation needs dynamic PHP semantics.

Two explicit escape hatches remain:

- `use varint_types` is a file-level mode that stores inferred integer values
  in `php::Var`, preserving PHP's overflow-to-float, non-integral division,
  and other Zend integer arithmetic behavior. It does not box `float` or
  `bool` locals.
- `std::any($value)` erases the static type of that individual expression, so
  a variable initialized from it uses `php::Var` and may participate in PHP
  reference or dynamic-value operations.

For example, `$i = 100` is now permanently an integer local. Reusing `$i` as a
`foreach` key is rejected because PHP keys have type `int|string`; write
`$i = std::any(100)` or select `use varint_types` if this reuse is intentional.
Projects must remove `use native_types` and add the new compatibility mode only
to files that genuinely depend on Zend integer widening semantics.

Fixed `int`, `float`, `bool`, `string`, and `array` locals now use typed native
references. A stable local alias and an exact by-reference parameter on a
statically resolved TypePHP call lower directly to C++ `T&`; this path does not
box the value or allocate a Zend reference. Bindings must be unconditional,
one-time, function-local, and non-escaping. Rebinding, `unset`, reference
capture/return, and storing such a reference into PHP storage are rejected.

Fixed `int`, `float`, `bool`, `string`, and `array` Native Class properties can
now be passed directly to an exactly matching reference parameter on a
statically resolved call. This lowers to a call-scoped C++ `T&` while precisely
rooting the Native receiver. It does not enable general PHP references for the
field: `=&`, `std::ref()`, dynamic calls, and escaping reference forms remain
forbidden. Explicit `any` properties continue to use the dynamic Zend reference
model.

Dynamic calls and Closures retain the existing Zend reference path and require
explicit `std::ref()` / `toRef()`. A call-scoped bridge validates the value on
write-back and rejects an escaping temporary reference. Use `std::any()` for
unrestricted PHP reference identity. Fixed object/resource/stream,
high-precision, Native/typed-object, Box, and `std`-container locals remain
non-referenceable because those types already carry identity/handle semantics.
Typed properties and PHP array elements continue to use Zend references and
their normal type-source behavior.

TypePHP is always strict. Project sources no longer need
`declare(strict_types=1)`; the directive remains accepted as a redundant PHP
compatibility declaration, while `strict_types=0` is rejected.

### Breaking: compile-time API namespace cleanup

TypePHP compile-time APIs now occupy only two global class symbols:

- `Type::*` describes types used by extension-method metadata. It does not
  execute conversions or other built-in operations.
- `std::*` contains TypePHP built-in compile-time functions.

As with PHP class and method names, the `std` / `Type` class names and `std`
method names are case-insensitive. `Type::Int` and the other `Type` members are
class constants, so their constant names remain case-sensitive under PHP rules.

The former global compile-time functions have changed as follows:

| Before 0.8 | Since 0.8 | Notes |
| --- | --- | --- |
| `any($value)` | `std::any($value)` | Erases the static type to `mixed/any`. |
| `refval($target)` | `std::ref($target)` | Explicit reference wrapper for call arguments. |
| `expected($condition)` | `std::expected($condition)` | Emits the `EXPECTED(...)` branch hint. |
| `unexpected($condition)` | `std::unexpected($condition)` | Emits the `UNEXPECTED(...)` branch hint. |
| `objval($value, Foo::class)` | `std::object($value, Foo::class)` | Restores concrete object type information; the equivalent keyword method remains available as `$value->toObject(Foo::class)`. |
| `std::ordered_map($keyType, $valueType)` | `std::orderedMap($keyType, $valueType)` | Uses the standard camelCase spelling for a multi-word method name. |

No compatibility functions are installed in the global namespace. Applications
may define and call their own `any()`, `refval()`, `expected()`, `unexpected()`,
and `objval()` functions without being intercepted by the compiler.

`std::ref()` retains the previous reference-wrapper restrictions: its target
must be a variable, array element, or object property, and it is only valid as a
call argument wrapper. `toRef()` remains the equivalent keyword method.

All projects using the old spellings must update their source and rebuild.

### Compatibility policy before 1.0

TypePHP is still pre-1.0 software. Public compiler APIs, TypePHP-specific source
syntax, generated-code interfaces, configuration, and packaging may change
between minor releases. Breaking changes will be documented here, but users
should review the change log and run their full test suite before upgrading.

---

## 0.10.0（中文）

### 关键词方法可由 class 自行实现（类型契约固定）

从 0.10.0 起调整了方法优先级：由 TypePHP 编译的 class、trait、interface 现在可以声明 `toInt()`、`toFloat()`、
`toString()`、`toBool()`、`toArray()`。此前关键词方法完全内置：`toInt`/`toFloat`/
`toString`/`toBool` 的同名方法会被静默忽略，只有 `toArray` 会被校验。

类型契约不可更改，因为类型系统依赖它（例如 `toArray()` 一定是 `php::Array`）。
声明必须是 `public`、非 static、无参数，且返回类型与关键词完全一致，不可为
nullable、不可按引用返回，否则编译报错（现在这五个名称对 interface 和 Native
class 也同样校验，且不区分大小写）。当接收者的静态类声明了此类方法时，调用按普通
方法处理，优先于内置转换。`toString()` 的顺序为 `toString()`、`__toString()`、
默认转换。

对 `mixed`/`any`/`object` 接收者，`toInt`/`toFloat`/`toString`/`toBool` 在运行时检查（phpx 新增 `typephp_keyword_to_*`）：仅当对象的类声明了同名方法才调用并校验签名；其他值（含 `null`）仍直接走 Zend 转换。`php::toInt()` 及 `(int)$obj` 等强制转换不变。

`php::toArray()` 与其共用同一段签名检查。`toStream`、`toBigInt`、`toBigFloat`、`toDecimal`、`toObject`、`toStdList`、`toStdDict` 现在不允许普通 class 声明（编译报错；Native class 的显式转换方法不受影响）；运行时加载的类若声明了这些名称，动态路径会静默忽略。

扩展方法（`MethodsFor`）按接收者的静态类绑定。若已编译的子类声明了同名真实方法，仍调用扩展方法（与之前一致），但编译器现在会给出警告，避免误解。运行时才加载的子类不会被检测。

对象扩展方法调用现在会在运行时检查接收者（`$this` 与 `new` 除外）：接收者为 null 时抛出 PHP 的 `Call to a member function x() on null` Error，而不是把 null 传给扩展方法。

PHP 函数可能返回 `false` 的字符串/流/数组方法（`base64Decode`、`mbDetectEncoding`、`mbConvertEncoding`、`jsonEncode`，以及流的 `write/read/stat/tell/getChar/getLine/getContents/copy/getSocketName/recvFrom/sendTo/getRecord`）现在抛出 `TypeError`，不再静默转换 `false`（phpx 新增 `php::toExactString/Int/Array()`）。 `STREAM::enableCrypto()` 的返回类型改为 `var`（`true`、`false` 或 `0`），不再误报为 `int`。

`int`/`float` 的 `add/sub/mul/div/mod()`、`max()`、`min()`、`abs()` 以及 `float` 的 `pow()` 现为原生实现：结果类型与接收者一致（PHP 函数可能返回另一种数值类型），参数必须是同类型（`float` 也接受 `int`）；`int` 的 `abs(PHP_INT_MIN)` 与 C++ 一样回绕。`var` 参数在运行时检查（phpx 新增 `php::toExactInt/Float()`），类型不符抛出 `TypeError`，不做转换；字符串在编译期报错。`jsonDecodeToObject()` 在解码结果不是对象时抛出 `TypeError`（phpx 新增 `php::toExactObject()`）。

`toAny`、`toRef`、`instanceOf` 仍为保留名。已用不同签名声明这五个可自定义名称的代码，需改为符合关键词契约或重命名。

---

## 0.8.0（中文）

### 破坏性变更：默认使用原生标量存储

`use native_types` 已移除。推断出的 `int`、`float`、`bool` 局部变量现在默认分别
使用 `php::Int`、`php::Float`、`php::Bool`。这些变量的存储类型一旦确定便不会因为
后续操作需要 PHP 动态语义而被编译器自动提升为 `php::Var`。

只保留两个显式出口：

- `use varint_types` 是文件级模式，使推断出的整数使用 `php::Var`，保留 PHP 的
  整数溢出转浮点、整数除法产生非整数结果等 Zend 算术语义；它不会装箱 `float`
  或 `bool`。
- `std::any($value)` 只擦除该表达式的静态类型。以它初始化的变量使用 `php::Var`，
  可参与引用或其他动态值操作。

例如 `$i = 100` 现在固定为整数局部变量。由于 PHP 的 foreach key 类型为
`int|string`，之后复用 `$i` 作为 key 会在编译期报错；确需复用时，应写成
`$i = std::any(100)` 或在文件中声明 `use varint_types`。升级项目必须删除
`use native_types`，并且只为真正依赖 Zend 整数扩展语义的文件添加新兼容模式。

固定 `int`、`float`、`bool`、`string`、`array` 局部变量现在使用强类型原生引用。
稳定的局部别名，以及静态可解析 TypePHP 调用上的精确引用参数，会直接生成 C++ `T&`，
不装箱、不创建 Zend reference。绑定必须位于函数顶层、只发生一次且不得逃逸；重新绑定、
`unset`、引用捕获/返回，或把引用存入 PHP 槽位都会在编译期拒绝。

Native Class 中固定类型为 `int`、`float`、`bool`、`string`、`array` 的属性，现在也可
直接传给静态可解析调用中类型完全匹配的引用参数。编译器将其生成为仅在本次调用期间
有效的 C++ `T&`，同时精确保活 Native 接收对象；这并不会为字段开放通用 PHP 引用，
`=&`、`std::ref()`、动态调用及其他可能逃逸的引用形式仍被禁止。显式声明为 `any` 的
属性继续使用动态 Zend reference 模型。

动态调用与 Closure 继续使用既有 Zend reference 路径，并要求显式使用 `std::ref()` /
`toRef()`。调用级 bridge 在返回时检查类型并拒绝临时引用逃逸；需要完整 PHP 引用身份时
应使用 `std::any()`。固定 object/resource/stream、高精度值、Native/typed object、Box
和 `std` 容器仍然禁止取引用，因为这些类型本身已有 identity/handle 语义。Typed
Property 与 PHP 数组元素继续使用 Zend reference 及其 type source 约束。

TypePHP 始终使用严格类型，项目源码不再需要 `declare(strict_types=1)`。该声明仍作为
冗余的 PHP 兼容语法被接受，而 `strict_types=0` 会被拒绝。

### 破坏性变更：整理编译期 API 命名空间

TypePHP 编译期 API 现在只占用两个全局类符号：

- `Type::*` 仅描述扩展方法元数据等场景使用的类型，不执行类型转换或其他内置操作。
- `std::*` 承载 TypePHP 内置编译期函数。

遵循 PHP 的类名与方法名规则，`std` / `Type` 类名以及 `std` 方法名均不区分
大小写。`Type::Int` 等成员属于类常量，因此其常量名仍按 PHP 规则区分大小写。

原全局编译期函数迁移如下：

| 0.8 之前 | 0.8 起 | 说明 |
| --- | --- | --- |
| `any($value)` | `std::any($value)` | 将静态类型降级为 `mixed/any`。 |
| `refval($target)` | `std::ref($target)` | 调用参数的显式引用包装器。 |
| `expected($condition)` | `std::expected($condition)` | 生成 `EXPECTED(...)` 分支提示。 |
| `unexpected($condition)` | `std::unexpected($condition)` | 生成 `UNEXPECTED(...)` 分支提示。 |
| `objval($value, Foo::class)` | `std::object($value, Foo::class)` | 恢复具体对象类型；仍可使用等价关键词方法 `$value->toObject(Foo::class)`。 |
| `std::ordered_map($keyType, $valueType)` | `std::orderedMap($keyType, $valueType)` | 多单词方法统一使用 camelCase 命名。 |

TypePHP 不在全局命名空间安装兼容函数。应用可以自行定义并正常调用
`any()`、`refval()`、`expected()`、`unexpected()` 和 `objval()`，编译器不会拦截。

`std::ref()` 延续原引用包装限制：目标必须是变量、数组元素或对象属性，且只能作为
调用参数包装器使用。`toRef()` 仍是等价的关键词方法。

使用旧写法的项目必须修改源码并重新编译。

### 1.0 之前的兼容性策略

TypePHP 目前仍处于 1.0 之前。公开编译器 API、TypePHP 专有源码接口、生成代码接口、
配置和打包方式都可能在次版本中发生变化。破坏性变更会记录在本文件中；升级前请阅读
变更记录，并运行项目的完整测试。
