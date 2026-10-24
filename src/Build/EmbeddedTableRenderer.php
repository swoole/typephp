<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

final class EmbeddedTableRenderer
{
    public function render(
        EmbeddedArchive $archive,
        string $phpVersion,
        bool $windows,
        bool $emitEmptyRuntimeHooks = true,
        ?string $entryFile = null,
    ): string
    {
        $code = '#include <typephp_opcode_table.h>' . PHP_EOL;
        if (!$archive->isEmpty()) {
            $code .= $windows
                ? 'static const uint8_t *const typephp_embedded_archive_start = typephp_embedded_archive_data();' . PHP_EOL
                : 'extern "C" const uint8_t typephp_embedded_archive_start[];' . PHP_EOL;
        }
        $code .= $this->renderTable(
            'typephp_opcode_entry',
            'typephp_opcodes',
            'typephp_project_opcode_table',
            $archive->opcodeIndex,
        );
        $code .= $this->renderTable(
            'typephp_embedded_file_entry',
            'typephp_embedded_files',
            'typephp_project_embedded_file_table',
            $archive->fileIndex,
        );
        $version = json_encode($phpVersion, JSON_THROW_ON_ERROR);
        $code .= 'extern "C" const char *typephp_project_php_version(void) { return ' . $version . '; }' . PHP_EOL;
        $entry = $entryFile === null
            ? 'nullptr'
            : json_encode($entryFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $code .= 'extern "C" const char *typephp_project_entry_file(void) { return ' . $entry . '; }' . PHP_EOL;
        if ($archive->isEmpty() && $emitEmptyRuntimeHooks) {
            $code .= 'extern "C" void typephp_opcode_table_install(void) {}' . PHP_EOL;
            $code .= 'extern "C" void typephp_opcode_table_uninstall(void) {}' . PHP_EOL;
            $code .= 'extern "C" void typephp_opcode_table_request_startup(void) {}' . PHP_EOL;
            $code .= 'extern "C" void typephp_opcode_table_request_shutdown(void) {}' . PHP_EOL;
        }
        return $code;
    }

    /** @param array<string, array{int, int}> $index */
    private function renderTable(string $type, string $name, string $accessor, array $index): string
    {
        $code = "static const {$type} {$name}[] = {" . PHP_EOL;
        foreach ($index as $path => [$offset, $length]) {
            $encodedPath = json_encode(
                $path,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $code .= "    {{$encodedPath}, typephp_embedded_archive_start + {$offset}, {$length}}," . PHP_EOL;
        }
        $code .= '    {nullptr, nullptr, 0},' . PHP_EOL . '};' . PHP_EOL;
        $code .= "extern \"C\" const {$type} *{$accessor}(size_t *count) {" . PHP_EOL;
        $code .= '    *count = ' . count($index) . "; return {$name};" . PHP_EOL . '}' . PHP_EOL;
        return $code;
    }
}
