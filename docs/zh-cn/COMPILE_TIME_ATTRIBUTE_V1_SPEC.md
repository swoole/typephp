# TypePHP 编译期注解 v1 规范

## 0. 文档信息块

| 字段 | 值 |
|---|---|
| 标题 | TypePHP 编译期注解 v1 规范 |
| 文档编号 | TP-CA-1 |
| 类别 | 标准跟踪 (Standards Track) |
| 状态 | 已实现 (v1) |
| 适用版本 | TypePHP master `d4e440eb` 之后的 `feat/compile-time-attribute` |
| 日期 | 2026-10-30 |

本文档使用 RFC 2119 / RFC 8174 的关键词约定。除非上下文另有说明，MUST、MUST NOT、REQUIRED、SHALL、SHALL NOT、SHOULD、SHOULD NOT、RECOMMENDED、MAY、OPTIONAL 这些英文大写关键词在本文档中均按 RFC 2119 / RFC 8174 解释。本文档的规范性来源是编译器实现本身，涉及的提交为 `2aedc3d7`、`8341f698`、`9c9e7697`、`318c7d34`、`936bb23f`。

## 1. 引言

### 1.1 目的

本规范定义 TypePHP 编译期注解扩展通道 v1 的清单格式、Provider 契约、生成器接口、片段语法、展开语义、诊断、缓存与确定性要求，以及实现与 Provider 作者双方的一致性义务。目标是让读者不查看源码也能编写出符合 v1 的 Provider 与符合 v1 的编译器实现。

### 1.2 范围

本规范覆盖 `feat/compile-time-attribute` 分支上已实现并有测试覆盖的行为：普通 `#[Attribute]` 类在编译期由 Provider 展开为真实类成员或属性读写钩子。本规范不描述 `wrap()`、lib 模式支持、跨项目聚合等 v1 未交付能力，相关内容见第 11 节的非目标清单。

### 1.3 规范性来源

本文档的规范性来源是编译器实现与它的测试。凡本文档与实现对不上的地方，以实现为准并修正本文档；示例代码见 14 节。

## 2. 术语与约定

| 术语 | 定义 |
|---|---|
| 注解类 | 用户编写的普通 PHP `#[Attribute]` 类。编译器只把它的 FQCN 当字符串使用，从不反射它，也不读取它的 target 位掩码。 |
| Provider | 处理某个注解类的类，挂有 `#[ProviderFor]`，提供 `compile(Generator $g): void`。 |
| 宿主 (host) | 携带被注册注解的声明：具名 class、trait、enum、属性声明或方法声明。展开以宿主所在的类 (ClassLike) 节点为单位执行。 |
| 站点 (site) | 注解的一次使用。由注解节点、目标描述、宿主文件与注解所在行号组成，值对象为 `Site`。 |
| 记录 (record) | 一次展开计算的产物，形如 `['members' => [...], 'hooks' => [...]]`，成员以源码字符串保存，钩子为每个通道合成后的一份 body 字符串。记录进入进程内 memo。 |
| 片段 (snippet) | Provider 传给 `member()`、`setter()`、`getter()` 的 PHP 源码文本，由 `SnippetParser` 包进合成类后解析。 |
| 通道 (channel) | 属性钩子的方向，取值为 `get` 或 `set`。 |
| 层 (layer) | 同一属性同一通道上的一份钩子 body 及其站点。多层按顺序合成为单个钩子。 |
| 展开 (expansion) | 收集站点、运行 Provider、汇总成员与钩子、校验、写入 AST 的全过程，实现入口为 `Expansion::apply()`。 |
| 宿主快照 (host view) | 展开开始时为宿主构建的只读视图，包含 FQCN、类信息、方法、属性与类常量，实现为 `HostView`。 |

约定：本文档中 `<...>` 表示占位符；反引号内的内容为逐字文本或代码标识符；文件路径均为仓库内相对路径。

## 3. 一致性

### 3.1 编译器实现 MUST

- MUST 只在项目清单解析路径上注册 Provider；目录模式与单文件模式 MUST NOT 注册，未注册的注解 MUST 按既有运行时属性语义处理。
- MUST 在零 Provider 时保持与未引入本特性时逐字节一致的输出、缓存键与诊断。
- MUST 保证同一宿主在同一进程内至多计算一次展开记录，prepare、convert、arginfo 三棵树 MUST 使用同一段推导代码与同一份记录。
- MUST 在符号化 (`prepareClass`) 之前、既有内置降级之后执行展开。
- MUST 对第 9 节列出的每一种拒绝情景给出诊断，MUST NOT 静默跳过；诊断消息文本 MUST 与附录 B 逐字一致。
- MUST 在任何 AST 写入与缓存写入之前完成全部校验，失败时 MUST NOT 留下被部分修改的语法树。
- MUST 把 Provider 文件排除在 `sources` 编译范围之外。
- MUST 在 `mode: lib` 下拒绝已注册 Provider 的项目。
- MUST 让 Provider 指纹参与 prepared 缓存键与增量输出复用键；指纹为空时 MUST NOT 改变这两个键的任何字节。
- 注册后的注解 MUST NOT 再被运行时属性工厂降级消费，MUST NOT 被 `PHASE_ENTER` 的函数/方法注解降级消费。

### 3.2 Provider 作者 MUST

- Provider 类 MUST 只挂一个 `#[ProviderFor]`，MUST 可被无参实例化。
- Provider 类 MUST 声明 `compile` 方法且至少接受一个参数，推荐签名为 `compile(Generator $g): void`。
- Provider 文件在 boot 期被 `require_once`，MUST NOT 有顶层副作用（不得输出、写文件、发起网络请求或依赖被编译项目的自动加载）。
- `compile()` SHOULD 是纯函数：不写文件、不改全局状态、不依赖时间、随机数、环境变量与外部文件。
- 片段里的类型引用 MUST 写成 FQCN；trait `use` 由实现改写为 FQCN，其余名称不继承宿主的 `use` 列表。
- 生成的成员名 MUST NOT 以 `__typephp_` 开头，MUST NOT 是 `__construct`、`__destruct`、`__clone`（大小写不敏感）。
- 片段内 MUST NOT 出现属性组。
- 同一属性同一通道的 setter 层中，除最内层之外的每一层 MUST 包含 `{{call}}`。
- 属性带默认值时，getter 表达式 MUST 包含 `{{value}}`。
- SHOULD 只依赖清单声明的输入，以保证缓存可解释与展开可重复。

