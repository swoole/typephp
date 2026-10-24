# varint_types 当前行为与实现边界

`use varint_types` 是文件级的整数存储与算术代码生成策略。它既决定推断整数是否存入 `php::Var`，也参与运算路径、常量求值、异常诊断、返回值检查、容器约束和优化选择。将它解释为“只在局部变量声明时有效”会遗漏实际行为。

实现核对基线：`b7d03cd9`，2026-10-20，包含 match 类型推断及后续嵌套溢出修复。以下记录当前实现，不代表所有表达式都已统一为 Zend PHP 语义。普通原生类型规则见 [NATIVE_TYPES.md](NATIVE_TYPES.md)。

## 影响范围总览

| 范围 | 当前影响 |
| --- | --- |
| 推断局部变量 | 普通推断 `int` 映射为 `php::Var`；`float`、`bool` 不因该映射改为 Var |
| 声明为 int 的参数 | 参数 ABI 仍为 `php::Int`，但参数参与的普通整数二元运算可改走 PHPX 算术 |
| 临时值和运行时调用结果 | 影响求值顺序临时变量的存储、标量转换，以及部分内部函数调用结果是否立即解箱 |
| 算术运算 | 影响整数加减乘除、取模、移位、幂运算及浮点除法的代码生成路径 |
| 常量表达式 | 影响除法求值、溢出折叠、除零与移位边界的编译错误或运行时异常选择 |
| 复合赋值 | 动态整数局部变量保留扩展结果；普通 Zend int 属性改走计算后检查写入的路径 |
| 类型推断与返回值 | 保留潜在的 float 结果，影响 match 的 lambda 返回类型及严格返回类型检查 |
| 类型化容器 | 对整数元素或键的潜在扩展表达式增加编译期限制 |
| 优化 | 跳过通用 SSA 局部变量收窄和属性收窄，改变 min/max 快速路径，并启用特定属性加法链优化 |
| 编译状态 | 按文件重置；模式参与预处理缓存键 |

## 开启方式与作用域

```php
<?php
use varint_types;
```

- 普通 `use` 导入中的名称按大小写不敏感识别，`use VaRiNt_TyPeS;` 同样有效。`use function` 和 `use const` 走各自的符号导入分支。
- 编译器维护 `CompilerBase::$varIntTypes`，初始值为 `false`；`parseUse()` 将其设为 `true`。它不是运行时 ini 选项，也不是某个变量上的类型注解。
- `resetFile()` 将模式恢复为 `false`，不会泄漏到随后转换的其他文件。被调用函数的函数体按其自身源文件的模式生成代码，调用方的模式不重新编译被调用函数。
- `resetNamespace()` 不清除该标志，不能把同一文件的不同 namespace 块当作模式隔离边界。应将声明放在文件前部的导入区；当前没有对应的关闭指令。
- 预处理缓存键包含该标志，同时也包含源文件哈希和代码生成器指纹。模式属于编译输入的一部分。
- 编译器自身的 [gen_stub.php](../../src/gen_stub.php) 也使用这一声明；这不表示所有用户源文件默认开启该模式，文件重置规则仍然适用。

实现：[DeclarationSymbolTrait::parseUse](../../src/Resolver/DeclarationSymbolTrait.php)、[CompilerBase::resetFile / resetNamespace](../../src/CompilerBase.php)、[PreparedProjectCacheTrait::preparedProjectKey](../../src/Build/PreparedProjectCacheTrait.php)。大小写与跨文件重置由 [VarIntTypesTest](../../phpunit/src/VarIntTypesTest.php) 覆盖。

## 值类型与 C++ 存储类型

需要区分三个信息：PHP 值当前是什么类型、编译器推断出的表达式类型、生成 C++ 使用什么存储。它们并不总是相同。

### 局部变量与固定 ABI

| 场景 | 开启后的存储或约束 |
| --- | --- |
| 首次普通赋值 `$n = 42` | 通常为 `php::Var`，初始保存 PHP int |
| 将普通 int 参数复制给新的局部变量 | 新局部变量也经过推断整数的 Var 映射 |
| `$f = 1.5`、`$b = true` | 仍分别为 `php::Float`、`php::Bool` |
| `function f(int $n): int` | 参数和返回 ABI 仍为 `php::Int`，没有变成 `mixed` |
| `$n = std::int(42)` | 显式原生存储 `php::Int` |
| 经过安全范围证明的循环计数器 | 仍可能使用 `php::Int`，见后文的优化边界 |
| 普通 `global` 槽与局部 static 的后备槽 | 有各自的全局/static 存储规则，不能直接套用普通新局部变量的映射规则 |

