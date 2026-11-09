--TEST--
Unpacked native method calls retain callable names and lexical visibility
--FILE--
<?php
class UnpackedBase
{
    protected function protectedValue(int $a, int $b = 2): int
    {
        return $a + $b;
    }

    private function privateValue(int $a, int $b): int
    {
        return $a * $b;
    }

    public function run(array $args): array
    {
        return [$this->protectedValue(...$args), $this->privateValue(...$args)];
    }
}

final class UnpackedChild extends UnpackedBase
{
    public function publicValue(int $a, int $b = 2): int
    {
        return $a - $b;
    }
}

final class UnpackedCaller
{
    public function run(array $args): void
    {
        $target = new UnpackedChild();
        var_dump($target->publicValue(...$args));
        var_dump($target->publicValue(...[7]));
        var_dump($target->run($args));
    }
}

function main(): void
{
    (new UnpackedCaller())->run([7, 3]);
}
?>
--EXPECT--
int(4)
int(5)
array(2) {
  [0]=>
  int(10)
  [1]=>
  int(21)
}
