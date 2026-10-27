# typephp-mcp — TypePHP 辅助开发 MCP Server（TypePHP 自己实现）

用 TypePHP AOT 编译器把 PHP 源码编译成原生二进制的 MCP（Model Context Protocol）server，
通过 stdio 以换行分隔的 JSON-RPC 2.0 对外提供服务。它替代了设计文档
（《TypePHP辅助开发MCP设计.md》）中原计划的 Python/FastMCP 实现：**协议层、检索层、
编译器 oracle 全部由 TypePHP 编译后的 PHP 代码完成**，运行时只依赖 PHP 运行时 DLL。

## 工具面（12 个）

| 工具 | 作用 |
|---|---|
| `check_code` | 第一工具。跑 `php bin/tpc.php --dry` 验证 TypePHP 子集合法性，返回结构化结果 `OK/FAILED stage=generate file=… diagnostics=N` + 逐条 `file:line[:col] 严重级别 消息`。只覆盖 C++ 生成阶段；编译/链接阶段不跑、不覆盖。工具自身执行失败（文件不存在 / PHP 或 SDK 缺失 / 编译器超时）以 `isError: true` 返回，与"用户代码不合格"的正常结果区分开 |
| `list_restrictions` | 写码前必读。TypePHP 裁剪规则（程序结构/类型/引用/对象/控制流/std 表面/stub 契约/构建配置），可按 topic 查询 |
| `get_symbol` | 按精确名字查函数/类/接口/trait/enum/const 声明，返回签名 + `file:line`（扫 src/、examples/、tests/std/）。支持完整命名空间（`TypePhp\Translator`）与 `Class::method`；大文件分块扫描（超过扫描上限会显式报告 Coverage 截断，绝不静默跳过） |
| `list_stdlib` | 列出 tests/std 各分类及测试标题；指定分类返回该分类全部用例 |
| `get_example` | 返回 examples/ 下可运行示例（单文件内容，或目录的文件清单 + README/main.php/project.yml 摘录） |
| `get_stub_api` | 解析 `.stub.php` ABI 契约：列出全部 stub 文件，或读取指定文件的声明 |
| `search_docs` | docs/zh-cn + docs/en 全文检索，返回带 `docs/<lang>/<file>.md#<heading> (L<line>)` 出处的 chunk |
| `search_code` | grep examples/、tests/std/、src/，返回 `file:line` + 所在声明 |
| `get_changelog` | 读 CHANGELOG.md：最新小节或指定版本 |
| `get_build_config_help` | project.yml 字段、bin/lib/ext 三种模式、CLI 选项、stub 契约 |
| `list_topics` | 列出全部文档标题（双语） |
| `get_index_info` | 元数据：repo 根、tpc 版本、git commit、文档数、工具清单（staleness 自证） |

设计取舍：

- **无预建索引**。docs/examples/src 每次实时读取（约 2 MB），从结构上消除索引过期；
  `get_index_info` 用 tpc 版本 + git commit 自证时效。
- **约束注入**。`initialize` 的 instructions 与 `check_code`/`list_restrictions` 的
  tool description 中内置约 800 token 的 TypePHP 红线摘要，不调工具也能压住头号失败模式。
- **返回摘录 + 出处，不返整文件**；单次结果有字节预算（约 4 KB），UTF-8 边界安全。
- **协议版本来真的**。`initialize` 按 MCP 版本规范应答：客户端请求的版本属于已实现集合才回显，
  否则回本 server 支持的最新版（不假装支持任意版本）。`roots` 只在存在该能力的版本里声明，
  工作区发现另有 `TYPEPHP_MCP_PROJECT_ROOT` 与 cwd 自动探测兜底。无 id 的通知（含 `tools/list`）一律不回复。
- **编译器调用有生命周期控制**。`check_code` 通过受控子进程跑 tpc：超时、输出预算、进程树内存上限，
  超时杀树并清理临时目录；服务在等待期间不会永久卡死。

## 构建

### Windows

前置：VS 2022 BuildTools（`vcvars64.bat`，经 vswhere 自动定位）与一个 tpc 包。

```bat
tools\typephp-mcp\build.bat
```

