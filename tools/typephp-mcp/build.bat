@echo off
REM Build typephp-mcp.exe with TypePHP (AOT).
REM
REM All paths are resolved relative to this script's location:
REM   <script dir>                     project.yml, build/, output exe + DLLs
REM   <script dir>\..\..               repo root (bin\tpc.php)
REM   <script dir>\..\..\..\tpc_v...   tpc package (default, sibling of the repo)
REM
REM Prerequisites: a tpc package (see TPC_PACKAGE below) and the MSVC
REM toolchain (located via vswhere, with a VS 2022 BuildTools fallback).
REM If the default package location does not exist, set PHP_HOME to your
REM tpc package directory before running this script.
setlocal

cd /d "%~dp0"
set SCRIPT_DIR=%CD%

REM --- tpc package: repo-relative default, PHP_HOME fallback ----------------
set TPC_PACKAGE=%SCRIPT_DIR%\..\..\..\tpc_v0.9.5_windows_x64
for %%I in ("%TPC_PACKAGE%") do set "TPC_PACKAGE=%%~fI"
if not exist "%TPC_PACKAGE%\php.exe" (
    if defined PHP_HOME if exist "%PHP_HOME%\php.exe" set "TPC_PACKAGE=%PHP_HOME%"
)
if not exist "%TPC_PACKAGE%\php.exe" (
    echo [build] ERROR: tpc package not found at "%TPC_PACKAGE%".
    echo [build] Fix the package path in this script, or set PHP_HOME first:
    echo [build]     set PHP_HOME=D:\path\to\tpc_v0.9.5_windows_x64
    exit /b 1
)
set "PHPX_HOME=%TPC_PACKAGE%\phpx"
set "PHP_HOME=%TPC_PACKAGE%"

REM --- repo root (bin\tpc.php lives there) -----------------------------------
for %%I in ("%SCRIPT_DIR%\..\..") do set "REPO_ROOT=%%~fI"

REM --- MSVC environment (vswhere auto-detect, VS 2022 BuildTools fallback) ---
set "VCVARS="
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
if exist "%VSWHERE%" (
    for /f "usebackq tokens=*" %%I in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VCVARS=%%I\VC\Auxiliary\Build\vcvars64.bat"
)
if not defined VCVARS set "VCVARS=D:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvars64.bat"
echo [build] Initializing MSVC environment: "%VCVARS%"
call "%VCVARS%"
if %errorlevel% neq 0 exit /b 1

REM --- build -----------------------------------------------------------------
echo [build] tpc package: "%TPC_PACKAGE%"
REM --job 1: user-managed machines build serially (AGENTS.md resource safety).
REM CI builds the MCP binary with its own step, which passes --job %BUILD_JOBS%
REM because a dedicated runner may use its available CPU count.
"%PHP_HOME%\php.exe" "%REPO_ROOT%\bin\tpc.php" project.yml -O2 --job 1 --build-dir build --no-color --no-progress
if %errorlevel% neq 0 exit /b 1

REM Deploy the PHP runtime DLLs next to the executable so it runs without
REM touching PATH (the import libs come from the tpc package).
for %%D in (phpx.dll php8ts.dll gmp-10.dll gmpxx-4.dll mpfr-6.dll libmpdec-4.0.1.dll libmpdec++-4.0.1.dll) do (
    if exist "%PHP_HOME%\%%D" copy /y "%PHP_HOME%\%%D" . > nul
)
echo [build] Done: "%SCRIPT_DIR%\typephp_mcp.exe"
