# Typed property method calls

This benchmark calls a final class method through a declared object property
1,000,000 times, returning checksum `7000000`. It isolates receiver/property and
method-call overhead; it is not a whole-application performance estimate.

Build from the repository root with a matching PHPX runtime:

```sh
php bin/tpc.php benchmark/property-method-call/project.yml --no-progress \
  --build-dir /tmp/typephp-property-method-build -o /tmp/property-method-call
/tmp/property-method-call
```

For a before/after comparison, use the same PHPX, compiler flags and workload in
both checkouts, separate build directories, and alternate the two binaries after
a warmup. Verify equal output before comparing elapsed time.

An initial Linux ARM64/PHP 8.5.10 ZTS/PHPX `4b3a472` O2 comparison against TypePHP
`a1782233` measured five-run median process times of 29.97 ms before and 22.63 ms
after (24.5% lower elapsed time). Timings include process startup. The normal
22-command dungeon scenario improved only about 2.4%, and its exception-heavy
28-command scenario showed no reliable improvement. These results demonstrate a
local call-path improvement, not a claim that all applications become 24.5% faster.
