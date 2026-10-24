<?php
function run(Actor $actor): int { return $actor->face(); }
function main(): int { $actor = new Actor(); return run($actor); }
