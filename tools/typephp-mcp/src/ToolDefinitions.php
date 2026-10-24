<?php

final class ToolDefinitions
{
    public const SERVER_VERSION = '1.0.0';

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'check_code',
                'description' => "Validate a PHP file or project.yml with the TypePHP compiler (`tpc --dry`, C++ generation only; link errors are not covered). Returns diagnostics with file:line, or OK. ALWAYS call this before submitting TypePHP code.\n\n"
                    . Restrictions::summary(),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Absolute path to a .php file or project.yml, or a path relative to the repository root.',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'list_restrictions',
                'description' => "List the TypePHP restrictions (what stock PHP allows but TypePHP rejects). Call BEFORE writing TypePHP code. Topics: structure, types, refs, objects, control, runtime, std, stub, build; empty topic returns the full summary.\n\n"
                    . Restrictions::summary(),
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => [
                            'type' => 'string',
                            'description' => 'One of: structure, types, refs, objects, control, runtime, std, stub, build. Empty or "all" returns everything.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'get_symbol',
                'description' => 'Find a function/class/interface/trait/enum/const declaration by exact name. Returns signature + file:line from src/, examples/ and tests/std/. Pass dirs to search your own project instead of the bundled repo.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Exact symbol name, e.g. main, array_push, stdMap.'],
                        'dirs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional list of absolute directories to search INSTEAD of the bundled aot-compiler repo (e.g. your project src plus vendor/swoole/typephp). Empty/absent searches the official repo; vendor/node_modules are NOT skipped when you pass dirs.',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_stdlib',
                'description' => 'List the stdlib test categories under tests/std (array, core, dir, file, strings, ...) with real usage tests. With a category, lists the tests and their titles.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'Optional category directory name, e.g. array, strings, network.'],
                    ],
                ],
            ],
            [
                'name' => 'get_example',
                'description' => 'Return a runnable example: a single file (name without .php) or a directory (file list plus README/main.php/project.yml excerpts). By default searches examples/ in the bundled repo; pass dirs to look in your own project instead.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Example name, e.g. hello, shop-fpm, qt-taskboard, bigint.'],
                        'dirs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional list of absolute directories to search INSTEAD of the bundled examples/ (e.g. your project root). Empty/absent searches the official repo; vendor/node_modules are NOT skipped when you pass dirs.',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_stub_api',
                'description' => 'Inspect .stub.php declarations (the C++/PHP ABI contract). Without a path, lists all stub files; with a path, returns the declarations of that file. By default scopes to the bundled repo; pass dirs to list/read stubs in your own project (e.g. vendor/swoole/typephp).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Path to a .stub.php file, relative to a dir in dirs (or repo-relative when dirs is empty), e.g. examples/qt-taskboard/php-src/taskboard.stub.php.'],
                        'dirs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional list of absolute directories to search INSTEAD of the bundled repo. Empty/absent searches the official repo; vendor/node_modules are NOT skipped when you pass dirs.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'search_docs',
                'description' => 'Full-text search over docs/zh-cn and docs/en. Returns chunks with docs/<lang>/<file>.md#<heading> (L<line>) citations. Bilingual: lang selects zh-cn (default), en, or all.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Keywords, e.g. "std::bigInt", "property hook", "embedded-files".'],
                        'lang' => ['type' => 'string', 'description' => 'zh-cn (default), en, or all.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max chunks to return (default 5, max 10).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'search_code',
                'description' => 'Grep for a code snippet or API name. By default searches examples/, tests/std/ and src/ of the bundled repo; pass dirs to search your own project (and its Composer-installed framework). Returns file:line plus the enclosing declaration (max 3 hits per file).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Text to find, e.g. "std::orderedMap", "proc_open", "JSON_THROW_ON_ERROR".'],
                        'limit' => ['type' => 'integer', 'description' => 'Max hits (default 15, max 30).'],
                        'dirs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional list of absolute directories to search INSTEAD of the bundled repo (e.g. your project src plus vendor/swoole/typephp). Empty/absent searches the official repo; vendor/node_modules are NOT skipped when you pass dirs.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_changelog',
                'description' => 'Read CHANGELOG.md: latest section, or the section for a specific version. Use before relying on behavior that may have changed (e.g. native scalar storage default in 0.8.0).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'string', 'description' => 'Version prefix, e.g. 0.8. Empty returns the latest section.'],
                    ],
                ],
            ],
            [
                'name' => 'get_build_config_help',
                'description' => 'Explain project.yml and the three build modes (bin/lib/ext): sources, ignore, objects, include-paths, defines, link-paths, link-libs, ext-deps, embedded-files, entry, sapi; CLI flags (--dry, -O, -m, --sapi, --php-builder); and the .stub.php contract.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name' => 'list_topics',
                'description' => 'List all documentation topics (docs/zh-cn and docs/en) with their titles, so you know what can be searched with search_docs.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name' => 'get_index_info',
                'description' => 'Index metadata: repo root, tpc version, git commit, doc counts, tool list. Use to detect staleness (version drift is a top risk with this young compiler).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $tool) {
            $names[] = $tool['name'];
        }
        return $names;
    }
}
