--TEST--
Ternary branches mixing property reads and literals unify C++ storage types
--FILE--
<?php
final class TernaryStorage
{
    private static bool $staticEnabled = false;

    private static string $label = 'off';

    private bool $enabled = false;

    private string $mode = 'idle';

    private int $count = 5;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function modeOrEmpty(): string
    {
        return $this->enabled ? $this->mode : '';
    }

    public function countOrDefault(): int
    {
        return $this->enabled ? $this->count : 0;
    }

    public static function staticLabel(): string
    {
        return self::$staticEnabled ? self::$label : '';
    }
}

function main()
{
    $on = new TernaryStorage(true);
    $off = new TernaryStorage(false);
    echo $on->modeOrEmpty(), '|', $off->modeOrEmpty(), "\n";
    echo $on->countOrDefault(), '|', $off->countOrDefault(), "\n";
    echo '[', TernaryStorage::staticLabel(), ']', "\n";
}
?>
--EXPECT--
idle|
5|0
[]
