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
# tpc's php-builder left under ~/.typephp/php-builder. When neither is available,
# build one (compiles php-src plus a static phpx; tpc only offers php-builder
# interactively, so the flag has to be explicit in a scripted shell):
#
#   php bin/tpc.php tools/typephp-mcp/project.yml --php-builder='extensions: []'
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
cd "$SCRIPT_DIR"
REPO_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)

php_home=${PHP_HOME:-}
phpx_home=${PHPX_HOME:-}

if [[ -z "$php_home" ]]; then
    for candidate in $(ls -1d "${HOME}"/.typephp/php-builder/php-*/install 2>/dev/null | sort -r); do
        if [[ -f "$candidate/lib/libphp.a" || -f "$candidate/lib/libphp.dylib" ]]; then
            php_home=$candidate
            break
        fi
    done
fi

if [[ -z "$phpx_home" ]]; then
    for candidate in "${REPO_ROOT}/vendor/swoole/phpx" "${php_home}/phpx" "$php_home"; do
        if [[ -f "$candidate/include/phpx.h" ]] \
            && { [[ -f "$candidate/lib/libphpx.a" ]] || [[ -f "$candidate/lib/libphpx.dylib" ]]; }; then
            phpx_home=$candidate
            break
        fi
    done
fi

if [[ -z "$php_home" || -z "$phpx_home" ]]; then
    echo "[build] ERROR: no usable PHP/PHPX runtime found."
    echo "[build]   PHP_HOME needs lib/libphp.a (or .dylib);"
    echo "[build]   PHPX_HOME needs include/phpx.h plus lib/libphpx.a (or .dylib)."
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

rm -rf build
echo "[build] Compiling typephp-mcp"
php "${REPO_ROOT}/bin/tpc.php" project.yml -O2 --job 1 --build-dir build \
    --no-color --no-progress -o typephp_mcp ${LINK_FLAGS[@]+"${LINK_FLAGS[@]}"}

BINARY="${SCRIPT_DIR}/typephp_mcp"
if [[ ! -x "$BINARY" ]]; then
    echo "[build] ERROR: typephp_mcp was not produced."
    exit 1
fi
file "$BINARY"
otool -L "$BINARY" | sed -n '2,20p'
echo "[build] Done: $BINARY"
