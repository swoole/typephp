[简体中文](README-CN.md) | [English](README.md)

<div align="center">

# TypePHP

**PHP 原生 AOT 编译器**

将 PHP 源码提前（AOT）编译为原生机器码，生成原生可执行文件、PHP 扩展和共享库，
同时保留你熟悉的 PHP 语法。

[![Linux x64](https://github.com/swoole/typephp/actions/workflows/linux-x64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/linux-x64.yml)
[![Linux ARM64](https://github.com/swoole/typephp/actions/workflows/linux-arm64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/linux-arm64.yml)
[![macOS ARM64](https://github.com/swoole/typephp/actions/workflows/macos-arm64.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/macos-arm64.yml)
[![Windows x64](https://github.com/swoole/typephp/actions/workflows/windows-build.yml/badge.svg)](https://github.com/swoole/typephp/actions/workflows/windows-build.yml)
[![PHP 8.4–8.5](https://img.shields.io/badge/PHP-8.4--8.5-777bb4.svg)](https://www.php.net/)
[![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-blue.svg)](LICENSE)

</div>

---

## 什么是 TypePHP？

TypePHP 是一个 AOT（Ahead-Of-Time，提前编译）编译器，它把 PHP 源码翻译为 C++，
再编译为原生机器码。与字节码缓存或虚拟机不同，它不会在运行时解释 opcode，
而是直接生成在 CPU 上运行的原生二进制。

它保留熟悉的 PHP 语法，同时引入编译期类型信息，让编译器为性能热点生成快速、
静态类型的 C++ 代码。动态 PHP 值、内置函数、反射和对象元数据继续通过 PHPX
与 Zend runtime 互操作；用户函数编译完成后不再以 Zend opcode 方式执行。

TypePHP **完全由 PHP 语言编写**，并且**完全自举**：`tpc` 编译器二进制就是
用 TypePHP 编译编译器自身的 PHP 源码得到的。整个自举链路是纯 PHP——编译器
本身没有任何 C 或 C++ 胶水代码。

TypePHP 仍在积极开发中。它提供的是边界明确、可测试的 PHP 子集，而不是宣称可以
无修改替代所有高度动态的 PHP 程序。在将现有项目迁移到 TypePHP 前，请先阅读
[兼容性模型](#兼容性模型)和[不兼容特性清单](docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md)。

## 工作原理

```text
PHP 源码 + .stub.php 声明 + 可选 C/C++ 源码
                       │
                       ▼
          解析、校验并收集全部声明
                       │
                       ▼
       将函数实现和常量表达式降级为 C++17
                       │
                       ▼
        原生编译器 + 可复用对象/PCH 缓存
                       │
                       ▼
 可执行文件 | PHP 扩展 | 共享库 | WASI Component
```

prepare 阶段只建立完整符号模型，不分配运行时 Cache ID。常量和声明默认值只保留
AST，待全部项目符号就绪后再在 convert 阶段解析。这一两阶段设计保证多文件构建和
编译器自举过程具有确定性。

## 特性

- **完全自举、纯 PHP 实现** —— TypePHP 编译器完全由 PHP 语言编写，并能自举：
  用 `tpc` 编译编译器自身的源码，即可生成原生二进制。
- **真正的 AOT 编译** —— PHP 先降级为 C++17，再编译为原生机器码。无解释器、
  无 opcode 缓存、无 JIT 预热。
- **三种原生构建模式** —— 同一份代码可编译为原生 `bin` 可执行文件、可加载的
  PHP `ext` 扩展，或可复用的 `lib` 共享库。
- **原生类型系统** —— `int`、`float`、`bool` 直接映射为 C++ 标量类型
  （`int64_t`、`double`、`bool`），数值代码可获得数量级的性能提升。
- **高精度数值** —— `bigInt`（GMP）、`decimal`（libmpdec）、`bigFloat`（MPFR），
  提供强类型运算符和方法 API。
- **强类型容器** —— `std::array`、`std::vector`、`std::map`、`std::orderedMap`，
  元素类型在编译期确定；最高比 PHP 数组快 **10 倍**，性能与 C++ `std::vector` 相当。
- **通用方法（Universal Methods）** —— 在原生类型上直接调用方法
  （`$s->upper()`、`$arr->contains()`、`$big->mul(2)`）；静态类型已知时在编译期
  直接解析调用。
- **混合 C++ / PHP 编程** —— 在性能关键内核中直接调用 C++ 函数（反之亦然）。
- **编译期函数与关键词** —— `std::any()`、`std::object()`、`std::ref()`、
  `std::expected()`、`std::unexpected()`，以及 `toObject()`、`toInt()`、
  `toString()`、`toArray()` 等。
- **编译期安全检查** —— `#[Immutable]` 只读契约和 `StdList` / `StdDict` 数组类型注解，
  在编译期检查，零运行时开销。
- **编译期代码生成** —— `#[Getter]`、`#[Setter]`、`#[With]`、`#[Constructor]`、
  `#[Printer]` 和 `#[Arrayable]` 根据属性声明生成类型安全的方法。
- **现代 PHP 支持** —— PHP 8.4 property hooks、非对称可见性、PHP 8.5
  `clone()`-with 以及 `(void)` 丢弃表达式。
- **跨平台、移动原生与 WASM** —— 面向 x64 和 ARM64 的 Linux、Windows、macOS
  目标，使用 Android NDK 和 iOS SDK 开发 Android/iOS 原生应用，以及生成 WASI 0.2
  和浏览器（Jco）输出。
- **Python 桥接** —— 为 Python 模块生成 IDE helper，并将 Python 脚本转换为 TypePHP。

## 为什么选择 TypePHP？

| | TypePHP AOT | 字节码缓存（OPcache） | JIT（PHP 8+） |
|---|---|---|---|
| 编译目标 | 原生机器码 | 字节码 | 机器码（trace） |
| 启动 / 预热 | 无（已编译完成） | 每进程预热 | JIT 预热 |
| 类型驱动优化 | 编译期、全程序 | 无 | 有限，基于 trace |
| 生成原生可执行文件 | 支持 | 不支持 | 不支持 |
| 源码保护 | 编译为机器码 | 字节码（可还原） | 字节码（可还原） |
| 性能确定性 | 是 | 否 | 否 |

**相较原生 PHP 的优势：**

- **接近原生的性能。** 数值密集和容器密集的热点路径会编译为与 C++ 程序相同的机器码。
  见下方[基准测试](#基准测试)。
- **源码保护。** 源码被编译掉——交付物是原生二进制，而不是可读的 PHP 文件。
- **原生进程入口。** 二进制模式直接启动原生可执行文件，不需要 PHP CLI 或独立的
  解释器进程。可执行文件仍会嵌入或链接 PHPX、`libphp` 及项目配置的原生库，部署包
  中必须提供这些运行时依赖。
- **默认使用强标量类型。** 推断出的 `int`、`float`、`bool` 局部变量直接使用
  C++ 原生存储。单个动态值使用 `std::any()`；只有文件确实依赖 PHP 整数扩展语义时，
  才使用 `use varint_types`。
- **始终严格调用。** TypePHP 不启用 PHP 的弱标量类型转换，无需声明
  `declare(strict_types=1)`。
- **Zend 生态互通。** 扩展模式以标准 PHP 扩展形式加载，项目可以调用受支持的
  内置函数，并显式声明依赖的其他 Zend 扩展。

## 前置要求

- **PHP 8.4 – 8.5** CLI、开发头文件及 `php-config`
- 在类 Unix 系统构建二进制/共享库时，需要与 PHP 匹配的 **embed 库**
  （`libphp.so` 或 `libphp.dylib`）
- **GCC 9+**（或 Clang），支持 **C++17**
- **CMake 3.24+**
- **Composer 2**
- 高精度数学库：**GMP**、**MPFR**（libmpdec 已随 PHPX 内置）

```shell
# Ubuntu/Debian
sudo apt install build-essential cmake pkg-config libgmp-dev libmpfr-dev

# RHEL/CentOS/Fedora
sudo dnf install gcc gcc-c++ cmake pkgconf-pkg-config gmp-devel mpfr-devel

# Arch Linux
sudo pacman -S base-devel cmake pkgconf gmp mpfr
```

> GMP 用于 `bigInt`，MPFR 用于 `bigFloat`。`decimal` 底层是 libmpdec，
> 已随 PHPX 内置，无需单独安装。

Linux x64 是主要开发及全量测试 CI 平台。编译器也提供 Windows、macOS、ARM64、
Android `arm64-v8a`、iPhoneOS `arm64` 和 WASI 后端；具体主机能否构建某个目标，
仍取决于 PHP embed、平台 SDK、工具链和第三方库是否可用。移动端可以将界面结构、
应用状态和业务逻辑编写为 TypePHP，仅使用轻量的平台原生 UI 桥接，参见
[Android 原生应用示例](examples/android-native/)和
[iOS/macOS 原生应用示例](examples/apple-native/)。

原生 Release Assets 默认使用 PHP 8.5 ZTS 的最新版本构建，提供 Linux x64、Linux
ARM64、macOS ARM64 和 Windows x64 四个平台包；不提供原生 NTS 或 32 位 x86 包。
Linux 与 macOS 包包含编译器和 production Composer 依赖，Windows 包则包含完整且
匹配的 PHP/PHPX 运行时与 SDK。

## 安装

### 通过 Composer

```bash
composer require --dev swoole/typephp
```

然后编译你的项目：

```bash
vendor/bin/tpc.php project.yml
```

在 TypePHP 源码仓库中开发时，改用本地入口：

```bash
bin/tpc.php project.yml
```

### 从源码安装

```bash
git clone https://github.com/swoole/typephp.git
cd typephp
composer install
php bin/tpc.php --help
```

可以使用 `PHPX_HOME` 指向独立的 PHPX 源码或安装目录。`PHP_HOME` 可以指向 PHP
embed 安装前缀；在类 Unix 系统中，该目录应包含 `bin/php-config`、PHP 头文件和
`lib/libphp.so`。

### 构建 `libphp.so`

二进制和共享库构建需要 PHP 的 `embed` SAPI。如果 Linux 上缺少 `libphp.so`，
`tpc.php` 可以交互式下载 PHP 源码并自动构建。PHP 扩展构建从宿主 SAPI 解析 Zend
符号，不能再加载第二份 `libphp`。详见[自动构建 libphp.so](docs/zh-cn/LIBPHP_INSTALLER.md)。

## 快速开始

创建 `hello.php`：

```php
<?php

function main(): void
{
    echo "Hello World!\n";
    var_dump(PHP_VERSION);
    var_dump(php_uname());
}
```

编译并运行：

```bash
bin/tpc.php hello.php
./hello
```

输出示例（具体 PHP 版本和平台字符串取决于实际链接的运行时）：

```
Hello World!
string(5) "8.x.x"
string(16) "Linux ..."
```

> 二进制模式需要全局 `main()` 函数。它可以声明为无参数，或
> `main(int $argc, array $argv)` 以接收命令行参数，且必须返回 `void`。全局作用域
> 不允许可执行语句；可执行代码必须位于函数或方法内。

### 无 VM 的 Nano 原生程序

使用 `--nano` 可将单个 PHP 源文件与 PHP Nano、PHPX 源码整体编译。产物不链接
`libphp`，也不包含 Zend opcode 解释器：

```bash
./bin/tpc.php --nano examples/hello.php
./hello
```

默认可执行文件生成在执行 `tpc` 时的当前目录；普通模式与 Nano 模式共用
`build` 目录保存生成代码、目标文件等中间产物。可使用 `-o` 显式修改输出路径。

PHP 与 Composer 仅用于编译期。在 Linux、macOS、iOS、Android 上，生成的程序
使用静态选定的 Nano 运行时及仅文件模式的 stream。Native Nano 可使用 C11、
C++17 与 POSIX.1-2008，但依然不提供 socket、DNS、网络、远程 stream、动态 PHP
加载及进程执行能力。WASI 是更小的能力子集，直接调用目标不支持的 API 会在
编译期报错。

所有平台的 `--nano` 都会拒绝 `eval`、`include`、`include_once`、`require`、
`require_once` 等 VM 入口以及匿名类。

Windows 的差异在于构建后端：即使指定 `--nano`，也仍走原有的宿主机编译、链接
流程，通过 import library 连接 `php.dll` 与 `phpx.dll`。Windows 不加载
`swoole/php-nano`、`swoole/phpx` 的源码清单，也不会把它们的 C/C++ 源文件加入
项目 `sources`。外部命令 API 与反引号语法依然会被拒绝；请求启动时还会从 Zend
函数表移除这些命令函数，避免变量函数或回调形式绕过编译期检查。

除运行时 sources、头文件目录、编译宏和链接输入外，Nano 与普通模式共用同一套
命令行参数解析、TypePHP 代码生成、并行任务调度、编译进度条、输出路径规则以及
`main(int $argc, array $argv)` 参数语义。

## 编译模式

TypePHP 支持三种构建模式，通过 `-m` / `--mode` 选择：

| 模式 | 参数 | 输出 | 需要 `main()` | 典型用途 |
|---|---|---|---|---|
| 二进制 | `-m bin`（默认） | 可执行文件 | 是 | CLI 工具、常驻服务、独立应用 |
| 扩展 | `-m ext` | PHP `.so` / `.dll` | 否 | 将编译后的函数和类加载到 PHP SAPI |
| 库 | `-m lib` | 共享库及自动生成的 `.stub.php` | 否 | 在其他项目中复用编译后的 TypePHP API |

```bash
# 二进制（默认）
bin/tpc.php app.php -o myapp

# PHP 扩展
bin/tpc.php extension/ -m ext -o my_extension

# 共享库，同时生成 mylib.stub.php
bin/tpc.php lib/ -m lib -o mylib
```

详见[编译模式](docs/zh-cn/COMPILATION_MODES.md)。

## 项目配置

多文件项目建议使用 `project.yml` 固化可复用的构建配置：

```yaml
name: myapp
mode: bin
php-version: "8.5"
optimize: 2
job: 8
build-dir: build
cxx-std: c++17

sources:
  - src
  - cpp-src
  - path: src/php85
    if: PHP_VERSION_ID >= 80500
  - path: src/windows
    if: PHP_OS_FAMILY == "Windows"

# 将文件打包到二进制，并为 ZendVM 提供字节码及内存文件读取。
bundled-files:
  - vendor

# 由项目自身的原生构建流程预编译。
objects:
  - native/build/startup.o
  - path: native/build/platform.obj
    if: PHP_OS_FAMILY == "Windows"

ignore:
  - src/experimental

include-paths:
  - native/include
defines:
  - FEATURE_FAST_PATH=1
link-paths:
  - native/lib
link-libs:
  - curl

# Zend 扩展依赖，不是原生链接库。
# `extension-dependencies` 是等价长名称，两者不能同时使用。
ext-deps:
  - pdo_mysql
  - curl
```

路径以 YAML 文件所在目录为基准。source 可以是文件或目录；条件 source 支持
`PHP_VERSION`、`PHP_VERSION_ID` 和 `PHP_OS_FAMILY`。命令行参数优先于 YAML
中的同名配置。扫描源码目录时会进入符号链接指向的目录，因此通过 Composer path
仓库安装的依赖（以符号链接方式安装）会像其他源码一样被编译；如需排除，请在
`ignore` 中按访问该目录所用的路径书写。原生链接依赖应写入 `link-libs`；`ext-deps` 会生成
`ZEND_MOD_REQUIRED`，缺少所需 PHP 扩展时由 Zend 拒绝加载模块。
`bundled-files` 支持与 `sources` 相同的文件、目录及条件写法，仅在显式配置时启用。
所列文件全部打包进二进制；未通过 `sources` 成功原生编译的 PHP 文件由 OPcache
生成字节码。运行时的 `require` 和 `require_once` 从内存交给 ZendVM 执行，
无需读取磁盘上的 PHP 文件。构建字节码的 PHP CLI、OPcache 与目标 PHP 运行时需匹配。
运行二进制文件时无需 Composer 安装、磁盘 vendor 文件或 OPcache 扩展；Composer
自动加载文件也已嵌入，仍按需加载类。构建时有 OPcache 的情况下，匿名类在首次执行
对应 `new class` 表达式时从同一字节码表加载。未配置 `bundled-files` 且缺少
OPcache 时，匿名类退回内嵌 PHP 代码方式。
仅当目录名为 `vendor` 且目录下存在 `autoload.php` 时，才会使用字节码缓存；
目录 mtime 及构建用的 PHP/OPcache 未变化时复用字节码。其他 `bundled-files`
文件（包括缺少 `autoload.php` 的同名目录）每次构建都重新生成。
修改目录下已有文件不会更新目录 mtime；这种情况可用 `--force` 重新生成
vendor 字节码。
通用的 `objects` 列表会把已有 `.o`/`.obj` 文件直接加入链接步骤。TypePHP
不会重新编译这些文件；原生编译器、目标架构、编译参数和增量构建均由项目负责。
使用目标通用参数的原生文件仍应放入 `sources`；仅当某个编译单元需要不同参数且
生成的对象与最终目标 ABI 兼容时，才应预编译后放入 `objects`。例如 `-m32`
生成的对象不能直接链接到 64 位目标，必须由项目在 tpc 产出 ELF 后另行封装。
项目级 `cxx-flags`、`c-flags`、`asm-flags` 和 `ld-flags` 分别应用于
C++、C、汇编和链接命令。

构建目录中的可读 C++ 和头文件与内部产物分开存放。对象文件、opcode 字节码、
二进制归档、清单、链接响应文件和预编译头均放在 `build-dir/cache` 下。复用同一个
构建目录可以显著加快增量构建；仅在确实需要重编 PHPX 公共对象时使用 `--force`。

全部项目配置项及命令行优先级详见[编译器命令行](docs/zh-cn/COMPILER_CLI.md)。

## 兼容性模型

TypePHP 会在适合 AOT 编译的范围内保持 PHP 语法和运行行为，同时有一些明确限制：

- 全局作用域只允许声明，可执行语句必须位于函数或方法内；
- 二进制模式对 `main()` 使用严格签名；
- 推断出的 `int`、`float`、`bool` 默认使用固定原生存储，之后不能改为不兼容类型；
- `use varint_types` 使推断出的整数存入 `php::Var`，保留 PHP 的整数溢出和除法语义；
  `std::any()` 则只擦除单个表达式的静态类型；
- 静态可确定的调用和属性会直接编译，受支持的动态操作则通过 PHPX/Zend runtime
  fallback 执行；
- `.stub.php` 用于声明 C++ 或外部库 API，函数体必须为空，stub 文件禁止声明
  `#[Native]` 类；
- 部分高度动态的引用、声明、闭包和反射模式仍明确不支持。

兼容性边界属于公共契约，同时有正向和负向测试保护。请以
[不兼容 PHP 特性清单](docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md)为当前准确列表，不要把
README 未提及的行为默认理解为已支持。

## 编译期 Attribute 与代码生成

TypePHP 在 class lowering 阶段消费内置的代码生成 Attribute。生成的方法保留属性
声明的类型，并与显式声明的方法一样参与名称冲突、继承关系和 final 方法检查。

| Attribute | 目标 | 生成的 API |
|---|---|---|
| `#[Getter]` | 实例属性，包括构造器提升属性 | `public function getName(): T` |
| `#[Setter]` | 可变实例属性，包括构造器提升属性 | `public function setName(T $name): void` |
| `#[With]` | 可变实例属性，包括构造器提升属性 | `public function withName(T $name): static`；克隆对象、修改副本并返回副本 |
| `#[Constructor]` | 普通实例属性声明 | 将属性加入自动生成的 public `__construct()` |
| `#[Printer]` | 具名类 | `public function __toString(): string` |
| `#[Arrayable]` | 具名类 | `public function toArray(): array` |

```php
<?php

#[Printer(fields: ['id', 'name'])]
#[Arrayable(fields: ['id', 'name'])]
final class User
{
    #[Constructor, Getter, With]
    public int $id;

    #[Constructor, Getter, Setter]
    public string $name = 'guest';
}

function main(): void
{
    $user = new User(7);
    $user->setName('Alice');

    $copy = $user->withId(8);
    echo $user->getId();       // 7
    echo $copy->getId();       // 8
    echo $user;                // User(id=7, name=Alice)
    echo $user->toArray()['name'];
}
```

未指定 `fields` 时，`#[Printer]` 和 `#[Arrayable]` 使用当前类自身的 public 实例
属性。位置参数写法 `#[Arrayable(['id'])]` 等价于
`#[Arrayable(fields: ['id'])]`。

`#[Getter]`、`#[Setter]` 和 `#[With]` 不能用于 static 属性或带 property hook 的
属性；`#[Setter]` 和 `#[With]` 还会拒绝 readonly 属性。类中已经显式声明
`__construct()` 时不能使用 `#[Constructor]`，必填的构造属性必须位于带默认值的
属性之前。生成的方法名若与已有方法冲突，或覆盖继承而来的 final 方法，编译期会
直接报错。

## 使用示例

### 1. 原生类型 —— 编译期数值加速

```php
<?php

function fib(int $n): int
{
    if ($n == 1 || $n == 2) {
        return 1;
    }
    return fib($n - 1) + fib($n - 2);
}

function main(int $argc, array $argv): void
{
    $n = (int)$argv[1];
    $begin = microtime(true);
    echo fib($n) . "\n";
    echo "Time: " . (microtime(true) - $begin) . "\n";
}
```

```bash
bin/tpc.php fib.php -O3 -o fib
./fib 30
```

默认情况下，推断和声明的 `int` 变量都会变为 C++ `int64_t`，算术运算直接编译为
CPU 指令，而不是 ZendVM 调用。仅当本文件需要 PHP 的整数溢出转浮点、整数除法产生
非整数结果等语义时，才添加 `use varint_types`。

### 2. 高精度数值

```php
<?php

function main(): void
{
    // 54 位整数 —— 自动识别并存储为 bigInt
    $a = std::bigInt("123456789012345678901234567890123456789012345678901234");
    $b = std::bigInt("987654321098765432109876543210987654321098765432109876");

    echo $a->add($b)->toString() . "\n";   // 精确计算，不会溢出

    // 精确的十进制运算 —— 无二进制浮点误差
    $c = std::decimal("0.1")->add(std::decimal("0.2"));
    echo $c->toString() . "\n";            // "0.3"

    // 256 位浮点数
    $pi = std::bigFloat("3.14159265358979323846264338327950288419716939937510");
    echo $pi->mul(2)->toString() . "\n";
}
```

详见[高精度类型](docs/zh-cn/HIGH_PRECISION_TYPES.md)和[原生类型](docs/zh-cn/NATIVE_TYPES.md)。

### 3. 强类型容器

```php
<?php

function main(): void
{
    $vector = std::vector(Type::Int);

    $vector[] = 1;
    $vector[] = 2;
    $vector[] = 3;

    $sum = 0;
    foreach ($vector as $value) {
        $sum += $value;
    }

    echo $sum . "\n";       // 6
    echo $vector[1] . "\n"; // 2

    // 固定 key/value 类型的映射
    $map = std::orderedMap(Type::String, Type::Int);
    $map["a"] = 1;
    $map["b"] = 2;
}
```

详见 [Std 容器](docs/zh-cn/STD_CONTAINERS.md)。

### 4. 通用方法

```php
<?php

function main(): void
{
    $s = "hello world";
    echo $s->length() . "\n";       // strlen()
    echo $s->upper() . "\n";        // strtoupper()
    echo $s->substr(0, 5) . "\n";   // substr()

    $arr = [1, 3, 5, 7, 9];
    echo $arr->count() . "\n";      // count()
    var_dump($arr->contains(3));    // in_array()

    $big = std::bigInt("12345678901234567890");
    echo $big->mul(2)->toString() . "\n";
}
```

原生类型上的方法调用在编译期被解析为直接的 C/C++ 函数调用——没有虚表查找、
没有反射、没有运行时派发。详见[通用方法](docs/zh-cn/UNIVERSAL_METHODS.md)。

### 5. 混合 C++ / PHP

用 C++ 编写性能关键内核，并在 PHP 中调用：

```cpp
// math.cpp
#include <phpx.h>

using namespace php;

Int php_fast_sum(Int a, Int b) {
    return a + b;
}
```

```php
<?php
// math.stub.php —— 声明 C++ 函数签名
function fast_sum(int $a, int $b): int {}
```

```php
<?php
function main(): void
{
    echo fast_sum(3, 4) . "\n";  // 7
}
```

需要将 `math.cpp`、`math.stub.php` 和调用它的 PHP 源码加入同一个项目配置。
C++ 符号的 `php_` 前缀属于 TypePHP callable ABI；stub 函数只提供类型元数据，
不能包含实际实现。

详见[混合 C++/PHP](docs/zh-cn/MIXED_CPP_PHP.md)。

## 基准测试

### PHP 语言基准（来自 php-src）

TypePHP 使用 `-O3` 运行 PHP 源码树自带的官方 `bench.php` 与
`micro_bench.php` 语言性能测试：

| 基准 | 解释执行 PHP | TypePHP AOT（`-O3`） | 加速比 |
|---|---|---|---|
| `bench.php`（总计） | 5.034 秒 | **0.603 秒** | 约 8× |
| `micro_bench.php`（总计） | 13.045 秒 | **2.021 秒** | 约 6.5× |

两项基准覆盖 PHP 语言核心性能——函数调用、对象属性访问、数组/哈希访问、
字符串处理、控制流等。测试代码见 [`benchmark/bench.php`](benchmark/bench.php) 和
[`benchmark/micro_bench.php`](benchmark/micro_bench.php)。其他专项性能回归测试
统一放置在 [`benchmark/`](benchmark/) 目录中。

这些数字是项目测量快照，不是性能保证。PHP 版本、编译器、CPU、优化参数和已启用
扩展都会影响结果；在用于部署决策前，应在同一机器上使用相同 workload 自行对比。

### std::array 对比 PHP 数组

一个 10000×100000 的元素累加循环，对比 PHP 数组、TypePHP `std::array`
与原生 C++：

| 实现 | 耗时 |
|---|---|
| PHP 数组（JIT） | 67.6 秒 |
| `std::array`（TypePHP AOT） | **6.4 秒** |
| C++ `std::vector` | 6.2 秒 |

在该 workload 中，`std::array` 比 PHP 数组快约 **10 倍**，并接近手写 C++ 结果。
完整基准测试见 [Std 容器](docs/zh-cn/STD_CONTAINERS.md)。

## 命令行

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

常用示例：

```bash
# 编译单个文件
bin/tpc.php app.php

# 优化并运行，`--` 后的参数传给生成的程序
bin/tpc.php app.php -O3 -r -- --flag value

# 编译 project.yml 定义的项目
bin/tpc.php project.yml -O2 -j 8

# 生成 PHP 扩展
bin/tpc.php extension/ -m ext -o my_extension

# 只生成 C++（跳过编译与链接）
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build

# 编译为 WASI 0.2
bin/tpc.php --wasm app.php

# 编译为浏览器目标（需要 jco）
bin/tpc.php --wasm=browser app.php
```

主要选项：

| 选项 | 说明 |
|---|---|
| `-O <0-3>` | 优化级别（默认 `0`） |
| `-d`, `--debug` | 调试构建，带符号和源码跟踪 |
| `-o`, `--output <file>` | 输出文件名 |
| `-m`, `--mode <bin\|lib\|ext>` | 构建模式（默认 `bin`） |
| `-r`, `--run` | 构建成功后运行 |
| `-j`, `--job <num>` | 并行编译任务数（默认 `4`） |
| `-f`, `--force` | 不使用缓存，重新编译可复用 PHPX 对象 |
| `--build-dir <dir>` | 生成 C++ 与中间产物的目录 |
| `--dry` | 只生成 C++，跳过编译与链接 |
| `--php-version <8.4\|8.5>` | 接受的 PHP 语法版本 |
| `--cxx-std <ver>` | C++ 标准（如 `c++17`、`c++20`） |
| `--march <arch>` | 目标指令集（如 `native`） |
| `--target-platform <triple>` | 交叉编译目标 triple |
| `--lto` | 启用链接时优化 |
| `--sanitize <type>` | 启用 sanitizer（如 `address`） |
| `--profile` | 启用 Linux gperftools 性能分析 |
| `--format` | 使用 clang-format 格式化生成的 C++ |
| `--no-literal-strings` | 禁用字面量字符串表优化 |
| `--no-progress`, `--no-color` | 适合 CI 的输出控制 |
| `-I`, `-D`, `-L`, `-l` | 可重复指定的原生 include、define、库路径和链接库参数 |

运行 `bin/tpc.php --help` 查看权威的最新参数列表。详见
[编译器命令行](docs/zh-cn/COMPILER_CLI.md)，包括 Bash 补全：

```bash
source <(./tpc --generate-completion=bash)
```

## 常见问题

- **缺少 `libphp.so` / `libphp.dylib`：** 安装或编译与当前 PHP 匹配的 embed SAPI，设置
  `PHP_HOME`，或使用 `bin/tpc.php` 在 Linux 上提供的交互式安装流程。
- **找不到 PHPX：** 将 `PHPX_HOME` 指向包含 `include/` 和
  `lib/libphpx.so`（或对应平台文件）的 PHPX 安装目录，并在编译项目前先构建 PHPX。
- **启动崩溃或出现 ABI 错误：** PHP 头文件、`php-config`、`libphp` 和扩展 ABI
  必须使用一致的 PHP 版本及 ZTS/NTS 模式，不能混用不同 PHP 构建产生的产物。
- **增量构建异常缓慢：** 固定使用同一个 `--build-dir`，以复用对象和 PCH 缓存。
  当外层测试工具已经并行运行多个测试时，不要再设置过大的 `tpc -j`，避免并发数
  相乘后造成 CPU 和内存争用。
- **使用 `bin/tpc.php` 可以编译，但自举 `tpc` 失败：** 必须用自举编译器复现。
  自举执行可能暴露 PHP-hosted 编译器不会经过的动态调用或 ABI 路径。

## Python 桥接

TypePHP 内置一个 Python 工具子模块，复用 `tpc` 入口：

```shell
# 为 Python 模块生成 IDE helper
./tpc --gen-python-helper math
./tpc --gen-python-helper numpy --output-dir .ide-helper

# 将 Python 脚本转换为 TypePHP
./tpc --convert-python-to-php script.py > script.php
```

详见 [Python 工具子模块](docs/zh-cn/python/tools.md)。

## 开发与测试

安装开发依赖并运行编译器单元测试：

```bash
composer install
PHPX_HOME=/path/to/phpx vendor/bin/phpunit
```

PHPT 是端到端测试。必须先构建自举编译器，并显式传给测试工具；将 Zend PHP
可执行文件作为 `--compiler` 并不能验证实际交付的编译器：

```bash
PHPX_HOME=/path/to/phpx php bin/tpc.php project.yml --job 2 --no-progress
php run-tests.php -q -j8 --compiler ./tpc tests/compiler
```

静态分析与从测试源码生成的覆盖矩阵是两项独立检查：

```bash
composer analyse
php bin/analyze-test-coverage.php
php bin/analyze-test-coverage.php \
  --format=markdown --output=build/test-coverage.md --strict
```

覆盖工具分别报告 PHP 版本 × 特性 × 正向编译 × 运行语义 × 负向诊断，并列出实际
出现的 php-parser AST 节点。它不会给出分母不明确的单一百分比。详见
[测试覆盖分析工具](docs/zh-cn/TEST_COVERAGE_ANALYZER.md)。

GitHub Actions 会在 PHP 8.4 和 8.5 上分别运行 PHPUnit 与自举 PHPT。修改编译器
内部规则或代码生成时应增加聚焦的 PHPUnit；运行输出或诊断可观察时还应增加 PHPT。

## 文档

- [快速入门](docs/zh-cn/QUICKSTART.md) —— 最小编译流程
- [变更记录](CHANGELOG.md) —— 破坏性变更与 1.0 前升级说明
- [编译模式](docs/zh-cn/COMPILATION_MODES.md) —— `bin`、`ext`、`lib`
- [编译器命令行](docs/zh-cn/COMPILER_CLI.md) —— CLI 参数与项目配置
- [不兼容 PHP 特性清单](docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md) —— 当前限制
- [原生类型](docs/zh-cn/NATIVE_TYPES.md) —— 原生标量类型
- [高精度类型](docs/zh-cn/HIGH_PRECISION_TYPES.md) —— BigInt / Decimal / BigFloat
- [Std 容器](docs/zh-cn/STD_CONTAINERS.md) —— 强类型容器
- [通用方法](docs/zh-cn/UNIVERSAL_METHODS.md) —— 编译期方法解析
- [编译期函数](docs/zh-cn/COMPILE_TIME_FUNCTIONS.md) —— `std::any()`、`std::ref()`、`std::expected()` 等
- [混合 C++/PHP](docs/zh-cn/MIXED_CPP_PHP.md) —— C++/PHP 互操作
- [`#[Immutable]`](docs/zh-cn/IMMUTABLE.md) —— 编译期只读契约
- [强类型 PHP 数组与类型注解](docs/zh-cn/TYPED_ARRAYS.md) —— 强类型数组属性契约
- [Property hooks](docs/zh-cn/PROPERTY_HOOKS.md) —— PHP 8.4 hook 降级和运行时元数据
- [对象存储模型](docs/zh-cn/OBJECT_STORAGE_AND_PASSING_MODELS.md) —— Zend object、Box 与 Native class 边界
- [Generator](docs/zh-cn/YIELD_GENERATOR.md) —— 生成器降级与生命周期
- [测试覆盖分析工具](docs/zh-cn/TEST_COVERAGE_ANALYZER.md) —— AST 与特性证据矩阵
- [WASI 构建](docs/zh-cn/WASI_BUILD.md) —— WASI 目标

## 致谢

TypePHP 感谢每一位参与项目建设的开发者和贡献者。项目的实现同样离不开 GCC、
Clang/LLVM、MSVC、ISO C++（WG21）、PHP、PHP-Parser 以及众多辅助开源社区的
长期工作。完整名单与说明请参阅[致谢](docs/zh-cn/ACKNOWLEDGEMENTS.md)。

## 授权协议

TypePHP 采用 [GNU General Public License v3.0](LICENSE) 授权。

## 社区

- 代码仓库：<https://github.com/swoole/typephp>
- 版权所有 © 2026 上海识沃网络科技有限公司（Swoole）
