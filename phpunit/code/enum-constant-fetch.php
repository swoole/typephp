<?php

interface BaseLabels
{
    public const INHERITED = ['value' => 1];
}

interface Labels extends BaseLabels {}

enum try_type implements Labels
{
    case t_int;

    public const LABEL = 'integer';
    public const ALIAS = self::t_int;
    public const COMPOSITE = [self::t_int];
    public const EMPTY = null;
}

function trying(?try_type $type = null): void {}

function validCase(): void
{
    trying(try_type::t_int);
}

function mixedCaseType(): void
{
    trying(TRY_TYPE::t_int);
}

function scalarConstant(): string
{
    return try_type::LABEL;
}

function aliasedCase(): try_type
{
    return try_type::ALIAS;
}

function arrayConstant(): array
{
    return try_type::COMPOSITE;
}

function inheritedConstant(): array
{
    return try_type::INHERITED;
}

function nullConstant(): mixed
{
    return try_type::EMPTY;
}

function main(): void {}
