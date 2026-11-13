--TEST--
Native int div()/mod() by zero and INT_MIN by -1 raise SIGFPE; float division yields INF/NAN
--SKIPIF--
<?php
if (!function_exists('pcntl_fork')) exit("skip pcntl extension not loaded");
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

function run_case(int $kind, int $a, int $b): void
{
    $pid = pcntl_fork();
    if ($pid === 0) {
        if ($kind === 0) {
            divide($a, $b);
        } else {
            modulo($a, $b);
        }
        exit(0);
    }
    pcntl_waitpid($pid, $status);
    if (pcntl_wifsignaled($status)) {
        echo "signal ", pcntl_wtermsig($status) === SIGFPE ? "SIGFPE" : "other", "\n";
    } else {
        echo "exit ", pcntl_wexitstatus($status), "\n";
    }
}

function float_div(float $a, float $b): float
{
    return $a->div($b);
}

function main(): void
{
    run_case(0, 10, 2);
    run_case(0, 10, 0);
    run_case(1, 10, 0);
    run_case(0, PHP_INT_MIN, -1);
    var_dump(float_div(1.0, 0.0), float_div(-1.0, 0.0), is_nan(float_div(0.0, 0.0)));
}
?>
--EXPECT--
exit 0
signal SIGFPE
signal SIGFPE
signal SIGFPE
float(INF)
float(-INF)
bool(true)
