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
get_called_class() must be called from within a class
