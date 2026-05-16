<?php

declare(strict_types=1);

namespace Mindum\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Mindum\Laravel\Commands\ChatCommand;
use Mindum\Laravel\Commands\InstallCommand;
use Mindum\Laravel\Commands\RescanCommand;
use Mindum\Laravel\Commands\StatusCommand;
use Mindum\Laravel\Http\Middleware\VerifyMcpSecret;
use Mindum\Laravel\Mcp\MindumMcpServer;

/**
 * Mindum Laravel SDK service provider.
 *
 * Auto-discovered by Laravel via composer.json's extra.laravel.providers.
 * Handles config publishing, command registration, and MCP HTTP-endpoint
 * registration (Phase 2A: customer's app exposes /mindum/mcp guarded by
 * a shared-secret header).
 */
class MindumServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerConfig();
        $this->registerCommands();
        $this->registerMcpServer();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mindum.php',
            'mindum',
        );
    }

    protected function registerConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mindum.php' => config_path('mindum.php'),
            ], 'mindum-config');
        }
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            RescanCommand::class,
            StatusCommand::class,
            ChatCommand::class,
        ]);
    }

    /**
     * Mount the MCP endpoint at the configured path. The route is registered
     * unconditionally (so route:list reflects reality), but the middleware
     * returns 503 when the secret is unset, so an empty install still hits
     * an instructive error rather than a 404.
     *
     * Empty `mcp_endpoint` config disables the route entirely.
     */
    protected function registerMcpServer(): void
    {
        $endpoint = ltrim((string) config('mindum.mcp_endpoint', ''), '/');

        if ($endpoint === '') {
            return;
        }

        Route::post($endpoint, function () {
            $transport = new HttpTransport(request());
            $server = new MindumMcpServer;
            $server->connect($transport);

            return $transport->run();
        })
            ->middleware(VerifyMcpSecret::class)
            ->name('mindum.mcp');
    }
}
