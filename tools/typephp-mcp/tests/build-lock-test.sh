#!/usr/bin/env bash
# Regression test for build.sh's build lock, clean-then-build order and the
# single-build contract on CI.
#
# Review scenarios:
#   1. build.sh cleaned build/ BEFORE acquiring the shared user-wide build
#      lock, so a second invocation that failed to get the lock had already
#      deleted the sources, objects and cache the first build was still
#      using. It must refuse to build - keeping build/ intact and never
#      invoking the compiler - while another build holds the lock.
#   2. Control: with the lock free it cleans build/ and starts the compiler.
#   3. On CI (CI=true) the script used to run the compiler BEFORE the clean
#      and then again after it, so every Unix CI job built twice and threw
#      the first results away. It must build exactly once, after the clean.
#
# Scenario 3 runs on every platform; scenarios 1-2 exercise the shared build
# lock, which is a Linux-only path.
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
# Records that the compiler was reached. A leftover sentinel means the clean
# did not run before the build started.
if [ -f "$work/build/sentinel" ]; then
    echo "STALE-SENTINEL \$*" >> "$work/php.invocations"
fi
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

# --- 3. CI=true: one build only, and it happens after the clean. -----------
# Runs on every platform: the CI path needs no lock or memory scope, so it is
# also the only scenario a non-Linux checkout can verify here.
mkdir -p "$work/build"
echo stale > "$work/build/sentinel"
rm -f "$work/php.invocations"

env CI=true PHP_HOME="$php_home" PHPX_HOME="$phpx_home" \
    bash "$work/build.sh" > "$work/ci.log" 2>&1

invocations=0
if [[ -f "$work/php.invocations" ]]; then
    invocations=$(grep -c . "$work/php.invocations")
fi
[[ "$invocations" -eq 1 ]] \
    || fail "CI ran the compiler $invocations time(s), expected exactly 1 (log: $(tail -n 3 "$work/ci.log" | tr '\n' ' '))"
grep -q "STALE-SENTINEL" "$work/php.invocations" \
    && fail "CI invoked the compiler before the clean (sentinel still present)"
grep -q "project.yml" "$work/php.invocations" \
    || fail "the CI build never reached the compiler (log: $(tail -n 3 "$work/ci.log" | tr '\n' ' '))"
[[ ! -f "$work/build/sentinel" ]] || fail "CI did not clean build/ before compiling"

if [[ "$(uname)" != "Linux" ]]; then
    echo "[build-lock-test] ok (lock scenarios skipped: the shared build lock is a Linux-only path)"
    exit 0
fi

# --- 1. Lock held by another build: refuse, keep build/, never compile. ----
mkdir -p "$work/build"
echo sentinel > "$work/build/sentinel"
rm -f "$work/php.invocations"

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
