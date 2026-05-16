<?php

// @mindum-generated
//
// Test fixture — echoes the `message` input back as text. Used by the MCP
// server tests to validate tools/list + tools/call wiring without touching
// any application database.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Mindum\Laravel\Tools\GeneratedTool;

class EchoTool extends GeneratedTool
{
    public function name(): string
    {
        return 'echo_tool';
    }

    public function description(): string
    {
        return 'Echoes the `message` input back, prefixed with "echo: ".';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->raw('message', ['type' => 'string', 'description' => 'Text to echo.'])->required();

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function execute(array $input): mixed
    {
        return 'echo: '.(string) ($input['message'] ?? '');
    }
}
