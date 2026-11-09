#!/usr/bin/env bash
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != true || "$(uname)" != Linux ]]; then
    echo 'This build entry is only for dedicated Linux GitHub Actions runners.' >&2
    exit 1
fi
[[ "${BUILD_JOBS:-}" =~ ^[1-9][0-9]*$ ]]
repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$repo_root"
work="$repo_root/build/asan-php"
mkdir -p "$work/source" "$work/objects"
show_failure_logs() {
    local status=$?
    local log
    for log in "$work/configure.log" "$work/make.log" "$work/install.log"; do
        if [[ -f "$log" ]]; then
            echo "ASan runtime build diagnostic: $log" >&2
            tail -n 60 "$log" >&2
        fi
    done
    exit "$status"
}
trap show_failure_logs ERR
php -n -r 'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,"extensions"=>get_loaded_extensions()]);' > "$work/host.json"
php -r 'echo json_encode(get_loaded_extensions());' > "$work/host-loaded.json"
php-config --configure-options > "$work/host-configure.txt"
php -r 'echo php_ini_loaded_file() ?: "";' > "$work/host-ini-path"
php -r '
    $contents = php_ini_loaded_file() ? file_get_contents(php_ini_loaded_file()) : "";
    $contents .= "\nextension_dir=\"" . addcslashes(ini_get("extension_dir"), "\\\"") . "\"\n";
    file_put_contents($argv[1], $contents);
' "$work/runtime.ini"
php .github/scripts/prepare-asan-php.php "$work"
source_root=$(cat "$work/source-path")
cp -a "$source_root/." "$work/source/"
python3 - "$work/source/Zend/zend_portability.h" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
contents = path.read_text()
count = contents.count(' | RTLD_DEEPBIND')
path.write_text(contents.replace(' | RTLD_DEEPBIND', ''))
print(f'Removed {count} RTLD_DEEPBIND loader flag(s); ASan interception stays enabled.')
PY
mapfile -d '' -t configure_args < "$work/configure-args"
(
    cd "$work/objects"
    "$work/source/configure" "${configure_args[@]}" > "$work/configure.log" 2>&1
    make -j"${BUILD_JOBS}" > "$work/make.log" 2>&1
    make -j"${BUILD_JOBS}" install > "$work/install.log" 2>&1
)
test -f "$work/install/lib/libphp.so"
"$work/install/bin/php" -n -r 'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,"extensions"=>get_loaded_extensions()]);' > "$work/private.json"
PHPRC="$work/runtime.ini" "$work/install/bin/php" -r 'echo json_encode(get_loaded_extensions());' > "$work/private-loaded.json"
python3 - "$work" <<'PY'
import json
from pathlib import Path
import sys

root = Path(sys.argv[1])
host = json.loads((root / 'host.json').read_text())
private = json.loads((root / 'private.json').read_text())
for key in ('version', 'zts', 'debug'):
    assert host[key] == private[key], (key, host, private)
assert sorted(host['extensions']) == sorted(private['extensions']), (host, private)
loaded_host = json.loads((root / 'host-loaded.json').read_text())
loaded_private = json.loads((root / 'private-loaded.json').read_text())
assert sorted(loaded_host) == sorted(loaded_private), (loaded_host, loaded_private)
print('Private runtime matches the host version, ZTS/debug mode, built-in and loaded extensions.')
PY
echo "TYPEPHP_ASAN_PHP_LIB_DIR=$work/install/lib" >> "$GITHUB_ENV"
echo "PHPRC=$work/runtime.ini" >> "$GITHUB_ENV"
# Keep PHP_HOME and the SDK unchanged. Only libphp.so at runtime is replaced
# by the same-version build, with identical built-in modules and no DEEPBIND.
