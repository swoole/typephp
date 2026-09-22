--TEST--
Closure and arrow function magic names preserve their lexical context
--FILE--
<?php
namespace MagicClosureNames {
    function outer(): void {
        $closure = function (): void {
            echo __FUNCTION__, "\n", __METHOD__, "\n";
            static $name = __METHOD__;
            echo $name, "\n";
            $nested = fn(): string => __METHOD__;
            echo $nested(), "\n";
        };
        $closure();
        $arrow = fn(): string => __METHOD__;
        echo $arrow(), "\n";
        echo __METHOD__, "\n";
    }

    function dynamicClosure(): \Closure {
        return function (): string { return __METHOD__; };
    }

    function dynamicArrow(): \Closure {
        return fn(): string => __FUNCTION__;
    }

    function generatorClosure(): void {
        $generator = function () {
            yield __METHOD__;
            $nested = fn(): string => __METHOD__;
            yield $nested();
        };
        foreach ($generator() as $name) {
            echo $name, "\n";
        }
        echo __METHOD__, "\n";
    }

    trait Names {
        public function original(): void {
            $closure = fn(): string => __METHOD__;
            echo $closure(), "\n";
        }
    }

    class UsesNames {
        use Names { original as renamed; }
    }

    class Example {
        public static function method(): void {
            $closure = function (): string { return __METHOD__; };
            echo $closure(), "\n";
            $arrow = fn(): string => __FUNCTION__;
            echo $arrow(), "\n";
            echo __METHOD__, "\n";
        }
    }
}
namespace {
    function globalOuter(): void {
        $closure = function (): string { return __METHOD__; };
        echo $closure(), "\n";
        $arrow = fn(): string => __METHOD__;
        echo $arrow(), "\n";
    }
    function main(): void {
        \MagicClosureNames\outer();
        \MagicClosureNames\Example::method();
        globalOuter();
        (new \MagicClosureNames\UsesNames())->renamed();
        $closure = \MagicClosureNames\dynamicClosure();
        echo $closure(), "\n";
        $arrow = \MagicClosureNames\dynamicArrow();
        echo $arrow(), "\n";
        \MagicClosureNames\generatorClosure();
    }
}
?>
--EXPECT--
{closure:MagicClosureNames\outer():4}
{closure:MagicClosureNames\outer():4}
{closure:MagicClosureNames\outer():4}
{closure:{closure:MagicClosureNames\outer():4}:8}
{closure:MagicClosureNames\outer():12}
MagicClosureNames\outer
{closure:MagicClosureNames\Example::method():50}
{closure:MagicClosureNames\Example::method():52}
MagicClosureNames\Example::method
{closure:globalOuter():60}
{closure:globalOuter():62}
{closure:MagicClosureNames\Names::original():39}
{closure:MagicClosureNames\dynamicClosure():18}
{closure:MagicClosureNames\dynamicArrow():22}
{closure:MagicClosureNames\generatorClosure():26}
{closure:{closure:MagicClosureNames\generatorClosure():26}:28}
MagicClosureNames\generatorClosure
