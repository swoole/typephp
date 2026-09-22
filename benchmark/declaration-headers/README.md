# Declaration header generation benchmark

Creates 300 PHP source files with 6,001 functions, each ordinary function
having a default parameter. It prepares and converts the project, then measures
six calls to the real declaration-header generator and discards the first as
warm-up. The timed region includes rendering and writing headers; source
preparation, conversion, and C++ compilation are excluded. This measures one
compiler phase, not total build time.

Run from a checkout with Composer development dependencies installed:

```sh
project_dir=$(mktemp -d)
php benchmark/declaration-headers/run.php "$project_dir" > /tmp/declaration-candidate.log
tail -1 /tmp/declaration-candidate.log
```

The script writes generated sources and build outputs into the supplied
benchmark directory. Reuse the same directory for comparisons: absolute source
paths contribute to header names and therefore the combined content digest.

The baseline does not contain this new benchmark. From the candidate checkout,
these commands create a separate baseline and run the same source in both. The
shared vendor directory is supported by `phpunit/bootstrap.php`'s checkout-local
source loader.

```sh
baseline_dir=$(mktemp -d)
project_dir=$(mktemp -d)
git archive b3898c32 | tar -x -C "$baseline_dir"
ln -s "$(pwd)/vendor" "$baseline_dir/vendor"
mkdir -p "$baseline_dir/benchmark/declaration-headers"
cp benchmark/declaration-headers/run.php "$baseline_dir/benchmark/declaration-headers/run.php"
php "$baseline_dir/benchmark/declaration-headers/run.php" "$project_dir" > /tmp/declaration-baseline.log
php benchmark/declaration-headers/run.php "$project_dir" > /tmp/declaration-candidate.log
tail -1 /tmp/declaration-baseline.log
tail -1 /tmp/declaration-candidate.log
```

Compare median `ms` values and require identical `headers` counts and `sha256`
digests. The digest covers the contents and names of every generated declaration
header, including runtime and aggregate headers.

## Sample result

Linux ARM64 Docker, PHP 8.5.10 ZTS, CLI opcache disabled; baseline `b3898c32`:

| Revision | Median (ms) | Headers |
| --- | ---: | ---: |
| Baseline | 88.001 | 302 |
| Grouped functions | 13.087 | 302 |

The measured phase is about 6.72x faster, with identical output digests. Raw
samples are in `results-arm64.json`. This workload isolates function-heavy
projects; class and constant declaration scans are not optimized by this change.
