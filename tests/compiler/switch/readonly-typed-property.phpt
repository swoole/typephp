--TEST--
Readonly promoted int property dispatches through a native switch
--FILE--
<?php
final class ReadonlyMachine
{
    public function __construct(private readonly int $status = 0)
    {
    }

    public function run(): string
    {
        switch ($this->status) {
        case 0:
            return 'pending';
        case 1:
            return 'done';
        default:
            return 'unknown';
        }
    }
}

function main()
{
    echo (new ReadonlyMachine(0))->run(), "\n";
    echo (new ReadonlyMachine(1))->run(), "\n";
    echo (new ReadonlyMachine(2))->run(), "\n";
}
?>
--EXPECT--
pending
done
unknown
