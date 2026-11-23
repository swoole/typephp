# 将 PHP 依赖嵌入可执行文件

`embedded-files` 用于把 Composer 依赖、PHP fallback 文件和运行时资源打包进
TypePHP 可执行文件。它主要解决以下部署问题：

- 第三方库不支持 TypePHP AOT，无法放入 `sources` 原生编译；
- 程序仍需通过 Composer autoload 按需加载这些 PHP 类；
- 发布环境不希望执行 `composer install`，也不希望携带独立的 `vendor` 目录；
- 配置、模板、证书等只读文件也需要随程序一起发布。

启用后，TypePHP 在构建期将不能 AOT 编译的 PHP 文件转换为 OPcache opcode，
同时把配置中选中的全部文件写入可执行文件。运行时 `require`、`require_once` 和
Composer autoload 直接从内存中的 opcode 表加载 PHP 文件，普通只读文件则通过
内存文件表读取。

## 推荐用法

开发构建不启用 `embedded-files`，直接使用工作区中的 `vendor`。发布构建通过
YAML `include` 复用公共配置，并只在发布阶段嵌入依赖。

`project.yml`：

```yaml
name: myapp
mode: bin
build-dir: build

sources:
  - app.php
  - src

ignore:
  - src/legacy
```

`project-release.yml`：

```yaml
include: project.yml
optimize: 2

embedded-files:
  - vendor
  - resources/config
```

构建发布版本：

```bash
composer install --no-dev --classmap-authoritative
tpc project-release.yml
```

项目根目录的 `app.php` 仍按普通 Composer 方式启动自动加载器：

```php
<?php

function main(): void
{
    require_once __DIR__ . '/vendor/autoload.php';

    $application = new App\Application();
    $application->run();
}
```

生成的可执行文件可以移到没有项目源码和 `vendor` 目录的位置运行：

```bash
mkdir -p /tmp/myapp-release
cp myapp /tmp/myapp-release/
cd /tmp/myapp-release
./myapp
```

这里的“独立运行”是指不再需要 PHP CLI、Composer 安装和磁盘上的 PHP 项目文件。
程序仍需要构建方式所对应的 `libphp`、PHPX 及其他原生共享库；这些依赖需随发布包
提供，或使用项目支持的静态链接方式处理。

## `sources`、`ignore` 和 `embedded-files` 的关系

三个配置项承担不同职责：

| 配置 | 作用 |
|---|---|
| `sources` | 选择要翻译为 C++ 并编译为机器码的 PHP/C/C++ 文件。 |
| `ignore` | 从 `sources` 的扫描结果中排除文件。 |
| `embedded-files` | 选择要原样打包进可执行文件的文件，并为没有成功 AOT 编译的 PHP 文件生成 opcode。 |

一个文件可以同时由 `sources` 和 `embedded-files` 选中：

- 成功进入 AOT 的 PHP 文件使用原生实现，不再生成重复 opcode；
- 被 `ignore` 排除或因不支持的语法而未进入 AOT 的 PHP 文件会进入 opcode 表；
- `ignore` 不会从 `embedded-files` 中删除文件；
- `.stub.php` 只作为 API 声明原样打包，不会生成可执行 opcode。

因此可以先让应用代码进入 `sources`，再将整个 `vendor` 放入
`embedded-files`。支持 AOT 的文件继续以机器码运行，其余依赖由 ZendVM 按需执行。

```yaml
sources:
  - src
  - vendor/acme/optimized-package/src

embedded-files:
  - vendor
```

`embedded-files` 没有单独的排除列表。若不希望打包某个子目录，应列出实际需要的
目录或文件，而不是配置整个上级目录。

## 支持的配置格式

该配置只在 YAML 项目中显式启用，值必须是列表。每一项可以是文件、目录或带条件的
路径，相对路径以最外层项目 YAML 所在目录为基准。

```yaml
embedded-files:
  - vendor
  - resources/app.json
  - path: resources/windows
    if: PHP_OS_FAMILY == "Windows"
  - path: resources/php85
    if: PHP_VERSION_ID >= 80500
```

目录会被递归扫描，其中的所有普通文件都会进入归档。除 PHP 文件外，JSON、YAML、
模板和其他资源也会按原始字节保存。

运行时可以使用原来的路径读取已嵌入文件：

```php
$config = file_get_contents(__DIR__ . '/resources/app.json');
```

内嵌文件是只读的。程序产生的日志、缓存、上传文件和数据库应写入单独的运行时数据
目录。不要依赖对内嵌目录执行 `glob()` 或目录遍历；应使用已知文件路径读取资源。

## 构建环境要求

`embedded-files` 仅支持普通的 `mode: bin` 构建。它不适用于 `ext`、`lib`、Nano、
WASI、iOS 或 Android 目标。

构建机必须具备：

1. 与目标 `libphp` 匹配的 `php` 或 `php.exe`；
2. 可由该 CLI 加载的 Zend OPcache 扩展；
3. 与最终运行时一致的 PHP 完整版本、ZTS/NTS、Debug 模式和整数宽度。

可以先检查构建 PHP：

