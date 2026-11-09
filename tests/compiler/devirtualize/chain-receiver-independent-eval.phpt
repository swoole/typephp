--TEST--
Chained receiver calls independent eval without retaining a Zend object frame
--FILE--
<?php

class ChainEvalFrame
{
    public int $value = 1;

    public static function make(): self
    {
        return new self();
    }

    public function run(): self
    {
        $this->value = eval('return 100;');
        return $this;
    }
}

function main(): void
{
    $fromFactory = ChainEvalFrame::make()->run();
    var_dump($fromFactory->value);

    $fromNew = (new ChainEvalFrame())->run();
    var_dump($fromNew->value);
}

?>
--EXPECT--
int(100)
int(100)
