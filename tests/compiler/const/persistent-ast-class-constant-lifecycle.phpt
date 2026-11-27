--TEST--
Persistent array and enum-case class constants survive request initialization and shutdown
--FILE--
<?php
enum AstLifecycleCase
{
    case Ready;
}

interface AstLifecycleContract
{
    public const ITEMS = ['contract'];
}

class AstLifecycleBase
{
    public const DATA = ['nested' => [1, 2]];
    public const STATE = AstLifecycleCase::Ready;
}

final class AstLifecycleChild extends AstLifecycleBase implements AstLifecycleContract
{
    public const OWN = ['child'];
}

final class AstLifecycleLeaf extends AstLifecycleBase
{
}

function main(): void
{
    var_dump(AstLifecycleBase::DATA === ['nested' => [1, 2]]);
    var_dump(constant('AstLifecycleChild::DATA') === AstLifecycleBase::DATA);
    var_dump(constant('AstLifecycleChild::ITEMS') === ['contract']);
    var_dump((new ReflectionClassConstant(AstLifecycleChild::class, 'OWN'))->getValue() === ['child']);
    var_dump(constant('AstLifecycleBase::STATE') === AstLifecycleCase::Ready);
    var_dump(constant('AstLifecycleLeaf::DATA') === AstLifecycleBase::DATA);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