### 3.3 项目 MUST

- MUST 使用项目清单（`.yml`/`.yaml`）表达 `compile-time-attributes`；目录模式与单文件模式没有清单入口，MUST NOT 期望 Provider 生效。
- MUST 把每个 Provider 文件放在 `sources` 解析出的目录之外。
- MUST NOT 在 `mode: lib` 项目里注册 Provider。
- SHOULD 显式声明 `sources`：`sources` 缺省时，"sources 之外"的检查退化为"清单所在目录之外"，位于清单目录内的 Provider 文件会被拒绝。
- SHOULD 保持 Provider 文件自包含：未在清单中声明的辅助文件改动不会使缓存失效。

## 4. 项目清单

清单在项目 YAML 中使用顶层键 `compile-time-attributes`，其下只允许一个子键 `providers`：

```yaml
compile-time-attributes:
  providers:
    - file: providers/Audited.php
      class: App\Providers\AuditedProvider
```

规范要求：

- `compile-time-attributes` 的值 MUST 是映射；出现其他子键 MUST 报错。
- `providers` 的值 MUST 是列表（`array_is_list`）；缺失或空列表表示未配置 Provider，语义等同于没有该键。
- 每个条目 MUST 是映射，且只允许两个键：`file`（必填，非空字符串）与 `class`（可选，非空字符串）。未知键 MUST 报错。
- 路径解析：`file` 以 `/`、`\` 或盘符开头时按绝对路径处理，否则 MUST 相对清单文件所在目录解析；解析后 MUST `realpath` 成功且指向一个已存在的文件。
- `class` 省略时，实现 MUST 自动发现该文件声明的类（以反射的文件名等于 `realpath` 后的 Provider 文件为准）。
- Provider 文件 MUST NOT 位于 `sources` 之下。`sources` 存在时按解析出的目录逐个判断，`sources` 缺省时按清单所在目录判断；前缀比较基于 realpath。
- 条目顺序 MUST NOT 影响产物：实现按 Provider 文件路径排序后装入注册表。
- 未配置或为空时，Provider 表为空、指纹为空串，后续所有路径 MUST 与未引入本特性的行为一致。清单语法的 ABNF 见附录 A。

## 5. Provider 注册

### 5.1 `#[ProviderFor]` 契约

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class ProviderFor
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $target,
    ) {}
}
```

- `attribute` 是注解类 FQCN，去壳后 MUST 非空。
- `target` MUST 取 `ProviderDefinition::TARGETS` 中的值之一：`class`、`trait`、`enum`、`property`、`method`。`target` 是宿主形状的唯一真相来源，注解类上的 `#[Attribute(...)]` 位 MUST NOT 被读取。
- 每个 Provider 类 MUST 恰好声明一个 `#[ProviderFor]`，超过一个 MUST 报错。
- Provider 类 MUST 可实例化（构造函数不得有必填参数），否则报错。
- Provider 类 MUST 声明 `compile` 方法且参数个数不少于 1。实现只校验"存在且至少一个参数"，不校验参数类型与返回类型，推荐签名为 `compile(Generator $g): void`。
- 一个 Provider 文件在省略 `class` 时发现的每个候选类都 MUST 通过上述校验；一个类都没有时该文件 MUST 报错。

### 5.2 注册动作

boot 期对每个 Provider 文件执行 `require_once`，随后反射校验并把定义装入 Provider 表：

- 注册键为 `strtolower(ltrim(attribute, '\\'))`，因此注解名匹配大小写不敏感。
- 同一个注解名被两个 Provider 声明时 MUST 报错，无隐式优先级。
- 注解名与内置注解重名时 MUST 报错。内置注解共 24 个：`Native`、`MethodsFor`、`NoExport`、`WasmExport`、`Getter`、`Setter`、`With`、`Printer`、`Arrayable`、`NotNull`、`NotEmpty`、`Validate`、`Override`、`MustUse`、`Immutable`、`Hot`、`Cold`、`Constructor`、`StdArray`、`StdVector`、`StdMap`、`StdOrderedMap`、`StdList`、`StdDict`。
- boot 期任何一条校验失败 MUST 中止整个构建（全有或全无）。
- boot 消息里的 "in ..." 后缀是字面量 `compile-time-attributes.providers`，不是清单文件路径。
- 注册同时向编译器既有注解定义表合并一条定义，字段固定为：

| 字段 | 值 |
|---|---|
| `name` | 注解 FQCN |
| `targets` | 单元素列表，由 `target` 映射（见下表） |
| `target_error` | `#[<短名>] can only be applied to a class`（`enum` 时为 `an enum`，其余为 `a <target>`） |
| `repeatable` | `false` |
| `argument_parser` | `provider` |
| `conflicts` | `[]` |
| `phase` | `provider`（独立相位，绝不等于 `enter`） |
| `preserve_in_library_stub` | `false` |

| `target` | 注册表常量 | 匹配的节点 |
|---|---|---|
| `class` | `TARGET_NAMED_CLASS` | 有名字的 `Class_` |
| `trait` | `TARGET_TRAIT` | `Trait_` |
| `enum` | `TARGET_ENUM` | `Enum_` |
| `property` | `TARGET_DECLARED_PROPERTY` | 声明的属性，不含提升参数 |
| `method` | `TARGET_METHOD` | `ClassMethod` |

### 5.3 调用约定

- Provider 文件 MUST NOT 有顶层副作用，因为它早于任何源码解析被执行，在 `--dry`、缓存命中等路径上会重复触发。
- 对给定宿主，展开计算在进程内至多执行一次；每个站点的 `compile()` 在该次计算中恰好调用一次。同一注解在同一宿主上重复出现会先被重复性校验拒绝。
- `error()` 只终止当前站点的 Provider 执行；同一宿主其他站点的 Provider MUST 继续执行并各自收集诊断。
- 嵌入方（测试 harness、直接调用 `Translator::prepareFile()` 的代码）MUST 走项目清单解析路径，否则 Provider 不生效，注解会静默降级为运行时属性。

## 6. 生成器接口

`compile()` 的唯一入参是 `Generator`。下表为 v1 的全部方法，共 10 个。

