--TEST--
Function imports are case-insensitive and take precedence over global functions
--FILE--
<?php
namespace {
    function route(): string { return 'global'; }
    function size(string $value): int { return 99; }
}
namespace AliasLibrary {
    function route(): string { return 'import'; }
    function callback_target(): string { return 'import-callback'; }
}
namespace AliasConsumer {
    use function AliasLibrary\route as ROUTE;
    use function AliasLibrary\route as GET_CALLED_CLASS;
    use function strlen as SIZE;
    use function AliasLibrary\route as extract;
    use function AliasLibrary\{route as GROUPED};
    use function AliasLibrary\callback_target as CALLBACK_TARGET;

    function exercise(): void {
        echo route(), ':', RoUtE(), ':', get_called_class(), ':', sIzE('abc'), ':', grouped(), ':', extract(), ':', \route(), "\n";
        $size = sIzE(...);
        $callback = cAlLbAcK_tArGeT(...);
        $route = rOuTe(...);
        $dynamic = $size;
        echo $size('abcd'), ':', $callback(), ':', $route(), ':', $dynamic('abc'), "\n";
    }
}
namespace { function main(): void { \AliasConsumer\exercise(); } }
?>
--EXPECT--
import:import:import:3:import:import:global
4:import-callback:import:3
