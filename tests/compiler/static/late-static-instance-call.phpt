--TEST--
Late static calls from instance methods retain the receiver on inherited and overridden methods
--FILE--
<?php
class InstanceCallBase
{
    public string $value = 'base-value';

    protected function helper(string $suffix = ''): string
    {
        return $this->value . $suffix;
    }

    protected static function label(): string
    {
        return static::class;
    }

    public function fixed(): string
    {
        return static::helper();
    }

    public function withArgs(): string
    {
        return static::helper('-arg');
    }

    public function dynamic(): string
    {
        $name = 'helper';
        return static::{$name}('-dynamic');
    }

    public function staticFixed(): string
    {
        return static::label();
    }

    public function staticDynamic(): string
    {
        $name = 'label';
        return static::{$name}();
    }
}

class InstanceCallInherited extends InstanceCallBase
{
}

class InstanceCallOverride extends InstanceCallBase
{
    protected function helper(string $suffix = ''): string
    {
        return 'override-' . $this->value . $suffix;
    }

    protected static function label(): string
    {
        return 'override:' . static::class;
    }
}

function main(): void
{
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    foreach ([new InstanceCallBase(), new InstanceCallInherited(), new InstanceCallOverride()] as $item) {
        echo $item->fixed(), "\n";
        echo $item->withArgs(), "\n";
        echo $item->dynamic(), "\n";
        echo $item->staticFixed(), "\n";
        echo $item->staticDynamic(), "\n";
    }
    restore_error_handler();
}
?>
--EXPECT--
base-value
base-value-arg
base-value-dynamic
InstanceCallBase
InstanceCallBase
base-value
base-value-arg
base-value-dynamic
InstanceCallInherited
InstanceCallInherited
override-base-value
override-base-value-arg
override-base-value-dynamic
override:InstanceCallOverride
override:InstanceCallOverride
