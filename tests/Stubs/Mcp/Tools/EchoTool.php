<?php

// @mindum-generated
//
// Test fixture — echoes the `message` input back as text. Used by the MCP
// server tests to validate tools/list + tools/call wiring without touching
// any application database.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
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

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('Text to echo.')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function execute(array $input): mixed
    {
        return 'echo: '.(string) ($input['message'] ?? '');
    }
}
