--TEST--
Trait property defaults retain the original imports through nested composition
--FILE--
<?php
namespace TraitPropertyDefaults\Support {
    enum Level: int {
        case Debug = 100;
    }

    enum OtherLevel: int {
        case Debug = 200;
    }
}

namespace TraitPropertyDefaults\Template {
    use TraitPropertyDefaults\Support\Level;

    trait HasLevels {
        public array $levels = ['debug' => Level::Debug];
    }
}

namespace TraitPropertyDefaults\Wrapper {
    use TraitPropertyDefaults\Support\OtherLevel as Level;
    use TraitPropertyDefaults\Template\HasLevels;

    trait NestedLevels {
        use HasLevels;
    }
}

namespace TraitPropertyDefaults\Consumer {
    use TraitPropertyDefaults\Support\OtherLevel as Level;
    use TraitPropertyDefaults\Template\HasLevels;
    use TraitPropertyDefaults\Wrapper\NestedLevels;

    class DirectConsumer {
        use HasLevels;

        public array $ownLevels = ['debug' => Level::Debug];
    }

    class NestedConsumer {
        use NestedLevels;
    }
}

namespace {
    function main(): void {
        $direct = new TraitPropertyDefaults\Consumer\DirectConsumer();
        $nested = new TraitPropertyDefaults\Consumer\NestedConsumer();
        var_dump($direct->levels['debug']->value);
        var_dump($nested->levels['debug']->value);
        var_dump($direct->ownLevels['debug']->value);
    }
}
?>
--EXPECT--
int(100)
int(100)
int(200)