| 方法 | 返回 | 语义 |
|---|---|---|
| `target()` | `Target` | 被注解的那个声明（类或成员）。 |
| `classInfo()` | `ClassInfo` | 声明所在类的只读信息；对 property/method 站点返回其所属类的信息，实现不按 target 限制调用。 |
| `args()` | `array<string, mixed>` | 注解实参；位置参数键为 `0, 1, ...`，命名参数键为参数名，整体按源码顺序插入；不填充默认值。 |
| `methods()` | `array<string, MethodSignature>` | 宿主声明的方法，按名字索引，已过滤内部成员。 |
| `properties()` | `array<string, PropertyInfo>` | 宿主声明的属性（含构造函数提升参数），按名字索引。 |
| `constants()` | `array<string, mixed>` | 宿主类常量中可按字面量求值的项，按名字索引；不可求值的常量不出现在结果中。 |
| `member(string $source)` | `void` | 登记一个成员片段。 |
| `property(?string $name)` | `property 不存在时见下` 返回 `PropertyBuilder` | 省略名字时取 `target()->name`；当 target 不是属性时该值为空，此时 MUST 显式传名，否则抛出错误。 |
| `error(string $message)` | `never` | 终止当前 Provider 并记录一条诊断。 |
| `site()` | `Site` | 当前站点的位置信息。 |

`PropertyBuilder` 只有两个方法：`setter(string $body): void` 与 `getter(string $expression): void`，分别登记 `set` 与 `get` 通道的一层 body。

**这些调用只登记意图，不立即修改语法树。** `member()`、`setter()`、`getter()` 把产物写入按站点隔离的缓冲区；只有当宿主上全部站点成功返回并完成校验后，实现才合并、解析并写入 AST。因此 Provider 看不见自己或其他 Provider 为同一宿主生成的成员，冲突检测发生在全部产物已知之后，任何一个片段失败都导致该宿主一个成员都不写入。

值对象均为只读、无 AST 引用：

| 类型 | 字段 |
|---|---|
| `Target` | `fqcn`、`shortName`、`kind`（`class`/`trait`/`enum`/`property`/`method`）、`name`（property/method 站点为成员名，类站点为 `null`）。另有 `isClass()`、`isTrait()`、`isEnum()`、`isProperty()`、`isMethod()` 五个判断方法。 |
| `ClassInfo` | `fqcn`、`shortName`、`isFinal`、`isAbstract`、`isReadonly`、`extends`（`?string`）、`implements`（`string[]`）、`isEnum`、`enumBackingType`（`?string`）。 |
| `MethodSignature` | `name`、`params`（`ParamInfo[]`）、`returnType`（`?string`）、`isStatic`、`isAbstract`、`isFinal`、`isPublic`、`isProtected`、`isPrivate`、`returnsByRef`。另有 `paramNames()` 与 `isConstructor()`。 |
| `ParamInfo` | `name`、`type`（`?string`）、`byRef`、`variadic`、`hasDefault`、`defaultIsLiteral`、`promoted`。 |
| `PropertyInfo` | `name`、`type`（`?string`）、`isStatic`、`isReadonly`、`promoted`、`hasDefault`、`visibility`（`public`/`protected`/`private`）、`hooks`（`array<string, bool>`）。另有 `hasHook(string)` 与 `isPublic()`。 |
| `Site` | `attribute`（注解 FQCN）、`target`（目标描述字符串）、`file`（宿主文件）、`line`（注解起始行）。另有 `describe()` 与 `format(string)`。 |

类型与名字字段（`type`、`extends`、`implements`、`returnType`、`enumBackingType`）由编译器的类型字符串化产出，类名与接口名是解析后的无前导反斜杠 FQCN，标量与联合/交叉类型按源码文本拼接。

`methods()` 与 `properties()` MUST 过滤内部成员：`__typephp_` 前缀的方法、属性钩子降级产生的内部方法标记、非对称可见性标记方法。`properties()` MUST 同时收录构造函数提升参数（`promoted` 为真），提升参数的 `hooks` 恒为空。

## 7. 片段语法

`member()` 接受且仅接受一个类体成员，语法范围为四类：方法（`ClassMethod`）、属性（`Property`）、类常量（`ClassConst`）、trait `use`（`TraitUse`，形如 `use \Shop\HasSku;`）。规范要求：

- 片段 MUST 恰好解析出一个成员；多于一个或不足一个 MUST 报错。
- 片段在全局命名空间语义下解析：除 trait `use` 由实现强制改写为 `Name\FullyQualified` 外，其他类型名 MUST 由 Provider 写成 FQCN。
- 片段及 `setter()` 语句、`getter()` 表达式内 MUST NOT 出现属性组，报错文本说明生成成员不会被再次展开。
- 保留前缀 `__typephp_`：任何生成成员名以此开头 MUST 被拒绝（保留字语义）。
- 禁止生成 `__construct`、`__destruct`、`__clone`，比较大小写不敏感。
- 表达式片段（getter）MUST 是单个表达式；语句片段（setter）是语句序列，可为空。
- 解析方式：把片段包进合成类。成员用前缀 `<?php\nfinal class __TypePhpSnippet {\n` 加后缀 `\n}`；语句用前缀 `<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): void {\n` 加后缀 `\n} }`；表达式用前缀 `<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): mixed { return ` 加后缀 `;\n} }`。
- 行号归属：合成前缀占 2 行，解析错误报告的 `at generated line N` 为解析器行号减 2（最小为 1），即片段内部的行号；而最终诊断的锚点仍是注解所在行。
- 生成的成员不携带注解，也不会再经过注解展开。

片段语法与清单语法的 ABNF 见附录 A。

## 8. 展开语义

### 8.1 规范顺序

展开 MUST 按下列顺序执行，且 MUST 在任何 AST 写入之前完成 1 到 5：

1. 收集站点：先遍历类自身的属性组，再遍历成员语句；成员语句自上而下，同一声明的属性组自左向右。只收集注册表中存在且 `target` 与站点种类匹配的注解。
2. `#[Native]` 类检查：宿主是原生类时，对该宿主的每个站点各记一条错误并中止。
3. 逐站点运行：求值实参，构造 `Generator`（携带宿主快照），调用 `compile()`。任一环节抛出都被捕获为该站点的诊断。
4. 汇总：解析每个成员片段并做冲突检测，登记钩子层。
5. 校验钩子：目标存在性、宿主种类、static、readonly、promoted、已有同通道钩子、getter 层数、`{{value}}`、`{{call}}`，并合成钩子体、解析合成结果。
6. 若存在任何诊断，抛出 `SyntaxError`，本宿主不写入任何成员。
7. 无诊断时返回记录并写入：成员按登记顺序追加到类语句列表；钩子挂到属性节点上，随后复用 `PropertyHookLowering::lowerProperty()` 降级为隐藏方法。

