--TEST--
__FUNCTION__ and __METHOD__ preserve function and method names
--FILE--
<?php
namespace MagicFunctionMethod {
    class Example {
        public function instanceMethod(): void {
            echo __FUNCTION__, "\n", __METHOD__, "\n";
        }

        public static function staticMethod(): void {
            echo __FUNCTION__, "\n", __METHOD__, "\n";
        }
    }

    function namespacedFunction(): void {
        echo __FUNCTION__, "\n", __METHOD__, "\n";
    }
}

namespace {
    function globalFunction(): void {
        echo __FUNCTION__, "\n", __METHOD__, "\n";
    }

    function main(): void {
        (new \MagicFunctionMethod\Example())->instanceMethod();
        \MagicFunctionMethod\Example::staticMethod();
        \MagicFunctionMethod\namespacedFunction();
        globalFunction();
        echo __METHOD__, "\n";
    }
}
?>
--EXPECT--
instanceMethod
MagicFunctionMethod\Example::instanceMethod
staticMethod
MagicFunctionMethod\Example::staticMethod
MagicFunctionMethod\namespacedFunction
MagicFunctionMethod\namespacedFunction
globalFunction
globalFunction
main
