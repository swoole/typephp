# TypePHP 编译器命令行

## Bash 自动补全

TypePHP 提供与当前编译器参数同步的 Bash completion。当前终端临时启用：

```shell
source <(./tpc --generate-completion=bash)
```

从源码仓库开发时也可以直接执行 `source completions/tpc.bash`。

安装到当前用户并在后续 Bash 会话自动加载：

```shell
mkdir -p "$HOME/.local/share/bash-completion/completions"
./tpc --generate-completion=bash \
    > "$HOME/.local/share/bash-completion/completions/tpc"
```

如果系统没有自动扫描用户 completion 目录，可在 `~/.bashrc` 中加载：

```shell
source "$HOME/.local/share/bash-completion/completions/tpc"
```

系统级安装可将生成结果写入 `/usr/share/bash-completion/completions/tpc`。该操作通常
需要 root 权限。

补全支持编译选项、WASM profile、构建模式、PHP/C++ 版本、sanitizer、输入源码、
项目 YAML、Python 源文件以及目录参数。`--` 之后是被编译程序自身的参数，补全器
不会再把它们解释为 `tpc` 参数。

发布包携带预生成的 `completions/tpc.bash`。此文件由同一个生成器产生，并有单元
测试保证它与 `./tpc --generate-completion=bash` 的输出一致。

本文档与 `src/Translator.php::showUsage()` 保持同步。使用：

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

## 常用示例

```bash
# 编译单文件
bin/tpc.php app.php

# 优化并运行，`--` 后参数传给生成的程序
bin/tpc.php app.php -O2 -r -- --flag value

# 编译项目配置
bin/tpc.php project.yml -O2 -j 8

# 生成 PHP 扩展
bin/tpc.php extension/ -m ext -o my_extension

# 只生成 C++，不编译和链接
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build
```

## 构建选项

| 选项 | 说明 |
|---|---|
| `-O <0-3>` | 优化级别，默认 `0`。 |
| `-d`, `--debug` | 调试构建；关闭优化、增加调试符号和 TypePHP 源码跟踪。 |
| `-o`, `--output <file>` | 输出文件名。 |
| `-m`, `--mode <bin|lib|ext>` | 构建模式，默认 `bin`。 |
| `-r`, `--run` | 构建成功后运行。 |
| `-j`, `--job <num>` | 并行编译任务数，默认 `4`。 |
| `-f`, `--force` | 忽略 phpx misc 对象缓存，强制重新编译。 |
| `--build-dir <dir>` | 生成的 C++ 和中间产物目录。 |
| `--dry` | 只生成 C++，跳过编译与链接。 |
| `--format` | 对生成代码运行 clang-format。 |
| `--no-progress` | 不显示进度条，逐文件输出进度。 |
| `--no-color` | 禁用彩色输出。 |

`-v` / `--version` 只显示版本，不是 verbose 选项。

## 目标和工具链

| 选项 | 说明 |
|---|---|
| `--php-version <8.4|8.5>` | 限制接受的 PHP 语法版本，默认 `8.5`。 |
| `--cxx-std <ver>` | C++ 标准，例如 `c++17`、`c++20`。 |
| `--march <arch>` | 目标指令集，例如 `native`、`x86-64-v3`。 |
| `--target-platform <triple>` | 交叉编译目标 triple。 |
| `--lto` | 启用 Link Time Optimization。 |
| `--sanitize <type>` | 启用 sanitizer，例如 `address`、`undefined`。 |
| `--no-console` | Windows GUI 模式隐藏控制台窗口。 |
| `--profile` | Linux 上启用 gperftools profiler，并强制重编译相关对象。 |

`--php-version` 控制解析器接受的源码语法，也用于 `project.yml` 中依据 `PHP_VERSION` / `PHP_VERSION_ID` 选择源文件。它不负责选择链接的 PHP 安装目录。

TypePHP 和 PHPX 的最低运行时版本均为 PHP 8.4。`--php-version` 与实际链接的 `libphp.so` 不要求小版本完全相同，但两者都必须为 PHP 8.4 或更高版本。

## C++ 编译和链接参数

这些参数均可重复：

```bash
-I /opt/library/include
-D FEATURE_ENABLED=1
-L /opt/library/lib
-l curl
```

对应长选项：

- `--include-path`
- `--define`
- `--link-path`
- `--link-lib`

## 项目配置优先级

传入 `project.yml` 时，命令行参数优先于 YAML 中的同名配置。项目文件格式参见用户文档及代码中的项目配置解析器。

### 符号链接的源码目录

扫描源码目录时会进入符号链接指向的目录，因此通过 Composer path 仓库安装的依赖
（以符号链接方式安装）会像其他源码一样被编译。指向自身上级目录的链接不会无限
递归；通过多个链接都能访问到的文件只会被编译一次。

如需排除，使用常规的 `ignore` 配置，并按访问该目录所用的路径书写，而不是链接
指向的目标路径：

```yaml
ignore:
  - vendor/vendor/mylib
```

### 预编译对象文件

项目可以把外部工具链生成的对象文件作为通用链接输入：

```yaml
objects:
  - build/startup.o
  - path: build/platform.obj
    if: PHP_OS_FAMILY == "Windows"
```

路径相对于 `project.yml` 解析，并支持与 `sources` 相同的条件表达式。
`tpc` 只负责装载并链接 `.o`/`.obj`，不会重新编译其对应的 C、C++ 或汇编源码。
使用项目通用编译参数的原生源码应继续放在 `sources`；`objects` 主要用于单独编译
且与最终目标 ABI 兼容的编译单元。`-m32` 对象不能直接链接到 64 位目标，需要在
tpc 产出 ELF 后由项目自己的构建流程另行封装。
使用 `cxx-flags`、`c-flags`、`asm-flags` 和 `ld-flags` 分别设置项目级
C++、C、汇编和链接参数。

### PHP 扩展依赖

程序依赖其他 PHP 扩展时，可以将必需模块写入 Zend 模块依赖表：

```yaml
extension-dependencies:
  - pdo_mysql
  - curl
```

`ext-deps` 是等价的简写名称。一个项目中只能使用其中一个配置名；同时出现 `extension-dependencies` 和 `ext-deps` 会产生配置错误。

编译器会为每一项生成 `ZEND_MOD_REQUIRED`。Zend 在加载 TypePHP 模块时检查这些扩展是否已加载。该配置不表示原生链接库；C/C++ 链接依赖仍使用 `link-libs`。

## 查看权威帮助

命令行实现可能继续演进，发布版本的实际参数以以下命令为准：

```bash
bin/tpc.php --help
```

兼容性边界参见 [INCOMPATIBLE_PHP_FEATURES.md](INCOMPATIBLE_PHP_FEATURES.md)，构建模式参见 [COMPILATION_MODES.md](COMPILATION_MODES.md)。
