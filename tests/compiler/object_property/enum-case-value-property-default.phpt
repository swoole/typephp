--TEST--
Enum case ->value and ->name fold into property defaults (PHP 8.2+)
--FILE--
<?php

enum TextRole: int
{
    case Unset = -1;
    case User = 1;
}

enum Level: string
{
    case Guest = 'guest';
}

enum Suit
{
    case Hearts;
}

class RoleCard
{
    public int $role = TextRole::Unset->value;
    public string $level = Level::Guest->value;
    public string $caseName = Suit::Hearts->name;
    public TextRole $defaultRole = TextRole::User;
}

function main(): void
{
    $card = new RoleCard();
    var_dump($card->role);
    var_dump($card->level);
    var_dump($card->caseName);
    var_dump($card->defaultRole === TextRole::User);
}
?>
--EXPECT--
int(-1)
string(5) "guest"
string(6) "Hearts"
bool(true)
