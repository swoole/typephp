# Native Class 实现验收矩阵

> 审计日期：2026-08-17  
> 本文记录 `#[Native]` 对象模型的需求、实现入口和直接验证证据。它是
> [NATIVE_CLASS_OBJECT.md](NATIVE_CLASS_OBJECT.md) 的实现验收附件，不替代语义设计文档。

## 1. 验收原则

每一项能力必须同时具有：

1. 明确的语言边界；
2. 可定位的编译器或 PHPX 实现；
3. 正向 PHPT、负向 PHPUnit 或 PHPX C++ 单测中的直接证据。

仅有代码、仅有文档或“当前没有发现失败”均不视为完成。Native Object 没有 Zend
表示，因此任何不能静态证明安全的跨边界行为都必须在生成 C++ 前拒绝。

## 2. 对象模型与代码生成

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| `#[Native]` 只用于具名 class | `NativeClassAttributeLowering`、`NativeClassSupportTrait` | `testRejectsNativeAttributeOnInterface/Trait/Enum/AnonymousClass` | 已验证 |
| 不注册 Zend class/object handlers | Native struct、descriptor 和自由函数生成路径 | `clone-and-zend-invisible.phpt`、Reflection 负向测试 | 已验证 |
| 方法保持 `php_*` 自由函数 ABI | Native method/virtual thunk 生成路径 | `basic.phpt`、`chained-call.phpt` | 已验证 |
| 静态可解析的 `new NativeClass()` 使用 Native Heap | `CompilerBase::parseNew()`、`php::nativeConstruct()` | `basic.phpt`、`construction-gc-roots.phpt` | 已验证 |
| `new (表达式)()` 保持普通 PHP 动态实例化 | `parseNew()` 只对 `Node\\Name` 进入 Native 分支 | `testLeavesDynamicClassExpressionsToTheOrdinaryPhpPath` | 已验证 |
| Native 对象本身不能充当动态 class target | `assertNotNativeObjectDynamicClassTarget()` | dynamic new/static call/class constant 负向测试 | 已验证 |
| 所有不支持的用法在编译期终止 | Native 边界检查、类型兼容检查 | 131 项 `NativeClassValidationTest` | 已验证 |

## 3. 属性与固定布局

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| 所有属性必须声明类型 | Native field validation | `testRejectsUntypedProperty` | 已验证 |
| bool/int/float 使用固定值字段 | Native field C++ type mapping | `basic.phpt`、`numeric-properties.phpt` | 已验证 |
| string/array/object/typed object/Stream/mixed 可作为字段 | Native PHPX field mapping、写入检查 | `phpx-properties.phpt`、`stream-property.phpt`、`composite-property-types.phpt` | 已验证 |
| BigInt/BigFloat/Decimal 可作为字段 | 高精度字段映射与 trace/destroy | `high-precision-properties.phpt` | 已验证 |
| Native 类型字段保存裸指针，可形成循环类型 | struct 前置声明、descriptor trace | `mutual-reference-types.phpt`、`gc-cycle.phpt` | 已验证 |
| 未显式初始化字段使用确定零值 | Native field initializer | `zero-values.phpt` | 已验证 |
| 属性写入保持声明类型 | Native property assignment validation | composite、stream 及多项负向 PHPUnit | 已验证 |
| 仅 `any` 属性允许取 PHP 引用 | Native property reference lowering | `any-property-reference.phpt` 及 mixed/fixed property 负向测试 | 已验证 |
| Native 属性不支持 `unset()` | property unset validator | `testRejectsUnsetOnNativeObjectProperties` | 已验证 |
| readonly 属性不支持 | Native declaration validator | `testRejectsReadonlyPropertyUntilNativeWriteStateIsImplemented` | 已验证 |
| Box/Std Container 不能嵌入字段 | Native field validator | Box/Std Container property 负向测试 | 已验证 |

