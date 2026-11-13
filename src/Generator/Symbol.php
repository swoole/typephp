<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Generator;

class Symbol
{
    public static function getStaticProperty(): string
    {
        return 'php::getStaticProperty';
    }

    public static function getStaticPropertyRef(): string
    {
        return 'php::getStaticPropertyRef';
    }

    public static function setStaticProperty(): string
    {
        return 'php::setStaticProperty';
    }

    public static function instanceOf_(): string
    {
        return 'php::instanceOf';
    }

    public static function concat(): string
    {
        return 'php::concat';
    }

    public static function constant(): string
    {
        return 'php::constant';
    }

    public static function getClassEntrySafe(): string
    {
        return 'php::getClassEntrySafe';
    }

    public static function argList(): string
    {
        return 'php::ArgList';
    }

    public static function varList(): string
    {
        return 'php::VarList';
    }

    public static function safeIndex(string $index, int|string $size): string
    {
        return "php::safeIndex({$index}, {$size})";
    }
}
