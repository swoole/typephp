--TEST--
Variadic arguments reset on every loop iteration instead of accumulating
--FILE--
<?php
class Leaf {}
class Box {
    /** @var array<int, Leaf> */
    public array $k = [];
    public function add(...$els): void {
        foreach ($els as $e) { $this->k[] = $e; }
    }
    public function cnt(): int { return count($this->k); }
}

function main()
{
    $b = new Box();

    // Positional variadic inside a for loop: 3 iterations x 2 args = 6.
    for ($i = 0; $i < 3; $i++) {
        $b->add(new Leaf(), new Leaf());
    }

    // Positional variadic inside a while loop: 2 iterations x 1 arg = 2.
    $i = 0;
    while ($i < 2) {
        $b->add(new Leaf());
        $i++;
    }

    // Unpacked variadic inside a for loop: 2 iterations x 2 args = 4.
    $arr = [new Leaf(), new Leaf()];
    for ($i = 0; $i < 2; $i++) {
        $b->add(...$arr);
    }

    // Mixed positional + unpacked variadic enters the aggregation path
    // (the single-unpack fast path does not): 2 iterations x 3 args = 6.
    for ($i = 0; $i < 2; $i++) {
        $b->add(new Leaf(), ...$arr);
    }

    var_dump($b->cnt());
}
?>
--EXPECT--
int(18)
