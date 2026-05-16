<?php

// Test fixture — a plain class that lives next to GeneratedTool subclasses
// but does NOT extend GeneratedTool. The discovery service must skip it.

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Mcp\Tools;

class NotATool
{
    public function ignored(): void {}
}
