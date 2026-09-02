<?php

namespace TraitPropertyImports;

use TraitPropertyImports\Support\Level as ImportedLevel;

trait HasLevels
{
    public array $levels = ['debug' => ImportedLevel::Debug];
}