```bash
php -r 'var_dump(PHP_VERSION, PHP_ZTS, extension_loaded("Zend OPcache"), function_exists("opcache_compile_file"));'
```

TypePHP 自己也会用 `-n` 探测 OPcache，并尝试加载标准位置中的扩展。仅有 `tpc`
可执行文件但没有匹配的 PHP CLI 和 OPcache 时，无法构建 `embedded-files`。

生成 opcode 时使用隔离的构建专用 INI，隐藏内部函数声明，保留 OPcache 序列化函数
及不能禁用的语言结构，使内嵌调用保留函数名，而不携带进程局部的 frameless
handler 索引。即使 PHP 版本一致，CLI 与 embed SAPI 的这些索引也可能不同。
生成程序的内部函数保持正常，
AOT C++ 代码生成不受影响；采用旧策略的 vendor 和匿名类 opcode 缓存会重新生成。

OPcache 只参与构建期序列化。运行生成的程序时：

- 不需要启用或安装 OPcache 扩展；
- 不需要 PHP CLI；
- 不需要执行 `composer install`；
- 不需要磁盘上的 `vendor/autoload.php` 或其他已嵌入文件。

二进制中的 opcode 与 PHP 完整版本绑定。若运行时 PHP 与生成 opcode 的 PHP 版本
不同，程序会在启动时报告版本不匹配，而不会继续执行不兼容的字节码。

## 构建输出和日志

首次构建会看到类似输出：

```text
embedded-files: found 3012 files (2886 PHP)
Generating embedded opcodes for 2886 PHP files
Vendor opcode cache: 0 reused, 2886 to generate
Packed 3012 files and 2886 opcode blobs
```

再次构建时，满足缓存条件的 Composer vendor opcode 会被复用：

```text
Vendor opcode cache: 2886 reused, 0 to generate
Packed 3012 files and 2886 opcode blobs
```

内部 opcode、归档和对象文件位于 `build-dir/cache`。生成的
`embedded-opcodes-<name>.cc` 只包含可读的索引代码，大块文件内容不会展开成 C++
数组。

OPcache 无法编译且预期不会被 `require` 的 PHP 文件会显示
`Skipping non-executable embedded PHP file`，文件原始内容仍会被打包。若程序实际会
加载该文件，应修复构建错误，不能忽略这条日志。

## vendor opcode 缓存

只有目录名为 `vendor` 且根目录存在 `autoload.php` 时，TypePHP 才启用持久 opcode
缓存。缓存键包含：

- vendor 根目录路径和目录 mtime；
- PHP CLI 与 OPcache 二进制签名；
- PHP 版本、ZTS/Debug 模式及整数宽度。

其他 `embedded-files` 目录每次构建都会重新生成 opcode，因为编译器无法确定它们的
可靠失效边界。

直接修改 vendor 中一个已有文件不一定改变 vendor 根目录 mtime。此时使用
`--force` 重新生成：

```bash
tpc project-release.yml --force
```

Composer `install` 或 `update` 通常会重建根目录内容和 autoload 文件，但发布流程仍应
在依赖发生人工修改或缓存结果可疑时使用 `--force`。

## 性能和发布建议

嵌入大量 vendor 文件会增大最终二进制、链接输入和进程启动成本。对于频繁启动大量
短进程的 PHPT、单元测试或本地开发，这部分固定成本会很明显。因此建议：

- 开发和测试使用不含 `embedded-files` 的 `project.yml`；
- 只在交付构建使用 `project-release.yml`；
- 保留同一个 `build-dir` 以复用 opcode、归档和对象缓存；
- 修改 vendor 内容但缓存未失效时再使用 `--force`。

Composer autoload 仍是懒加载：只有代码首次引用某个类时，对应 vendor opcode 才由
ZendVM 执行。打包整个 vendor 不会在程序启动时执行全部 PHP 文件。

## 常见问题

### 为什么配置后仍然需要 Composer autoload？

`embedded-files` 改变的是文件保存位置和 PHP 文件的编译来源，不会替代 Composer
生成的类映射与 PSR-4 规则。程序仍然 `require_once` 同一个
`vendor/autoload.php`，只是该文件和后续类文件都从二进制内存表加载。

### 能否只把 `vendor/autoload.php` 放进去？

不能。autoload 文件只包含加载规则，真正的包文件也必须存在于 `embedded-files`
中。通常应直接配置整个 `vendor`。

### 运行主机需要 OPcache 吗？

不需要。反序列化和执行支持已经随 PHPX 链入程序。OPcache 扩展只用于构建 opcode。

### 这是否能保护 PHP 源码？

不要把它当作加密或源码保护功能。为了支持普通文件读取，归档中包含所选文件的原始
字节；能够分析二进制文件的人仍可能提取这些内容。

### vendor 代码依赖其他 PHP 扩展怎么办？

`embedded-files` 只打包 PHP 文件，不会把 `curl`、`pdo_mysql` 等扩展实现打进程序。
应使用 `ext-deps` / `extension-dependencies` 声明依赖，并在目标运行时提供对应扩展。

```yaml
ext-deps:
  - curl
  - pdo_mysql
```
