<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../examples/terminal/php-src/Terminal.php';

final class TerminalExampleTest extends TestCase
{
    public function testTerminalConstants(): void
    {
        self::assertSame(0, Terminal::STDIN);
        self::assertSame(1, Terminal::STDOUT);
        self::assertSame(2, Terminal::STDERR);
        self::assertSame("\033[0m", Terminal::COLOR_RESET);
    }

    public function testGetDimensionsFallback(): void
    {
        $size = Terminal::getSize();
        self::assertIsArray($size);
        self::assertArrayHasKey('width', $size);
        self::assertArrayHasKey('height', $size);
        self::assertGreaterThanOrEqual(1, $size['width']);
        self::assertGreaterThanOrEqual(1, $size['height']);
    }

    public function testIsInteractiveReturnsBool(): void
    {
        self::assertIsBool(Terminal::isInteractive());
    }

    public function testSupportsColorReturnsBool(): void
    {
        self::assertIsBool(Terminal::supportsColor());
    }

    public function testColorizeAppliesAnsiCodes(): void
    {
        $colored = Terminal::colorize('hello', Terminal::FG_GREEN);
        if (Terminal::supportsColor()) {
            self::assertSame("\033[32mhello\033[0m", $colored);
        } else {
            self::assertSame('hello', $colored);
        }
    }

    public function testMoveCursorFormatting(): void
    {
        ob_start();
        Terminal::moveCursor(10, 20);
        $output = ob_get_clean();
        self::assertSame("\033[10;20H", $output);
    }
}