## 4. 身份、空值与调用 ABI

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| `$a = $b` 只复制指针并共享对象身份 | Native pointer local representation | `parameter-semantics.phpt` | 已验证 |
| Native 参数和返回必须显式声明具体类 | call argument/return boundary validation | untyped/mixed/interface 参数与返回负向测试 | 已验证 |
| 普通 Native 参数非空，`?Class` 才可为空 | function entry/return checks | `non-null-parameter.phpt`、`nullable-signatures.phpt`、`return-nullability.phpt` | 已验证 |
| Native 参数、返回和变量禁止 `&` | reference boundary validation | reference parameter/return/assignment/function/method 负向测试 | 已验证 |
| Native variadic、union/intersection signature 不支持 | signature validation | variadic/union/null-union 负向测试 | 已验证 |
| `unset($object)`/`$object = null` 只清当前 pointer slot | Native root slot lowering | `unset-alias.phpt` | 已验证 |
| `===`/`!==` 与 `match` 使用指针身份 | Native identity lowering | `strict-identity.phpt`、`match-identity.phpt` | 已验证 |
| ternary/match/coalesce 为兄弟子类选择最近公共 Native 基类 | `getCommonNativeObjectClass()`、selection pointer cast | `value-selection.phpt`、跨文件 global discovery 测试 | 已验证 |
| 条件表达式检查非空指针，不调用 `toBool()` | Native condition lowering | `conditions.phpt` | 已验证 |
| 松散比较、算术、位运算、增减、复合写入和 switch 禁止 | operator validators | 对应 PHPUnit 负向测试 | 已验证 |
| `isset`/`empty`/`is_null`/nullsafe 保持 typed pointer | Native selection/nullsafe lowering | `isset-empty.phpt`、`is-null.phpt`、`nullsafe.phpt` | 已验证 |
| 调用参数严格从左到右求值并在 safe point 精确 rooting | Native call argument materialization | `call-argument-roots.phpt`、`constructor-argument-roots.phpt` | 已验证 |

## 5. 类语言能力

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| 单继承、abstract 与有限虚分派 | Native C++ inheritance/virtual slot adapters | `abstract-method.phpt`、`polymorphic-clone.phpt`、`virtual-signature-variance.phpt` | 已验证 |
| public/private/protected 在编译期检查 | Native member resolution | `method-visibility.phpt` 及不可访问方法/常量负向测试 | 已验证 |
| Trait 在注入后按普通 Native member 编译 | 现有 Trait AST 注入 + Native member generation | `trait-inheritance-interface.phpt` | 已验证 |
| Interface 仅作编译期契约，不能成为值表示 | interface contract validator | `internal-interface.phpt`、`interface-property-hooks.phpt` 及 interface escape 负向测试 | 已验证 |
| 编译期可解析的 `instanceof` 折叠 | Native instanceof lowering | `instanceof.phpt`、dynamic instanceof 负向测试 | 已验证 |
| Getter/Setter 注解生成直接调用 | annotation lowering + Native method path | `generators.phpt` | 已验证 |
| Property Hook 只支持直接 get/set | Native hook lowering | `property-hooks.phpt`、`property-hook-native-object.phpt` 及间接操作负向测试 | 已验证 |
| `clone` 保持动态子类、PHPX COW 和浅对象语义 | Native clone descriptor/thunk、`php::nativeClone()` | clone 系列 PHPT、`clone-phpx-fields.phpt` | 已验证 |
| `__construct` 仅由 `new` 调用 | Native construction path、显式调用检查 | construction 系列 PHPT、explicit constructor 负向测试 | 已验证 |
| `__destruct` 由 GC 至多执行一次，继承链 derived-to-base | Native finalizer chain | destructor/finalizer/lifecycle 系列 PHPT | 已验证 |
| `__invoke` 和 `__toString` 使用确定 Native Call | Native magic method allow-list | `magic-methods.phpt` | 已验证 |
| 动态魔术方法、变量属性/方法名不支持 | Native magic/dynamic access deny-list | dynamic magic、variable method/property 负向测试 | 已验证 |
| `toArray/toString/toInt/toFloat/toBool/toObject` 要求实体方法、零参数和精确返回类型 | Native keyword method resolution | `keyword-conversions.phpt`、`testNativeObjectToObjectKeywordUsesDeclaredNativeMethod` 及签名负向测试 | 已验证 |
| `count($obj)` 仅在实现 Countable 时特化 | Native count optimizer | `keyword-conversions.phpt`、count-without-countable 负向测试 | 已验证 |
| `ArrayAccess` 直接语法映射到 Native `offset*()` 方法 | Native array access lowering | `array-access.phpt` | 已验证 |
| Native `ArrayAccess` 禁止间接修改和引用 | writable-chain/reference validators | ArrayAccess compound/increment/nested/property/reference/coalesce 负向测试 | 已验证 |
| Native `Iterator` foreach 映射到协议方法，保持 PHP 调用顺序 | Native foreach lowering | `iterator.phpt` | 已验证 |
| `IteratorAggregate` 分流 Native Iterator 与 PHP Traversable | aggregate return-type lowering | `iterator.phpt` | 已验证 |
| Native foreach 不枚举属性且禁止引用遍历 | interface/reference validators | foreach 负向 PHPUnit | 已验证 |

