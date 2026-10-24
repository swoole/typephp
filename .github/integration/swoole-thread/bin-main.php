<?php

function main(): void
{
    // Worker RINIT precedes Swoole's population of Thread::getArguments().
    // Use the TSRM main-thread flag, not getArguments(), to guard this entry.
    if (!Swoole\Thread::getInfo()['is_main_thread']) {
        return;
    }
    require __DIR__ . '/host.php';
}
