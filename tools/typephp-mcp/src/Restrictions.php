<?php

/**
 * Compact TypePHP constraint knowledge, distilled from the repository docs.
 *
 * This is the "write code correctly on the first try" layer: stock PHP tooling
 * does not know about the TypePHP subset, so these rules are surfaced before
 * and alongside the compiler oracle (check_code).
 *
 * Sources (authoritative, read them for details):
 *   docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md
 *   docs/en/INCOMPATIBLE_PHP_FEATURES.md
 *   docs/zh-cn/MIXED_CPP_PHP.md        (.stub.php contract)
 *   docs/zh-cn/COMPILER_CLI.md         (CLI/YAML options)
 *   docs/zh-cn/COMPILATION_MODES.md    (bin/lib/ext modes)
 *   docs/zh-cn/STD_CONTAINERS.md, HIGH_PRECISION_TYPES.md,
 *   COMPILE_TIME_FUNCTIONS.md, UNIVERSAL_METHODS.md
 */
final class Restrictions
{
    /**
     * @return array<string, array{title: string, rules: array<int, string>}>
     */
    public static function topics(): array
    {
        return [
            'structure' => [
                'title' => '程序结构',
                'rules' => [
                    '全局作用域不允许可执行语句；只允许声明、use、declare、常量定义。echo/赋值等语句必须放进函数或方法。',
                    '函数和方法内部不允许声明函数，也不允许声明具名类。',
                    'bin 模式必须定义全局 main()；签名仅允许 function main() 或 function main(int $argc, array $argv)。',
                    '文档要求 main() 返回 void；实际示例（examples/qt-taskboard）使用 int 退出码，以编译器行为为准。',
                    '所有源文件必须是 UTF-8。',
                ],
            ],
            'types' => [
                'title' => '类型与声明',
                'rules' => [
                    '局部变量一旦被静态推断为具体 native 类型（int/float/bool/string/array），同一作用域内不能重新赋值为不兼容类型；需要 Zend 动态语义时用 std::any() 或文件级 use varint_types。',
                    '不支持可变变量 $$var。',
                    'declare 只支持 strict_types 与 encoding=UTF-8；strict_types=0 被拒绝；不支持 ticks。',
                    '严格参数个数：非 variadic 函数不接受声明范围之外的额外参数，func_get_args() 不会放宽签名。',
                    '__construct() 不允许返回值；参数默认值不允许出现在必填参数之前。',
                    '联合/交叉/nullable 类型以 mixed/any 作为 C++ 表示；静态阶段会提前拒绝确定不兼容的用法。',
                    '不支持 PHP 8.5 #[NoDiscard]；全局/命名空间常量声明上不允许 attributes（class constant 不受限制）。',
                ],
            ],
            'refs' => [
                'title' => '调用与引用',
                'rules' => [
                    '动态调用、闭包调用的引用参数必须显式 std::ref() 或 toRef()；这是已确认的语言设计约束，不会做运行时自动识别。',
                    '闭包和箭头函数不支持按引用返回。',
                    '不支持 Closure::bind()、Closure::bindTo()、Closure::call()。',
                    '原生引用绑定必须无条件、一次性、函数内、不逃逸；条件/循环内首次绑定、重新绑定、unset、按引用返回局部变量、存入属性/数组/全局槽位都会在编译期被拒绝。',
                    'Closure 的 use (&$value) 是显式退化边界：捕获的局部量退化为 php::Var。',
                ],
            ],
            'objects' => [
                'title' => '对象模型',
                'rules' => [
                    '可以继承内置类（Zend 内部类，如 ArrayObject/PDO/Exception，编译期按反射判定），也可以继承参与 AOT 编译的用户类；不能继承仅在运行时定义/加载（autoload）的用户类，否则报 "inherits from a non-existent class"。',
                    'Native Class（#[Native]）与普通 Zend 类不能互相继承；不能继承 final 类；readonly 继承契约双向适用（Zend 父类以宿主运行时反射为准，如 BcMath\Number 是 internal readonly）。',
                    'Native Class 使用独立固定布局，不能当作普通 Zend Object、PHP array key/value 或任意 mixed 值使用。',
                    'toInt()/toString()/toArray() 等关键词方法先于普通对象方法解析；toAny()/toRef() 不可覆盖。',
                    '禁止子类用同名 private 属性隐藏父类私有属性。',
                ],
            ],
            'control' => [
                'title' => '表达式与控制流',
                'rules' => [
                    '非空 switch case 必须以 return/break/continue/exit/throw 结束，不要依赖 PHP 的隐式 case fallthrough。',
                    'foreach 遍历 std::vector/std::map/std::orderedMap 期间禁止追加、插入、unset() 或整体替换；已有元素的非结构性更新可以用赋值完成。',
                    '静态类型明确为 array 时，与标量的算术、位运算及复合赋值编译期报错；array + array 使用 PHP 并集规则（保留左侧键与顺序）。',
                    'match 的 arm condition 不能是 match 表达式。',
                    'TypePHP 明确把 null 数组 key 视为追加操作，而不是转换为空字符串 key。',
                ],
            ],
            'runtime' => [
                'title' => '运行时动态能力',
                'rules' => [
                    '动态属性链、动态类名、动态函数名、动态回调统一走 Zend runtime fallback，不保证 native 优化。',
                    'eval() 能通过 Zend VM 执行 PHP，但访问不到 AOT 编译的局部变量（AOT 局部量是 C++ 栈变量）；需用返回值或 $GLOBALS 交换数据。',
                    'static::class 在需要编译期常量类名的位置不支持；__CLASS__/__TRAIT__ 只允许在对应 class/trait 定义内使用。',
                    'include/require 可用（tests/compiler/include_require/ 有覆盖）。',
                ],
            ],
            'std' => [
                'title' => 'std 表面与属性代码生成',
                'rules' => [
                    '容器：std::array / std::vector / std::map / std::orderedMap（详见 docs/zh-cn/STD_CONTAINERS.md）。',
                    '高精度数值：std::bigInt / std::decimal / std::bigFloat（详见 docs/zh-cn/HIGH_PRECISION_TYPES.md）。',
                    '值构造：std::any() / std::object() / std::ref() / std::expected() / std::unexpected()。',
                    '属性代码生成属性（如 #[Immutable]，见 examples/attributes/）与编译期函数见 docs/zh-cn/COMPILE_TIME_FUNCTIONS.md。',
                    '通用方法（toString/toInt/toArray/jsonEncode/jsonDecode、String/Array/Stream 等 200+ 个）见 docs/zh-cn/UNIVERSAL_METHODS.md。',
                ],
            ],
            'stub' => [
                'title' => '.stub.php 契约（混合 C++/PHP）',
                'rules' => [
                    '.stub.php 只声明由外部 C++ 提供的 Zend ABI 符号；函数体必须为空 {}，写实现是编译错误。',
                    '禁止对 .stub.php 中的类使用 #[Native]；Native Class 的对象布局必须由 TypePHP 编译器生成和持有。',
                    '外部 C++ 函数硬规则：参数类型必须全是 php:: 类型；返回类型必须是 php:: 类型；函数名必须以 php_ 前缀开头；必须有对应 .stub.php 声明。',
                    '-m lib 会把项目的 .php 与本地 .stub.php 聚合为目标 <target>.stub.php 并自动加 @import-library；#[\NoExport] 可把声明排除出公共 ABI。',
                    '范式参考：examples/qt-taskboard/php-src/taskboard.stub.php、examples/prime/php-src/vector.stub.php。',
                ],
            ],
            'build' => [
                'title' => '构建配置（project.yml）',
                'rules' => [
                    'mode：bin（默认，需全局 main()）/ lib / ext；ext 模式用 info 提供 phpinfo 元数据。',
                    '常用字段：name/version/cxx-std/cxx-flags/c-flags/ld-flags/sources/ignore/objects/include-paths/defines/link-paths/link-libs/ext-deps/embedded-files/entry/sapi/build-dir/output。',
                    'ext-deps 是 PHP 扩展依赖（生成 ZEND_MOD_REQUIRED）；link-libs 是 C++ 链接库；两者不能互相替代。',
                    '命令行参数优先于 YAML 同名项；--dry 只生成 C++，不编译不链接（链接期错误覆盖不到）。',
                    'bin 模式默认 embed SAPI；cli/fpm SAPI 强依赖 php-builder，从 php-src 构建私有静态运行时。',
                    'embedded-files 把 Composer vendor/只读资源打包进可执行文件；内嵌文件系统不支持 glob()/目录迭代。',
                ],
            ],
        ];
    }

