<?php
function main(): void {
    $vec = \std::vector(\Type::Int);
    $vec[] = 7;
    var_dump(\Incremental\answer($vec));
}
