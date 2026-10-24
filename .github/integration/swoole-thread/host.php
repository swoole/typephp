<?php

require __DIR__ . '/host-symbols.php';
threadTestTrace('host: entered');
threadCheckEmbeddedResource();
if (getenv('TYPEPHP_THREAD_CONTROL') === '1') {
    require __DIR__ . '/probe.php';
}

function threadCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function checkSnapshot(array $value, int $owner, int $calls): void
{
    $expected = [
        'calls' => $calls, 'global_owner' => $owner, 'native_owner' => $owner,
        'date' => (string) $owner, 'method' => 'host-' . $owner,
        'dynamic' => '[' . $owner . ']', 'closure' => $owner + 5,
        'exception' => getenv('TYPEPHP_THREAD_EXCEPTIONS') === '0' ? '' : 'expected-' . $owner,
    ];
    threadCheck($value === $expected, 'Unexpected snapshot: ' . json_encode([$value, $expected]));
}

$events = new Swoole\Thread\Queue();
$map = new Swoole\Thread\Map();
$counter = new Swoole\Thread\Atomic();
typephp_thread_setup($events, 100000);
checkSnapshot(typephp_thread_snapshot(100000), 100000, 1);
$snapshots = 0;
$destructors = 0;
for ($round = 0; $round < 3; ++$round) {
    threadTestTrace('host: round ' . $round);
    $barrier = new Swoole\Thread\Barrier(4);
    $threads = [];
    for ($index = 1; $index <= 3; ++$index) {
        $owner = $round * 3 + $index;
        $threads[] = typephp_thread_spawn(__DIR__ . '/worker.php', $owner, $events, $map, $counter, $barrier);
    }
    $barrier->wait();
    threadTestTrace('host: barrier released');
    $workerStatuses = [];
    foreach ($threads as $thread) {
        threadCheck($thread->join(), 'Thread join failed');
        $workerStatuses[] = $thread->getExitStatus();
    }
    threadTestTrace('host: workers joined');
    threadCheck($workerStatuses === [0, 0, 0], 'Worker request failed: ' . json_encode($workerStatuses));
    $calls = [];
    $destroyed = [];
    for ($index = 0; $index < 21; ++$index) {
        $event = $events->pop(2.0);
        if ($event instanceof Swoole\Thread\Map) {
            $event = $event->toArray();
        }
        threadCheck(is_array($event), 'Missing worker event ' . $index . ': calls=' . json_encode($calls) . ', destructors=' . json_encode($destroyed));
        $owner = $event['owner'];
        threadCheck($owner > $round * 3 && $owner <= $round * 3 + 3, 'Event belongs to another request round');
        if ($event['kind'] === 'snapshot') {
            $calls[$owner] = ($calls[$owner] ?? 0) + 1;
            if ($event['value'] instanceof Swoole\Thread\Map) {
                $event['value'] = $event['value']->toArray();
            }
            checkSnapshot($event['value'], $owner, $calls[$owner]);
            ++$snapshots;
        } elseif ($event['kind'] === 'native-dtor') {
            threadCheck(!isset($destroyed[$owner]), 'Native root destroyed more than once');
            $destroyed[$owner] = true;
            ++$destructors;
        } else {
            throw new RuntimeException('Worker error: ' . json_encode($event));
        }
    }
    threadCheck(count($destroyed) === 3, 'Request shutdown did not finalize every native root');
    foreach ($calls as $owner => $count) {
        threadCheck($count === 6 && $map[(string) $owner] === 6, 'Shared map or request-local counter mismatch');
    }
}
checkSnapshot(typephp_thread_snapshot(100000), 100000, 2);
threadCheck($snapshots === 54 && $counter->get() === 54, 'Shared atomic counter mismatch');
threadCheck($destructors === 9 && $events->count() === 0, 'Unexpected shutdown events');
echo json_encode([
    'status' => 'swoole-thread-ok', 'snapshots' => $snapshots, 'shutdowns' => $destructors,
    'php' => PHP_VERSION, 'swoole' => SWOOLE_VERSION, 'sapi' => PHP_SAPI, 'zts' => (bool) PHP_ZTS,
    'embedded_resource' => getenv('TYPEPHP_THREAD_EMBEDDED') === '1',
], JSON_UNESCAPED_SLASHES), "\n";
