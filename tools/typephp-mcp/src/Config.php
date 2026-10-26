<?php

/**
 * Default repository root resolution.
 *
 * Detection order at runtime (see RepoRoot::detect()):
 *   1. TYPEPHP_MCP_ROOT environment variable
 *   2. an upward walk from the current working directory
 *   3. the source-relative default below
 *
 * The previous build-time constant pinned a developer machine path
 * (D:\git\php\aot-compiler) that is meaningless on any other machine and in
 * released artifacts. The source-relative default works whenever the server
 * runs interpreted from a checkout, on any machine. A deployed AOT binary
 * bakes in the path of the machine it was built on, so it MUST set
 * TYPEPHP_MCP_ROOT; RepoRoot::detect() fails with an actionable message
 * instead of guessing a wrong root.
 *
 * Distinction: the repository root found here is the TOOL RESOURCE directory
 * (docs/, bin/tpc.php, the compiler checkout). The user's own project is a
 * separate concept - it is discovered via TYPEPHP_MCP_PROJECT_ROOT, MCP
 * roots, or cwd auto-detection (ProjectDetector), never from this default.
 */
final class Config
{
    /**
     * Last-resort repository root: three levels above this file's directory
     * (tools/typephp-mcp/src -> repo root).
     */
    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
