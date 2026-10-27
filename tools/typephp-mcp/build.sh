#!/usr/bin/env bash
# Build typephp_mcp with TypePHP (AOT) on macOS/Linux.
#
# All paths are resolved relative to this script's location:
#   <script dir>         project.yml, build/, output binary
#   <script dir>/../..   repo root (bin/tpc.php)
#
# Unlike build.bat there is no toolchain installer to call: clang must be on
# PATH (xcode-select --install) and the PHP/PHPX runtime has to be located,
# because there is no per-platform tpc package directory to default to.
#
#   PHP_HOME   prefix providing lib/libphp.{a,dylib} (the embed SAPI)
#   PHPX_HOME  phpx tree providing include/phpx.h and lib/libphpx.{a,dylib}
#
# Resolution order: explicit environment, then the newest private runtime that
# tpc's php-builder left under ~/.typephp/php-builder. When neither is
# available, build one (compiles php-src plus a static phpx; tpc only offers
# php-builder interactively, so the flag has to be explicit in a scripted
# shell):
#
#   php bin/tpc.php tools/typephp-mcp/project.yml --php-builder='extensions: []'
#
# The compiler is the built ./tpc when the repository already has one (CI
# builds it first), otherwise the interpreted `php bin/tpc.php`, which needs
# the phpy extension that only the Linux x64 workflow enables.
#
# Resource safety (AGENTS.md): user-managed machines build serially under the
# shared user-wide build lock (Linux) with a virtual memory cap for the whole
# build tree. Dedicated CI runners instead build with their available CPU
# count, so nothing here serializes a CI build.
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
cd "$SCRIPT_DIR"
REPO_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)

php_home=${PHP_HOME:-}
phpx_home=${PHPX_HOME:-}

if [[ -z "$php_home" ]]; then
    for candidate in $(ls -1d "${HOME}"/.typephp/php-builder/php-*/install 2>/dev/null | sort -r); do
        if [[ -f "$candidate/lib/libphp.a" || -f "$candidate/lib/libphp.dylib" || -f "$candidate/lib/libphp.so" ]]; then
            php_home=$candidate
            break
        fi
    done
fi

if [[ -z "$phpx_home" ]]; then
    for candidate in "${REPO_ROOT}/vendor/swoole/phpx" "${php_home}/phpx" "$php_home"; do
        if [[ -f "$candidate/include/phpx.h" ]] \
            && { [[ -f "$candidate/lib/libphpx.a" ]] || [[ -f "$candidate/lib/libphpx.dylib" ]] || [[ -f "$candidate/lib/libphpx.so" ]]; }; then
            phpx_home=$candidate
            break
        fi
    done
fi

if [[ -z "$php_home" || -z "$phpx_home" ]]; then
    echo "[build] ERROR: no usable PHP/PHPX runtime found."
    echo "[build]   PHP_HOME needs lib/libphp.a, .so or .dylib (the embed SAPI);"
    echo "[build]   PHPX_HOME needs include/phpx.h plus lib/libphpx.a, .so or .dylib."
    echo "[build] Export them, or build a private runtime once with --php-builder"
    echo "[build] (see the header of this script)."
    exit 1
fi

# The compiler emits php::empty_string; a phpx checkout older than that API
# compiles every translation unit and then dies in the link, so fail here with
# the fix instead. composer.lock is not committed, so a stale vendor/ is easy
# to carry over from an unrelated project.
if ! grep -q "empty_string" "$phpx_home/include/phpx.h"; then
    echo "[build] ERROR: ${phpx_home} is too old for this compiler (include/phpx.h lacks php::empty_string)."
    echo "[build] Update it (composer update swoole/phpx) or point PHPX_HOME at a current phpx tree."
    exit 1
fi

export PHP_HOME="$php_home" PHPX_HOME="$phpx_home"
echo "[build] PHP_HOME=${PHP_HOME}"
echo "[build] PHPX_HOME=${PHPX_HOME}"

# keg-only brew formulae are not symlinked into the prefix root.
brew_keg_lib() {
    local dir
    for dir in "/opt/homebrew/opt/$1/lib" "/usr/local/opt/$1/lib"; do
        if [[ -d "$dir" ]]; then
            echo "$dir"
            return
        fi
    done
    echo ""
}

