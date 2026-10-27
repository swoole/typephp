#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$repo_root"
mkdir -p build/asan-canary
cp .github/integration/sanitizer/overflow.phpt build/asan-canary/overflow.phpt
chmod +x .github/integration/sanitizer/compiler.php
set +e
REPORT_EXIT_STATUS=1 NO_INTERACTION=1 php run-tests.php -q -j1 --sanitize address \
    --compiler .github/integration/sanitizer/compiler.php \
    -w build/asan-canary/failed.txt build/asan-canary/overflow.phpt \
    > build/asan-canary/harness.log 2>&1
status=$?
set -e
cat build/asan-canary/harness.log
test "$status" -eq 1
grep -q overflow.phpt build/asan-canary/failed.txt
grep -q 'AddressSanitizer: heap-buffer-overflow' build/asan-canary/overflow.log.sanitizer-*
echo 'ASan canary: the instrumented buffer overflow failed PHPT as expected.'
