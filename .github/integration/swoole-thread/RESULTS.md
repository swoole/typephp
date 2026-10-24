# 三平台修复与实测记录（2026-10-20）

本套线程兼容性用例在 Linux、macOS、Windows 的纯 PHP、TypePHP ext、bin、
bin-embedded 四种模式均通过。默认字面量优化和 throw/catch 检查全部开启，
每项同时要求运行结果正确、构建成功和进程正常退出。该结论覆盖下述用例，
不代表所有 Swoole API 或长时间压力测试均已验证。

| 平台 | PHP（ZTS） | Swoole | 纯 PHP | ext | bin | bin-embedded |
| --- | --- | --- | --- | --- | --- | --- |
| Linux x86_64 | 8.4.14 Debug | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |
| macOS arm64 | 8.4.20 | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |
| Windows 11 x64 | 8.4.26 | 6.3.0RC1 | 通过 | 通过 | 通过 | 通过 |

每个模式包含 3 轮、每轮 3 个并发工作线程，共 54 次状态快照和 9 次请求退出
析构检查；其中一半快照在 Swoole 协程回调中执行。还检查主请求状态保持、
工作请求的全局变量/函数静态变量隔离、动态函数与方法调用、闭包、异常、
共享 Queue/Map/Atomic，以及原生 GC 根的请求退出清理。

`bin-embedded` 在构建后删除测试目录中的文本文件，要求主请求和每个工作请求
仍能通过 `file_exists`、`file_get_contents` 读取嵌入副本。它验证每个线程请求
都安装流包装器，不依赖磁盘上的原文件。

## 根因与修复

### 共享字面量仍在修改引用计数

Linux Debug PHP 原先在工作线程或进程退出时触发 `IS_STR_PERSISTENT` 断言。
生成代码中的 `static php::Str _literal_strings[]` 跨线程共享；旧 PHPX 仅为
persistent string 增加一次引用计数以避免释放，各线程的复制/析构仍会修改
同一非原子计数，额外引用不能消除竞争。

PHPX 的 persistent 字符串构造现在生成带预计算 hash 的不可变、永久字符串，
Zend 的普通复制和销毁跳过其引用计数，修改操作生成独立存储。它保持原有的
进程生命周期约定；不调用尚未初始化的 Zend intern pool，因此支持 C++ 静态
初始化阶段。新增 C++ 回归检查字符串标志、复制、数组键/值、修改隔离及计数不变。

### macOS 跨 dylib 异常类型不可见

完整 ext 用例原先在主线程第一次抛出 `RuntimeException` 时终止，尚未创建
Swoole 工作线程。崩溃报告为未捕获的 `_zend_object*`；PHPX dylib 与隐藏符号的
生成扩展对该指针异常产生不同可见性的 RTTI，libc++abi 无法匹配 catch。

最小的跨 dylib throw/catch 探针复现了旧行为（退出 134）；对 `_zend_object`
前置声明设置默认 `type_visibility` 后可正确捕获（退出 0）。PHPX 在 Apple
Clang 下、包含 Zend 头文件之前应用这一声明。最终线程用例保留完整异常检查，
未使用 `--without-exceptions` 绕过问题。

### bin 模块注册晚于 Zend 线程快照

macOS、Windows 的 bin 工作请求原先报 `Call to undefined function typephp_thread_setup()`。旧 runtime 在 `php_embed_init()` 之后以
`MODULE_TEMPORARY` 注册生成模块，已经错过持久符号快照和请求处理器收集。

PHPX 现在在 embed SAPI 的 startup 中把生成模块交给 `php_module_startup()`，
由 Zend 作为持久模块管理。主请求的项目 RINIT 延后至标准流和嵌入资源准备完毕；
工作请求正常运行自己的 RINIT/RSHUTDOWN。退出交给 `php_embed_shutdown()` 统一
处理，恢复原回调以支持重复启动。TypePHP 同时补齐空嵌入表的请求钩子定义。

bin 入口使用 `Thread::getInfo()['is_main_thread']` 判断主线程；工作请求 RINIT
早于 `Thread::getArguments()` 初始化，不能依赖参数是否为空判断入口。

### 嵌入文件包装器状态应属于请求线程