# A private runtime cached from an unrelated project usually carries more
# extensions than this project declares, and libphp.a then references system
# libraries tpc does not know about (-lonig for mbstring, -lresolv for sockets,
# ...). Derived from the archive contents so the list is not hand-maintained.
LINK_FLAGS=()
LIBPHP="${PHP_HOME}/lib/libphp.a"
if [[ -f "$LIBPHP" ]]; then
    OBJECTS=$(ar -t "$LIBPHP" 2>/dev/null || true)
    while IFS='|' read -r probe libs keg; do
        [[ -z "$probe" ]] && continue
        if grep -qE "$probe" <<< "$OBJECTS"; then
            for lib in $libs; do
                LINK_FLAGS+=(-l "$lib")
            done
            if [[ -n "$keg" ]]; then
                LINK_FLAGS+=(-L "$(brew_keg_lib "$keg")")
            fi
        fi
    done <<'PROBES'
^php_mbregex\.o$|onig|oniguruma
^libxml\.o$|xml2|
^sqlite3\.o$|sqlite3|
^zlib\.o$|z|
^sockets\.o$|resolv|
^curl_.*\.o$|curl|
^openssl\.o$|ssl crypto|openssl@3
PROBES
fi
if [[ "$(uname)" == "Darwin" ]]; then
    # PHP's mac runtime references libiconv, and the brew build of it is
    # keg-only, so the library search path has to be given explicitly.
    LINK_FLAGS+=(-l iconv)
    if [[ -n "$(brew_keg_lib libiconv)" ]]; then
        LINK_FLAGS+=(-L "$(brew_keg_lib libiconv)")
    fi
fi

