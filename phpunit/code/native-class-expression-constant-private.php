<?php

#[Native]
class NativeConstantOwner
{
    private const SECRET = 1;
}

#[Native]
class NativeConstantReader
{
    public const MASK = NativeConstantOwner::SECRET | 2;
}
