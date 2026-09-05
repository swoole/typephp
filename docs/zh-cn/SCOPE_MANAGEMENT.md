# TypePHP 作用域管理设计

本文是 TypePHP 与 PHPX 的内部实现文档，说明当前三种作用域管理器的职责、实现方式、生命周期、性能特征和适用场景。这里的“作用域”并不是同一个 Zend 概念：callable 解析、执行帧类作用域和 `EG(fake_scope)` 分别服务于不同子系统，不能相互替代。

## 1. 设计目标

TypePHP 生成的 C++ 方法并不是普通 Zend user function。动态调用回到 ZendVM 时，Zend 仍然需要以下信息才能复现 PHP 的可见性规则：

- 声明方法的词法作用域，用于判断 private/protected 成员是否可访问；
- 当前 late static binding 的 called scope；
- 当前实例 `$this`，用于解析非静态方法 callable；
- 某些 Zend 属性、对象和异常 API 所读取的 `EG(fake_scope)`。

Scope 设计遵循以下原则：

1. 优先显式传递作用域，不修改 Zend 的全局或真实执行帧状态。
2. 一个 AOT 方法调用期间只创建一次可复用的 callable context，循环中的多次调用共享它。
3. 只有编译器无法确定 callback 位置时，才临时修改最近的 user-code frame。
4. 修改 Zend executor 状态时必须使用 RAII，并保证异常路径恢复。
5. 不为纯 Native Call 或 public、绝对定位的 callback 支付额外包装成本。

## 2. 总览

| 管理器 | 管理的状态 | 主要用途 | 是否修改 Zend 当前状态 |
| --- | --- | --- | --- |
| `php::CallableScope` | synthetic `zend_execute_data`，包含 lexical scope、called scope 和 `$this` | 动态方法调用、first-class callable、内置函数 callback | 否 |
| `php::UserCodeScopeGuard` | 最近 user-code frame 的 `zend_function::common.scope` | `call_user_func*` 及 callback 隐藏在参数展开中的动态调用路径 | 是，析构时恢复 |
| `php::FakeScopeGuard` | `EG(fake_scope)` | Zend 属性、对象、异常等读取 fake scope 的 API | 是，析构或显式 `restore()` 时恢复 |

选择规则可以简化为：

- 能拿到明确 callable 值：使用 `CallableScope`。
- 调用 `call_user_func*`，或其他内置函数的 callback 藏在 `...$args` 中：使用 `UserCodeScopeGuard`。
- 调用的 Zend API 明确读取 `EG(fake_scope)`：使用 `FakeScopeGuard`。
- 纯 native 调用或不依赖调用者可见性的操作：不创建任何 Scope 管理器。

## 3. `php::CallableScope`

### 3.1 职责

`CallableScope` 是当前普通 callable 解析的主路径。它将调用者上下文显式交给 `zend_is_callable_at_frame()`，用于：

- 解析 private/protected 方法；
- 解析 `self`、`parent`、`static` callback；
- 保留 late static binding 的 called scope；
- 为非静态方法提供真实 `$this`；
- 在不修改 `EG(current_execute_data)` 和真实执行帧的前提下调用动态方法。

它不负责属性访问，也不会设置 `EG(fake_scope)`。

### 3.2 内部结构

类定义在 PHPX 的 `include/phpx.h` 中，持有：

```cpp
zend_function *caller_function_;
zend_class_entry *called_scope_;
zend_object *this_object_;
mutable zend_execute_data frame_{};
```

构造时通过 `zend_vm_init_call_frame()` 初始化一个 synthetic frame：

- `caller_function_->common.scope` 是 lexical scope，即声明当前方法的类；
- `called_scope_` 是运行时 called scope；
- 实例调用设置 `ZEND_CALL_HAS_THIS` 并携带真实 `zend_object *`；
- 静态调用不携带对象，只传 called scope；
- 若 called scope 为空，则回退到 lexical scope。

解析时调用：

```cpp
zend_is_callable_at_frame(callable, object, &frame_, 0, cache, error);
```

synthetic frame 不会安装到 `EG(current_execute_data)`，因此不会污染当前 Zend 调用栈，也不需要在退出时恢复全局状态。

### 3.3 生命周期与所有权

`CallableScope` 不拥有 `zend_function`、`zend_class_entry` 或 `zend_object`，只在当前 AOT 方法栈帧内借用这些指针：

