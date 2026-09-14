--TEST--
get_called_class preserves runtime scope across direct instance and static calls
--FILE--
<?php
namespace CalledShadow {
    function get_called_class(): string { return 'shadow'; }
    class Probe {
        public function name(): string { return get_called_class(); }
    }
}
namespace CalledAliasTarget {
    function get_called_class(): string { return 'alias'; }
}
namespace CalledAlias {
    use function CalledAliasTarget\get_called_class as CALLED_ALIAS;
    class Probe {
        public function name(): string { return cAlLeD_aLiAs(); }
    }
}
namespace CalledDynamicShadow {
    class Probe {
        public function name(): string { return get_called_class(); }
    }
}
namespace CalledDynamicFrozen {
    class Probe {
        public function name(): string { return get_called_class(); }
    }
    class Child extends Probe {}
}
namespace {
    use function get_called_class as calledName;

    trait CalledNameTrait {
        public function traitName(): string { return get_called_class(); }
    }
    class CalledScopeBase {
        use CalledNameTrait;
        final public function name(): string { return get_called_class(); }
        public static function staticName(): string { return \GET_CALLED_CLASS(); }
        public function aliasName(): string { return calledName(); }
        public function closureName(): string {
            $name = function (): string { return get_called_class(); };
            return $name();
        }
    }
    class CalledScopeChild extends CalledScopeBase {}
    function printCalledNames(CalledScopeBase $object): void {
        echo $object->name(), ':', $object->traitName(), ':',
            $object->aliasName(), ':', $object->closureName(), "\n";
    }
    function main(): void {
        printCalledNames(new CalledScopeBase());
        printCalledNames(new CalledScopeChild());
        echo CalledScopeBase::staticName(), ':', CalledScopeChild::staticName(), "\n";
        eval('class RuntimeCalledScope extends CalledScopeBase {}');
        $runtime = eval('return new RuntimeCalledScope();');
        printCalledNames($runtime);
        echo (new \CalledShadow\Probe())->name(), "\n";
        echo (new \CalledAlias\Probe())->name(), "\n";
        eval('namespace CalledDynamicShadow { function get_called_class(): string { return "shadow-first"; } }');
        echo (new \CalledDynamicShadow\Probe())->name(), "\n";
        echo (new \CalledDynamicFrozen\Probe())->name(), "\n";
        eval('namespace CalledDynamicFrozen { function get_called_class(): string { return "shadow-late"; } }');
        echo (new \CalledDynamicFrozen\Child())->name(), "\n";
        try {
            get_called_class();
        } catch (\Error $error) {
            echo $error->getMessage(), "\n";
        }
    }
}
?>
--EXPECT--
CalledScopeBase:CalledScopeBase:CalledScopeBase:CalledScopeBase
CalledScopeChild:CalledScopeChild:CalledScopeChild:CalledScopeChild
CalledScopeBase:CalledScopeChild
RuntimeCalledScope:RuntimeCalledScope:RuntimeCalledScope:RuntimeCalledScope
shadow
alias
shadow-first
CalledDynamicFrozen\Probe
CalledDynamicFrozen\Child
get_called_class() must be called from within a class
