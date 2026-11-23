<?php

function threadEntryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(): void
{
    // Fail before spawning anything if the runtime re-enters main during RINIT.
    threadEntryCheck(Swoole\Thread::getInfo()['is_main_thread'], 'Application main entered a worker');
    $queue = new Swoole\Thread\Queue();
    for ($round = 0; $round < 3; ++$round) {
        foreach (['TypephpThreadEntry\run', '\TYPEPHPTHREADENTRY\Worker::RUN'] as $entry) {
            $thread = new Swoole\Thread($entry, $queue, $round);
            threadEntryCheck($thread->join(), 'Unable to join internal entry');
            threadEntryCheck($thread->getExitStatus() === 0, 'Internal entry failed');
            threadEntryCheck($queue->pop(1.0) === 'entry:' . $round . ':1', 'Entry or request state mismatch');
            threadEntryCheck($queue->pop(1.0) === 'shutdown', 'Request shutdown did not execute');
            $result = $thread->getResult();
            threadEntryCheck($result === ['round' => $round, 'calls' => 1], 'Internal entry result was lost');
        }
    }
    $thread = new Swoole\Thread(__DIR__ . '/worker-script.php', $queue, 9);
    threadEntryCheck($thread->join(), 'Unable to join script entry');
    threadEntryCheck($thread->getExitStatus() === 0, 'Script entry failed');
    threadEntryCheck($queue->pop(1.0) === 'entry:9:1', 'Script did not call the compiled entry');
    threadEntryCheck($queue->pop(1.0) === 'shutdown', 'Script request shutdown did not execute');
    $result = $thread->getResult();
    threadEntryCheck($result === ['round' => 9, 'calls' => 1], 'Script result was lost');
    threadEntryCheck(Swoole\Thread::activeCount() === 1, 'Worker thread did not exit');
    echo "swoole-internal-entry-ok\n";
}