脚本所有路径都相对自身位置解析，**可从任意工作目录执行**：

| 路径 | 解析方式 |
|---|---|
| `project.yml` / `build/` / 输出 exe | 脚本所在目录 |
| `bin\tpc.php` | 脚本目录上两级（仓库根） |
| tpc 包（phpx/PHP SDK/运行时 DLL） | 脚本目录上三级 + `tpc_v0.9.5_windows_x64`（即仓库同级目录） |

换 tpc 版本只需改 `build.bat` 里 `TPC_PACKAGE` 一行中的版本目录名；若 tpc 包不在
默认位置，先 `set PHP_HOME=<包目录>` 再运行（脚本找不到包时会给出该提示并以退出码 1 失败）。

产物 `typephp_mcp.exe` 及运行时 DLL（phpx/php8ts/gmp/mpfr/mpdec）都在本目录，
**不需要改 PATH**。

### macOS / Linux

```bash
tools/typephp-mcp/build.sh
```

Windows 有一个 tpc 发行包目录装着 phpx/PHP SDK，Unix 侧没有，所以 `build.sh` 改为定位
**宿主 embed 运行时**（顺序：显式 `PHP_HOME`/`PHPX_HOME` → `~/.typephp/php-builder/php-*/install`
里最新的私有运行时 → 仓库 `vendor/swoole/phpx`）：

| 前置 | 要求 | 实测踩坑 |
|---|---|---|
| clang | `xcode-select --install` | tpc 启动即打印 `Initialized platform/backend: macOS + Clang` |
| `PHP_HOME` | `lib/libphp.{a,dylib}` | Homebrew 的 php 只建 cli SAPI，**没有 libphp**；`--dry` 也会直接 Fatal |
| `PHPX_HOME` | `include/phpx.h` + `lib/libphpx.{a,dylib}` | 编译器现在生成 `php::empty_string`，phpx 需 ≥ 该 API（`composer update swoole/phpx`），`build.sh` 会先校验再报错 |
| 链接库 | — | 私有运行时的扩展集通常比本项目声明的多，`libphp.a` 于是引用 tpc 不认识的 `-lonig/-lxml2/-lsqlite3/-lz/-lresolv/-lcurl/-lssl -lcrypto/-liconv`；`build.sh` 用 `ar -t` 探测实际对象后补齐，iconv/openssl 是 brew keg-only 还要带 `-L` |

产物是本目录的 `typephp_mcp`（无 `.exe` 后缀，Mach-O arm64，约 25 MB），依赖 gmp/mpfr/oniguruma
等 brew dylib 的绝对路径，换机需重装同样的 brew 包。

以上 macOS 路径为实测；`build.sh` 同时按 Linux 写（`uname` 分支只影响 libiconv 那条）。

`build.sh` 在用户自管机器上遵守资源安全约束（AGENTS.md）：Linux 下先把清理和编译放进全用户构
建锁 `${XDG_RUNTIME_DIR:-/tmp}/codex-build-$(id -u).lock` 的临界区（抢不到锁就不触碰 `build/`、不调用编译器），`-j1` 串行，并把整个构建进程树放进 systemd user scope
（`MemoryMax` 为 8 GiB 或启动时可用内存的一半取小者，`MemorySwapMax=0` 禁 swap）——这才是整树
内存总预算，`ulimit -v` 只是每进程第二道防线；scope 建不起来时拒绝构建并给出可用的受控入口
（root scope / 容器）；CI 跑在专用 runner 上，改用可用逻辑 CPU 数。

CI（本 PR 新增）在 **Linux x64 / Linux arm64 / macOS arm64 / Windows x64** 上都会实际构建 MCP
产物并跑原生 stdio 冒烟测试；缺产物即判失败（不再靠"缺产物就 skip"证明兼容）。

## 运行 / 接入 MCP 客户端

```bat
set TYPEPHP_MCP_ROOT=D:\git\php\aot-compiler
tools\typephp-mcp\typephp_mcp.exe
```

macOS / Linux（`check_code` 要调编译器，所以 `PHP_HOME`/`PHPX_HOME` 与构建时同一套）：

