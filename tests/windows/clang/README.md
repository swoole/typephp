# Windows x64 Clang verification

This suite uses the Microsoft ABI (`x86_64-pc-windows-msvc`), Microsoft's
headers and import libraries, and LLVM executables. It does not use MinGW.

See [the verification report](RESULTS.md) for tested versions and results.

## Environment

Install an LLVM distribution with `clang++`, `clang-cl`, `lld-link`,
`llvm-readobj`, `llvm-rc`, `llvm-lib`, and `llvm-mt`. Install the MSVC x64
headers/libraries and Windows SDK, plus matching PHP runtimes, PHP development
packs, and PHPX libraries for NTS and ZTS. Run from a dedicated PowerShell
session in the compiler repository:

```powershell
. .\tests\windows\clang\set-llvm-env.ps1 `
  -LlvmHome D:\workspace\tools\llvm-23.1.2 `
  -VcToolsRoot 'D:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Tools\MSVC\14.44.35207' `
  -WindowsSdkRoot 'C:\Program Files (x86)\Windows Kits\10' `
  -WindowsSdkVersion 10.0.26100.0

$matrix = @{
  PhpNts = 'D:\workspace\tools\php-8.4.23-nts'
  PhpZts = 'D:\workspace\php-8.4.23'
  PhpxNts = 'D:\workspace\.typephp-tests\windows-clang-20260930\phpx-nts'
  PhpxZts = 'D:\workspace\.typephp-tests\windows-clang-20260930\phpx-zts'
  NoMsvcTools = $true
}
& .\tests\windows\clang\run-matrix.ps1 @matrix -ArtifactDir D:\workspace\artifacts\clang-baseline
```

Adjust paths to the installed packages. The setup script replaces the session's
PATH with LLVM and Windows system directories. It sets INCLUDE/LIB directly
and does not run `vcvars64.bat`. Dependencies such as GMP, MPFR, and mpdecimal
must also match the PHP/PHPX package.

For Nano, Composer must resolve the local `swoole/phpx` and `swoole/php-nano`
sources. A PHPX package resolved through Composer may differ from PHPX_HOME;
the library entry point is tested with this layout too.

## Additional options

```powershell
& .\tests\windows\clang\run-matrix.ps1 @matrix `
  -ArtifactDir D:\workspace\artifacts\clang-lto -Lto `
  -Cases normal-nts-bin,normal-zts-lib,normal-zts-ext,nano-nts-bin,nano-zts-lib

& .\tests\windows\clang\run-matrix.ps1 @matrix `
  -ArtifactDir D:\workspace\artifacts\clang-options -DebugBuild -NoConsole -BinResources `
  -Cases normal-zts-bin,nano-nts-bin
```

Each run writes `results.json`, build logs, run logs, and artifacts. Use a
different artifact directory for each profile. The baseline has 10 successful
compile/run cases and 2 expected rejections: Nano extension mode is unsupported.
Nano built from either an NTS or a ZTS compiler host runs as NTS. Its runtime
tests use a PATH containing only Windows system directories.

Tests exercise WinAPI, PHP/native ZTS agreement, objects, closures, arrays,
exceptions, UTF-8 file I/O, PHP extension loading, and DLL runtime
initialization/shutdown. The options profile checks a PDB, the actual PE GUI
subsystem, and the actual Unicode version-resource values.

## Rebuilding PHPX without MSVC executables

Install standalone CMake and Ninja. After the setup above, set PHP_HOME to the
matching runtime and configure a separate PHPX checkout for each NTS/ZTS mode:

```powershell
$env:PHP_HOME = $matrix.PhpNts
$llvm = $env:LLVM_HOME.Replace('\', '/')
$cmake = 'D:\workspace\tools\cmake-4.4.3-windows-x86_64\bin\cmake.exe'
& $cmake --fresh -S $matrix.PhpxNts -B "$($matrix.PhpxNts)/build" -G Ninja `
  '-DCMAKE_MAKE_PROGRAM=D:/workspace/tools/ninja-1.13.2/ninja.exe' `
  "-DCMAKE_C_COMPILER=$llvm/bin/clang-cl.exe" `
  "-DCMAKE_CXX_COMPILER=$llvm/bin/clang-cl.exe" `
  "-DCMAKE_LINKER=$llvm/bin/lld-link.exe" `
  "-DCMAKE_AR=$llvm/bin/llvm-lib.exe" `
  "-DCMAKE_RC_COMPILER=$llvm/bin/llvm-rc.exe" `
  "-DCMAKE_MT=$llvm/bin/llvm-mt.exe" `
  -DCMAKE_BUILD_TYPE=Release -DCMAKE_MSVC_RUNTIME_LIBRARY=MultiThreadedDLL `
  '-DCMAKE_C_FLAGS=' '-DCMAKE_CXX_FLAGS=/EHsc'
if ($LASTEXITCODE -ne 0) { throw 'PHPX configuration failed' }
& $cmake --build "$($matrix.PhpxNts)/build" --target phpx --parallel 2
if ($LASTEXITCODE -ne 0) { throw 'PHPX build failed' }
```

Forward slashes in the CMake tool paths avoid backslash escapes in generated
CMake files. `clang-cl` is used for PHPX's MSVC-style CMake options; TypePHP's
compiler backend uses `clang++` and links directly with `lld-link`.
