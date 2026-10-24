<?php
namespace Incremental;
class Actor {
    public function face(): int { return 1; }
    public function move(): int { return $this->face(); }
}
