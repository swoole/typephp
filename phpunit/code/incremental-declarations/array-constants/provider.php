<?php
namespace Incremental;
const LIMIT = 42;
const FIRST = [[[LIMIT, 1]], [[2, 3]]];
const ENABLED = true;
const SECOND = [0 => ['left' => LIMIT], 'tail' => [2, [3]]];
function answer(): int { return FIRST[0][0][0] + SECOND[0]['left']; }
