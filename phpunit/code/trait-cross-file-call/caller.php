<?php

use Lib\ClassB;

function callTraitMethod(): void
{
    $b = new ClassB();
    $b->getAttribute('SomeClass');
}