### 8.2 宿主快照

宿主快照 MUST 在运行任何 Provider 之前构建一次，站点集合内全部复用。快照反映当时的语法树状态：包含手写成员，也包含展开之前已经执行的内置降级产物（属性钩子降级方法、`#[Getter]`/`#[Setter]`/`#[With]`/`#[Constructor]`/`#[Printer]`/`#[Arrayable]` 生成的成员），MUST NOT 包含同一宿主上任何 Provider 的产物。

### 8.3 占位符

- `{{value}}`：在 setter 层中替换为 `$value`；在 getter 表达式中替换为 `$this-><属性名>`，即内层值。
- `{{call}}`：在 setter 层中替换为内层链。最内层（最后登记的一层）不含 `{{call}}` 时，终结语句 `$this-><属性名> = $value;` MUST 追加到该层之后。

### 8.4 setter 与 getter

- setter 可多层。层序即站点收集顺序：源码自上而下等于由外到内，类级注解在成员级注解之外。除最内层外，每一层 MUST 包含 `{{call}}`，否则内层永远不会执行，展开期报错。
- getter 只允许一层。同一属性第二次请求 getter 会在展开期报错，因为 `{{value}}` 指内层值，而内层 getter 的 body 不是可调用表达式。
- getter 的 `{{value}}` 判定：属性有默认值时 getter MUST 包含 `{{value}}`，否则是虚拟 getter，展开期直接拒绝；属性无默认值时允许不含 `{{value}}` 的表达式，该属性成为 virtual。
- 属性是否 virtual 由钩子体内是否存在对 `$this-><属性名>` 的存储访问决定。set 的终结语句 `$this->x = $value;` 与 get 的终结 `return $this->x;` MUST 位于钩子体内，若改写成普通方法调用会导致属性被判为 virtual 并引发无限递归。
- 钩子 MUST 通过既有 `PropertyHookLowering::lowerProperty()` 降级，MUST NOT 自行构造方法，否则运行期元数据缺失会绕过钩子。
- 装上钩子后，宿主类内既有的对该属性的赋值会按钩子语义被改写，这是 v1 的既定行为。

### 8.5 冲突检测

- 同宿主两个生成成员同名 MUST 报错：方法名比较大小写不敏感，属性名与常量名比较大小写敏感。报错 MUST 同时给出对方站点。
- 生成成员与宿主手写成员同名 MUST 报错，并给出宿主声明所在行。
- trait `use` 语句不参与成员名冲突检测。
- 钩子目标属性不存在于宿主快照时 MUST 报错，因此对 Provider 生成的属性再装钩子会被拒绝（v1 没有 Provider 到 Provider 的流水线）。
- 属性已有同通道手写钩子时 MUST 报错，叠加到既有钩子属于 v1.1 范围。
- 只有 enum 宿主的生成属性被拒绝（消息见 9.2 节）。trait 宿主上的生成属性 MUST 照常写入，v1 不做额外检查。

## 9. 诊断

### 9.1 统一格式

展开期与注册期的诊断 MUST 是单行文本，由消息体加一个后缀方括号构成：`<消息体> [compile-time attribute: #[<注解FQCN>]; target: <目标描述>; source: <file>:<line>]`。其中 `<目标描述>` 由 `CompileTimeAttributeDiagnostic::describeTarget()` 产出，形如 `class Account`、`trait Foo`、`enum Grade`、`property $x`、`method add()`；`<file>:<line>` 是宿主文件与注解起始行。若消息体本身已含 `[compile-time attribute:` 标记，实现 MUST NOT 再追加一次。清单与 boot 期错误是不带该后缀的裸 `SyntaxError` 文本；站点内联文本 `#[<A>] on <目标描述> at <file>:<line>` 会出现在片段类错误的消息体中。

### 9.2 拒绝情景表

下表为 v1 的完整拒绝情景。消息文本 MUST 与代码逐字一致，触发点见附录 B。

