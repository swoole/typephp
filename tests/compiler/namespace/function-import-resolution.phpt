--TEST--
Function imports are case-insensitive and take precedence over global functions
--FILE--
<?php
namespace { function route(): string { return 'global'; } }
namespace AliasLibrary { function route(): string { return 'import'; } }
namespace AliasConsumer {
    use function AliasLibrary\route as ROUTE;
    use function AliasLibrary\route as GET_CALLED_CLASS;
    use function strlen as SIZE;
    use function AliasLibrary\route as extract;
    use function AliasLibrary\{route as GROUPED};
    function exercise(): void {
        echo route(), ':', RoUtE(), ':', get_called_class(), ':', sIzE('abc'), ':', grouped(), ':', extract(), ':', \route(), "\n";
    }
}
namespace { function main(): void { \AliasConsumer\exercise(); } }
--EXPECT--
import:import:import:3:import:import:global
