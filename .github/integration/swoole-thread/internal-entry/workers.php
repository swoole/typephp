<?php

namespace TypephpThreadEntry;

function shutdown(\Swoole\Thread\Queue $queue): void
{
    $queue->push('shutdown');
}

function run(\Swoole\Thread\Queue $queue, int $round): array
{
    static $calls = 0;
    ++$calls;
    $arguments = \Swoole\Thread::getArguments();
    if (count($arguments) !== 2 || $arguments[1] !== $round) {
        throw new \RuntimeException('Thread argument mismatch');
    }
    register_shutdown_function('TypephpThreadEntry\shutdown', $queue);
    $queue->push('entry:' . $round . ':' . $calls);
    return ['round' => $round, 'calls' => $calls];
}

class Worker
{
    public static function run(\Swoole\Thread\Queue $queue, int $round): array
    {
        return run($queue, $round);
    }
}