`getNativeType()` 在普通整数且未先命中高精度模式时，将 `Type::INT` 映射为 `Type::VAR`。整数 AST 字面量本身仍可被 `detectTypeOfExpr()` 判定为 `Type::INT`，因此不能从“字面量是 Int”推导出“接收它的局部变量使用 Int”。

存储变化还会间接改变后续赋值是否允许。例如，默认原生模式下先赋整数的变量不能直接接收普通 foreach 的动态键；varint 模式下该变量使用 Var，可以复用为 foreach 键。此差异由 `VarIntTypesTest::testVarIntAndExplicitAnyCanBeReusedAsForeachKeys` 覆盖。

实现：[AssignOpTrait::parseAssign](../../src/Parser/AssignOpTrait.php)、[TypeConversionTrait::getNativeType](../../src/Parser/TypeConversionTrait.php)、[Translator 的参数注册](../../src/Translator.php)、[CompilerBase::parseGlobal / parseStatic](../../src/CompilerBase.php)。

### 临时值与内部函数调用

`usesNativeScalarStorage(Type::INT)` 在此模式下返回 `false`；float 和 bool 的判定不变。它的使用范围超过普通变量声明：

1. `getOrderedOperandTmpType()` 为调用、二元运算和自增减等表达式选择临时存储时，可能保留 Var，避免提前将扩展后的 float 转回 int。
2. `parseOrderedOperand()` 根据这一判定决定是否执行面向原生槽的标量转换。选择 Var 后，临时值还需要按 zval 所有权规则在语句边界清理。
3. `convertRuntimeCallResult()` 对部分已知标量返回类型的 `php::call(...)` 结果使用 `getNativeType()`；已知 int 结果在此模式下可以继续保留 Var，float/bool 结果仍可转换为原生表示。这不改变被调函数的声明 ABI，也不表示每个内置函数都使用同一条调用路径。

实现：[BinaryOpTrait::parseOrderedOperand / getOrderedOperandTmpType](../../src/Parser/BinaryOpTrait.php)、[TypeConversionTrait::usesNativeScalarStorage / convertRuntimeCallResult](../../src/Parser/TypeConversionTrait.php)、[FunctionCallTrait::parseFuncCall](../../src/Parser/FunctionCallTrait.php)。

## 算术表达式

### 声明参数也参与模式选择

```php
use varint_types;

function divide(int $a, int $b): mixed
{
    return $a / $b;
}

// divide(6, 3) 得到 int(2)
// divide(7, 2) 得到 float(3.5)
// divide(PHP_INT_MIN, -1) 得到 float
// divide(7, 0) 抛出可捕获的 DivisionByZeroError
```

这里 `$a`、`$b` 的 C++ 参数依然是 `php::Int`。变化发生在二元运算的 lowering：普通动态 int 运算可先包装为 Var，再调用 PHPX 运算符。PHPX 可以使用自己的检查过的算术重载；生成 Var 运算不等于每一步都回到 ZendVM 执行字节码。

### 各运算的分派

下表中的“普通”指未被显式原生算术标记排除的操作数；固定存储、显式原生值和高精度数值的例外见后文。

| 运算 | varint 模式增加或改变的行为 |
| --- | --- |
| 动态 int `+`、`-`、`*` | 改用可扩展的 PHPX 运算，溢出时保留 float，覆盖普通声明参数参与运算的情况 |
| int `/` | 常量求值或动态 PHPX 路径保留整除为 int、非整除为 float、最小整数除以 -1 扩展为 float 的区别 |
| int/float、float/int、float/float `/` | 普通动态除法也可进入 Var 路径，使零除数产生 PHP 异常，而不是原生 double 除法的 INF/NAN |
| 动态 int `%` | 非显式原生操作数使用 `php::fn::mod()` 并恢复 int 结果；保留零除异常和最小整数 `% -1` 的 PHP 行为 |
| 动态 int `<<`、`>>` | 无法证明为安全常量移位时使用 Var；保留负移位的 `ArithmeticError`、大移位计数及负数右移语义 |
| `**` | 普通整数操作数可走 `php::fn::pow()`；原生标量快速路径仍用于默认模式、显式原生操作数或 float 参与的相应分支 |
| `%`、`&`、`\|`、`^`、`<<`、`>>` 有原生 float/bool 参与 | 通用整数运算分支先转换为整数再计算；这是共享的运算规则，不是 varint 模式独有的全套 Zend 检查 |

