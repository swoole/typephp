--TEST--
Late static calls distinguish parent and child private methods in an instance context
--FILE--
<?php
class LatePrivateBase
{
    private function value(): string
    {
        return 'base';
    }

    public function viaThis(): string
    {
        return $this->value();
    }

    public function viaStatic(): string
    {
        return static::value();
    }

    public function viaDynamic(): string
    {
        $name = 'value';
        return static::{$name}();
    }
}

class LatePrivateInherited extends LatePrivateBase
{
}

class LatePrivateRedeclared extends LatePrivateBase
{
    private function value(): string
    {
        return 'child';
    }
}

function main(): void
{
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    $inherited = new LatePrivateInherited();
    echo $inherited->viaThis(), "\n";
    echo $inherited->viaStatic(), "\n";
    echo $inherited->viaDynamic(), "\n";

    $redeclared = new LatePrivateRedeclared();
    echo $redeclared->viaThis(), "\n";
    try {
        echo $redeclared->viaStatic(), "\n";
    } catch (Error $error) {
        echo get_class($error), "\n";
    }
    try {
        echo $redeclared->viaDynamic(), "\n";
    } catch (Error $error) {
        echo get_class($error), "\n";
    }
    restore_error_handler();
}
?>
--EXPECT--
base
base
base
base
Error
Error