| 情景 | 触发阶段 | 精确消息文本 |
|---|---|---|
| 清单顶层不是映射 | boot | ``compile-time-attributes` must be a map`` |
| 清单顶层未知键 | boot | ``Unknown key `<key>` in `compile-time-attributes` `` |
| providers 不是列表 | boot | ``compile-time-attributes.providers` must be a list`` |
| providers 不是列表（装载层） | boot | `compile-time-attributes.providers must be a list in compile-time-attributes.providers` |
| 条目不是映射 | boot | `compile-time-attributes.providers[<i>] must be a map with a `file` key in compile-time-attributes.providers` |
| 条目含未知键 | boot | `compile-time-attributes.providers[<i>] has unknown key(s) `<k>` in compile-time-attributes.providers` |
| `file` 缺失或为空 | boot | `compile-time-attributes.providers[<i>].file must be a non-empty string in compile-time-attributes.providers` |
| `class` 非法 | boot | `compile-time-attributes.providers[<i>].class must be a non-empty string in compile-time-attributes.providers` |
| Provider 文件不存在 | boot | `compile-time attribute provider file not found: <file> (resolved from compile-time-attributes.providers)` |
| `class` 未被该文件声明 | boot | `compile-time attribute provider class <C> was not declared by <file>` |
| 缺少 `#[ProviderFor]` | boot | `compile-time attribute provider class <C> is missing #[ProviderFor] in compile-time-attributes.providers` |
| 多个 `#[ProviderFor]` | boot | `compile-time attribute provider class <C> declares more than one #[ProviderFor]` |
| Provider 不可实例化 | boot | `compile-time attribute provider class <C> must be instantiable` |
| 缺少 `compile` 方法 | boot | `compile-time attribute provider class <C> must declare compile(Generator $g): void` |
| 未知 `target` | boot | `compile-time attribute provider class <C> declares unknown target `<t>`; expected one of class, trait, enum, property, method` |
| 空注解名 | boot | `compile-time attribute provider class <C> declares an empty annotation name` |
| 文件内没有 Provider 类 | boot | `compile-time attribute provider file <file> declares no class with #[ProviderFor]` |
| 两个 Provider 声明同一注解 | 注册 | `compile-time attribute #[<A>] is claimed by both <P1> and <P2>` |
| 占用内置注解名 | 注册 | `compile-time attribute #[<A>] is built in and cannot be provided by <P>` |
| Provider 文件位于 sources 内 | 配置 | ``Provider file `<path>` must live outside `sources` `` |
| lib 模式注册了 Provider | 配置 | ``compile-time-attributes` is not supported with `mode: lib` `` |
| 注解挂错宿主 | enterNode | `#[<短名>] can only be applied to a class`（`trait`/`enum`/`property`/`method` 同形；enum 为 `an enum`） |
| 注解在同一声明上重复 | enterNode | `<注解名> cannot be repeated on the same declaration` |
| 宿主是 `#[Native]` 类 | 展开收集 | `compile-time attribute providers cannot be applied to a #[Native] class` |
| 注解用于多属性声明 | 展开运行 | `provider attributes cannot be applied to a multi-property declaration; declare one property per line` |
| 实参不可求值 | 展开运行 | `attribute argument must be a literal, a literal array or ::class, got <TYPE>`（其余见附录 B） |
| Provider 抛出异常 | 展开运行 | `<ProviderClass> threw <ExceptionClass>: <message>` |
| 非属性站点省略属性名 | 展开运行 | `property() needs an explicit property name when the annotation targets <kind> <fqcn>` |
| 片段成员个数错误 | 片段解析 | `member() takes exactly one class member, got <N> <site>` |
| 片段成员种类错误 | 片段解析 | `member() accepts a method, a property, a class constant or `use TraitName;`, got <TYPE> <site>` |
| 片段含属性组 | 片段解析 | `attribute groups are not allowed in generated code; generated members are not expanded again <site>` |
| 生成成员名用保留前缀 | 片段解析 | `generated member name `<n>` is reserved <site>` |
| 生成成员名被禁止 | 片段解析 | `generated member name `<n>` is not allowed <site>` |
| 片段语法错误 | 片段解析 | `generated code does not parse: <msg> at generated line <N> <site>` |
| getter 不是单个表达式 | 片段解析 | `expected a single expression <site>` |
| 生成属性写入 enum | 展开汇总 | `generated property $<n> cannot be added to enum <fqcn>; enums cannot declare properties` |
| 两个生成成员重名 | 展开汇总 | `generated member `<n>` collides with another generated member requested at <file>:<line>` |
| 生成成员撞宿主成员 | 展开汇总 | `generated member `<n>` collides with the host declaration at <file>:<line>` |
| 钩子目标属性不存在 | 展开校验 | `property $<p> does not exist on <fqcn>; generated properties cannot be intercepted by another annotation` |
| 非 class 宿主要求钩子 | 展开校验 | `only classes support generated property hooks; <kind> <fqcn> cannot declare properties` |
| static 属性装钩子 | 展开校验 | `static property $<p> cannot have hooks` |
| readonly 属性装钩子 | 展开校验 | `readonly property $<p> cannot have hooks` |
| 提升参数装钩子 | 展开校验 | `promoted property $<p> cannot have hooks` |
| 属性已有同通道钩子 | 展开校验 | `property $<p> already declares a `<ch>` hook` |
| 同属性第二次 getter | 展开校验 | `property $<p> already has a generated getter requested at <file>:<line>; only one getter layer is supported` |
| 带默认值属性用虚拟 getter | 展开校验 | `getter for $<p> must read {{value}}: the property has a default value, so a virtual getter is not allowed` |
| 非内层 setter 缺 `{{call}}` | 展开校验 | `setter layer for $<p> must contain {{call}}, otherwise the inner layers would never run` |
| 合成钩子体语法错误 | 展开校验 | `generated code does not parse: <msg> at generated line <N> <site>` |

### 9.3 收集规则

同一宿主上全部站点的诊断 MUST 收集完再一次性抛出，多个 Provider 的错误 MUST 都出现在同一个异常消息中（以换行分隔，各自带自己的后缀）。`error()` 与任何片段失败都只影响当前站点。任何诊断都发生在缓存写入之前，因此失败结果 MUST NOT 被缓存。

## 10. 缓存与确定性

- Provider 指纹 MUST 参与缓存键。指纹为 `sha256`，输入是按 Provider 文件路径升序排列的每条定义的 `文件路径 \0 文件内容 sha256 \0 注解 FQCN \0 target \0 Provider 类名`，以 `\n` 连接；清单文件路径不参与，清单内容通过条目集合间接参与。
- 指纹 MUST 在非空时以 `compile-time-attribute-providers:` 为前缀并入 `getIncrementalGeneratorFingerprint()`。prepared 项目缓存键 `preparedProjectKey()` 与增量输出复用键都以该函数为输入，因此两者都含指纹。
- 指纹为空串时 MUST NOT 追加任何字节，零 Provider 的缓存键与输出 MUST 与未引入本特性的版本逐字节一致。
- 展开 MUST 是确定性的：同一份原始 AST 与同一 Provider 集合 MUST 产生逐字节相同的记录。记录只含源码字符串，成员顺序由站点收集顺序决定，与清单条目顺序无关。
- 记录 MUST 保存在进程内 memo 中，键为 `(宿主文件, 宿主 FQCN)`；Provider 表被重置时（generation 递增）memo MUST 整体失效。
- 跨进程确定性依赖 `compile()` 的纯度契约（3.2 节 SHOULD 条目）。v1 没有提供 `--strict-determinism`、`--dump-expansions`、`--no-providers` 这类命令行开关，代码中不存在这些选项。

## 11. 模式与边界

- `mode: bin` 支持本特性，`examples/compile-time-attribute/` 与 `phpunit/src/CompileTimeAttribute/ProviderBuildTest.php` 提供端到端用例。
- `mode: lib` MUST 拒绝：配置校验阶段直接报错，消息见 9.2 节。import stub 不跑展开入口，v1 不提供 lib 支持。
- `mode: ext` 没有被实现显式拒绝，但 v1 的端到端验证只覆盖 bin，本规范不为 ext 提供支持声明。
- 单文件模式与目录模式不解析 YAML，没有清单入口，因此不支持本特性。这两种模式下注解既不展开也不报错，按既有语义退化为运行时属性；若注解类只存在于清单目录内，运行期实例化会失败。
- CLI 没有与 Provider 相关的选项，清单键是唯一激活源。