如果静态操作数类型本来就是 Var、Ref、string 等动态算术类型，通用动态分派在未开启 `varint_types` 时也存在。数字字符串继续作为字符串交给运行时转换，不应把 `"1.5" * 2`、数字字符串的零除异常等现象全部归因于此开关。varint 模式还会通过另一侧整数的装箱改变后续表达式和复合赋值的存储。

实现：[BinaryOpTrait::parseBinaryOp / parseBinaryOpPow / emitDynamicBinaryOp](../../src/Parser/BinaryOpTrait.php)。对应测试包括 [typed-vs-explicit-native-arith](../../tests/compiler/operator/typed-vs-explicit-native-arith.phpt)、[typed-int-float-division](../../tests/compiler/operator/typed-int-float-division.phpt)、[typed-int-mod-shift](../../tests/compiler/operator/typed-int-mod-shift.phpt)、[float-integer-operators-varint](../../tests/compiler/operator/float-integer-operators-varint.phpt)、[numeric-string-varint-types](../../tests/compiler/operator/numeric-string-varint-types.phpt)、[string-arithmetic-varint](../../tests/compiler/operator/string-arithmetic-varint.phpt)。

## 常量求值与诊断

这一模式会改变同一段源码是在编译期被拒绝、折叠成一个值，还是保留到执行时抛异常。

| 场景 | 默认原生路径 | varint 路径 |
| --- | --- | --- |
| 已知整数 `+`、`-`、`*` 溢出 | 对可静态识别的 C++ 未定义行为报编译错误 | 发出溢出警告，并折叠为 PHP float 结果 |
| 已知 `PHP_INT_MIN / -1` | 拒绝对应的原生未定义行为 | 折叠为 float |
| 已知 `PHP_INT_MIN % -1` | 拒绝对应的原生未定义行为 | 折叠为 int(0) |
| 常量树中的整数除法 | 求值辅助函数按 C++ 整除计算，以检查外层原生运算 | 辅助求值采用 PHP 除法结果，可能为 float |
| 已知零除数 | 原生路径拒绝编译 | 符合 PHP 算术分派条件时警告并保留运行时 `DivisionByZeroError`，未执行的分支不抛出 |
| 移位数大于等于字长 | 已知不安全原生移位被拒绝 | 警告并按 PHP 折叠：左移为 0，右移根据符号为 0 或 -1 |
| 负移位数 | 已知不安全原生移位被拒绝 | 警告并生成运行时 `ArithmeticError` 路径 |
| 常量负数移位或左移触及符号位 | 原生安全检查可能拒绝 | 不安全的直接 C++ 移位可改走 PHPX 路径 |
| 对可静态确定的 `PHP_INT_MIN` 取负 | 拒绝编译 | 生成 float，类型推断也返回 float |

上述原生路径对比不适用于本来已经选择动态 PHP 算术的操作数。除零判定还覆盖部分浮点零、数值字符串零和嵌套常量零表达式；具体取决于 `guardLiteralDivisionByZero()`、`handleNestedConstantDivisionByZero()` 和 `usesPhpArithmeticForZeroDivisor()`。

`PHP_INT_MAX`、`PHP_INT_MIN` 的折叠要求证明引用的是全局常量；namespace 内可能被覆盖的非限定常量不能据此当作全局边界值。这一名称解析约束并非 varint 模式专有，但修改相关折叠时必须保留。

实现：[BinaryOpTrait 的 constantNumericValue / constantIntValue / tryFoldConstantIntArithmetic / tryFoldConstantShift](../../src/Parser/BinaryOpTrait.php)、[UnaryExpressionTrait::parseUnaryMinus](../../src/Parser/UnaryExpressionTrait.php)、[CompilerBase::detectTypeOfExpr](../../src/CompilerBase.php)。

