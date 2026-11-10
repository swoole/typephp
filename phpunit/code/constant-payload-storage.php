<?php

namespace ConstantPayload;

const INTEGER = 36;
const FRACTION = 0.5;

final class Values
{
    public const INTEGER = 36;
    public const FRACTION = 0.5;
    public function integer(): mixed { return self::INTEGER; }
    public function fraction(): mixed { return self::FRACTION; }
}

function integer(): mixed { return INTEGER; }
function fraction(): mixed { return FRACTION; }
