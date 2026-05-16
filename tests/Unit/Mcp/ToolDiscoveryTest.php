<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit\Mcp;

use Mindum\Laravel\Mcp\ToolDiscovery;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

class ToolDiscoveryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.tools_path', __DIR__.'/../../Stubs/Mcp/Tools');
        $app['config']->set('mindum.tools_namespace', 'Mindum\\Laravel\\Tests\\Stubs\\Mcp\\Tools');
    }

    public function test_discover_returns_only_generated_tool_subclasses(): void
    {
        $tools = ToolDiscovery::discover();

        // Sorted ascending; NotATool must NOT appear even though it lives in the same dir.
        $this->assertSame([
            'Mindum\\Laravel\\Tests\\Stubs\\Mcp\\Tools\\AddNumbersTool',
            'Mindum\\Laravel\\Tests\\Stubs\\Mcp\\Tools\\EchoTool',
        ], $tools);
    }

    public function test_discover_returns_empty_when_path_missing(): void
    {
        config()->set('mindum.tools_path', sys_get_temp_dir().'/mindum_does_not_exist_'.bin2hex(random_bytes(4)));

        $this->assertSame([], ToolDiscovery::discover());
    }

    public function test_discover_returns_empty_when_namespace_unset(): void
    {
        config()->set('mindum.tools_namespace', '');

        $this->assertSame([], ToolDiscovery::discover());
    }
}