- TypePHP 编译方法使用 persistent `zend_function`，其生命周期覆盖请求调用；
- Closure 的 `zend_function *` 在 Closure 对象存活期间有效；
- `$this` 在当前方法执行期间有效；
- `CallableScope` 不可复制、不可移动，防止 synthetic frame 被意外转移或跨生命周期保存。

不得把 `CallableScope` 缓存到请求之外，也不得让它比所属方法或 Closure 活得更久。

### 3.4 编译器生成方式

编译器通过 `FunctionContext::$callableScopeVar` 延迟申请 Scope 变量。第一次需要显式 callable scope 时，`getCallableScopeExpr()` 分配临时变量；随后 `genScopeVarDecl()` 将初始化代码提升到函数入口：

```cpp
php::CallableScope tmp_var_1 = php::getCallableScope(
    get_persistent_method(...),
    this_
);
```

`php::getCallableScope()` 根据 `this_` 同时构建 called scope 和真实实例信息。一个方法内所有 scoped call 都引用同一个 `tmp_var_1`，因此循环中的重复调用不会重复创建 synthetic frame。

如果方法从未使用 scoped dynamic call、first-class callable 或 scoped callback，编译器不会生成该变量。

### 3.5 使用入口

#### `typephp_call_cached()` / `typephp_call_method_cached()`

TypePHP 为每个未解析的函数或对象方法调用点分配一个 request 级 slot。字符串 callable 缓存解析后的 `zend_function`；方法调用还会校验 receiver class。非字符串 callable、临时 magic trampoline，以及相对的 `self::` / `parent::` / `static::` 字符串仍走 Zend 完整解析路径。

#### `typephp_call_method_scoped_cached()`

用于动态对象方法调用。它使用 `CallableScope::resolve()` 获取 `zend_fcall_info_cache`，将允许缓存的结果保存在 request 级调用点 slot 中，然后执行 `zend_call_function()`。

典型场景是编译器无法将对象方法解析为 Native Call，但仍需保留当前类的 private/protected 访问权。缓存命中必须同时匹配目标类、方法名、lexical scope、called scope 和调用方 `$this` 的类；trampoline 或发生变化的调用点仍执行完整 scoped resolution。

#### `php::makeScopedCallable()`

用于 first-class callable 语法。此语法的结果必须是一个真正的 `Closure`，所以即使目标方法是 public，也不能只返回原始 callback 数组或字符串。

```php
$callback = self::privateMethod(...);
$callback = $this->publicMethod(...);
```

普通方法通过 `zend_create_fake_closure()` 创建 Closure。若 Zend 返回 `ZEND_ACC_CALL_VIA_TRAMPOLINE`，则使用转发 Closure 保留 magic `__call()` / `__callStatic()` 的动态语义。

#### `php::prepareScopedCallback()`

用于向 `array_map()`、`usort()` 等 PHP 内置函数传递 callback。这里的目标只是让内置函数正确调用 callback，不要求参数本身变成 Closure。

因此它会优先复用以下 callback 的原始值：

- public 方法；
- 使用绝对类名定位；
- 不依赖 trampoline。

只有 private/protected 方法、`self` / `parent` / `static` 相对 callback 或 trampoline 才创建 Closure。这避免了循环中每次调用内置函数都无条件分配 fake Closure。

### 3.6 为什么仍要运行时识别 `self` / `parent` / `static`

直接语法中的 `self::class` 可以在编译期展开为具体类名，但 PHP callback 也允许动态值：

```php
$class = 'self';
$callback = [$class, 'method'];
```

此时只有运行时才能知道数组中的类名是否为相对类名。因此 `isRelativeCallableClass()` 不能完全移到编译期。对于已知的绝对 public callback，该检查会很快返回 false，并复用原值。

## 4. `php::UserCodeScopeGuard`

### 4.1 职责与适用范围

`UserCodeScopeGuard` 服务于完全动态的 `call_user_func()` / `call_user_func_array()`、callback map，以及编译器无法静态改写 callback 的参数展开场景。

```php
$args = [[$this, 'privateMethod'], 1];
call_user_func(...$args);
```

内置函数 callback 可能位于固定位置、倒数位置、命名参数中，甚至一个函数有多个 callback。执行 `...$args` 展开前，编译器并不知道最终的 positional/named 参数布局，无法只对对应值调用 `prepareScopedCallback()`。

`call_user_func*` 本身就是 ZendVM 的完全动态调用边界，无论 callback 是否显式出现，都不创建 fake Closure。如果 callable 数组使用 `self`、`parent` 或 `static`，则先由 `normalizeCallableClass()` 将 class 部分转换为真实类名：

