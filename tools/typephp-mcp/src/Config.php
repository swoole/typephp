<?php

/**
 * Build-time default repository root.
 *
 * Detection order at runtime: TYPEPHP_MCP_ROOT env var, then an upward walk
 * from the current working directory, then this constant. The constant only
 * matters when the binary is started outside the repository.
 */
const TYPEPHP_MCP_DEFAULT_ROOT = 'D:\\git\\php\\aot-compiler';
