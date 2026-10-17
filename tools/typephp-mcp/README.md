# typephp-mcp — TypePHP 辅助开发 MCP Server（TypePHP 自己实现）

用 TypePHP AOT 编译器把 PHP 源码编译成原生二进制的 MCP（Model Context Protocol）server，
通过 stdio 以换行分隔的 JSON-RPC 2.0 对外提供服务。它替代了设计文档
（《TypePHP辅助开发MCP设计.md》）中原计划的 Python/FastMCP 实现：**协议层、检索层、
编译器 oracle 全部由 TypePHP 编译后的 PHP 代码完成**，运行时只依赖 PHP 运行时 DLL。

## 工具面（12 个）

| 工具 | 作用 |
|---|---|
| `check_code` | 第一工具。跑 `php bin/tpc.php --dry` 验证 TypePHP 子集合法性，返回 `file:line` 诊断或 OK（只覆盖 C++ 生成，不覆盖链接期） |
| `list_restrictions` | 写码前必读。TypePHP 裁剪规则（程序结构/类型/引用/对象/控制流/std 表面/stub 契约/构建配置），可按 topic 查询 |
| `get_symbol` | 按精确名字查函数/类/接口/trait/enum/const 声明，返回签名 + `file:line`（扫 src/、examples/、tests/std/） |
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

## 构建

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

## 运行 / 接入 MCP 客户端

```bat
set TYPEPHP_MCP_ROOT=D:\git\php\aot-compiler
tools\typephp-mcp\typephp_mcp.exe
```

环境变量：

| 变量 | 默认 | 说明 |
|---|---|---|
| `TYPEPHP_MCP_ROOT` | 从 cwd 向上找 `bin/tpc.php` + `docs/`，再退到构建期默认值 | 仓库根 |
| `TYPEPHP_MCP_PHP` | `php`（PATH 查找） | `check_code` / `get_index_info` 调编译器用的 PHP |

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
- `McpBinaryTest`：驱动编译产物跑真实 stdio 会话，含逐条 flush 超时校验
  （未构建时自动 skip）。

## 已知问题（编译器侧，非本项目 bug）

`STDIN/STDOUT/STDERR` 常量在某些项目形态下被 -O2 优化器折叠为字面量 `0`
（见 `src/Translator.php::loadInternalConstants()` 与
`src/Parser/ConstantExpressionTrait.php::parseConstFetch()` 的常量折叠路径），
运行时 `fwrite()/fgets()` 会抛 `TypeError`。规避：`main.php` 统一用
`fopen('php://stdin'/'php://stdout'/'php://stderr')` 打开标准流。
