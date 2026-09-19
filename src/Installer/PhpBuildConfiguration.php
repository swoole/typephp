<?php

namespace TypePhp\Installer;

final class PhpBuildConfiguration
{
    /** @return list<string> */
    public static function parseShellWords(string $value): array
    {
        $words = [];
        $word = '';
        $quote = null;
        $wordStarted = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];
            if ($quote === null) {
                if (str_contains(" \t\r\n\v\f", $char)) {
                    if ($wordStarted) {
                        $words[] = $word;
                        $word = '';
                        $wordStarted = false;
                    }
                    continue;
                }
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $wordStarted = true;
                    continue;
                }
                if ($char === '\\') {
                    if (++$index >= $length) {
                        throw new \InvalidArgumentException('Incomplete escape sequence in configure options');
                    }
                    $word .= $value[$index];
                    $wordStarted = true;
                    continue;
                }
                $word .= $char;
                $wordStarted = true;
                continue;
            }

            if ($char === $quote) {
                $quote = null;
                continue;
            }
            if ($quote === '"' && $char === '\\' && $index + 1 < $length
                && str_contains('\"$`', $value[$index + 1])
            ) {
                $word .= $value[++$index];
                continue;
            }
            $word .= $char;
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException('Unterminated quote in configure options');
        }
        if ($wordStarted) {
            $words[] = $word;
        }
        return $words;
    }

    /** @return list<string> */
    public static function parsePhpConfigOptions(string $value): array
    {
        $options = [];
        foreach (self::parseShellWords($value) as $option) {
            // php-config --configure-options loses the quoting of trailing build
            // assignments. Once the first assignment is reached, tokens that follow
            // may be either part of its value or another assignment, so none of them
            // can safely be reused as configure arguments.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $option)) {
                break;
            }
            $options[] = $option;
        }
        return $options;
    }

    /**
     * Autoconf installation directories, program name transforms and cache files.
     *
     * A distribution build points these outside its own --prefix (--mandir=/usr/share/man,
     * --includedir=/usr/include) or renames the installed binaries (--program-suffix=8.3).
     * Inheriting them makes `make install` write to system paths the user cannot own,
     * and hides bin/php behind a versioned name. Autoconf derives every one of them
     * from --prefix when it is absent, so dropping them keeps the private build
     * entirely inside the requested prefix.
     *
     * The versioned name is the Debian and Ubuntu packaging scheme, not a PPA
     * addition: php8.3-dev in noble-updates/main carries --program-suffix=8.3
     * and --mandir=/usr/share/man, and ppa:ondrej/php repeats it per version.
     *
     * @var list<string>
     */
    private const array PREFIX_DERIVED = [
        '--exec-prefix', '--bindir', '--sbindir', '--libexecdir', '--sysconfdir',
        '--sharedstatedir', '--localstatedir', '--runstatedir', '--libdir',
        '--includedir', '--oldincludedir', '--datarootdir', '--datadir',
        '--infodir', '--localedir', '--mandir', '--docdir', '--htmldir',
        '--dvidir', '--pdfdir', '--psdir',
        '--program-prefix', '--program-suffix', '--program-transform-name',
        // A cache recorded for the distribution prefix answers the wrong questions
        // here, and its path may not even exist on this machine.
        '--cache-file', '--config-cache',
    ];

    /**
     * @param string|list<string> $configureOptions
     * @return list<string>
     */
    public static function derive(string|array $configureOptions, string $prefix): array
    {
        $replace = [
            '--prefix', '--with-config-file-path', '--with-config-file-scan-dir',
            '--enable-embed', '--enable-cli', '--disable-cli', '--with-libdir',
        ];
        $drop = [
            '--with-apxs', '--with-apxs2', '--enable-fpm', '--with-fpm-systemd',
            ...self::PREFIX_DERIVED,
        ];
        $result = [];
        $options = is_string($configureOptions) ? self::parseShellWords($configureOptions) : $configureOptions;
        foreach ($options as $option) {
            // Build assignments are not PHP feature configuration. Keep only long
            // configure options; callers can override CFLAGS through the environment.
            if (!str_starts_with($option, '--')) {
                continue;
            }
            $name = explode('=', $option, 2)[0];
            if (in_array($name, $replace, true) || in_array($name, $drop, true)) {
                continue;
            }
            $result[] = $option;
        }
        return [
            '--prefix=' . $prefix,
            '--with-config-file-path=' . $prefix . '/lib',
            '--with-config-file-scan-dir=' . $prefix . '/lib/conf.d',
            '--enable-embed=shared',
            '--enable-cli',
            ...$result,
        ];
    }
}
