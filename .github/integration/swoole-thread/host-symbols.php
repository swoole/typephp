<?php

function threadTestTrace(string $event): void
{
    $path = getenv('TYPEPHP_THREAD_TRACE');
    if ($path !== false && $path !== '') {
        file_put_contents($path, $event . "\n", FILE_APPEND | LOCK_EX);
    }
}

function threadCheckEmbeddedResource(): void
{
    if (getenv('TYPEPHP_THREAD_EMBEDDED') === '1') {
        $path = __DIR__ . '/request.txt';
        if (!file_exists($path) || file_get_contents($path) !== "thread-request-embedded-resource\n") {
            throw new RuntimeException('Embedded resource unavailable in this request');
        }
    }
}

class TypephpThreadHostValue
{
    public function __construct(private int $owner) {}

    public function render(): string
    {
        return 'host-' . $this->owner;
    }
}

function typephp_thread_host_transform(string $value): string
{
    return '[' . $value . ']';
}
