--TEST--
ASan canary must fail even with a permissive expectation and XFAIL
--AOT_ARGS--
--sanitize undefined
--ENV--
ASAN_OPTIONS=halt_on_error=0:detect_leaks=1
USE_ZEND_ALLOC=1
--XFAIL--
This section must not hide sanitizer reports
--FILE--
<?php
function main(): void {}
?>
--EXPECTF--
%a