测试：[constant-int-arithmetic-overflow](../../tests/compiler/operator/constant-int-arithmetic-overflow.phpt)、[constant-shift-boundaries](../../tests/compiler/operator/constant-shift-boundaries.phpt)、[literal-division-by-zero-runtime](../../tests/compiler/operator/literal-division-by-zero-runtime.phpt)、[ConstantArithmeticOverflowTest](../../phpunit/src/ConstantArithmeticOverflowTest.php)、[php-int-max-namespace-shadow](../../tests/compiler/const/php-int-max-namespace-shadow.phpt)。对最小整数取负的特殊处理以能静态确定边界值为条件，不能扩展为“所有固定 int 变量的动态取负都有溢出保护”。

## 复合赋值与属性访问

### 动态局部变量和固定存储不同

```php
use varint_types;

$n = PHP_INT_MAX;        // 普通推断局部变量使用 Var
$n += 1;                // 可以保存 float

$explicit = std::int(10);
$explicit /= 4;         // 固定原生整数存储，仍为 int
```

Var 左值的复合赋值保留 RHS 的运行时类型，交给 PHPX 处理。不能先把看似 int 的 RHS 解箱，否则会在写入前丢失溢出后的 float。`+=` 还有 `addAssign()` 路径，动态幂赋值使用相应的动态幂运算。

**声明为 int 的参数没有因此获得动态存储。** `parseAssignOp()` 的固定标量分支仍可能生成直接 C++ 复合赋值。`$result = $intParam; $result /= $other;` 中的新局部变量和直接 `$intParam /= $other` 不应视为同一存储场景。模式也没有统一重写所有原生自增减、复合运算或显式原生运算。

当前有专门的零除数分支：对普通固定 int/float 变量的 `/= 0`、`%= 0`，varint 模式可以生成抛出 PHP 异常的计算，避免写回，保持原值；显式 `std::int()` / `std::float()` 左值在该分支被编译期拒绝。它是特定保护，不能据此推导出所有固定左值复合运算都遵循动态 PHP 语义。

实现：[AssignOpTrait::parseAssignOp](../../src/Parser/AssignOpTrait.php)。测试：[local-var-compound-overflow](../../tests/compiler/operator/local-var-compound-overflow.phpt)、[runtime-int-division](../../tests/compiler/operator/runtime-int-division.phpt)、[assign-op-mixed-types](../../tests/compiler/operator/assign-op-mixed-types.phpt)、[literal-division-by-zero-typed-slots](../../tests/compiler/operator/literal-division-by-zero-typed-slots.phpt)。

### Zend 属性读取和检查写入

- 普通 Zend 对象的已知 int 实例属性和部分静态属性读取受 `usesNativeScalarStorage()` 影响：varint 模式跳过对应的原生标量读取快速路径，保留动态值表示。属性声明本身仍是 int；float/bool 属性不会仅因这一判定被排除。
- 对能走到 `parseNativePropertyAssignOp()` 的普通 Zend int 属性，`+=`、`-=`、`*=`、`/=`、`%=`、`**=`、移位及按位复合赋值改走 `parseCheckedPropertyAssignOp()`。先按 PHP 规则计算，再检查属性写入；结果不满足 int 约束时抛出 `TypeError`，保留旧值。
- Native Class Object 的 C++ 字段在这些分支中被单独处理，不会为了此开关自动变为 Zend Variant 属性。readonly、hook 和动态属性也有各自的处理分支。

实现：[PropertyAccessTrait::parseNativeStaticPropertyFetch / parsePropertyFetch](../../src/Parser/PropertyAccessTrait.php)、[AssignOpTrait::parseNativePropertyAssignOp](../../src/Parser/AssignOpTrait.php)。测试：[int-property-compound-assignment](../../tests/compiler/object_property/int-property-compound-assignment.phpt)、[native-int-property-assign-op-var](../../tests/compiler/object_property/native-int-property-assign-op-var.phpt)。

## 类型推断与返回值检查

### 潜在扩展的表达式不能过早固定为 int

`detectTypeOfExpr()` 在 varint 模式下处理普通 int 算术时：

