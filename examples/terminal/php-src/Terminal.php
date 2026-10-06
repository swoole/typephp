<?php

/**
 * Native C++ terminal functions exported to TypePHP.
 * When compiled by tpc, these call directly into terminal.cc without Zend zval boxing.
 */
function terminal_get_width(): int
{
    return 80;
}

function terminal_get_height(): int
{
    return 24;
}

function terminal_isatty(int $fd): bool
{
    return false;
}

function terminal_set_raw_mode(bool $enable): bool
{
    return false;
}

function terminal_read_char(): string
{
    return '';
}

function terminal_read_secret(string $prompt = ''): string
{
    return '';
}

function terminal_set_title(string $title): bool
{
    return false;
}

function terminal_supports_color(): bool
{
    return true;
}

/**
 * Cross-platform Terminal API for TypePHP compiled binaries.
 * Provides native terminal control on Linux, macOS, and Windows.
 */
class Terminal
{
    public const int STDIN = 0;
    public const int STDOUT = 1;
    public const int STDERR = 2;

    public const string COLOR_RESET = "\033[0m";
    public const string COLOR_BOLD = "\033[1m";
    public const string COLOR_DIM = "\033[2m";

    public const string FG_RED = "\033[31m";
    public const string FG_GREEN = "\033[32m";
    public const string FG_YELLOW = "\033[33m";
    public const string FG_BLUE = "\033[34m";
    public const string FG_MAGENTA = "\033[35m";
    public const string FG_CYAN = "\033[36m";
    public const string FG_WHITE = "\033[37m";

    public static function getWidth(): int
    {
        return terminal_get_width();
    }

    public static function getHeight(): int
    {
        return terminal_get_height();
    }

    /**
     * @return array{width: int, height: int}
     */
    public static function getSize(): array
    {
        return [
            'width' => terminal_get_width(),
            'height' => terminal_get_height(),
        ];
    }

    public static function isInteractive(): bool
    {
        return terminal_isatty(self::STDOUT);
    }

    public static function enableRawMode(): bool
    {
        return terminal_set_raw_mode(true);
    }

    public static function disableRawMode(): bool
    {
        return terminal_set_raw_mode(false);
    }

    public static function readChar(): string
    {
        return terminal_read_char();
    }

    public static function readSecret(string $prompt = ''): string
    {
        return terminal_read_secret($prompt);
    }

    public static function setTitle(string $title): bool
    {
        return terminal_set_title($title);
    }

    public static function supportsColor(): bool
    {
        return terminal_supports_color();
    }

    public static function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }

    public static function moveCursor(int $line, int $col): void
    {
        echo "\033[{$line};{$col}H";
    }

    public static function colorize(string $text, string $colorCode): string
    {
        if (!self::supportsColor()) {
            return $text;
        }
        return $colorCode . $text . self::COLOR_RESET;
    }
}
