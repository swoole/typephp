--TEST--
Native int div()/mod() by zero and INT_MIN by -1 raise SIGFPE; float division yields INF/NAN
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') exit("skip POSIX shell required");
?>
--FILE--
<?php
function divide(int $a, int $b): int
{
    return $a->div($b);
}

function modulo(int $a, int $b): int
{
    return $a->mod($b);
}

function float_div(float $a, float $b): float
{
    return $a->div($b);
}

// The shell reports a process killed by signal N as exit status 128 + N.
function run_child(string $self, string $kind, int $a, int $b): void
{
    $command = escapeshellarg($self) . " $kind $a $b >/dev/null 2>&1; echo \$?";
    echo $kind, " ", trim((string) shell_exec('sh -c ' . escapeshellarg($command))), "\n";
}

function main(int $argc, array $argv): void
{
    if ($argc > 1) {
        $a = (int) $argv[2];
        $b = (int) $argv[3];
        if ($argv[1] === 'div') {
            divide($a, $b);
        } else {
            modulo($a, $b);
        }
        return;
    }
    run_child($argv[0], 'div', 10, 2);
    run_child($argv[0], 'div', 10, 0);
    run_child($argv[0], 'mod', 10, 0);
    run_child($argv[0], 'div', PHP_INT_MIN, -1);
    var_dump(float_div(1.0, 0.0), float_div(-1.0, 0.0), is_nan(float_div(0.0, 0.0)));
}
?>
--EXPECT--
div 0
div 136
mod 136
div 136
float(INF)
float(-INF)
bool(true)
