<?php

final class ReadonlyReceiverState
{
    public function __construct(
        public readonly int $status,
        public readonly float $ratio = 0.5,
        public readonly bool $enabled = true,
    ) {
    }
}

function readonlyParameterStatus(ReadonlyReceiverState $state): string
{
    switch ($state->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyLocalStatus(int $status): string
{
    $local = new ReadonlyReceiverState($status);
    switch ($local->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyExpressionStatus(int $status): string
{
    switch ((new ReadonlyReceiverState($status))->status) {
        case 0: return 'pending';
        case 1: return 'done';
        default: return 'unknown';
    }
}

function readonlyParameterRatio(ReadonlyReceiverState $state): float
{
    return $state->ratio;
}

function readonlyParameterEnabled(ReadonlyReceiverState $state): bool
{
    return $state->enabled;
}
