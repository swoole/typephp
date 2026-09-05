<?php

declare(strict_types=1);

const DYNAMIC_CALL_ITERATIONS = 1_000_000;
const DYNAMIC_CALL_ROUNDS = 5;

function dynamicCallNoArgs(): int
{
    return 1;
}

function dynamicCallAddOne(int $value): int
{
    return $value + 1;
}

function dynamicCallAddTwoArgs(int $left, int $right): int
{
    return $left + $right;
}

function dynamicCallAddFourArgs(int $a, int $b, int $c, int $d): int
{
    return $a + $b + $c + $d;
}

function dynamicCallAddTwo(int $value): int
{
    return $value + 2;
}

function dynamicCallAddThree(int $value): int
{
    return $value + 3;
}

function dynamicCallAddFour(int $value): int
{
    return $value + 4;
}

function dynamicCallAddFive(int $value): int
{
    return $value + 5;
}

function dynamicCallAddSix(int $value): int
{
    return $value + 6;
}

function dynamicCallAddSeven(int $value): int
{
    return $value + 7;
}

function dynamicCallAddEight(int $value): int
{
    return $value + 8;
}

final class DynamicCallTarget
{
    public static function addOne(int $value): int
    {
        return $value + 1;
    }

    public static function addTwoStatic(int $value): int
    {
        return $value + 2;
    }

    public function addTwo(int $value): int
    {
        return $value + 2;
    }

    public function hitOne(int $value): int
    {
        return $value + 1;
    }

    public function hitTwo(int $value): int
    {
        return $value + 2;
    }

    public function hitZero(): int
    {
        return 1;
    }

    public function __invoke(int $value): int
    {
        return $value + 3;
    }
}

final class DynamicCallAlternateTarget
{
    public static function addOne(int $value): int
    {
        return $value + 1;
    }

    public static function addTwoStatic(int $value): int
    {
        return $value + 2;
    }

    public function hitOne(int $value): int
    {
        return $value + 1;
    }
}

final class ScopedDynamicCallTarget
{
    private function hitZero(): int
    {
        return 1;
    }

    protected function hitOne(int $value): int
    {
        return $value + 1;
    }

    public function runDynamicNameZeroArgs(int $iterations): int
    {
        $method = 'hitZero';
        $sum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $sum += $this->$method();
        }
        return $sum;
    }

    public function runDynamicName(int $iterations): int
    {
        $method = 'hitOne';
        $sum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $sum += $this->$method($i);
        }
        return $sum;
    }

    public function runNamedDynamicReceiver(object $target, int $iterations): int
    {
        $sum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $sum += $target->hitOne($i);
        }
        return $sum;
    }
}

function createDynamicMethodReceiver(): object
{
    return new DynamicCallTarget();
}

function runDirectCall(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += dynamicCallAddOne($i);
    }
    return $sum;
}

function runMonomorphicStringCall(int $iterations): int
{
    $callback = 'dynamicCallAddOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i);
    }
    return $sum;
}

function runMonomorphicStringCallZeroArgs(int $iterations): int
{
    $callback = 'dynamicCallNoArgs';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback();
    }
    return $sum;
}

function runMonomorphicStringCallTwoArgs(int $iterations): int
{
    $callback = 'dynamicCallAddTwoArgs';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i, 1);
    }
    return $sum;
}

function runMonomorphicStringCallFourArgs(int $iterations): int
{
    $callback = 'dynamicCallAddFourArgs';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i, 1, 2, 3);
    }
    return $sum;
}

function runAlternatingStringCall(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $callback = ($i & 1) === 0 ? 'dynamicCallAddOne' : 'dynamicCallAddTwo';
        $sum += $callback($i);
    }
    return $sum;
}

function runMegamorphicStringCall(int $iterations): int
{
    $callbacks = [
        'dynamicCallAddOne',
        'dynamicCallAddTwo',
        'dynamicCallAddThree',
        'dynamicCallAddFour',
        'dynamicCallAddFive',
        'dynamicCallAddSix',
        'dynamicCallAddSeven',
        'dynamicCallAddEight',
    ];
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $callback = $callbacks[($i * 5 + 3) & 7];
        $sum += $callback($i);
    }
    return $sum;
}

