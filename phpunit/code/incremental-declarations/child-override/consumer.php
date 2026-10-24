<?php
class World extends \Incremental\Actor {  }
function run(\Incremental\Actor $actor): int { return $actor->face(); }
function main(): int { $world = new World(); return $world->move() + run($world); }