v1 明确的非目标（未实现，MUST NOT 被当作受支持能力）：

| 非目标 | 说明 |
|---|---|
| `wrap()` 包裹或改写既有方法体 | v1 只新增成员与钩子，不改写既有代码。 |
| 引用语义 getter（`&get`） | `getter()` 只接受表达式，生成的钩子恒为非引用，DSL 没有对应入参。 |
| 与 `#[Immutable]` 的组合检查 | 展开后不追加只读契约校验。 |
| 跨项目聚合与生成新文件 | v1 只有逐宿主产物。 |
| 生成的注解类（元注解） | 需要多轮展开。 |
| 注解参数的非字面量求值 | 常量表在展开期尚不存在。 |
| Provider 之间互相消费产物 | 会引入隐式顺序。 |
| 模板 DSL、`warn()`、`deps` 声明、lib 模式支持、目录/单文件模式的清单 | v1 不提供，见上文。 |
| `--dump-expansions`、`--strict-determinism`、`--no-providers` | 代码中不存在这些开关。 |

## 12. 安全与可靠性

- Provider 文件在 boot 期被 `require_once`，Provider 实例在展开期被调用，两者都以编译器进程的权限执行。清单与 Provider 文件 MUST 被视为与编译器源码同信任级的构建输入：能在机器上修改 Provider 文件等同于能在构建期执行任意代码。
- v1 不提供沙箱、超时、内存配额或系统调用限制，实现 MUST NOT 假设存在这类保护。
- Provider MUST 被当作受信代码审查，清单是 Provider 的唯一注册来源，不存在环境扫描或自动发现；任何 Provider 失败都 MUST 中止整个构建，"半个插件集" MUST NOT 被接受。

## 13. 版本与扩展

v1 的冻结面如下，后续版本 MUST NOT 以破坏这些兼容点的方式演进：

- 清单键 `compile-time-attributes`、子键 `providers`、条目键 `file`/`class` 的含义与路径解析规则。
- `#[ProviderFor]` 的构造签名、`target` 取值集合、`Generator` 的 10 个方法与 6 个值对象的字段集合。
- 片段的四类成员、名字黑名单、属性组禁令、合成类解析方式，以及 `{{value}}`、`{{call}}` 的语义、setter 层序与 getter 单层限制。
- 诊断统一格式与 9.2 节的消息文本（消息属于接口，改动需按不兼容变更处理）。
- Provider 指纹的参与方式与零 Provider 字节一致性质。
- memo 键 `(宿主文件, 宿主 FQCN)` 与"每宿主至多一次计算"的执行模型。

允许的向后兼容扩展：新增可选清单键、新增 `Generator` 方法（旧 Provider 必须继续可用）、新增 `target` 取值（需同时扩展拒绝矩阵与测试）、新增拒绝情景。扩展 MUST NOT 改变已合法程序的展开结果，MUST NOT 改变既有消息文本。

## 14. 使用示例

本节给出一个完整可运行的最小工程：两个注解，一个在类上生成成员，一个在属性上装配 setter 层。示例只用本规范定义的能力，输出为实测结果。

### 14.1 工程结构

```text
demo/
  project.yml
  src/
    Attributes.php
    Counter.php
    main.php
  providers/
    VersionedProvider.php
    ClampProvider.php
```

`providers/` 与 `src/` 平级：Provider 文件 MUST NOT 位于 `sources` 之下（4 节），否则清单校验直接报错。

### 14.2 定义注解类

注解类就是普通 PHP 属性类，编译器只把它的 FQCN 当字符串使用，从不反射它，也不读它的 `Attribute` 位（2 节）。

```php
<?php

namespace Demo;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Versioned
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Clamp
{
    public function __construct(public readonly int $min, public readonly int $max)
    {
    }
}
```

### 14.3 编写 Provider

一个 Provider 处理一个注解，用 `#[ProviderFor]` 声明自己负责的注解与宿主种类。`target` 是宿主形状的唯一真相来源，与注解类上的 `Attribute` 位无关（5.1 节）。

类级 Provider 用 `member()` 登记新成员，可以直接读宿主快照：

```php
<?php

namespace Demo\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Demo\Versioned::class, target: 'class')]
final class VersionedProvider
{
    public function compile(Generator $g): void
    {
        $kind = strtolower($g->classInfo()->shortName);

        $g->member('public const KIND = ' . var_export($kind, true) . ';');
        $g->member('public function kind(): string { return self::KIND; }');
    }
}
```

属性级 Provider 用 `property()->setter()` 装配一层 setter body。`$value` 是这一层的入参，`{{call}}` 是通往内层（这里是真实属性写入）的调用，除最内层外 MUST 出现，否则内层永远不会执行（8.4 节）：

```php
<?php

namespace Demo\Providers;

use TypePhp\CompileTimeAttribute\Generator;
use TypePhp\CompileTimeAttribute\ProviderFor;

#[ProviderFor(\Demo\Clamp::class, target: 'property')]
final class ClampProvider
{
    public function compile(Generator $g): void
    {
        $min = $g->args()['min'];
        $max = $g->args()['max'];

        $g->property()->setter(
            'if ($value < ' . $min . ') { $value = ' . $min . '; }'
            . ' if ($value > ' . $max . ') { $value = ' . $max . '; }'
            . ' {{call}}',
        );
    }
}
```

片段是纯源码文本，类型名 MUST 写成 FQCN（7 节）。所有 `member()`、`setter()`、`getter()` 调用只登记意图：Provider 看不见自己或别的 Provider 生成的成员，全部产物到齐后才合并写入，任一失败则本宿主一个成员都不写（6 节）。

### 14.4 在清单中注册

清单键 `compile-time-attributes.providers` 是唯一的激活源，CLI 没有相关开关（11 节）：

```yaml
name: spec_example
mode: bin
output: spec_example
sources:
  - src
compile-time-attributes:
  providers:
    - file: providers/VersionedProvider.php
    - file: providers/ClampProvider.php
```

`file` 相对清单所在目录解析；条目顺序不影响产物，实现按 Provider 文件路径排序后装入注册表（4 节）。

### 14.5 使用注解

```php
<?php

namespace Demo;

#[Versioned]
final class Counter
{
    #[Clamp(min: 0, max: 10)]
    public int $value = 0;
}
```