- `self` 转为 `CallableScope::lexicalScope()`；
- `parent` 转为 lexical scope 的父类；
- `static` 转为 `CallableScope::calledScope()`。

规范化只复制需要修改的 callback 数组。绝对类名、对象 callback、Closure 和普通函数名保持原值。

`preg_replace_callback_array()` 是 callback map 的特例。Zend 会在函数内部逐项解析 map 中的 callback；若提前包装 map，则每次调用都要执行 O(N) 扫描，并可能触发数组 COW 和多个 Closure 分配。因此编译器保留原始 map，在方法入口创建一次 `UserCodeScopeGuard`，让 Zend 直接按正确作用域解析。

除完全动态调用、callback map 和参数展开外，普通 callback 参数不得使用此 guard；只要单个 callback 的 AST 参数位置已知，就应使用 `CallableScope` 路径。

### 4.2 实现方式

构造函数从 `EG(current_execute_data)` 开始向上查找最近的 user-code frame，并跳过 internal frame：

```cpp
while (frame && (!frame->func || !ZEND_USER_CODE(frame->func->type))) {
    frame = frame->prev_execute_data;
}
```

找到后保存，并使用 `CallableScope::lexicalScope()` 设置可见性作用域：

```cpp
function_ = frame->func;
previous_scope_ = function_->common.scope;
function_->common.scope = callable_scope.lexicalScope();
```

析构函数恢复 `previous_scope_`。类不可复制、不可移动，保证一次构造对应一次恢复。如果没有可用的 user-code frame，会抛出：

```text
A user-code frame is required for scoped dynamic callback calls
```

该 guard 操作的是从当前请求执行链找到的 user-code frame，不是 TypePHP 注册在 MINIT 的 persistent internal method。`EG(current_execute_data)` 本身属于当前 executor 上下文。其影响窗口被限制在当前 AOT 方法调用的 RAII 生命周期内。

### 4.3 编译器生成方式

编译器维护语义明确的标记：

```php
FunctionContext::$needsUserCodeCallableScope
```

当编译器遇到 `call_user_func*` 的动态 callback，或一个已知会同步调用 callback 的内置函数存在无法匹配的参数展开时，`markUserCodeCallableScope()` 设置该标记。状态属于当前 `FunctionContext`，因此普通方法、嵌套 Closure 和 Fiber 各自独立，不会把 guard 错误泄漏到外层函数。每个函数体入口只生成一个：

```cpp
php::CallableScope tmp_var_1 = php::getCallableScope(..., this_);
php::UserCodeScopeGuard tmp_var_2{tmp_var_1};
```

即使调用形态是 `call_user_func($closure)`，且 Closure 内部再次通过
`call_user_func(['self', 'method'])` 调用，每一层也只读取自己的
`FunctionContext`、lexical scope 和 `$this`，不能复用或污染外层 guard。

它不是按 call site 或循环迭代创建的。没有上述动态 callback 的方法不会产生此成本。

### 4.4 为什么当前保留该兜底

若完全移除它，编译器必须在参数展开完成后增加一套结构化参数绑定和改写流程，正确处理：

- positional 与 named 参数合并；
- callback 的正向和倒数位置；
- 一个函数的多个 callback；
- callback map；
- unpack 中重复、缺失或覆盖参数时的 PHP 错误语义。

这不是一个局部替换，而是对 `parseCallArgs()` 和参数容器生成流程的中等规模重构。在完成统一的运行时参数后处理机制前，保留范围严格受控的 `UserCodeScopeGuard` 更简单可靠。

## 5. `php::FakeScopeGuard`

### 5.1 职责

`FakeScopeGuard` 是 `EG(fake_scope)` 的 RAII 包装。部分 Zend API 不接受显式调用 frame，而是直接读取 `EG(fake_scope)` 来判断类成员可见性或执行类作用域相关操作。只有这些 API 才应使用它。

当前典型场景包括：

- 动态属性读取、写入和属性 hook；
- Zend object handler 调用；
- 类作用域下的默认值或对象初始化；
- 异常对象相关的 Zend 操作；
- 其他明确读取 `EG(fake_scope)` 的 Zend 内部接口。

TypePHP 的属性访问生成器会通过 `FakeScopeGuard::current()` 将当前 fake scope 传给 PHPX 属性 helper。

### 5.2 实现方式

构造时保存旧值并设置新值，析构时恢复：

```cpp
explicit FakeScopeGuard(Scope scope) noexcept : previous_(current()) {
    EG(fake_scope) = scope;
}

~FakeScopeGuard() noexcept {
    restore();
}
```

