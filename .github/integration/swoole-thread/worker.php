<?php

require __DIR__ . '/host-symbols.php';
threadTestTrace('worker: entered');
if (getenv('TYPEPHP_THREAD_CONTROL') === '1') {
    require __DIR__ . '/probe.php';
}

[$owner, $events, $map, $counter, $barrier] = Swoole\Thread::getArguments();
threadTestTrace('worker: arguments received ' . $owner);
try {
    $barrier->wait();
    threadTestTrace('worker: barrier released ' . $owner);
    threadCheckEmbeddedResource();
    typephp_thread_setup($events, $owner);
    threadTestTrace('worker: setup finished ' . $owner);
    for ($iteration = 0; $iteration < 3; ++$iteration) {
        typephp_thread_publish($events, $map, $counter, $owner, typephp_thread_snapshot($owner));
        typephp_thread_publish($events, $map, $counter, $owner, typephp_thread_coroutine_snapshot($owner));
    }
    threadTestTrace('worker: finished ' . $owner);
} catch (Throwable $error) {
    threadTestTrace('worker: error ' . (string) $error);
    $events->push(['kind' => 'error', 'owner' => $owner, 'message' => (string) $error], 1);
    exit(1);
}
