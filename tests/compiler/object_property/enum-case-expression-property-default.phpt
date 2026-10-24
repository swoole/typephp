--TEST--
Enum scalar property defaults fold through arithmetic, nullsafe access, constants and traits
--FILE--
<?php

namespace EnumDefaultFixture {
    enum Code: int { case One = 1; }
    enum Label: string { case Guest = "a\0b"; }
}

namespace {
    use EnumDefaultFixture\Code as ImportedCode;
    use EnumDefaultFixture\Label;

    trait ComputedDefaults {
        public int $fromTrait = ImportedCode::One->value + self::OFFSET;
    }

    class Holder {
        use ComputedDefaults;
        private const BASE = ImportedCode::One->value;
        public const OFFSET = 4;
        public int $sum = ImportedCode::One->value + 2;
        public int $nullsafe = ImportedCode::One?->value;
        public ?int $absent = null?->value;
        public int $choice = ImportedCode::One->value === 1 ? 10 : 20;
        public string $name = ImportedCode::One->name . '!';
        public string $binary = Label::Guest?->value;
        public int $indirect = self::BASE + 2;
        public static int $staticValue = ImportedCode::One->value + 2;
        public ImportedCode $case = ImportedCode::One;
        public array $cases = [ImportedCode::One];
        public bool $same = ImportedCode::One === ImportedCode::One;
        public static bool $staticSame = ImportedCode::One === ImportedCode::One;
    }

    function main(): void {
        $first = new Holder();
        var_dump($first->sum, $first->nullsafe, $first->absent, $first->choice);
        var_dump($first->name, bin2hex($first->binary), $first->indirect, $first->fromTrait);
        var_dump(Holder::$staticValue);
        var_dump($first->case === ImportedCode::One, $first->cases[0] === ImportedCode::One);
        var_dump($first->same, Holder::$staticSame);
        $first->sum = 99;
        $first->cases[] = ImportedCode::One;
        $second = new Holder();
        var_dump($second->sum, count($second->cases));
        $defaults = (new ReflectionClass(Holder::class))->getDefaultProperties();
        var_dump($defaults['sum'], $defaults['nullsafe'], $defaults['absent']);
    }
}
?>
--EXPECT--
int(3)
int(1)
NULL
int(10)
string(4) "One!"
string(6) "610062"
int(3)
int(5)
int(3)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
int(1)
int(3)
int(1)
NULL