`Scope` 通过 `decltype(EG(fake_scope))` 推导，以同时兼容 PHP 8.4 的可变指针和 PHP 8.5 的 pointer-to-const。`restore()` 是幂等操作，可以安全地提前调用一次。

### 5.3 Zend bailout 注意事项

C++ 异常展开会执行析构函数，但 Zend bailout 使用 `longjmp`，不会执行 C++ 析构函数。如果 guard 的生命周期跨越 bailout 边界，必须在对应的 `zend_catch` 路径中显式调用：

```cpp
fake_scope_guard.restore();
```

然后再继续 bailout 或转换异常。仅依赖析构函数处理 bailout 是错误的。

### 5.4 不适用场景

`FakeScopeGuard` 不能替代 `CallableScope`：

- 它没有 synthetic frame；
- 它不能携带 `$this`；
- 它不能完整表达 lexical scope 与 called scope；
- `zend_is_callable_at_frame()` 的解析语义不应通过全局 fake scope 间接模拟。

同样，不能为了“可能需要访问 private”而在整个 AOT 方法入口无条件设置 `EG(fake_scope)`。这会扩大全局状态的影响范围，并让无关的 native 密集调用承担成本。

## 6. 三种 Scope 的调用流程

### 6.1 已知动态方法调用

```text
AOT method entry
  -> lazily generated CallableScope
  -> typephp_call_method_scoped_cached()
  -> 调用点缓存命中，或 CallableScope::resolve()
  -> 缓存未命中时执行 zend_is_callable_at_frame(synthetic frame)
  -> zend_call_function()
```

整个过程不修改真实 Zend frame。

### 6.2 已知内置函数 callback

```text
compiler marks callback argument
  -> prepareScopedCallback(value, CallableScope)
  -> public absolute callback: reuse value
  -> scoped/trampoline callback: create Closure
  -> call PHP internal function
```

first-class callable 使用同一解析基础，但必须调用 `makeScopedCallable()` 并返回 Closure。

### 6.3 参数展开中的 callback

```text
AOT method entry
  -> UserCodeScopeGuard changes nearest user-code frame scope
  -> internal function receives expanded arguments
  -> Zend resolves hidden callback using that frame scope
  -> method exit / C++ exception unwind
  -> guard restores original scope
```

### 6.4 属性或对象 handler

```text
save EG(fake_scope)
  -> install FakeScopeGuard
  -> call Zend property/object API
  -> restore on normal/C++ exception exit
  -> explicitly restore in zend_catch if bailout is possible
```

## 7. 禁止混用与维护约束

1. 不要用 `FakeScopeGuard` 解析 callable。
2. 不要为普通已知 callback 修改真实 user-code frame；使用 `prepareScopedCallback()`。
3. 不要让 `UserCodeScopeGuard` 重新变成所有动态调用的通用入口。
4. 不要在循环中的 call site 重建 `CallableScope`；应由 `FunctionContext` 提升到方法入口并复用。
5. 不要缓存 `CallableScope` 借用的函数、对象或 synthetic frame 到请求之外。
6. 不要把 first-class callable 改为返回原始 callback；其 PHP 结果类型必须是 Closure。
7. 新增会同步调用 callback 的 PHP 内置函数时，需要更新 callback 参数描述表，注明位置、参数名以及是否为 callback map。
8. 保存 callback 但不立即调用的函数不能仅因接收 callable 就标记 scope fallback，例如 `spl_autoload_register()`。
9. 新增跨 Zend bailout 的 `FakeScopeGuard` 用法时，代码审查必须检查 `zend_catch` 是否显式恢复。
10. request 级调用 slot 必须先于项目 request 符号清理而析构；slot 不得持有 Closure 对象、receiver 对象或 trampoline。

## 8. 性能模型