- 已知常量计算为 float 时，将表达式判为 float。
- 动态整数除法的结果依赖数值，判为 Var。
- 动态加减乘等表达式仍可能被粗粒度推断为 int，消费端还需要额外识别潜在扩展。

`exprCanOverflowInt()` 是共享的保守检查器，识别加减乘除、`**` 和特定 `pow()` 调用，并穿透二元表达式、三元表达式、match arm、错误抑制以及一元正负号包装。完整三元表达式检查两个结果分支，简写三元表达式也检查被选作结果的条件值。它不做完整的数值范围证明，也不是任意调用和任意 AST 包装的通用结果类型分析。

### 函数返回边界

`parseReturn()` 在此模式下把“推断为 int，但可能扩展”的表达式按动态结果处理。对于 `: int`，必须先保留原始 PHP 值并执行严格返回类型检查，再转换到 C++ 的 `php::Int` 返回 ABI。溢出产生的 float 不能被 `toInt()` 静默收回。

因此，普通 int 参数的 `$a + $b` 在未溢出时可以满足 `: int`，溢出时会触发 `TypeError`；未声明 int 限制或声明 mixed 的返回路径可以保留 float。可空、联合类型和闭包返回值还有各自的检查流程，该开关并不移除它们。

### match 与嵌套表达式

match 聚合非 throw arm 的类型时，遇到 varint 模式下可能扩展的 int arm，会退回 Var。随后生成的 lambda 和 lowered-type 属性必须与这一结果一致，避免在 arm 返回时先转换成 int。

这包括 `match (...) { ... => ($flag ? $n + 1 : 0), ... }`：内部三元表达式不能遮蔽溢出。纯整数、没有潜在扩展运算的 match 仍可生成 `php::Int` lambda，外部普通局部变量赋值再按其存储规则决定是否装箱。

三元表达式的类型聚合本身仍有独立实现；共享检查器对其递归检查，不等于所有选择表达式都已经统一为同一种动态 lowering。

实现：[CompilerBase::detectTypeOfExpr / parseReturn](../../src/CompilerBase.php)、[SsaTypeOptimizer::exprCanOverflowInt](../../src/Optimizer/SsaTypeOptimizer.php)、[SelectionExpressionTrait::parseMatch / parseTernary](../../src/Parser/SelectionExpressionTrait.php)。测试：[runtime-int-overflow-return](../../tests/compiler/operator/runtime-int-overflow-return.phpt)、[match-varint-overflow](../../tests/compiler/control_flow/match-varint-overflow.phpt)、[match-varint-nested-overflow](../../tests/compiler/control_flow/match-varint-nested-overflow.phpt)、[MatchExpressionCodegenTest](../../phpunit/src/MatchExpressionCodegenTest.php)。

## 类型化数组与 Std 容器

`varint_types` 不把固定整数容器自动变成可同时保存 int/float 的容器。相反，它让部分写入或初始化检查更严格：

| 入口 | 当前约束 |
| --- | --- |
| 类型化 PHP list/dict 的整数元素或整数键 | 类型符合后，仍拒绝被识别为可能扩展的 int 表达式 |
| Std 容器按值初始化的元素类型推断 | 拒绝 Var/any；对推断为 int 且可能扩展的表达式也拒绝 |
| Std 容器按值初始化中的显式整数键 | 同样检查潜在扩展，不能将可能成为 float 的键推断为固定 int |

诊断要求调用方明确选择转换，例如 `std::int($n + 1)` 或 `($n + 1)->toInt()`。这是调用方接受转换结果的声明，不是自动的溢出范围证明。检查器保守识别运算节点，不能假定它会证明每一个看似安全的加法常量都不会扩展。

这些限制针对上述具体入口；不能据此宣称所有 Std 容器写入都有相同的模式分支。容器存储和注解规则见 [TYPED_ARRAYS.md](TYPED_ARRAYS.md) 与 [STD_CONTAINERS.md](STD_CONTAINERS.md)。

实现：[TypedArrayTrait::guardTypedArrayValue](../../src/Parser/TypedArrayTrait.php)、[StdContainerTrait::inferStdInitializerValueType / inferStdFlatInitializer](../../src/Parser/StdContainerTrait.php)。类型化 PHP 数组的拒绝与显式转换由 [TypedArrayTest](../../phpunit/src/TypedArrayTest.php) 覆盖。

## 优化选择

