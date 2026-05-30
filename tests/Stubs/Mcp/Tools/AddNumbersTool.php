<?php

// @mindum-generated
//
// Test fixture — adds two integers. Exercises a tool with a non-trivial
// return type (array result wrapped to JSON) plus multiple required
// properties on the input schema.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Mindum\Laravel\Tools\GeneratedTool;

class AddNumbersTool extends GeneratedTool
{
    public function name(): string
    {
        return 'add_numbers';
    }

    public function description(): string
    {
        return 'Returns the sum of two integers.';
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'a' => $schema->integer()->required(),
            'b' => $schema->integer()->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function execute(array $input): mixed
    {
        return [
            'sum' => (int) ($input['a'] ?? 0) + (int) ($input['b'] ?? 0),
        ];
    }
}