| 路径 | 主要成本 | 优化策略 |
| --- | --- | --- |
| `CallableScope` | 初始化一个 synthetic frame | 每个 AOT 方法最多一次，循环复用 |
| `typephp_call_cached()` | 解析动态字符串 callable | 每个调用点一个 request 级 slot；非字符串及相对 callable 仍保持动态解析 |
| `typephp_call_method_cached()` | 根据运行时对象解析方法名 | 只缓存带 class/name guard 的单态目标；调用点发生变化后禁用 slot |
| `typephp_call_method_scoped_cached()` | 带 guard 的缓存查找；未命中时执行 `zend_is_callable_at_frame()` | 每个动态调用点一个 request 级 slot；可解析的 Native Call 不进入此路径 |
| 稳定静态属性 | 解析类/属性元数据及 Zend slot | 仅在生成的 C++ 函数内惰性缓存最终 `zval*`；每次访问重新构造轻量 `Variant` view |
| `prepareScopedCallback()` | 一次 callable 解析 | public 绝对 callback 不创建 Closure |
| `makeScopedCallable()` | callable 解析及 Closure 分配 | 仅 first-class callable 使用 |
| `UserCodeScopeGuard` | 方法入口一次指针查找、写入和退出恢复 | 只为 `call_user_func*`、callback map 或未解析的 unpack callback 生成 |
| `FakeScopeGuard` | 两次 executor-global 指针赋值 | 仅包围确实读取 fake scope 的 Zend API |

这套设计刻意让常见的纯 Native Call、无 callback 方法和 public callback 保持最短路径。不要为了统一表面形式而把低频 fallback 下沉到所有调用中。

## 9. 测试要求

Scope 修改至少应覆盖以下层次：

- PHPX 单测：`FakeScopeGuard` 保存、嵌套、恢复和提前 `restore()`；
- 编译器结构测试：一个方法只生成一个 `php::getCallableScope()`，多处调用复用同一变量；
- PHPT：private/protected callback、非静态 `self::method(...)`、public callback；
- PHPT：callback map 中 public 与 scoped callback 混合；
- PHPT：`...$args` 中 private callback 可调用，异常退出后 scope 已恢复；
- PHPT：Closure、Fiber、普通方法中的作用域生成路径；
- 回归测试：纯 Native Call 不应生成额外 Scope guard。

当前相关测试包括：

- `phpunit/src/ScopedCallContextTest.php`
- `phpunit/code/scoped-call-context-reuse.php`
- `tests/compiler/place-holder/non-static-self.phpt`
- `tests/compiler/callable/scoped-internal-callbacks.phpt`
- `tests/compiler/callable/unpacked-callback-scope-restored.phpt`
- PHPX `tests/src/scope_guard.cpp`

涉及动态调用抛出异常的 PHPT 可能触发已知 ZendVM 内存泄漏报告；只有确认泄漏来自 Zend 动态调用异常路径时，测试才可局部设置 `USE_ZEND_ALLOC=0`，不能全局关闭内存检查。

## 10. 代码位置索引

| 内容 | 位置 |
| --- | --- |
| `CallableScope` 及 public helper 声明 | `vendor/swoole/phpx/include/phpx.h` |
| callable 解析与包装 | `vendor/swoole/phpx/src/core/base.cc`、`vendor/swoole/phpx/src/core/closure.cc` |
| `FakeScopeGuard` | `vendor/swoole/phpx/include/phpx_fake_scope_guard.h` |
| `UserCodeScopeGuard` | `vendor/swoole/phpx/include/typephp_helper.h`、`src/core/scope.cc` |
| `php::getCallableScope()` | `vendor/swoole/phpx/include/typephp_helper.h` |
| callback 标记和 Scope 变量生成 | `src/CompilerBase.php` |
| callback 参数包装 | `src/Generator/CallArgumentGenerator.php` |
| Closure/Fiber fallback guard | `src/Generator/ClosureGenerator.php`、`FiberGenerator.php` |
| 方法 fallback guard | `src/Translator.php` |
| Scope 状态 | `src/Context/FunctionContext.php` |
| 属性访问中的 fake scope | `src/Parser/PropertyAccessTrait.php` |

## 11. 后续演进原则

`UserCodeScopeGuard` 是复杂动态调用的长期保留机制，不以删除为目标。它修改的是当前线程、当前请求中的 user-code frame，并通过 RAII 恢复；ZTS 下不同线程拥有各自的执行上下文，因此不会共享被修改的 frame 状态。

`CallableScope` 用于编译器能够确定 callback 位置与调用边界的单一场景，以减少 frame 修改和 Closure 包装；它是一条更快、更明确的路径，而不是要求覆盖 unpack、多层动态 callback 等所有场景。遇到难以静态证明安全的组合时，应优先保留 `UserCodeScopeGuard`，不要为了形式上的统一强行改写为 `CallableScope`。

未来新增 Scope 抽象前，应先确认 Zend API 依赖的是 synthetic call frame、真实 user-code frame，还是 `EG(fake_scope)`。名称和类型应直接表达所管理的 Zend 状态，避免再次出现一个含义过宽的通用 `Scope` 类。