| 优化或分析 | varint 模式下的行为 |
| --- | --- |
| SSA/e-SSA 构建与稳定对象分析 | 仍执行；模式不是关闭 SSA 的总开关 |
| 通用 `optimizeVarTypes()` | 函数生成流程跳过整个 pass，包括该 pass 原本可做的其他标量收窄 |
| `optimizeObjectProps()` | 同样跳过，避免把动态算术依赖的属性值过早收窄 |
| `optimizeLoopVars()` | 仍执行；仅对符合范围、单调性和使用约束的计数器使用 `php::Int` |
| 两个精确整数的 min/max 原生选择表达式 | `isExactIntegerMinMaxOperand()` 因整数原生存储判定失败而不启用；即使局部显式使用了原生整数，也不能据此保证进入这个优化 |
| 稳定 final int 属性的加法链 | 只在 varint 模式尝试专用优化；至少三个操作数，使用独立 Var 累加器，保留逐步溢出语义并避免修改被引用的源属性 |

属性加法链要求实例属性为非 nullable、非 virtual、无 getter，且类或属性具有 final 稳定性；不能推广到任意属性加法。循环优化也不会将任意溢出风险循环强行改成 C++ int 循环。

实现：[Translator 的函数生成流程](../../src/Translator.php)、[LoopVarOptimizer](../../src/Optimizer/LoopVarOptimizer.php)、[FuncCallOptimizer::genIntegerMinMax / isExactIntegerMinMaxOperand](../../src/Optimizer/FuncCallOptimizer.php)、[BinaryOpTrait::tryParseFinalIntPropertyAddChain](../../src/Parser/BinaryOpTrait.php)。测试：[loop-var-overflow-semantics](../../tests/compiler/optimizations/loop-var-overflow-semantics.phpt)、[min-max-varint](../../tests/compiler/functions/min-max-varint.phpt)、[final-int-property-add-chain](../../tests/compiler/optimizations/final-int-property-add-chain.phpt)。

## 优先级与未覆盖的边界

1. **显式原生算术。** `std::int()`、`std::float()`、`std::bool()` 的表达式属性及部分变量赋值传播的标记，被 `isExplicitNativeArithmeticExpr()` 用于保留相应运算的 C++ 路径。标记不是任意表达式树或跨函数自动传播的模式，也不会统一覆盖所有常量诊断分支。
2. **高精度模式。** `getNativeType()` 对 int 先检查 `bigint_types`，对 float 检查 `decimal_types`，之后才应用普通 int 的 Var 映射。BigInt/Decimal/BigFloat 也有自己的算术分派和装箱表示；`varint_types` 不等于任意精度整数模式。
3. **动态值原有语义。** `std::any()`、运行时动态返回值和数字字符串原本就可能走 PHPX/Zend 算术，开关不是这些语义存在的唯一原因。
4. **固定契约继续存在。** 函数参数/返回声明、属性声明、类型化数组和 C++ 容器元素类型不会被改写为 mixed；普通 int 值是否允许扩展和目标位置是否接受 float 是两件事。
5. **float/bool 存储与表达式语义不同。** float/bool 不因普通整数装箱规则而改用 Var，但浮点除法、复合赋值检查和通用优化是否执行仍可能受模式影响。“只装箱整数”不能简写成“只影响整数”。
6. **现有功能契约不变。** 引用包装、闭包捕获、对象模型、PHP 数组与 Native Class Object 的边界仍按各自规则处理。存储改变会影响后续分派，但这不是完整的 PHP 兼容模式。

## 维护与审查入口

修改模式语义时，至少同时检索三组入口，不能只查 `getNativeType()`：

```text
varIntTypes / varint_types
getNativeType / usesNativeScalarStorage / isExplicitNativeArithmeticExpr
exprCanOverflowInt / getLoweredExpressionType / getOrderedOperandTmpType
```

验证应区分普通推断局部变量、声明参数、显式原生值、Zend 属性、Native Class Object 字段、选择表达式和容器边界；不能只用 `$n = 1` 的声明结果代表整个开关。涉及嵌套表达式时还要核对中间临时值和 lambda 是否提前收窄。编译与测试必须遵守仓库 [AGENTS.md](../../AGENTS.md) 的单任务、统一锁和资源上限要求。
