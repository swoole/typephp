<?php

namespace TraitPropertyImports;

use TraitPropertyImports\Support\OtherLevel as ImportedLevel;

trait NestedLevels
{
    use HasLevels;
}

class DirectConsumer
{
    use HasLevels;

    public array $ownLevels = ['debug' => ImportedLevel::Debug];
}

class NestedConsumer
{
    use NestedLevels;
}