    public static function topicNames(): array
    {
        $names = [];
        foreach (array_keys(self::topics()) as $name) {
            $names[] = $name;
        }
        return $names;
    }

    public static function render(string $topic): string
    {
        $topics = self::topics();
        if ($topic !== '' && $topic !== 'all') {
            if (!array_key_exists($topic, $topics)) {
                return 'Unknown topic: ' . $topic . "\nAvailable topics: " . implode(', ', self::topicNames());
            }
            $section = $topics[$topic];
            return '## ' . $section['title'] . ' [' . $topic . "]\n- " . implode("\n- ", $section['rules'])
                . "\n\nSources: docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md, docs/zh-cn/CLASS_INHERITANCE.md, docs/zh-cn/MIXED_CPP_PHP.md, docs/zh-cn/COMPILER_CLI.md";
        }

        $blocks = [];
        foreach ($topics as $name => $section) {
            $blocks[] = '## ' . $section['title'] . ' [' . $name . "]\n- " . implode("\n- ", $section['rules']);
        }
        return TextUtil::clip(implode("\n\n", $blocks), 6000);
    }

    /**
     * ~800-token summary injected into tool descriptions and the initialize
     * instructions, so an agent knows the red lines before calling any tool.
     */
    public static function summary(): string
    {
        return 'TypePHP red lines (verify with check_code before submitting): '
            . '1) global scope allows declarations only (use/declare/const/functions/classes); executable statements live in main()/functions/methods. '
            . '2) bin mode requires a global main() with signature main() or main(int $argc, array $argv). '
            . '3) once a local is inferred as a concrete native type (int/float/bool/string/array) it cannot be reassigned to an incompatible type in the same scope; use std::any() for dynamic semantics. '
            . '4) reference arguments of dynamic/Closure calls need explicit std::ref()/toRef(); closures cannot return by reference; Closure::bind/bindTo/call are unsupported. '
            . '5) built-in (internal) classes like ArrayObject/PDO CAN be extended, and so can user classes that take part in AOT compilation; classes defined/loaded only at runtime (autoload, not compiled) cannot - the compiler reports "inherits from a non-existent class"; Native classes and plain Zend classes cannot inherit from each other; final classes cannot be extended. '
            . '6) every non-empty switch case must end with return/break/continue/exit/throw; do not rely on fallthrough. '
            . '7) no $$var, no declare(ticks), strict_types=0 rejected; eval() cannot see AOT locals. '
            . '8) .stub.php bodies must be empty {}; external C++ functions are php_-prefixed with php:: types only. '
            . '9) foreach over std::vector/map/orderedMap forbids structural insert/remove. '
            . '10) check_code covers C++ generation only, not link-time errors. '
            . 'Verified against TypePHP v0.9.5; authoritative sources: docs/zh-cn/CLASS_INHERITANCE.md, docs/zh-cn/INCOMPATIBLE_PHP_FEATURES.md, src/Translator.php.';
    }
}
