--TEST--
Typed defaults distinguish native string expressions from Variant constant storage
--FILE--
<?php
const DEFAULT_STORAGE_TEXT = 'global';

class DefaultStorageTypes
{
    const TEXT = 'class';

    public function show(
        string $literal = 'literal',
        string $global = DEFAULT_STORAGE_TEXT,
        string $classConstant = self::TEXT,
        string $joined = 'joined' . '-text',
        string $className = \stdClass::class,
        string $internal = \DateTime::ATOM,
        int $mask = 1 | \ArrayObject::ARRAY_AS_PROPS,
        string $selectedLiteral = true ? 'yes' : 'no',
        string $selectedConstant = true ? DEFAULT_STORAGE_TEXT : DEFAULT_STORAGE_TEXT,
    ): void {
        var_dump($literal, $global, $classConstant, $joined, $className, $internal, $mask);
        var_dump($selectedLiteral, $selectedConstant);
    }
}

function main(): void
{
    (new DefaultStorageTypes)->show();
}
?>
--EXPECT--
string(7) "literal"
string(6) "global"
string(5) "class"
string(11) "joined-text"
string(8) "stdClass"
string(13) "Y-m-d\TH:i:sP"
int(3)
string(3) "yes"
string(6) "global"
