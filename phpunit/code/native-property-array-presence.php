<?php
#[Native]
class NativePropertyPresence
{
    public array $values = [];
    public function contains(mixed $key): bool { return isset($this->values[$key]); }
    public function read(mixed $key): mixed { return $this->values[$key] ?? null; }
    public function missing(mixed $key): bool { return empty($this->values[$key]); }
}
