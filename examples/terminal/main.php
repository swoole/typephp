<?php

function main(int $argc, array $argv): void
{
    $title = 'TypePHP Native Cross-Platform Terminal Demo';
    Terminal::setTitle($title);

    $isTty = Terminal::isInteractive();
    $size = Terminal::getSize();
    $width = $size['width'];
    $height = $size['height'];
    $os = PHP_OS_FAMILY;

    // Draw header box
    $bannerWidth = min(max($width - 4, 40), 72);
    $border = str_repeat('─', $bannerWidth);

    echo "\n";
    echo Terminal::colorize("┌{$border}┐\n", Terminal::FG_CYAN);
    echo Terminal::colorize('│ ' . str_pad($title, $bannerWidth - 2) . " │\n", Terminal::FG_CYAN . Terminal::COLOR_BOLD);
    echo Terminal::colorize("└{$border}┘\n", Terminal::FG_CYAN);

    echo "\n" . Terminal::colorize('Platform & Terminal Details:', Terminal::COLOR_BOLD) . "\n";
    echo '  • Operating System : ' . Terminal::colorize($os, Terminal::FG_GREEN) . ' (' . PHP_OS . ")\n";
    echo '  • Interactive TTY  : ' . ($isTty ? Terminal::colorize('Yes (stdout is a tty)', Terminal::FG_GREEN) : Terminal::colorize('No (redirected/pipe)', Terminal::FG_YELLOW)) . "\n";
    echo '  • Dimensions       : ' . Terminal::colorize("{$width} columns × {$height} lines", Terminal::FG_CYAN) . "\n";
    echo '  • ANSI Colors      : ' . (Terminal::supportsColor() ? Terminal::colorize('Supported', Terminal::FG_GREEN) : 'Disabled') . "\n";

    echo "\n" . Terminal::colorize('1. Testing Native Secret Input (No Echo):', Terminal::COLOR_BOLD) . "\n";
    echo 'Enter a secret password (typing will not be echoed): ';
    $secret = Terminal::readSecret();
    $masked = str_repeat('*', strlen($secret));
    echo '  Secret captured: ' . Terminal::colorize($masked . ' (' . strlen($secret) . ' chars)', Terminal::FG_YELLOW) . "\n";

    echo "\n" . Terminal::colorize('2. Testing Native Raw Mode (Instant Key Press):', Terminal::COLOR_BOLD) . "\n";
    echo "Press any single key (no Enter needed, 'q' to finish): ";

    if (Terminal::enableRawMode()) {
        $key = Terminal::readChar();
        Terminal::disableRawMode();

        $keyDisplay = ($key === "\n" || $key === "\r") ? '<ENTER>' : (($key === "\t") ? '<TAB>' : (($key === "\033") ? '<ESC>' : $key));
        echo "\n  Received key: " . Terminal::colorize($keyDisplay, Terminal::FG_MAGENTA . Terminal::COLOR_BOLD) . " (code: " . ord($key) . ")\n";
    } else {
        echo "\n  Raw mode not available in current environment.\n";
    }

    echo "\n" . Terminal::colorize('✔ Native terminal operations completed successfully.', Terminal::FG_GREEN . Terminal::COLOR_BOLD) . "\n\n";
}