if [[ ${#LINK_FLAGS[@]} -gt 0 ]]; then
    echo "[build] extra link flags: ${LINK_FLAGS[*]}"
fi

# CI runners are dedicated: build with the available logical CPU count.
# User-managed machines stay serial (AGENTS.md).
if [[ "${CI:-}" == "true" || "${GITHUB_ACTION:-}" == "true" ]]; then
    JOBS="${BUILD_JOBS:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)}"
else
    JOBS=1
fi

TPC=(php "${REPO_ROOT}/bin/tpc.php")
if [[ -x "${REPO_ROOT}/tpc" ]]; then
    TPC=("${REPO_ROOT}/tpc")
fi

BUILD_CMD=("${TPC[@]}" project.yml -O2 --job "$JOBS" --build-dir build \
    --no-color --no-progress -o typephp_mcp ${LINK_FLAGS[@]+"${LINK_FLAGS[@]}"})

if [[ "${CI:-}" == "true" || "${GITHUB_ACTION:-}" == "true" ]]; then
    "${BUILD_CMD[@]}"
else
    # --- self-managed machine: resource safety (AGENTS.md) ---
    # Budget for the build tree: 8 GiB or half of the memory available at
    # startup, whichever is smaller.
    limit_mb=8192
    if [[ "$(uname)" == "Linux" && -r /proc/meminfo ]]; then
        avail_kb=$(awk '/MemAvailable/{print $2}' /proc/meminfo 2>/dev/null || true)
        if [[ -n "$avail_kb" && "$avail_kb" -gt 0 ]]; then
            half_mb=$((avail_kb / 2048))
            [[ "$half_mb" -lt "$limit_mb" ]] && limit_mb="$half_mb"
        fi
    elif [[ "$(uname)" == "Darwin" ]]; then
        mem_mb=$(sysctl -n hw.memsize 2>/dev/null | awk '{print int($1/1048576)}' || true)
        if [[ -n "$mem_mb" && "$mem_mb" -gt 0 ]]; then
            half_mb=$((mem_mb / 2))
            [[ "$half_mb" -lt "$limit_mb" ]] && limit_mb="$half_mb"
        fi
    fi

    if [[ "$(uname)" == "Linux" ]]; then
        # Whole-tree budget. ulimit -v caps the address space of EACH
        # process; the compiler, its driver and every descendant each get
        # their own cap, so N processes could reach N * limit together. A
        # real total for the build tree needs a cgroup: this script enforces
        # it by running the build inside a transient systemd user scope
        # (cgroup v2) with a hard MemoryMax and swap forbidden. The probe
        # verifies the scope mechanism works before anything is cleaned or
        # compiled; when it cannot be established the build refuses to run
        # and prints the controlled entries it accepts instead.
        if ! command -v systemd-run >/dev/null 2>&1 \
            || ! systemd-run --user --quiet --collect --scope true 2>/dev/null; then
            {
                echo "[build] ERROR: no whole-build-tree memory limit could be established (no working systemd user scope)."
                echo "[build] A per-process cap is not a substitute: the build tree's TOTAL would be unbounded,"
                echo "[build] so this script refuses to run an unprotected Linux build. Re-run it inside one of:"
                echo "[build]   systemd-run --scope -p MemoryMax=${limit_mb}M -p MemorySwapMax=0 bash tools/typephp-mcp/build.sh"
                echo "[build]   podman run --rm --memory=${limit_mb}m --memory-swap=${limit_mb}m -v \"\$PWD\":/src -w /src <image> \\"
                echo "[build]     bash tools/typephp-mcp/build.sh"
                echo "[build] (dedicated CI is exempt: it builds with the runner's CPU count under its own budget)"
            } >&2
            exit 1
        fi
    fi

    # Second line of defence: a per-process virtual-memory cap, inherited by
    # every descendant, so one runaway compiler cannot take the machine down.
    # This is NOT the whole-tree budget - that is the systemd scope's
    # MemoryMax above (Linux). macOS does not implement ulimit -v, so a
    # rejection there is reported and the build continues: refusing outright
    # would make the script unusable on that platform while protecting
    # nothing. Anywhere else a rejection is fatal.
    if ! ulimit -v "$((limit_mb * 1024))" 2>/dev/null; then
        if [[ "$(uname)" == "Darwin" ]]; then
            echo "[build] note: macOS has no ulimit -v; continuing without a per-process memory cap"
        else
            echo "[build] ERROR: cannot apply the per-process memory cap (${limit_mb} MiB)" >&2
            exit 1
        fi
    else
        echo "[build] per-process memory cap: ${limit_mb} MiB virtual, jobs: ${JOBS}"
    fi

    if [[ "$(uname)" == "Linux" ]]; then
        if ! command -v flock >/dev/null 2>&1; then
            echo "[build] ERROR: flock is required for the shared build lock but was not found" >&2
            exit 1
        fi
        lock_file="${XDG_RUNTIME_DIR:-/tmp}/codex-build-$(id -u).lock"
        echo "[build] acquiring build lock: ${lock_file}"
        # Hold the lock on this shell's own descriptor instead of wrapping
        # the build command: `flock -n <file> <cmd>` would only cover
        # BUILD_CMD, and the clean below would run OUTSIDE the critical
        # section - a second invocation that failed to get the lock had
        # already deleted the directory the first build was writing into.
        exec 9>"$lock_file"
        if ! flock -n 9; then
            echo "[build] ERROR: another build holds ${lock_file}; wait for it to finish" >&2
            exit 1
        fi
    fi
fi

# Clean the previous build tree. Guarded so a failed cd (script dir gone,
# BASH_SOURCE oddity) can never turn this into `rm -rf` of the caller's cwd.
# On a self-managed Linux machine this runs INSIDE the build lock (and the
# whole build tree stays inside it, since every child inherits descriptor 9).
[ -n "$SCRIPT_DIR" ] && [ -d "$SCRIPT_DIR" ] || { echo "[build] ERROR: script directory not found" >&2; exit 1; }
rm -rf "${SCRIPT_DIR:?}/build"

if [[ "$(uname)" == "Linux" && "${CI:-}" != "true" && "${GITHUB_ACTION:-}" != "true" ]]; then
    # Compile inside the systemd user scope probed above: MemoryMax bounds
    # the total of this build tree, MemorySwapMax=0 forbids swap. The build
    # lock (descriptor 9) is inherited and stays held while tpc runs.
    echo "[build] whole-tree budget: systemd scope MemoryMax=${limit_mb}M, MemorySwapMax=0"
    systemd-run --user --quiet --collect --scope \
        -p "MemoryMax=${limit_mb}M" -p "MemorySwapMax=0" \
        "${BUILD_CMD[@]}"
else
    "${BUILD_CMD[@]}"
fi

BINARY="${SCRIPT_DIR}/typephp_mcp"
if [[ ! -x "$BINARY" ]]; then
    echo "[build] ERROR: typephp_mcp was not produced."
    exit 1
fi
file "$BINARY"
if [[ "$(uname)" == "Darwin" ]]; then
    otool -L "$BINARY" | sed -n '2,20p'
fi
echo "[build] Done: $BINARY"
