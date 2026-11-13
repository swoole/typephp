<?php

class ExtBase
{
}

class ExtChild extends ExtBase
{
    public function shout(): string
    {
        return 'child';
    }
}

#[MethodsFor(ExtBase::class)]
class ExtBaseMethods
{
    public static function shout(ExtBase $self): string
    {
        return 'base';
    }
}

function run(ExtBase $b): string
{
    return $b->shout();
}
