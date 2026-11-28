--TEST--
Late static method targets resolve before arguments without deprecated callables
--FILE--
<?php

trait ResolvedIssueTrait
{
    public function boot(): string { return static::cache(static::class); }
    protected static function cache(string $class): string { return 'cached:' . $class; }
}

class ResolvedIssueBase
{
    use ResolvedIssueTrait;
    private string $selected = 'allowed';
    protected function allowed(int $n = 1): string { return 'base:' . $n; }
    protected static function marker(): string { return static::class; }
    protected static function bump(int &$n): void { $n++; }

    public function zero(): string { return static::marker(); }
    public function instance(): string { return static::allowed(2); }
    public function named(): string { return static::allowed(n: 3); }
    public function spread(array $args): string { return static::allowed(...$args); }
    public function snapshot(): string { return static::{$this->selected}($this->changeSelected()); }
    private function changeSelected(): int { $this->selected = 'missing'; return 7; }
    public function reference(): int { $n = 1; static::bump($n); return $n; }
    public function invalid(string $name): void { static::{$name}(resolved_argument()); }
    public static function invalidStatic(string $name): void { static::{$name}(resolved_argument()); }
    public function throughStatic(): void { self::invalidStatic('allowed'); }
    public function closure(): string {
        $call = function (): string { return static::allowed(8); };
        return $call();
    }
    public function staticClosure(): void {
        $call = static function (string $name): void { static::{$name}(resolved_argument()); };
        $call('allowed');
    }
    public function staticClosureLiteral(): string {
        $call = static fn(): string => static::allowed();
        return $call();
    }
}

class ResolvedIssueChild extends ResolvedIssueBase
{
    protected function allowed(int $n = 1): string { return 'child:' . $n; }
}

class ResolvedIssueMagic extends ResolvedIssueBase
{
    public function __call(string $name, array $args): string { return $name . '(' . implode(',', $args) . ')'; }
    public function reenter(): string {
        $name = 'outer';
        return static::{$name}($this->magicArgument());
    }
    private function magicArgument(): string {
        $name = 'inner';
        return static::{$name}();
    }
    public function cancel(): string {
        $name = 'cancelled';
        return static::{$name}(resolved_throwing_argument());
    }
}

function resolved_argument(): int { echo "WRONG:argument-evaluated\n"; return 1; }
function resolved_throwing_argument(): string { throw new RuntimeException('argument'); }

function main(): void
{
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    foreach ([new ResolvedIssueBase(), new ResolvedIssueChild()] as $object) {
        echo $object->boot(), "\n";
        echo $object->zero(), "\n";
        echo $object->instance(), "\n";
        echo $object->named(), "\n";
        echo $object->spread([4]), "\n";
        echo $object->snapshot(), "\n";
        echo $object->reference(), "\n";
        echo $object->closure(), "\n";
    }
    try {
        (new ResolvedIssueBase())->invalid('missing');
    } catch (Error $error) {
        echo "missing:Error\n";
    }
    try {
        ResolvedIssueBase::invalidStatic('allowed');
    } catch (Error $error) {
        echo "nonstatic:Error\n";
    }
    try {
        (new ResolvedIssueBase())->throughStatic();
    } catch (Error $error) {
        echo "static-frame:Error\n";
    }
    try {
        (new ResolvedIssueBase())->staticClosure();
    } catch (Error $error) {
        echo "static-closure:Error\n";
    }
    try {
        (new ResolvedIssueBase())->staticClosureLiteral();
    } catch (Error $error) {
        echo "static-closure-literal:Error\n";
    }
    $magic = new ResolvedIssueMagic();
    try {
        $magic->cancel();
    } catch (RuntimeException $error) {
        echo "argument:RuntimeException\n";
    }
    echo $magic->reenter(), "\n";
    restore_error_handler();
}
?>
--EXPECT--
cached:ResolvedIssueBase
ResolvedIssueBase
base:2
base:3
base:4
base:7
2
base:8
cached:ResolvedIssueChild
ResolvedIssueChild
child:2
child:3
child:4
child:7
2
child:8
missing:Error
nonstatic:Error
static-frame:Error
static-closure:Error
static-closure-literal:Error
argument:RuntimeException
outer(inner())
