<?php

#[Native]
class TypephpThreadNativeRoot
{
    public int $owner;

    public function __construct(int $owner)
    {
        $this->owner = $owner;
    }

    public function __destruct()
    {
        typephp_thread_record_destruction($this->owner);
    }
}

function typephp_thread_record_destruction(int $owner): void
{
    global $typephpThreadEvents;
    $typephpThreadEvents->push(['kind' => 'native-dtor', 'owner' => $owner], 1);
}

function typephp_thread_setup(Swoole\Thread\Queue $events, int $owner): void
{
    global $typephpThreadEvents, $typephpThreadRoot;
    $typephpThreadEvents = $events;
    $typephpThreadRoot = new TypephpThreadNativeRoot($owner);
}

function typephp_thread_snapshot(int $owner): array
{
    static $calls = 0;
    ++$calls;
    global $typephpThreadState, $typephpThreadRoot;
    if (!isset($typephpThreadState)) {
        $typephpThreadState = ['owner' => $owner];
    }
    $date = new DateTimeImmutable('@' . $owner);
    // These symbols are declared separately in each worker's PHP request.
    $value = new TypephpThreadHostValue($owner);
    $transform = 'typephp_thread_host_transform';
    $closure = static fn (int $number): int => $number + $owner;
    $exception = '';
    if (getenv('TYPEPHP_THREAD_EXCEPTIONS') !== '0') {
        try {
            throw new RuntimeException('expected-' . $owner);
        } catch (RuntimeException $error) {
            $exception = $error->getMessage();
        }
    }
    return [
        'calls' => $calls,
        'global_owner' => $typephpThreadState['owner'],
        'native_owner' => $typephpThreadRoot->owner,
        'date' => $date->format('U'),
        'method' => $value->render(),
        'dynamic' => $transform((string) $owner),
        'closure' => $closure(5),
        'exception' => $exception,
    ];
}

function typephp_thread_coroutine_snapshot(int $owner): array
{
    $result = [];
    Swoole\Coroutine\run(static function () use ($owner, &$result): void {
        $result = typephp_thread_snapshot($owner);
    });
    return $result;
}

function typephp_thread_publish(
    Swoole\Thread\Queue $events,
    Swoole\Thread\Map $map,
    Swoole\Thread\Atomic $counter,
    int $owner,
    array $snapshot,
): void {
    $map->incr((string) $owner);
    $counter->add();
    $events->push(['kind' => 'snapshot', 'owner' => $owner, 'value' => $snapshot], 1);
}

function typephp_thread_spawn(
    string $script,
    int $owner,
    Swoole\Thread\Queue $events,
    Swoole\Thread\Map $map,
    Swoole\Thread\Atomic $counter,
    Swoole\Thread\Barrier $barrier,
): Swoole\Thread {
    return new Swoole\Thread($script, $owner, $events, $map, $counter, $barrier);
}
