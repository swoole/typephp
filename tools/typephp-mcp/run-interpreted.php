<?php

/**
 * Interpreted entry point for typephp-mcp.
 *
 * Loads the same sources the AOT build compiles (project.yml sources:
 * main.php + src/) so the stdio smoke checks can run against plain PHP and
 * be compared with the compiled binary - the sources are kept inside the
 * stock-PHP/TypePHP intersection on purpose.
 *
 * Usage: php tools/typephp-mcp/run-interpreted.php
 */

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/TextUtil.php';
require_once __DIR__ . '/src/RepoRoot.php';
require_once __DIR__ . '/src/Restrictions.php';
require_once __DIR__ . '/src/DocIndex.php';
require_once __DIR__ . '/src/CodeIndex.php';
require_once __DIR__ . '/src/CompilerOracle.php';
require_once __DIR__ . '/src/IndexInfo.php';
require_once __DIR__ . '/src/ToolDefinitions.php';
require_once __DIR__ . '/src/McpServer.php';
require_once __DIR__ . '/src/ProjectDetector.php';
require_once __DIR__ . '/main.php';

exit(main());