```bash
export TYPEPHP_MCP_ROOT=/Volumes/data/git/php/typephp-compiler
export PHP_HOME=$HOME/.typephp/php-builder/php-8.5.11-<hash>/install
export PHPX_HOME=<phpx 树：include/phpx.h + lib/libphpx.a>
tools/typephp-mcp/typephp_mcp
```

环境变量：

| 变量 | 默认 | 说明 |
|---|---|---|
| `TYPEPHP_MCP_ROOT` | 从 cwd 向上找 `bin/tpc.php` + `docs/`，再退到源码相对默认值（`tools/typephp-mcp/src` 上三级） | **工具资源目录**（编译器 checkout），不是用户项目目录。默认值随源码位置解析，不再是写死的开发机路径；但 **AOT 产物内嵌的是构建机路径**，发布二进制从别处拉起时必须显式设置（否则以可操作的错误信息失败） |
| `TYPEPHP_MCP_TPC` | 仓库根的 `tpc` / `tpc.exe` | `check_code` 调用的**编译好的 tpc 二进制**：不需要 PHP 解释器、也不需要 phpy 扩展（Windows 上尤其重要，因为解释执行 `php bin/tpc.php` 必须加载 phpy）。会先做一次有界的 `--version` 探测，跑不起来（缺运行时 DLL 或缺 mbstring）就退回解释执行 |
| `TYPEPHP_MCP_PHP` | `php`（PATH 查找） | 只有走解释执行（`php bin/tpc.php`）时才需要；`get_index_info` 探测 tpc 版本同理 |
| `PHP_HOME` / `PHPX_HOME` | 继承调用方环境 | 这两个只被 `check_code`/`get_index_info` 间接使用（tpc 自己读）；mac 上不设就是 `The host PHP embed library is missing`，工具会如实回报成 `isError: true` |
| `TYPEPHP_MCP_PROJECT_ROOT` | 未设 | 客户端不发 MCP roots 时，手动把 TypePHP 项目路径并入默认检索集。分隔符：`;` 通用，POSIX 下 `:` 也认；**Windows 只认 `;`**（盘符里的冒号不能当分隔符） |
| `TYPEPHP_MCP_COMPILE_TIMEOUT` | `120` | `check_code` 调编译器的超时秒数；超时杀掉整个子进程树并清理临时目录，服务保持可响应 |
| `TYPEPHP_MCP_COMPILE_OUTPUT_LIMIT` | `65536` | 编译器输出捕获字节上限（仍持续排空管道，子进程不会被写满阻塞） |
| `TYPEPHP_MCP_COMPILE_MEMORY_LIMIT_MB` | `4096` | POSIX 下编译器进程树的虚拟内存上限（MiB） |

Finch（`~/.finch/mcp/servers.json`，对象数组）：

```json
{
  "name": "typephp-dev",
  "enabled": true,
  "command": "D:\\git\\php\\aot-compiler\\tools\\typephp-mcp\\typephp_mcp.exe",
  "args": []
}
```

Claude Code：

```bash
claude mcp add typephp-dev -- D:\git\php\aot-compiler\tools\typephp-mcp\typephp_mcp.exe
```

CodeBuddy（用户级配置 `%USERPROFILE%\.codebuddy\mcp.json`，在 MCP 标签页手动添加等价）：

```json
{
  "mcpServers": {
    "typephp-mcp": {
      "type": "stdio",
      "command": "D:/git/php/aot-compiler/tools/typephp-mcp/typephp_mcp.exe",
      "args": [],
      "env": {
        "TYPEPHP_MCP_ROOT": "D:/git/php/aot-compiler",
        "TYPEPHP_MCP_PHP": "D:/git/php/tpc_v0.9.5_windows_x64/php.exe",
        "PHPX_HOME": "D:/git/php/tpc_v0.9.5_windows_x64/phpx",
        "PHP_HOME": "D:/git/php/tpc_v0.9.5_windows_x64"
      }
    }
  }
}
```