function runMonomorphicClosureCall(int $iterations): int
{
    $callback = static fn (int $value): int => $value + 1;
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i);
    }
    return $sum;
}

function runAlternatingClosureCall(int $iterations): int
{
    $first = static fn (int $value): int => $value + 1;
    $second = static fn (int $value): int => $value + 2;
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $callback = ($i & 1) === 0 ? $first : $second;
        $sum += $callback($i);
    }
    return $sum;
}

function runStaticMethodStringCall(int $iterations): int
{
    $callback = 'DynamicCallTarget::addOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i);
    }
    return $sum;
}

function runDynamicStaticClassCall(int $iterations): int
{
    $class = DynamicCallTarget::class;
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $class::addOne($i);
    }
    return $sum;
}

function runDynamicStaticMethodCall(int $iterations): int
{
    $method = 'addOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += DynamicCallTarget::$method($i);
    }
    return $sum;
}

function runDynamicStaticClassAndMethodCall(int $iterations): int
{
    $class = DynamicCallTarget::class;
    $method = 'addOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $class::$method($i);
    }
    return $sum;
}

function runAlternatingDynamicStaticClassCall(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $class = ($i & 1) === 0 ? DynamicCallTarget::class : DynamicCallAlternateTarget::class;
        $sum += $class::addOne($i);
    }
    return $sum;
}

function runAlternatingDynamicStaticMethodCall(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $method = ($i & 1) === 0 ? 'addOne' : 'addTwoStatic';
        $sum += DynamicCallTarget::$method($i);
    }
    return $sum;
}

function runAlternatingDynamicStaticClassAndMethodCall(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $class = ($i & 1) === 0 ? DynamicCallTarget::class : DynamicCallAlternateTarget::class;
        $method = ($i & 1) === 0 ? 'addOne' : 'addTwoStatic';
        $sum += $class::$method($i);
    }
    return $sum;
}

function runObjectMethodArrayCall(int $iterations): int
{
    $target = new DynamicCallTarget();
    $callback = [$target, 'addTwo'];
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i);
    }
    return $sum;
}

function runInvokableObjectCall(int $iterations): int
{
    $callback = new DynamicCallTarget();
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $callback($i);
    }
    return $sum;
}

function runMonomorphicMethodNameCall(int $iterations): int
{
    $target = new DynamicCallTarget();
    $method = 'hitOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $target->$method($i);
    }
    return $sum;
}

function runAlternatingMethodNameCall(int $iterations): int
{
    $target = new DynamicCallTarget();
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $method = ($i & 1) === 0 ? 'hitOne' : 'hitTwo';
        $sum += $target->$method($i);
    }
    return $sum;
}

function runPolymorphicMethodReceiverCall(int $iterations): int
{
    $targets = [new DynamicCallTarget(), new DynamicCallAlternateTarget()];
    $method = 'hitOne';
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $target = $targets[$i & 1];
        $sum += $target->$method($i);
    }
    return $sum;
}

function runNamedMethodDynamicReceiverZeroArgs(int $iterations): int
{
    // The declared `object` return type deliberately hides the concrete class
    // from TypePHP while keeping the call site monomorphic at runtime.
    $target = createDynamicMethodReceiver();
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $target->hitZero();
    }
    return $sum;
}

function runNamedMethodDynamicReceiverCall(int $iterations): int
{
    $target = createDynamicMethodReceiver();
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $target->hitOne($i);
    }
    return $sum;
}

function runNamedMethodPolymorphicReceiverCall(int $iterations): int
{
    $targets = [new DynamicCallTarget(), new DynamicCallAlternateTarget()];
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $target = $targets[$i & 1];
        $sum += $target->hitOne($i);
    }
    return $sum;
}

function runScopedMethodNameZeroArgs(int $iterations): int
{
    $target = new ScopedDynamicCallTarget();
    return $target->runDynamicNameZeroArgs($iterations);
}

function runScopedMethodNameCall(int $iterations): int
{
    $target = new ScopedDynamicCallTarget();
    return $target->runDynamicName($iterations);
}

function runScopedNamedDynamicReceiverCall(int $iterations): int
{
    $target = new ScopedDynamicCallTarget();
    return $target->runNamedDynamicReceiver($target, $iterations);
}

