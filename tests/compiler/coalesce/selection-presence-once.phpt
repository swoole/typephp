--TEST--
Coalesce keeps false and empty values and evaluates magic properties and array roots once
--FILE--
<?php

final class SelectionPresenceMagic
{
    public array $events = [];
    public function __isset(string $name): bool { $this->events[] = 'isset:' . $name; return $name === 'hit'; }
    public function __get(string $name): mixed { $this->events[] = 'get:' . $name; return 0; }
}
function selectionPresenceFallback(array &$events): int { $events[] = 'fallback'; return 5; }
function selectionPresenceArray(array &$events): array { $events[] = 'array'; return []; }
function selectionPresenceName(array &$events): string { $events[] = 'name'; return 'count'; }
final class SelectionPresenceRoot
{
    public function count(): int { return 3; }
}
function main(): void
{
    $events = [];
    $zero = 0;
    $empty = '';
    $false = false;
    var_dump($zero ?? selectionPresenceFallback($events));
    var_dump($empty ?? 'fallback', $false ?? true);
    $missing = null;
    var_dump($missing ?? selectionPresenceFallback($events));
    var_dump(selectionPresenceArray($events)['missing'] ?? selectionPresenceFallback($events));
    $magic = new SelectionPresenceMagic();
    var_dump($magic->hit ?? 11, $magic->miss ?? 11, $magic->events);
    var_dump($missing->anything ?? 12);
    $root = null;
    var_dump($root?->{selectionPresenceName($events)}() ?? 0);
    $root = new SelectionPresenceRoot();
    var_dump($root?->{selectionPresenceName($events)}() ?? 0);
    var_dump($events);
}
?>
--EXPECT--
int(0)
string(0) ""
bool(false)
int(5)
int(5)
int(0)
int(11)
array(3) {
  [0]=>
  string(9) "isset:hit"
  [1]=>
  string(7) "get:hit"
  [2]=>
  string(10) "isset:miss"
}
int(12)
int(0)
int(3)
array(4) {
  [0]=>
  string(8) "fallback"
  [1]=>
  string(5) "array"
  [2]=>
  string(8) "fallback"
  [3]=>
  string(4) "name"
}
