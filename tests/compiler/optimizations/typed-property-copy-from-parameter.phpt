--TEST--
Typed property copies from object parameters convert the actual lowered scalar storage
--FILE--
<?php
final class PropertyCopySource {
    public int $scene = 7;
    public float $ratio = 1.25;
    public bool $ready = true;
}
final class PropertyCopyTarget {
    public int $scene = 0;
    public float $ratio = 0.0;
    public bool $ready = false;

    public function copy(PropertyCopySource $source): void {
        $this->scene = $source->scene;
        $this->ratio = $source->ratio;
        $this->ready = $source->ready;
    }
}
function main(): void {
    $source = new PropertyCopySource();
    $target = new PropertyCopyTarget();
    $target->copy($source);
    var_dump($target->scene, $target->ratio, $target->ready);
    $source->scene = -9;
    $source->ratio = 2.5;
    $source->ready = false;
    $target->copy($source);
    var_dump($target->scene, $target->ratio, $target->ready);
}
?>
--EXPECT--
int(7)
float(1.25)
bool(true)
int(-9)
float(2.5)
bool(false)