```php
<?php

function main(): void
{
    $counter = new \Demo\Counter();

    $counter->value = 15;
    echo 'clamped=', $counter->value, \PHP_EOL;

    $counter->value = 3;
    echo 'kept=', $counter->value, \PHP_EOL;

    echo 'kind=', \Demo\Counter::KIND, \PHP_EOL;
    echo 'kind=', $counter->kind(), \PHP_EOL;
}
```

`KIND` 与 `kind()` 在源码里不存在，只由 Provider 生成；`$counter->value = 15` 走 setter 层。业务代码只能调用展开后的产物，不能依赖注解本身存在于运行期——单文件与目录模式不解析清单，注解在那里静默退化为运行时属性（11 节）。

### 14.6 编译与运行

在仓库根目录执行：

```sh
php bin/tpc.php demo/project.yml --no-progress
LD_LIBRARY_PATH="$PHPX_HOME/lib" demo/spec_example
```

输出的 `output` 字段决定可执行文件名，产物落在清单所在目录。运行产物需要运行时库位于 `LD_LIBRARY_PATH` 中：

```text
clamped=10
kept=3
kind=counter
kind=counter
```

`clamped=10` 是 setter 层把 15 改写为上限的结果，`kept=3` 未触发裁剪，`kind=counter` 来自生成的常量与方法。

### 14.7 完整示例

`examples/compile-time-attribute/` 覆盖 v1 的全部能力：10 个注解、class/trait/enum/property/method 五种 target、多层 setter、单层 getter、虚拟属性、方法签名改写与 `classInfo()` 摘要生成，`README.md` 中有逐条说明与完整输出。要验证自己的 Provider 实现，优先对照该目录的构建结果。

## 15. 参考文献

- RFC 2119, *Key words for use in RFCs to Indicate Requirement Levels*, <https://www.rfc-editor.org/rfc/rfc2119>
- RFC 8174, *Ambiguity of Uppercase vs Lowercase in RFC 2119 Key Words*, <https://www.rfc-editor.org/rfc/rfc8174>
- PHP Manual, *Property Hooks*, <https://www.php.net/manual/en/language.oop5.property-hooks.php>
- `docs/zh-cn/PROPERTY_HOOKS.md`：属性钩子降级与运行期元数据。
- `examples/compile-time-attribute/README.md`：v1 能力的参考示例与特性清单。
- `phpunit/src/CompileTimeAttribute/`：`ProviderRegistryTest`、`RejectionTest`、`SnippetParserTest`、`ExpansionTest`、`ProviderBuildTest`。
- `src/CompileTimeAttribute/`：本规范各章节的参考实现。

## 附录 A：清单与片段语法的 ABNF

本附录使用 RFC 5234 ABNF。`SP`、`WSP`、`CRLF` 取自 RFC 5234 核心规则；YAML 部分为结构抽象，缩进与引号规则以 YAML 规范为准。

```abnf
manifest-block  = "compile-time-attributes" ":" [ CRLF providers-map ]
providers-map   = 1*WSP "providers" ":" [ CRLF providers-list ]
providers-list  = 1*provider-entry
provider-entry  = 1*WSP "-" WSP entry-map
entry-map       = file-line [ CRLF class-line ]
                / class-line [ CRLF file-line ]
file-line       = "file" ":" WSP path-value
class-line      = "class" ":" WSP fqcn-value
path-value      = quoted-string / unquoted-path
fqcn-value      = qualified-name
qualified-name  = name *("\" name)

; 片段：包进合成类后解析，四类成员
member-source   = method-decl / property-decl / class-const-decl / trait-use
trait-use       = "use" fqcn *("," fqcn) ";"
method-decl     = php-method-decl          ; PHP 类体方法声明语法
property-decl   = php-property-decl        ; PHP 类体属性声明语法
class-const-decl = php-class-const-decl    ; PHP 类体类常量声明语法

; 钩子 body 中的占位符
placeholder     = "{{value}}" / "{{call}}"
setter-body     = *( %x00-7F / placeholder )
getter-expr     = *( %x00-7F / "{{value}}" )

; 合成包装与行号
member  = prefix-class  member-source  suffix-class
statements = prefix-method setter-body suffix-method
expression = prefix-return getter-expr suffix-return
prefix-class  = "<?php\nfinal class __TypePhpSnippet {\n"     ; 2 行前缀
suffix-class  = "\n}"
prefix-method = "<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): void {\n"
suffix-method = "\n} }"
prefix-return = "<?php\nfinal class __TypePhpSnippet { public function __typephp_snippet(): mixed { return "
suffix-return = ";\n} }"
generated-line = parser-line - 2    ; 最小为 1
```

## 附录 B：拒绝消息全表

本附录按源文件逐条列出 9.2 节消息的触发点，行号对应本文档适用的实现。`<...>` 为占位符，`<site>` 指 `#[<A>] on <目标描述> at <file>:<line>`。

**src/Translator.php**

- ``compile-time-attributes` must be a map`` (:4432)
- ``Unknown key `<key>` in `compile-time-attributes` `` (:4436)
- ``compile-time-attributes.providers` must be a list`` (:4441)
- ``Provider file `<path>` must live outside `sources` `` (:4453)

**src/Build/SourcePipelineTrait.php**

- ``compile-time-attributes` is not supported with `mode: lib` `` (:518)

**src/CompileTimeAttribute/ProviderBootstrap.php**

- `compile-time-attributes.providers must be a list in compile-time-attributes.providers` (:26)
- `compile-time-attributes.providers[<i>] must be a map with a `file` key in compile-time-attributes.providers` (:59)
- `compile-time-attributes.providers[<i>] has unknown key(s) `<k>` in compile-time-attributes.providers` (:66)
- `compile-time-attributes.providers[<i>].file must be a non-empty string in compile-time-attributes.providers` (:73)
- `compile-time-attributes.providers[<i>].class must be a non-empty string in compile-time-attributes.providers` (:80)
- `compile-time attribute provider file not found: <file> (resolved from compile-time-attributes.providers)` (:88)
- `compile-time attribute provider class <C> was not declared by <file>` (:113)
- `compile-time attribute provider class <C> is missing #[ProviderFor] in compile-time-attributes.providers` (:120)
- `compile-time attribute provider class <C> declares more than one #[ProviderFor]` (:126)
- `compile-time attribute provider class <C> must be instantiable` (:131)
- `compile-time attribute provider class <C> must declare compile(Generator $g): void` (:137)
- `compile-time attribute provider class <C> declares unknown target `<t>`; expected one of class, trait, enum, property, method` (:143)
- `compile-time attribute provider class <C> declares an empty annotation name` (:152)
- `compile-time attribute provider file <file> declares no class with #[ProviderFor]` (:166)

