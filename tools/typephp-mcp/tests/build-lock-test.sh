#!/usr/bin/env bash
# Regression test for build.sh's build-lock critical section (Linux only).
#
# Review scenario: build.sh cleaned the build directory BEFORE acquiring the
# shared user-wide build lock, so a second invocation that failed to get the
# lock had already deleted the sources, objects and cache the first build
# was still using. The script must
#   1. refuse to build - keeping build/ intact and never invoking the
#      compiler - while another build holds the lock, and
#   2. as a control, still clean build/ and start the compiler when the
#      lock is free.
#
# The test runs a sandbox copy of build.sh with fake runtimes (PHP_HOME /
# PHPX_HOME only need to pass the environment checks) and a php stub that
# records every invocation. systemd-run is stubbed as well so the whole-tree
# scope path behaves deterministically on machines without a systemd user
# session; the stub execs its command unchanged, which is exactly the
# delegation under test here (the scope must not change how the compiler is
# reached, only wrap it).
#
# Usage: bash tools/typephp-mcp/tests/build-lock-test.sh
set -uo pipefail

if [[ "$(uname)" != "Linux" ]]; then
    echo "[build-lock-test] skipped: the shared build lock is a Linux-only path"
    exit 0
fi

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
BUILD_SH="$SCRIPT_DIR/../build.sh"
[[ -f "$BUILD_SH" ]] || { echo "[build-lock-test] FAIL: $BUILD_SH not found" >&2; exit 1; }

work=$(mktemp -d)
cleanup() { rm -rf "$work"; }
trap cleanup EXIT

fail() { echo "[build-lock-test] FAIL: $*" >&2; exit 1; }

# Isolate the lock file: build.sh derives it from XDG_RUNTIME_DIR.
export XDG_RUNTIME_DIR="$work/xdg"
mkdir -p "$XDG_RUNTIME_DIR"
lock_file="$XDG_RUNTIME_DIR/codex-build-$(id -u).lock"

# Fake runtimes that pass build.sh's environment checks.
php_home="$work/php-home"
phpx_home="$work/phpx-home"
mkdir -p "$php_home/lib" "$phpx_home/include"
: > "$php_home/lib/libphp.a"
printf 'php::empty_string;\n' > "$phpx_home/include/phpx.h"

# Stubs on PATH: php records that the compiler was invoked (and fails, so a
# sandbox build can never produce a real binary); systemd-run execs its
# command, standing in for `--scope`.
mkdir "$work/bin"
cat > "$work/bin/php" <<PHP
#!/usr/bin/env bash
echo "\$*" >> "$work/php.invocations"
exit 97
PHP
cat > "$work/bin/systemd-run" <<'STUB'
#!/usr/bin/env bash
# Drop the scope options and exec the command: the same contract
# `systemd-run --user --scope` provides for the build tree.
args=()
while [[ $# -gt 0 ]]; do
    arg=$1
    shift
    case "$arg" in
        --user|--quiet|--collect|--scope) ;;
        -p|--property) shift ;;
        --property=*) ;;
        *) args+=("$arg") ;;
    esac
done
exec "${args[@]}"
STUB
chmod +x "$work/bin/php" "$work/bin/systemd-run"
export PATH="$work/bin:$PATH"

# Sandbox copy of build.sh: its rm -rf build must hit the sandbox, not the
# checkout.
cp "$BUILD_SH" "$work/build.sh"

run_build() {
    # Unset CI so the self-managed path (lock + scope) is exercised.
    env -u CI -u GITHUB_ACTION PHP_HOME="$php_home" PHPX_HOME="$phpx_home" \
        bash "$work/build.sh"
}

# --- 1. Lock held by another build: refuse, keep build/, never compile. ----
mkdir -p "$work/build"
echo sentinel > "$work/build/sentinel"

flock "$lock_file" -c "touch '$work/holder.ready'; while [ ! -f '$work/holder.release' ]; do sleep 0.05; done" &
holder=$!
for _ in $(seq 1 100); do
    [ -f "$work/holder.ready" ] && break
    sleep 0.05
done
[ -f "$work/holder.ready" ] || fail "the lock holder never acquired $lock_file"

run_build > "$work/attempt.log" 2>&1
attempt_code=$?

touch "$work/holder.release"
wait "$holder"

[[ $attempt_code -ne 0 ]] || fail "build.sh exited 0 while another build held the lock"
grep -q "another build holds" "$work/attempt.log" \
    || fail "build.sh did not fail at the lock step (log: $(tail -n 3 "$work/attempt.log" | tr '\n' ' '))"
[[ -f "$work/build/sentinel" ]] || fail "build.sh deleted build/ without owning the lock"
[[ ! -f "$work/php.invocations" ]] || fail "the compiler was invoked although the lock was held: $(cat "$work/php.invocations")"

# --- 2. Control: lock free -> clean runs and the compiler is reached. ------
echo sentinel2 > "$work/build/sentinel2"
run_build > "$work/control.log" 2>&1

grep -q "project.yml" "$work/php.invocations" 2>/dev/null \
    || fail "the control run never reached the compiler (log: $(tail -n 3 "$work/control.log" | tr '\n' ' '))"
[[ ! -f "$work/build/sentinel2" ]] || fail "the clean step did not run in the control run"

echo "[build-lock-test] ok"
