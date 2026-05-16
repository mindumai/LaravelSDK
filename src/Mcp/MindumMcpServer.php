<?php

declare(strict_types=1);

namespace Mindum\Laravel\Mcp;

use Laravel\Mcp\Server;

/**
 * The MCP server that the Mindum orchestrator connects to.
 *
 * On boot, registers every GeneratedTool subclass discovered under
 * `mindum.tools_path` via ToolDiscovery. The set is recomputed on each
 * request because `MindumMcpServer` is freshly instantiated by the route
 * closure — rescans show up without restarting the app.
 */
final class MindumMcpServer extends Server
{
    public string $serverName = 'Mindum';

    public string $serverVersion = '0.1.0';

    public string $instructions = 'Mindum-generated tools exposing the host Laravel application to the Mindum orchestrator.';

    public function boot(): void
    {
        foreach (ToolDiscovery::discover() as $toolClass) {
            $this->addTool($toolClass);
        }
    }
}
