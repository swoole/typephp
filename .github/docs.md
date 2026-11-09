# CI 提交标记

在最新一条 Git commit 消息的标题或正文中，使用独立的 `--skip-tests` 或
`--sanitize` 标记控制 Linux x64 CI 测试。它们是提交消息标记，不是 `git commit`
自身的选项，例如：

```bash
git commit -m "fix: native class constants --sanitize"
git commit -m "docs: clarify configuration --skip-tests"
```

Push 和手动运行检查所选提交的消息；PR 检查源分支的最新提交消息，而不是 GitHub
自动生成的合并提交消息。只读取最新一条消息，不扫描整个历史。标记需与其他文本
以空白分隔，可放在正文中。

## `--skip-tests`

- 跳过 Linux x64 的 PHPUnit、EXT/LIB 和 PHPT 测试作业。
- 编译作业仍然执行，包括 Linux、macOS 和 Windows 的构建及已有构建冒烟检查。
- 发布 tag 始终执行测试，忽略 `--skip-tests`。
- 普通分支或 PR 同时带两个标记时，`--skip-tests` 优先，不运行 ASan PHPT。

## `--sanitize`

- 该轮 PHPT 只运行 Linux x64 PHP 8.4/8.5 ZTS 的 ASan 作业，不再重复运行普通
  PHPT。未带此标记时只运行普通 PHPT；PHPUnit 和 EXT/LIB 作业保持不变。
- 每个原生 PHPT 产物在编译、链接时通过 `--sanitize address` 启用
  AddressSanitizer；仅设置环境变量或传入测试工具旧有的 `--asan` 不会给产物插桩。
  编译时增加 C++ 调试符号和 frame pointer，方便定位；不自动启用会改变 PHP
  异常行号和跟踪行为的 `--debug` 模式。
- 禁用 Zend 私有分配器（`USE_ZEND_ALLOC=0`），让地址检查能看到单次堆分配。
  启用遇错终止，关闭泄漏检查；这里检查地址错误，不把它当作独立的泄漏检查作业。
- 每次运行先执行一个真实 C++ 堆越界探针，验证 ASan 已启用且报告会让 PHPT 失败。
  报告不能被宽泛的 `EXPECTF`、`XFAIL` 或测试中的环境覆盖隐藏。
- 失败时保存测试日志、ASan 报告和生成的 C++/头文件；ASan 作业的 artifact 名带
  `-asan` 后缀，与普通测试区分。探针证据单独存入 `asan-canary-*` artifact。
- ASan 作业复用普通的 bootstrap 编译器及 PHP/PHPX 依赖，对每个 PHPT 生成的原生
  代码插桩；不表示 PHP/PHPX 的所有预编译代码也经过插桩。
- PHP 8.4 的打包运行库会用 `RTLD_DEEPBIND` 加载扩展，与 ASan 不兼容。该作业从
  经 SHA-256 验证的同版本官方源码构建私有 embed 运行库，只移除此加载标志；验证
  版本、ZTS/debug 模式及内置扩展一致后，通过库搜索路径使用它，保留原 SDK 和扩展。
- 未插桩 PHP 的 Fiber 切栈可能产生已知的 `__asan_handle_no_return` 提示。该提示
  单独保留并打印日志位置，不改变 PHPT 输出；同一报告中的真实错误及其他未知诊断
  仍然使测试失败，不跳过 Generator/Fiber 用例。ASan 作业无论成功与否，都会上传
  `phpt-sanitizer-reports-*` artifact，保留这些提示以供检查。
- 发布 tag 也只执行所选模式的 PHPT。测试通过后，使用原始运行库重新构建不含
  sanitizer 的发布包，每个 PHP 版本只打包、上传一次。

CI 使用 runner 的逻辑 CPU 数作为 PHPT worker 数；多个 worker 并行时，每个测试的
嵌套编译及 Make/CMake/Cargo 环境保持单任务，避免并发相乘。顺序运行时，编译器
使用 `PHP_COMPILER_JOBS` 配置，未配置则使用 1。用户机器仍须遵守 `AGENTS.md` 的资源限制。

## 手动运行 PHPT

```bash
php run-tests.php -q -j1 --sanitize address tests/compiler/native-class
php run-tests.php -q -j1 --sanitize address,undefined tests/compiler/native-class
```

`run-tests.php --sanitize <list>` 接受 `address`、`undefined` 或两者的逗号列表，
不支持与 `--no-aot` 或 WASM 后端组合。参数会传递给并行 worker 和每个测试的编译命令，
测试自身的 `--AOT_ARGS--` 不能覆盖所要求的 sanitizer。原有 `--asan` 仅设置 PHP
测试环境；要检查 TypePHP 生成代码，请使用新的 `--sanitize address`。
