# Cross-Platform Native Terminal Demo / 跨平台原生终端示例

[English](#english) | [简体中文](#简体中文)

---

<a name="english"></a>
## English

This example demonstrates how to build a **cross-platform native CLI/TUI application** with TypePHP using mixed C++ and PHP programming (`cpp-src` + `php-src`).

Standard PHP CLI applications traditionally depend on executing external processes (`stty`, `mode CON`) or installing separate PHP extensions to query window size, enable raw mode, or capture hidden passwords. When compiled into a standalone native binary with TypePHP (`tpc -m bin`), this implementation interacts directly with the operating system kernel and console APIs with zero external dependencies and zero `zval` boxing overhead on hot paths.

### Supported Operating Systems

- **Linux** (`x86_64`, `aarch64`): POSIX `termios`, `sys/ioctl.h`
- **macOS** (`Apple Silicon`, `Intel`): Darwin POSIX `termios`, `sys/ioctl.h`
- **Windows** (`x86_64`): Win32 Console API (`GetStdHandle`, `SetConsoleMode`, `ReadConsoleInputW`, `SetConsoleTitleW`)

### Features Implemented

1. **Terminal Sizing**: Queries columns and lines via `ioctl(TIOCGWINSZ)` on Unix and `GetConsoleScreenBufferInfo` on Windows.
2. **TTY Detection**: Fast in-process check for interactive terminal stream (`isatty` / `GetConsoleMode`).
3. **Raw Mode**: Non-canonical raw input mode without line buffering, featuring automatic terminal restoration on exit (`atexit`) to prevent terminal corruption.
4. **Secret Password Input**: In-process hidden input without echo, restoring terminal flags immediately upon completion.
5. **Window Title**: Sets terminal/console window title via OSC escape sequence on Unix and `SetConsoleTitleW` (UTF-8 to UTF-16) on Windows.
6. **ANSI Color Support**: Probes environment variables (`TERM`, `NO_COLOR`) to determine formatting compatibility.

### Project Structure

```
examples/terminal/
├── project.yml              # TypePHP build configuration
├── main.php                 # Interactive CLI demonstration entrypoint
├── cpp-src/
│   └── terminal.cc          # Cross-platform C++ implementation (POSIX & Win32)
└── php-src/
    └── Terminal.php         # PHP stubs and object-oriented Terminal wrapper
```

### Compilation & Usage

Compile the project into a standalone executable:

```bash
php bin/tpc.php examples/terminal/project.yml -o terminal-demo
```

Run the compiled executable:

```bash
./terminal-demo
```

---

<a name="简体中文"></a>
## 简体中文

本示例展示了如何使用 TypePHP 的 C++/PHP 混合编程能力（`cpp-src` + `php-src`），构建**跨平台原生命令行与终端（CLI/TUI）应用程序**。

在传统 PHP 命令行开发中，通常需要调用外部进程（例如 `stty` 或 Windows `mode CON`）或依赖额外安装的 C 扩展来获取终端尺寸、开启 Raw 模式或读取无回显密码。借助 TypePHP 编译为独立二进制（`tpc -m bin`）后，本实现直接调用底层操作系统内核与控制台 API，具备零外部依赖、零进程开销以及原生 C++ 调用性能。

### 支持的操作系统

- **Linux** (`x86_64`, `aarch64`)：基于 POSIX `termios`、`sys/ioctl.h`
- **macOS** (`Apple Silicon`, `Intel`)：基于 Darwin POSIX `termios`、`sys/ioctl.h`
- **Windows** (`x86_64`)：基于 Win32 控制台 API（`GetStdHandle`、`SetConsoleMode`、`ReadConsoleInputW`、`SetConsoleTitleW`）

### 核心功能

1. **终端窗口尺寸**：在 Unix 下调用 `ioctl(TIOCGWINSZ)`，在 Windows 下调用 `GetConsoleScreenBufferInfo`，快速读取行列数。
2. **TTY 终端检测**：原生物理终端连接检测（`isatty` / `GetConsoleMode`）。
3. **Raw 模式**：禁用规范输入行缓冲，支持单键即时捕获，并注册 `atexit` 自动安全恢复终端状态，防止终端异常。
4. **安全密码输入**：进程内无回显密码读取，读取完成后自动重置控制台标志。
5. **设置窗口标题**：Unix 下通过 OSC 转义序列，Windows 下通过 `SetConsoleTitleW`（UTF-8 转 UTF-16）设置控制台标题。
6. **ANSI 颜色支持探测**：检测 `TERM` 与 `NO_COLOR` 环境变量。

### 项目结构

```
examples/terminal/
├── project.yml              # TypePHP 项目编译配置
├── main.php                 # 交互式命令行演示入口
├── cpp-src/
│   └── terminal.cc          # 跨平台 C++ 实现（POSIX 与 Win32）
└── php-src/
    └── Terminal.php         # PHP 原生方法签名与面向对象 Terminal 封装类
```

### 编译与运行

使用 TypePHP 编译为原生二进制程序：

```bash
php bin/tpc.php examples/terminal/project.yml -o terminal-demo
```

运行编译后的程序：

```bash
./terminal-demo
```
