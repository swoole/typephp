<?php

namespace TypePhp\Tests\Backend;

use PHPUnit\Framework\TestCase;
use TypePhp\Platform\Windows;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Macos;
use TypePhp\Backend\Msvc;
use TypePhp\Backend\Gcc;
use TypePhp\Backend\Clang;

class BackendOptionsTest extends TestCase
{
    /**
     * 测试 MSVC 编译选项 - 基本配置
     */
    public function testMsvcCompileOptionsBasic(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'optimize' => 2,
            'debug' => false,
            'cpp_std' => 'c++17',
            'is_zts' => false,
        ]);
        
        $this->assertStringContainsString('/DZEND_WIN32', $options);
        $this->assertStringContainsString('/DPHP_WIN32', $options);
        $this->assertStringContainsString('/O2', $options);
        $this->assertStringContainsString('/W3', $options);
        $this->assertStringContainsString('/EHsc', $options);
        $this->assertStringContainsString('/std:c++17', $options);
        $this->assertStringContainsString('/MD', $options);
        $this->assertStringContainsString('/nologo', $options);
    }

    public function testMsvcNanoSectionGarbageCollectionOptions(): void
    {
        $compiler = new Msvc(new Windows());

        $compileOptions = $compiler->buildCompileOptions(['section_gc' => true]);
        self::assertStringContainsString('/Gy', $compileOptions);
        self::assertStringContainsString('/Gw', $compileOptions);

        $linkOptions = $compiler->buildLinkOptions(['section_gc' => true]);
        self::assertStringContainsString('/OPT:REF', $linkOptions);
        self::assertStringContainsString('/OPT:ICF', $linkOptions);
    }

    /**
     * 测试 MSVC 编译选项 - ZTS 模式
     */
    public function testMsvcCompileOptionsZts(): void
    {
        $platform = new Windows([], true); // ZTS mode
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'is_zts' => true,
        ]);
        
        $this->assertStringContainsString('/DZTS', $options);
    }

    /**
     * 测试 MSVC 编译选项 - 调试模式
     */
    public function testMsvcCompileOptionsDebug(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'debug' => true,
            'compiler_pdb' => 'C:\\build output\\cache\\msvc\\app.compile.pdb',
        ]);
        
        $this->assertStringContainsString('/Od', $options); // 禁用优化
        $this->assertStringContainsString('/Zi', $options); // 生成调试信息
        $this->assertStringContainsString(
            '/Fd' . escapeshellarg('C:\\build output\\cache\\msvc\\app.compile.pdb'),
            $options
        );
        $this->assertStringContainsString('/FS', $options);
    }

    public function testMsvcReleaseCompileOptionsDoNotCreatePdb(): void
    {
        $compiler = new Msvc(new Windows());
        $options = $compiler->buildCompileOptions([
            'debug' => false,
            'compiler_pdb' => 'C:\\build\\app.compile.pdb',
        ]);

        $this->assertStringNotContainsString('/Fd', $options);
        $this->assertStringNotContainsString('/FS', $options);
    }

    /**
     * 测试 MSVC 编译选项 - Sanitizer
     */
    public function testMsvcCompileOptionsSanitizer(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'sanitize' => 'address',
        ]);
        
        $this->assertStringContainsString('/fsanitize=address', $options);
    }

    /**
     * 测试 MSVC 编译选项 - 警告屏蔽
     */
    public function testMsvcCompileOptionsWarnings(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        // 使用关联数组（键是警告代码，值是描述）
        $options = $compiler->buildCompileOptions([
            'suppressed_warnings' => [
                '4996' => 'deprecated function',
                '4267' => 'size_t to int conversion',
            ],
        ]);
        
        $this->assertStringContainsString('/wd4996', $options);
        $this->assertStringContainsString('/wd4267', $options);
        // 确保不包含中文描述
        $this->assertStringNotContainsString('deprecated', $options);
        $this->assertStringNotContainsString('conversion', $options);
    }

    /**
     * 测试 MSVC 编译选项 - 性能分析
     */
    public function testMsvcCompileOptionsProfiler(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'enable_profiler' => true,
        ]);
        
        $this->assertStringContainsString('/DPPROF_ON=1', $options);
    }

    public function testMsvcCompileOptionsEscapesUnsafeDefines(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);

        $options = $compiler->buildCompileOptions([
            'enable_profiler' => true,
            'prof_output' => 'profile output.log',
            'user_defines' => ['APP_NAME="hello world"'],
        ]);

        $this->assertStringContainsString('/D' . escapeshellarg('APP_NAME="hello world"'), $options);
        $this->assertStringContainsString('/D' . escapeshellarg('PROF_OUTPUT_FILE="profile output.log"'), $options);
    }

    /**
     * 测试 MSVC 编译选项 - 自定义标志
     */
    public function testMsvcCompileOptionsCustomFlags(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([
            'cxxflags' => '/experimental:module',
        ]);
        
        $this->assertStringContainsString('/experimental:module', $options);
    }

    /**
     * 测试 MSVC 链接选项 - 基本配置
     */
    public function testMsvcLinkOptionsBasic(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildLinkOptions([]);
        
        $this->assertStringContainsString('/NODEFAULTLIB:LIBCMT', $options);
        $this->assertStringContainsString('/nologo', $options);
    }

    /**
     * 测试 MSVC 链接选项 - 调试
     */
    public function testMsvcLinkOptionsDebug(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildLinkOptions([
            'debug' => true,
        ]);
        
        $this->assertStringContainsString('/DEBUG', $options);
    }

    /**
     * 测试 MSVC 链接选项 - 无控制台
     */
    public function testMsvcLinkOptionsNoConsole(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildLinkOptions([
            'no_console' => true,
        ]);
        
        $this->assertStringContainsString('/SUBSYSTEM:WINDOWS', $options);
        $this->assertStringContainsString('/ENTRY:mainCRTStartup', $options);
    }

    /**
     * 测试 MSVC 链接选项 - 扩展模块
     */
    public function testMsvcLinkOptionsExtension(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildLinkOptions([
            'build_mode' => 'ext',
        ]);
        
        $this->assertStringContainsString('/DLL', $options);
    }

    /**
     * 测试 GCC 编译选项 - 基本配置
     */
    public function testGccCompileOptionsBasic(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildCompileOptions([
            'optimize' => 2,
            'debug' => false,
            'cpp_std' => 'c++17',
        ]);
        
        $this->assertStringContainsString('-O2', $options);
        $this->assertStringContainsString('-Wall', $options);
        $this->assertStringContainsString('-std=c++17', $options);
    }

    public function testGccExtensionSymbolsAreHiddenByDefault(): void
    {
        $compiler = new Gcc(new Linux());
        $options = $compiler->buildCompileOptions([
            'build_mode' => 'ext',
        ]);

        $this->assertStringContainsString('-fvisibility=hidden', $options);
    }

    /**
     * 测试 GCC 编译选项 - 调试模式
     */
    public function testGccCompileOptionsDebug(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildCompileOptions([
            'debug' => true,
        ]);
        
        $this->assertStringContainsString('-O0', $options);
        $this->assertStringContainsString('-g', $options);
    }

    /**
     * 测试 GCC 编译选项 - Sanitizer
     */
    public function testGccCompileOptionsSanitizer(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildCompileOptions([
            'sanitize' => 'address',
        ]);
        
        $this->assertStringContainsString('-fsanitize=address', $options);
        $this->assertStringContainsString('-g ', $options);
        $this->assertStringContainsString('-fno-omit-frame-pointer', $options);
        $this->assertStringContainsString('-O2', $options);
    }

    /**
     * 测试 GCC 编译选项 - UndefinedBehaviorSanitizer
     */
    public function testGccCompileOptionsUbsan(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildCompileOptions([
            'sanitize' => 'undefined',
        ]);
        
        $this->assertStringContainsString('-fsanitize=undefined', $options);
    }

    /**
     * 测试 GCC 编译选项 - PIC
     */
    public function testGccCompileOptionsPic(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildCompileOptions([
            'build_mode' => 'ext',
        ]);
        
        $this->assertStringContainsString('-fPIC', $options);
    }

    public function testGccCompileOptionsEscapesUnsafeDefines(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);

        $options = $compiler->buildCompileOptions([
            'enable_profiler' => true,
            'prof_output' => 'profile output.log',
            'user_defines' => ['APP_NAME="hello world"'],
        ]);

        $this->assertStringContainsString('-D' . escapeshellarg('APP_NAME="hello world"'), $options);
        $this->assertStringContainsString('-D' . escapeshellarg('PROF_OUTPUT_FILE="profile output.log"'), $options);
    }

    /**
     * 测试 GCC 链接选项 - 基本配置
     */
    public function testGccLinkOptionsBasic(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildLinkOptions([]);
        
        $this->assertEquals('', $options); // 基本配置应该为空
    }

    /**
     * 测试 GCC 链接选项 - 调试模式（链接时不需要 -g）
     */
    public function testGccLinkOptionsDebug(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildLinkOptions([
            'debug' => true,
        ]);
        
        // 链接时不应该包含 -g，调试信息在编译阶段已经生成
        $this->assertStringNotContainsString('-g', $options);
    }

    /**
     * 测试 GCC 链接选项 - 共享库
     */
    public function testGccLinkOptionsShared(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildLinkOptions([
            'build_mode' => 'ext',
        ]);
        
        $this->assertStringContainsString('-shared', $options);
    }

    public function testMacosExtensionResolvesPhpSymbolsFromHost(): void
    {
        $compiler = new Clang(new Macos());
        $options = $compiler->buildLinkOptions([
            'build_mode' => 'ext',
        ]);

        self::assertStringContainsString('-dynamiclib', $options);
        self::assertStringContainsString('-undefined dynamic_lookup', $options);
    }

    /**
     * 测试 GCC 链接选项 - RPATH
     */
    public function testGccLinkOptionsRpath(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildLinkOptions([
            'rpath' => ['/usr/lib', '/usr/local/lib'],
        ]);
        
        $this->assertStringContainsString('-Wl,-rpath', $options);
        $this->assertStringContainsString('/usr/lib', $options);
        $this->assertStringContainsString('/usr/local/lib', $options);
    }

    /**
     * 测试 Clang 编译选项 - Unix 平台
     */
    public function testClangCompileOptionsUnix(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildCompileOptions([
            'optimize' => 2,
            'cpp_std' => 'c++17',
        ]);
        
        $this->assertStringContainsString('-O2', $options);
        $this->assertStringContainsString('-Wall', $options);
        $this->assertStringContainsString('-std=c++17', $options);
        $this->assertStringNotContainsString('-fms-compatibility', $options);
    }

    /**
     * 测试 Clang 编译选项 - Windows 平台
     */
    public function testClangCompileOptionsWindows(): void
    {
        $platform = new Windows();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildCompileOptions([]);
        
        $this->assertStringContainsString('-fms-compatibility', $options);
        $this->assertStringContainsString('-fms-compatibility-version=19.40', $options);
        $this->assertStringContainsString('-fdelayed-template-parsing', $options);
        $this->assertStringContainsString('-fms-extensions', $options);
    }

    /**
     * 测试 Clang 编译选项 - PIC (Unix)
     */
    public function testClangCompileOptionsPicUnix(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildCompileOptions([
            'build_mode' => 'ext',
        ]);
        
        $this->assertStringContainsString('-fPIC', $options);
    }

    /**
     * 测试 Clang 链接选项 - Windows
     */
    public function testClangLinkOptionsWindows(): void
    {
        $platform = new Windows();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildLinkOptions([
            'debug' => true,
            'no_console' => true,
            'build_mode' => 'ext',
        ]);
        
        $this->assertStringContainsString('/DEBUG', $options);
        $this->assertStringContainsString('/SUBSYSTEM:WINDOWS', $options);
        $this->assertStringContainsString('/NODEFAULTLIB:LIBCMT', $options);
        $this->assertStringContainsString('/DLL', $options);
    }

    /**
     * 测试 Clang 链接选项 - Unix（链接时不需要 -g）
     */
    public function testClangLinkOptionsUnix(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildLinkOptions([
            'debug' => true,
            'build_mode' => 'ext',
            'rpath' => ['/usr/lib'],
        ]);
        
        // 链接时不应该包含 -g，调试信息在编译阶段已经生成
        $this->assertStringNotContainsString('-g', $options);
        $this->assertStringContainsString('-shared', $options);
        $this->assertStringContainsString('-Wl,-rpath', $options);
    }

    /**
     * 测试不同优化级别 - MSVC
     */
    public function testMsvcOptimizationLevels(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        // O0
        $opt0 = $compiler->buildCompileOptions(['optimize' => 0]);
        $this->assertStringContainsString('/Od', $opt0);
        
        // O1
        $opt1 = $compiler->buildCompileOptions(['optimize' => 1]);
        $this->assertStringContainsString('/O1', $opt1);
        
        // O2
        $opt2 = $compiler->buildCompileOptions(['optimize' => 2]);
        $this->assertStringContainsString('/O2', $opt2);
        
        // O3
        $opt3 = $compiler->buildCompileOptions(['optimize' => 3]);
        $this->assertStringContainsString('/Ox', $opt3);
    }

    /**
     * 测试不同优化级别 - GCC/Clang
     */
    public function testGccOptimizationLevels(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        // O0
        $opt0 = $compiler->buildCompileOptions(['optimize' => 0]);
        $this->assertStringContainsString('-O0', $opt0);
        
        // O1
        $opt1 = $compiler->buildCompileOptions(['optimize' => 1]);
        $this->assertStringContainsString('-O1', $opt1);
        
        // O2
        $opt2 = $compiler->buildCompileOptions(['optimize' => 2]);
        $this->assertStringContainsString('-O2', $opt2);
        
        // O3
        $opt3 = $compiler->buildCompileOptions(['optimize' => 3]);
        $this->assertStringContainsString('-O3', $opt3);
    }

    /**
     * 测试默认值
     */
    public function testDefaultValues(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        // 不提供选项时使用默认值
        $options = $compiler->buildCompileOptions([]);
        
        $this->assertStringContainsString('-O2', $options); // 默认优化级别
        $this->assertStringContainsString('-Wall', $options); // 默认警告
    }

    /**
     * 测试空配置
     */
    public function testEmptyConfig(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildCompileOptions([]);
        
        // 即使空配置也应该有基本的宏定义和选项
        $this->assertStringContainsString('/DZEND_WIN32', $options);
        $this->assertStringContainsString('/nologo', $options);
    }
}