## 6. GC 与生命周期

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| Wren 风格精确、非移动、STW mark-sweep | `phpx/thirdparty/wren-gc`、`native_gc.cc` | PHPX `wren_gc.*` | 已验证 |
| 裸指针写入无 RC、无 write barrier | Native pointer field/local codegen | 生成 C++ 审查、Native PHPT | 已验证 |
| 16 MiB 初始阈值、1 MiB 下限、50% headroom | Wren GC 配置 | `wren_gc.uses_stable_native_heap_defaults` | 已验证 |
| 精确 root frame 保持对象图存活 | `NativeRootFrame`、generated root slots | PHPX root tests、`gc-cycle.phpt` | 已验证 |
| Fiber 非 LIFO 生命周期安全 | root frame registry | `fiber-lifetime.phpt`、`fiber-shutdown.phpt`、PHPX Fiber root tests | 已验证 |
| global/static request roots 在 ZTS 下为 thread-local | generated globals/root registration | ZTS 环境下 `global-and-static.phpt`、PHPX request root tests | 已验证 |
| RSHUTDOWN 清空 root 并销毁 heap | `nativeGcRequestShutdown()` | PHPX shutdown tests | 已验证 |
| finalizer 可复活一次，之后不重复执行 | Wren/Native finalization state | `gc-cycle.phpt`、PHPX resurrection tests | 已验证 |
| finalizer 中分配、异常和 Zend 状态安全 | finalizer queue/exception cleanup | finalizer/lifecycle PHPT、PHPX finalizer tests | 已验证 |
| 构造或克隆失败不产生悬空对象，已逃逸对象保持有效 | `nativeConstruct()`、`nativeClone()` failure paths | `failed-lifecycle-escape.phpt`、`failed-clone-finalizer.phpt` | 已验证 |

## 7. ZendVM 边界与容器

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| Native Object 不能进入 PHP array/object property/mixed | escape and boundary validators | 对应 PHPUnit 负向测试 | 已验证 |
| 不能传给 PHP/ZendVM 动态函数、Closure 或 constructor | call boundary validator | dynamic call、Closure、Zend constructor 负向测试 | 已验证 |
| Reflection/WeakReference/serialize/json_encode 不支持 | facility-specific diagnostics | 对应 PHPUnit 负向测试 | 已验证 |
| Generator 不能保存、接收或产出 Native pointer | generator boundary validator | generator 系列负向测试 | 已验证 |
| 普通函数跨 Fiber suspend 的 Native local 有精确 root | root frame lifecycle | Fiber PHPT | 已验证 |
| 局部 Std Container 可保存具体 Native pointer | Std Container Native value mapping/root frame | `std-containers.phpt` | 已验证 |
| Native Std Container 不能逃逸为 Zend 值、static/global 或 closure capture | container escape validation | Std Container 系列负向 PHPUnit | 已验证 |
| `include`/`eval` 不捕获 AOT local，也不暴露 Native pointer 到 Zend symbol table | 不生成调用者变量导出表；动态源码仅支持独立执行 | `include-native-scope.phpt` | 已验证 |

## 8. 项目级分析

| 要求 | 实现证据 | 测试证据 | 结论 |
|---|---|---|---|
| Native class 前向声明不依赖文件顺序 | declaration discovery pre-pass | `testDiscoversNativeTypesBeforeCrossFileSignaturePreprocessing` | 已验证 |
| global Native slot ABI 在任一 C++ 文件生成前确定 | `NativeGlobalDiscovery`、`NativeGlobalTypeResolver` | `testDiscoversNativeGlobalSlotBeforeEarlierReaderIsConverted`，实际双文件构建 | 已验证 |
| `global $slot` 与静态可解析的 `$GLOBALS[...]` 使用同一 Native root slot | literal/constant global slot lowering、request root registration | `global-and-static.phpt`、跨文件 Closure/常量 `$GLOBALS` fixture | 已验证 |
| 动态 `$GLOBALS[$key]` 不得承载 Native Object | dynamic Zend boundary validation | `testRejectsNativeObjectStoredThroughDynamicGlobalsKey` | 已验证 |
| global slot 固定首个 Native 类型，只允许子类或 null | global registration/type validation | `global-and-static.phpt`、global type change 负向测试 | 已验证 |
| 未使用 Native Class 的项目跳过 Native global pre-pass | `discoverNativeGlobalObjects()` fast return | 源码检查、全量 PHPUnit | 已验证 |

## 9. 当前验证命令

```bash
./run-tests.php -j4 --compiler ./tpc tests/compiler/native-class/
vendor/bin/phpunit phpunit/src/NativeClass/NativeClassValidationTest.php
/home/swoole/workspace/aot/phpx/build/bin/phpx-tests \
  --gtest_filter='wren_gc.*:native_gc.*'
```

本次 Iterator 专项结果为：`iterator.phpt` 1/1、Native Class PHPUnit 136/136，
普通 foreach 回归 14/14。Native Class PHPT 目录现有 71 项；按当前任务约定暂未重复执行
该目录及编译器 PHPT 全量测试，留待下一轮统一回归。
