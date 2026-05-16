<?php

// @mindum-generated
//
// Test fixture — adds two integers. Exercises a tool with a non-trivial
// return type (array result wrapped to JSON) plus multiple required
// properties on the input schema.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
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

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->raw('a', ['type' => 'integer'])->required();
        $schema->raw('b', ['type' => 'integer'])->required();

        return $schema;
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
