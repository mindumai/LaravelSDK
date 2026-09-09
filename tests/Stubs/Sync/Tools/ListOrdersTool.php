<?php

// @mindum-handwritten
//
// Test fixture — a declared read tool for mindum:sync-tools.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Sync\Tools;

use Mindum\Laravel\Tools\GeneratedTool;

class ListOrdersTool extends GeneratedTool
{
    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return 'Lists recent orders.';
    }

    public function operationType(): ?string
    {
        return self::OPERATION_READ;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]];
    }

    protected function execute(array $input): mixed
    {
        return [];
    }
}
