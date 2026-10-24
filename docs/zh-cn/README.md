# TypePHP 编译器内部文档

本目录包含编译器实现、兼容性、构建模式和专项设计文档。用户侧使用手册位于独立的 `aot/docs` 仓库；这里的研究报告和重构计划可能描述历史状态，当前行为应以代码、测试和兼容性清单为准。

## 当前权威文档

- [AOT 与 PHP 不兼容特性清单](INCOMPATIBLE_PHP_FEATURES.md)：当前限制的简明清单。
- [不兼容性分类](PHP_INCOMPATIBILITY_CLASSIFICATION.md)：区分 Hard Limit、Intentional Rule、Pending 和 Partial。
- [兼容性工程准则](COMPATIBILITY_POLICY.md)：PHP 兼容性工作的优先级、PHPX 抽象边界与 Code Review 规则。
- [强类型原生引用设计](TYPED_NATIVE_REFERENCE_DESIGN.md)：引用参数只允许编译期推断；其他调用必须显式 `std::ref()`，不再推进运行时自动识别。
- [编译器命令行](COMPILER_CLI.md)：当前 CLI 参数和项目配置。
- [编译模式](COMPILATION_MODES.md)：binary、extension、library 模式。
- [快速入门](QUICKSTART.md)：最小编译流程。
- [将 PHP 依赖嵌入可执行文件](EMBEDDED_FILES.md)：`embedded-files` 的发布配置、Composer autoload、构建依赖和缓存规则。
- [编译期函数](COMPILE_TIME_FUNCTIONS.md)：`std::any()`、`std::ref()`、`std::expected()`、`std::unexpected()` 和关键词方法。
- [原生类型](NATIVE_TYPES.md)、[高精度类型](HIGH_PRECISION_TYPES.md)、[Std 容器](STD_CONTAINERS.md)。
- [varint_types 当前行为与实现边界](VARINT_TYPES.md)：文件模式对整数存储、算术、常量诊断、类型检查、容器与优化的完整影响范围。
- [三套对象存储与传递模型](OBJECT_STORAGE_AND_PASSING_MODELS.md)：Zend Object、PHPX Box 与 Native Class Object 的职责、ABI 和不可替代边界。
- [通用与扩展方法](UNIVERSAL_METHODS.md)、[Generator](YIELD_GENERATOR.md)。
- [`#[Immutable]` 编译期只读契约](IMMUTABLE.md)：方法、参数、别名、调用边界与动态逃逸规则。
- [强类型 PHP 数组与类型注解](TYPED_ARRAYS.md)：StdList/StdDict 元数据、直接写入检查与动态逃逸边界。
- [类继承](CLASS_INHERITANCE.md)、[混合 C++/PHP](MIXED_CPP_PHP.md)。

## 架构与维护

- [类型注解统一设计](TYPE_ANNOTATIONS.md)：StdArray/StdVector/StdMap/StdOrderedMap/StdList/StdDict 的契约、存储、索引和动态边界，区分现有实现与目标设计。
- [StdFunc / StdArgInfo 类型注解设计](STD_FUNC_DESIGN.md)：拟议的函数签名、Nullable、引用、Optional、Variadic 和调用端缺省值补齐；尚未实现。
- [后端中立 IR](BACKEND_NEUTRAL_IR.md)
- [TypePHP WASM 技术方案与实施计划](TYPEPHP_WASM_IMPLEMENTATION_PLAN.md)
- [构建 TypePHP WASI 程序](WASI_BUILD.md)
- [重建 PHPX WASM 静态库](PHPX_WASM_BUILD.md)：增量重编 `libphpx.a`、数值依赖重建与完整 SDK 重建边界。
- [核心重构计划](REFACTORING_PLAN.md)
- [作用域管理设计](SCOPE_MANAGEMENT.md)：`CallableScope`、`UserCodeScopeGuard` 与 `FakeScopeGuard` 的职责和使用边界。
- [运行时初始化与关闭流程](RUNTIME_LIFECYCLE.html)：PHP、PHPX、TypePHP 与项目四层生命周期，覆盖 bin/ext/lib、多模块及 WASM。
- [C++ 命名空间、前缀与符号 ABI](CPP_SYMBOL_NAMING.md)：`typephp_`、`php::`、`typephp_<project>` 与用户 callable `php_` 的职责边界和冲突规则。
- [Zend Object 创建与属性默认值初始化](OBJECT_CREATION.md)：`gen_stub.php` 默认属性表、自定义 `create_object` 的触发条件、执行流程与性能边界。
- [Native Class Object 设计](NATIVE_CLASS_OBJECT.md) 与 [实现验收矩阵](NATIVE_CLASS_IMPLEMENTATION_AUDIT.md)。
- [PHP 8.4 Property Hook 集成设计](PROPERTY_HOOKS.md)：编译期 lowering、Zend Hook 元数据、对象内省及 PHPX ABI 边界。
- [Interface Property Hook 实现方案](INTERFACE_PROPERTY_HOOKS.md)：接口属性契约、编译期方差检查及 PHP 8.4 抽象 Hook 元数据。
- [构建速度研究](AOT_BUILD_SPEED_RESEARCH.md)
- [优化优先级](aot-optimization-priority.md)
- [高精度类型原地运算优化方案](BIG_NUMBER_INPLACE_OPTIMIZATION_PLAN.md)
- [GMP 差异](GMP_GAP.md)

## 研究与历史资料

`hhvm-review.md`、`kphp-review.md`、`peachpie-review.md`、`phpstan-design-analysis.md`、`php-src-optimizer-analysis.md` 以及专利草案用于记录调研时点的比较和设计背景，不作为当前功能清单。

## 维护规则

1. 当前兼容性变化同时更新 `INCOMPATIBLE_PHP_FEATURES.md` 和分类文档。
2. 所有语法和语义限制统一链接到当前兼容性清单，避免维护重复清单。
3. 功能是否支持应以 PHPT/PHPUnit 回归测试为依据。
4. 历史研究文档保留原始比较结论，并在需要时注明调研日期，不应悄然改写为当前状态。
