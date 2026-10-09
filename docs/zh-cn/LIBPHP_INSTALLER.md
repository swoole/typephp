# PHP builder

在 `mode: bin` 下，TypePHP 将产物类型与 PHP SAPI 分开配置：

```yaml
mode: bin
sapi: [embed, cli, fpm]
php-builder:
  extensions: [swoole, mongodb]
  zts: on
```

`sapi` 用于选择一个或多个进程接口，默认值是 `embed`。`cli` 和 `fpm` 不是 mode；
由于宿主 PHP 安装不提供这些目标所需的静态构建输入，它们始终强依赖
`php-builder`。

`php-builder` 表示 TypePHP 从官方 php-src 归档构建私有 PHP 运行时。产物不依赖
宿主机 PHP 运行时，但会使用操作系统提供的开发库。官方 php-src 缓存不会被修改；
外部 PECL 扩展会注入派生的源码目录。显式 `extensions`、项目源码、YAML 依赖和
内嵌 Composer 元数据中的扩展需求会合并，并自动转换为 PHP configure 参数。

构建统一使用 `--disable-all`，然后通过显式 `--enable-*` / `--with-*` 参数开启
所需扩展及其必要依赖。例如 `pdo_sqlite` 会同时启用 PDO，`dom` 会同时启用
libxml；可选依赖不会自动开启。mbstring、sockets、zlib 等扩展不再默认启用，
需要时请加入 `extensions` 或通过上述项目依赖声明。
PHP 不可禁用的核心扩展，以及运行时所需的 OPcache，仍会保留。
构建不继承宿主 PHP 或源码目录中 `config.nice` 的配置。

等价的命令行为：

```bash
bin/tpc.php project.yml \
  --sapi=embed,cli,fpm \
  --php-builder='extensions: [swoole, mongodb]; zts: on'
```

`zts` 和 `debug` 接受 `on` 或 `off`，未配置时分别采用运行 TypePHP 编译器的
`PHP_ZTS` 和 `PHP_DEBUG`。例如 `php-builder: {}` 会沿用宿主的 ZTS/NTS 和
debug/release 构建设置。`php-builder.debug` 控制 PHP 的 `--enable-debug` /
`--disable-debug`，与顶层控制 C++ 调试信息的 `debug` 选项不同。
`php-builder.sapi` 是非法配置；SAPI 始终通过独立的顶层 `sapi` 选项指定。

## PHP 运行时兼容性

php-builder 的目标 PHP 必须与运行编译器的 PHP 保持以下设置一致，否则构建报错：

- major/minor 版本，例如 `8.4` 与 `8.5` 不能混用。
- ZTS/NTS 设置。
- DEBUG 设置。

这些规则同时适用于源码版 `bin/tpc.php` 和原生 `tpc` 可执行文件。
TypePHP 会在下载或编译 PHP 前检查配置，并在使用目标 PHP CLI 时核对实际运行时。
编译器不会自动切换到另一个 PHP。宿主为 PHP 8.4 时，请设置 `--php-version=8.4`
或 YAML 的 `php-version: '8.4'`；该选项的默认值仍为 `8.5`。

release（补丁）版本不同只输出警告，例如宿主 `8.5.7` 与目标 `8.5.10` 可以继续构建。
`PHP_VERSION`、`PHP_VERSION_ID` 等常量在编译期仍采用编译器运行时的值；需要查询
目标的实际版本时，可在程序中调用 `phpversion()` 或 `constant('PHP_VERSION')`。

## 默认 Embed 行为

未配置 `php-builder` 时，`mode: bin` 加 `sapi: embed` 保持原有行为，链接宿主机
Embed 库（`libphp.so` 或 `libphp.dylib`）。Linux 或 macOS 上缺少该库时，交互式
运行会询问是否启用 `php-builder`。非交互环境必须显式选择：

```bash
bin/tpc.php project.yml \
  --php-builder
```

若拒绝提示，则没有可用的 Embed 运行时，构建会停止。

## 缓存与代理

下载的源码和私有运行时缓存在 `~/.typephp`，兼容的运行时会在不同应用构建之间复用。
缓存匹配包含扩展需求及其 configure 参数，不再复用扩展配置不同的运行时。
ZTS 和 DEBUG 设置也参与缓存匹配，配置不同的运行时不会共用缓存。
旧版本未使用 `--disable-all` 的缓存会自动失效，首次构建需要重新编译 PHP。
`--proxy` 是全局网络设置，不属于 `php-builder`；PHP、PECL 元数据与归档，以及
TypePHP 的其他网络传输，都会使用指定的 HTTP(S) 或 SOCKS 代理。

```bash
bin/tpc.php project.yml --proxy=socks5h://127.0.0.1:1080 \
  --php-builder
```