注意：**路径一律用正斜杠**。CodeBuddy 的 MCP 启动器会剥掉配置里的反斜杠
（`D:\\git\\...` 会变成 `D:git...`，报 `not recognized ... Connection closed`），
正斜杠在 Windows 下全链路可用（与同配置中正常工作的 ripwire 条目一致）。

`env` 四个变量都建议显式设置：客户端的工作目录不确定，`TYPEPHP_MCP_ROOT` 保证仓库定位；
后三个把 `check_code` 调用的编译器钉在 tpc 包上，避免继承到过期的 `PHPX_HOME`/`PHP_HOME`
或 PATH 里没有 php。修改配置后需重启 CodeBuddy 生效。

`check_code` 会调编译器，属"副作用"工具，建议在客户端对该 server 单独授权。

## 测试

```bash
php vendor/bin/phpunit tools/typephp-mcp/tests/
```

- `McpServerTest`：协议分发 + 12 个工具的逻辑单测（普通 PHP 直接跑；
  源码刻意保持在 stock PHP 与 TypePHP 的交集内，解释执行与 AOT 编译跑同一份代码）。
- `McpBinaryTest`：用共享的 `McpBinaryDriver` 驱动编译产物跑真实 stdio 会话（逐条 flush 超时校验、
  大文件符号、工具失败 `isError`、通知不回复、仓库外 cwd 启动、解释执行与 AOT 一致性）。二进制按平台取
  `typephp_mcp.exe` / `typephp_mcp`，也可用 `TYPEPHP_MCP_BINARY=<path>` 指定；未构建时默认 skip，
  设 `TYPEPHP_MCP_REQUIRE_BINARY=1`（CI 会设）则改为失败。
- `tools/typephp-mcp/tests/mcp-binary-smoke.php`：独立 CI 驱动，落地同样的 wire 检查；`--interpreted`
  跑解释执行入口 `run-interpreted.php`，用于和 AOT 产物对比。缺产物直接以退出码 1 失败。

`check_code` 相关断言要求调用方环境里有一个能跑 `tpc --dry` 的 embed 运行时；Windows 靠 tpc 包，
macOS/Linux 靠 `PHP_HOME`/`PHPX_HOME`（见"构建"）。没设这些变量时它们会红，报的是编译器那句
`The host PHP embed library is missing`，不是本项目的问题。

本 PR 起 CI 在 Linux x64/arm64、macOS arm64、Windows x64 上实际构建产物并跑原生冒烟，
不再依赖"缺产物就 skip"来证明兼容。本机（Windows，PHP 8.5.11 + tpc_v0.9.5 包）实测：
`php vendor/bin/phpunit --testsuite TypePhpMcpTests` → 45 用例 / 456 断言全绿；AOT 产物真实 stdio
会话里 12 个工具与两条编译器通路（`get_index_info` 的 tpc+git、`check_code` 的 tpc --dry）均正常；
反向对照：把 `PHP_HOME` 指向没有 embed 库的目录，`check_code` 断言一起变红，说明它们真的在管编译器通路。

## 已知问题（编译器侧，非本项目 bug）

`STDIN/STDOUT/STDERR` 常量在某些项目形态下被 -O2 优化器折叠为字面量 `0`
（见 `src/Translator.php::loadInternalConstants()` 与
`src/Parser/ConstantExpressionTrait.php::parseConstFetch()` 的常量折叠路径），
运行时 `fwrite()/fgets()` 会抛 `TypeError`。规避：`main.php` 统一用
`fopen('php://stdin'/'php://stdout'/'php://stderr')` 打开标准流。

macOS 链接期缺扩展依赖：走私有 embed **静态** `libphp.a` 时，tpc 不按运行时的实际扩展集推导
系统库，除 gmp/mpfr/c++ 外的 `-lonig -lxml2 -lsqlite3 -lz -lresolv -lcurl -lssl -lcrypto -liconv`
都要应用侧补上，`build.sh` 用 `ar -t` 探测归档对象后补全（iconv/openssl 还是 brew keg-only，
要带 `-L`）。CI 的 macos-arm64 作业没踩到，因为它用 setup-php 的**共享** `libphp.dylib`
（依赖由 dyld 解析），只有静态 embed 运行时才需要这一串 `-l`。