PHP 的 volatile stream wrapper 表属于请求线程，旧 PHPX 却使用进程级
`file_wrapper_installed` 标志，导致后续工作请求跳过安装；共享包装器操作表还
可能被多个线程重复写入。现在只在首次进程钩子设置时初始化共享操作表，安装
状态使用 `THREAD_LOCAL`。已有的 `lexical_call_scope` 也统一使用这个宏。
宏仅在 ZTS 下展开为 `thread_local`，NTS 下为普通变量。

Linux 已用 PHP 8.5.1 NTS 头文件检查两个修改文件的 C++ 语法，并检查预处理输出
中的 `file_wrapper_installed` 为普通静态变量。这是 NTS 编译验证，不是 NTS 线程测试。

Linux 额外通过 PHPX 字符串测试 3 项、嵌入库生命周期测试 3 项（31 个断言，
包含重复启动/停止、失败启动与独立 C ABI 消费），以及退出清理相关 PHPT 6 项，
覆盖 enum 类常量、闭包 GC 根、原生析构动态调用、异常处理器 include、析构异常
和 generator finally。

## 依赖与构建环境

- 三个平台均从 Swoole 官方 master `0306f53604f760a147b7faef69a55db2f913c5dc`
  构建，开启 thread 支持。当前问题无需修改 Swoole 生产源码。
- PHPX 以官方 master `7a94afac097503eb40b522ebedd897a9fcfba9ea` 为基准应用上述
  修复，已提交到 `~/workspace/typephp/phpx` 的 Git 仓库，提交为 `13cf717`。
  使用固定源码构建
  的共享库；Composer 依赖改为 `dev-master`，修复发布前须显式设置 `PHPX_HOME`。
- Windows 使用 LLVM 23.1.2、MSVC 14.44、Windows SDK 10.0.26100.0。现有 PHP SDK
  头文件/导入库为 8.4.24，运行 PHP 为 8.4.26，属于同模块 API 的跨补丁版本
  验证，不能替代完全匹配 SDK 的验证。嵌入模式使用文本资源，不生成跨版本 PHP opcode。
- Windows 共享 SDK 的 `config.pickle.h` 残留 CURL 等扩展开关，导致关闭 CURL 的
  Swoole 构建仍包含 CURL 代码。测试复制私有 SDK、清空该副本的扩展配置后重新
  configure；原 SDK 保留。SDK 对扩展构建仍探测不可用的 parser generator，
  只在隔离生成的 configure.js 中跳过 Swoole 不使用的生成器检查。
- Windows 私有 SDK 补齐现有 PHP 8.4.26 CLI/OPcache；最初缺少 CLI 导致的
  bin-embedded 构建失败保留在日志中，补齐后该模式单独构建、运行通过。
  PHPX DLL 与 import library 配套，MSVC 保留 `/EHsc` 异常展开选项。

所有本任务编译按平台、目标依次执行，TypePHP `--job 1`。Linux 用 4 GiB cgroup
总内存硬限制、3 GiB MemoryHigh、禁 swap；其他项目占用公共锁时，使用用户明确
授权的单任务并存例外，不改动锁、不终止其他项目。Windows 用 4 GiB Job Object
覆盖整个进程树，NMake 串行且 `/MP1`，清除其不支持的 GNU `MAKEFLAGS=-j1`。
macOS 按用户明确授权不设内存硬限制，保持单任务并监控全机 cc 进程不超过 4。
Linux 最终回归的 cgroup 峰值为 1,058,279,424 bytes，memory.events 的 high/max/
oom/oom_kill 均为 0；Windows 两次最终线程验证峰值分别为 375,291,904 和
374,886,400 bytes。

## 证据目录

- Linux：`build/swoole-thread-Linux-final/results.json`，
  `build/swoole-thread-Linux-final-session.log`；PHPX 三项字符串测试和 NTS opcode
  检查另见 `build/swoole-thread-Linux-preflight-session.log`。
- macOS：测试机 `.../typephp-swoole-thread-20261020/artifacts-final`，本机归档为
  `build/swoole-thread-evidence/macOS/final`。
- Windows：测试机同名测试根目录下 `artifacts-fixed` 的前三项成功记录，以及
  `artifacts-embedded-final` 的嵌入资源成功记录。本机归档为
  `build/swoole-thread-evidence/Windows/final`。首份报告保留末项环境失败，最终
  嵌入模式结果以后一目录为准。

原失败日志和崩溃报告也保留，未被成功重跑覆盖。复现入口见 [README](README.md)。
