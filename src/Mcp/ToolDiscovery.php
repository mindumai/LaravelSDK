<?php

declare(strict_types=1);

namespace Mindum\Laravel\Mcp;

use Mindum\Laravel\Tools\GeneratedTool;

/**
 * Discovers the GeneratedTool subclasses installed at `mindum.tools_path`.
 *
 * Shared between MindumMcpServer (which must registers them at boot) and
 * StatusCommand (which reports how many are wired up).
 *
 * A file is only considered a tool when:
 *   - it lives directly in `tools_path` (no recursion),
 *   - the class FQCN (derived from `tools_namespace` + filename) autoloads, and
 *   - the class extends GeneratedTool.
 *
 * Files that fail any of those checks are silently skipped — user-owned
 * helpers and stale orphans don't get registered with the MCP server.
 */
final class ToolDiscovery
{
    /**
     * @return list<class-string<GeneratedTool>>
     */
    public static function discover(): array
    {
        $path = (string) config('mindum.tools_path', '');
        $namespace = trim((string) config('mindum.tools_namespace', ''), '\\');

        if ($path === '' || $namespace === '' || ! is_dir($path)) {
            return [];
        }

        $tools = [];

        foreach (glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $className = basename($file, '.php');
            $fqcn = $namespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                continue;
            }

            if (! is_subclass_of($fqcn, GeneratedTool::class)) {
                continue;
            }

            /** @var class-string<GeneratedTool> $fqcn */
            $tools[] = $fqcn;
        }

        sort($tools);

        return $tools;
    }
}