**src/CompileTimeAttribute/ProviderTable.php**

- `compile-time attribute #[<A>] is claimed by both <P1> and <P2>` (:39)
- `compile-time attribute #[<A>] is built in and cannot be provided by <P>` (:47)

**src/CompileTimeAttribute/ProviderDefinition.php**

- `#[<短名>] can only be applied to a class`，`enum` 时为 `an enum`，`trait`/`property`/`method` 为 `a <target>` (:50)

**src/Transform/CompileTimeAttribute.php**

- `<注解名> cannot be repeated on the same declaration` (:68)

**src/CompileTimeAttribute/Expansion.php**

- `compile-time attribute providers cannot be applied to a #[Native] class` (:53)
- `provider attributes cannot be applied to a multi-property declaration; declare one property per line` (:177)
- `<ProviderClass> threw <ExceptionClass>: <message>` (:219)
- `generated property $<n> cannot be added to enum <fqcn>; enums cannot declare properties` (:255)
- `generated member `<n>` collides with another generated member requested at <file>:<line>` (:263)
- `generated member `<n>` collides with the host declaration at <file>:<line>` (:273)
- `property $<p> does not exist on <fqcn>; generated properties cannot be intercepted by another annotation` (:322)
- `only classes support generated property hooks; <kind> <fqcn> cannot declare properties` (:330)
- `static property $<p> cannot have hooks` (:337)
- `readonly property $<p> cannot have hooks` (:342)
- `promoted property $<p> cannot have hooks` (:347)
- `property $<p> already declares a `<ch>` hook` (:353)
- `property $<p> already has a generated getter requested at <file>:<line>; only one getter layer is supported` (:380)
- `getter for $<p> must read {{value}}: the property has a default value, so a virtual getter is not allowed` (:388)
- `setter layer for $<p> must contain {{call}}, otherwise the inner layers would never run` (:413)

**src/CompileTimeAttribute/Generator.php**

- `property() needs an explicit property name when the annotation targets <kind> <fqcn>` (:75)

**src/CompileTimeAttribute/SnippetParser.php**

- `member() takes exactly one class member, got <N> <site>` (:32)
- `member() accepts a method, a property, a class constant or `use TraitName;`, got <TYPE> <site>` (:42)
- `expected a single expression <site>` (:75)
- `generated code does not parse: <msg> at generated line <N> <site>` (:89)
- `generated code does not parse <site>` (:95, :102)
- `attribute groups are not allowed in generated code; generated members are not expanded again <site>` (:125)
- `generated member name `<n>` is reserved <site>` (:144)
- `generated member name `<n>` is not allowed <site>` (:147)

**src/CompileTimeAttribute/Literal.php**

- `attribute argument must be a literal, a literal array or ::class, got <TYPE>` (:40)
- `attribute argument must be a literal number, got <TYPE>` (:50)
- `attribute argument must be a literal, got constant <name>` (:60)
- `spread in an attribute argument array is not allowed` (:70)
- `array keys in an attribute argument must be int or string` (:79)
- `only ::class is allowed in attribute arguments` (:90)
- `::class requires a class name` (:93)

**诊断后缀**

- ` [compile-time attribute: #[<A>]; target: <目标描述>; source: <file>:<line>]`（`src/Diagnostics/CompileTimeAttributeDiagnostic.php:56`）

## 附录 C：参考实现映射表

| 源文件 | 规范职责 | 章节 |
|---|---|---|
| `src/CompileTimeAttribute/ProviderFor.php` | `#[ProviderFor]` 注解定义 | 5 |
| `src/CompileTimeAttribute/ProviderBootstrap.php` | 清单条目解析、路径解析、反射校验、指纹计算 | 4、5、10 |
| `src/CompileTimeAttribute/ProviderTable.php` | 注册表、唯一性、内置冲突、active、generation | 5、10 |
| `src/CompileTimeAttribute/ProviderDefinition.php` | target 集合、注册表条目构造 | 5 |
| `src/CompileTimeAttribute/Generator.php` | Provider 门面与 10 个方法 | 6 |
| `src/CompileTimeAttribute/PropertyBuilder.php`、`ProductBuffer.php`、`HostView.php`、`TypeString.php` | 意图登记、按站点缓冲、宿主快照与类型字符串化 | 6、8 |
| `src/CompileTimeAttribute/Target.php`、`ClassInfo.php`、`MethodSignature.php`、`ParamInfo.php`、`PropertyInfo.php`、`Site.php` | 值对象 | 6、9 |
| `src/CompileTimeAttribute/Literal.php` | 注解实参求值 | 5、9 |
| `src/CompileTimeAttribute/SnippetParser.php` | 片段解析、名字黑名单、属性组禁令、行号偏移 | 7、9 |
| `src/CompileTimeAttribute/Expansion.php` | 展开顺序、冲突检测、钩子校验与合成、写入 | 8、9 |
| `src/CompileTimeAttribute/ExpansionMemo.php` | 进程内记录与失效 | 8、10 |
| `src/CompileTimeAttribute/ProviderException.php` | 诊断通道与格式标记 | 9 |
| `src/Transform/Visitor.php`、`src/Transform/CompileTimeAttribute.php` | 展开接入点（`leaveNode` 末尾、内置降级之后）、target 与重复性校验（`enterNode`） | 5、8、9 |
| `src/Transform/CompileTimeAttributeRegistry.php` | 注册表合并、常量、内置 24 条定义 | 5 |
| `src/Translator.php`、`src/Build/SourcePipelineTrait.php` | 清单入口、sources 之外检查、Provider 表重置、lib 拒绝、模式入口 | 4、10、11 |
| `src/Build/IncrementalCompilationTrait.php`、`src/Build/PreparedProjectCacheTrait.php` | 指纹参与缓存键 | 10 |
| `phpunit/src/CompileTimeAttribute/*.php`、`examples/compile-time-attribute/` | 符合性测试与参考示例 | 1、9、11 |
