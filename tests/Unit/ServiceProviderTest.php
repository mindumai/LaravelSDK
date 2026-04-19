<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit;

use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Sanity-check test. Verifies the service provider boots without errors
 * in a testbench Laravel app. Scaffolding-era placeholder; real test
 * coverage lands in Phase B.
 */
class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    public function test_service_provider_boots_without_errors(): void
    {
        $this->assertTrue($this->app->bound('config'));
        $this->assertNotNull(config('mindum'));
        $this->assertEquals('/mindum/mcp', config('mindum.mcp_endpoint'));
    }

    public function test_config_is_loaded_with_defaults(): void
    {
        $this->assertIsArray(config('mindum.scan_paths'));
        $this->assertContains('app/', config('mindum.scan_paths'));
        $this->assertEquals('App\\Mindum\\Tools', config('mindum.tools_namespace'));
    }
}