function runDynamicCallCase(string $case, int $iterations): int
{
    return match ($case) {
        'direct' => runDirectCall($iterations),
        'string_monomorphic_zero' => runMonomorphicStringCallZeroArgs($iterations),
        'string_monomorphic' => runMonomorphicStringCall($iterations),
        'string_monomorphic_two' => runMonomorphicStringCallTwoArgs($iterations),
        'string_monomorphic_four' => runMonomorphicStringCallFourArgs($iterations),
        'string_alternating' => runAlternatingStringCall($iterations),
        'string_megamorphic' => runMegamorphicStringCall($iterations),
        'closure_monomorphic' => runMonomorphicClosureCall($iterations),
        'closure_alternating' => runAlternatingClosureCall($iterations),
        'static_method_string' => runStaticMethodStringCall($iterations),
        'static_class_dynamic' => runDynamicStaticClassCall($iterations),
        'static_method_dynamic' => runDynamicStaticMethodCall($iterations),
        'static_class_method_dynamic' => runDynamicStaticClassAndMethodCall($iterations),
        'static_class_alternating' => runAlternatingDynamicStaticClassCall($iterations),
        'static_method_alternating' => runAlternatingDynamicStaticMethodCall($iterations),
        'static_class_method_alternating' => runAlternatingDynamicStaticClassAndMethodCall($iterations),
        'object_method_array' => runObjectMethodArrayCall($iterations),
        'invokable_object' => runInvokableObjectCall($iterations),
        'method_name_monomorphic' => runMonomorphicMethodNameCall($iterations),
        'method_name_alternating' => runAlternatingMethodNameCall($iterations),
        'method_receiver_polymorphic' => runPolymorphicMethodReceiverCall($iterations),
        'named_method_dynamic_receiver_zero' => runNamedMethodDynamicReceiverZeroArgs($iterations),
        'named_method_dynamic_receiver' => runNamedMethodDynamicReceiverCall($iterations),
        'named_method_polymorphic_receiver' => runNamedMethodPolymorphicReceiverCall($iterations),
        'scoped_method_name_zero' => runScopedMethodNameZeroArgs($iterations),
        'scoped_method_name' => runScopedMethodNameCall($iterations),
        'scoped_named_dynamic_receiver' => runScopedNamedDynamicReceiverCall($iterations),
        default => throw new RuntimeException("Unknown benchmark case: {$case}"),
    };
}

function measureDynamicCallCase(string $case): array
{
    for ($warmup = 0; $warmup < 2; $warmup++) {
        runDynamicCallCase($case, 1_000);
    }

    $best = 1.0e30;
    $bestResult = 0;
    for ($round = 0; $round < DYNAMIC_CALL_ROUNDS; $round++) {
        $start = hrtime(true);
        $result = runDynamicCallCase($case, DYNAMIC_CALL_ITERATIONS);
        $elapsed = hrtime(true) - $start;
        if ($elapsed < $best) {
            $best = $elapsed;
            $bestResult = $result;
        }
    }

    return [$best / DYNAMIC_CALL_ITERATIONS, $bestResult];
}

function main(): void
{
    $selectedCase = getenv('DYNAMIC_CALL_CASE');
    foreach ([
        'direct',
        'string_monomorphic_zero',
        'string_monomorphic',
        'string_monomorphic_two',
        'string_monomorphic_four',
        'string_alternating',
        'string_megamorphic',
        'closure_monomorphic',
        'closure_alternating',
        'static_method_string',
        'static_class_dynamic',
        'static_method_dynamic',
        'static_class_method_dynamic',
        'static_class_alternating',
        'static_method_alternating',
        'static_class_method_alternating',
        'object_method_array',
        'invokable_object',
        'method_name_monomorphic',
        'method_name_alternating',
        'method_receiver_polymorphic',
        'named_method_dynamic_receiver_zero',
        'named_method_dynamic_receiver',
        'named_method_polymorphic_receiver',
        'scoped_method_name_zero',
        'scoped_method_name',
        'scoped_named_dynamic_receiver',
    ] as $case) {
        if (is_string($selectedCase) && $selectedCase !== '' && $selectedCase !== $case) {
            continue;
        }
        [$nanoseconds, $result] = measureDynamicCallCase($case);
        printf("%s_ns=%.3f\n", $case, $nanoseconds);
        printf("checksum_%s=%d\n", $case, $result);
    }
}
