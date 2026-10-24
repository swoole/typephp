# Working agreements

## Build concurrency in CI

The user explicitly requires dedicated CI runners to build with their available
logical CPU count. Detect that count on each runner and pass it to TypePHP,
CMake, Make/Ninja and Cargo. Do not hard-code CI builds to one or two jobs.
Use the shared `configure-build-jobs` action and propagate its job settings to
test harnesses. When PHPT workers already run concurrently, keep each worker's
nested compiler at one job so concurrency does not multiply.

This CI policy does not authorize parallel builds on the user's machines.

## Resource safety on user-managed machines

A previous build exhausted this machine's resources and caused a crash and
reboot. Stability takes priority over build speed. These rules cover all
projects, agent sessions, subagents, test scripts and child processes launched
on user-managed machines. Do not relax them without explicit user authorization.

- Run at most one compilation or link task globally. Multiple simultaneous
  `make -j1` commands still violate this limit. Run PHP versions, SAPIs, build
  modes, test batches and bootstrap stages sequentially. Do not bypass the
  limit with background jobs, parallel tool calls, process pools, subagents or
  separate sessions.
- Use `make -j1`, `ninja -j1`, `cmake --build <directory> --parallel 1`, and
  TypePHP `--job 1`. GCC/Clang compilation and linking must remain serial.
  Never use bare `-j`, CPU-based automatic concurrency, or a jobs value above one.
- Explicitly set `CMAKE_BUILD_PARALLEL_LEVEL=1`, `PHP_COMPILER_JOBS=1`, and
  `CARGO_BUILD_JOBS=1`. Inspect inherited `MAKEFLAGS`, `MFLAGS` and tool-specific
  options and remove conflicts, including in recursive builds. GNU Make uses
  `-j1`; NMake rejects that option, so clear GNU Make flags and keep NMake serial
  with MSVC `/MP1`. Environment variables alone are insufficient: verify that
  the actual build commands obey these limits.
- Before starting, inspect existing compiler/linker processes, available memory,
  swap and system load. Do not start another build while one is active. Never
  terminate processes belonging to another task.
- On Linux use the same user-wide lock for every build entry point:
  `${XDG_RUNTIME_DIR:-/tmp}/codex-build-$(id -u).lock`. Wrap the complete build
  process tree with `flock -n <lock-file> <command> <arguments>`. If acquisition
  fails, stop that attempt; do not rename or delete the lock, or build outside
  it. Acquire it only at the outermost level and let children inherit it.
  Also check for external builds that do not use this coordination lock.
- Before a large C++ translation unit, link, whole-project build or bootstrap,
  impose a hard memory limit covering the entire build process tree, preferably
  through cgroups or a systemd user scope. The default limit must not exceed
  8 GiB or half the effective memory available at startup. Do not permit
  unlimited swap use. If a whole-tree limit cannot be enforced, explain the
  concrete obstacle before starting a high-memory build. Per-process limits
  are not a substitute for a process-tree total.
- Monitor memory and process counts during compilation. Stop this task's build
  tree and preserve logs if unexpected concurrency, sustained pressure near
  the limit, significant paging or poor system responsiveness appears. Do not
  hide failures by raising memory limits, increasing concurrency or retrying.
- After OOM, a crash, reboot or lost execution handle, do not resume builds
  automatically. Inspect surviving processes, logs and artifact integrity,
  and confirm that resource protections work before proceeding. Interrupted
  builds and tests must never be recorded as passing.
- Prefer affected targets and necessary tests. Accumulate changes before full
  suites, bootstrap builds and bootstrap tests; do not repeat them for small
  edits or run them concurrently.

These are behavioral requirements, not OS resource controls. The agent running
a build must enforce them in the actual command and process tree. Explicit
session-specific exceptions from the user take precedence; do not infer new
exceptions from them.
