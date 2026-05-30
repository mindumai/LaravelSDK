<?php

declare(strict_types=1);

namespace Mindum\Laravel\Mcp;

use Laravel\Mcp\Server;

/**
 * The MCP server that the Mindum orchestrator connects to.
 *
 * On boot, registers every GeneratedTool subclass discovered under
 * `mindum.tools_path` via ToolDiscovery. The set is recomputed on each
 * request because a fresh server is instantiated per request (laravel/mcp
 * 0.5+ builds it through the container for each HTTP call) — rescans show
 * up without restarting the app.
 *
 * laravel/mcp 0.5+ reads the server identity from the protected $name /
 * $version / $instructions properties and the tool list from the $tools
 * array (populated in boot()).
 */
final class MindumMcpServer extends Server
{
    protected string $name = 'Mindum';

    protected string $version = '0.1.0';

    protected string $instructions = 'Mindum-generated tools exposing the host Laravel application to the Mindum orchestrator.';

    protected function boot(): void
    {
        foreach (ToolDiscovery::discover() as $toolClass) {
            $this->tools[] = $toolClass;
        }
    }
}
