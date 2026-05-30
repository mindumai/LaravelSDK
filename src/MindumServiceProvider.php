<?php

declare(strict_types=1);

namespace Mindum\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Mindum\Laravel\Commands\ChatCommand;
use Mindum\Laravel\Commands\InstallCommand;
use Mindum\Laravel\Commands\RescanCommand;
use Mindum\Laravel\Commands\StatusCommand;
use Mindum\Laravel\Http\Controllers\WidgetTokenController;
use Mindum\Laravel\Http\Middleware\VerifyMcpSecret;
use Mindum\Laravel\Mcp\MindumMcpServer;
use Mindum\Laravel\View\Components\Widget;

/**
 * Mindum Laravel SDK service provider.
 *
 * Auto-discovered by Laravel via composer.json's extra.laravel.providers.
 * Handles config publishing, command registration, MCP HTTP-endpoint
 * registration (Phase 2A: /mindum/mcp guarded by a shared-secret header),
 * and the widget surface (Phase 2C: /mindum/widget/token proxy +
 * <x-mindum::widget /> Blade component).
 */
class MindumServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerConfig();
        $this->registerCommands();
        $this->registerMcpServer();
        $this->registerWidget();
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

        // laravel/mcp 0.5+ — Mcp::web() registers the spec-required GET (405)
        // plus the POST route and builds the HttpTransport (request, session-id)
        // internally, replacing the hand-rolled transport wiring that broke when
        // HttpTransport's constructor gained required arguments. We layer the
        // shared-secret guard on top and name the POST route for route:list.
        Mcp::web($endpoint, MindumMcpServer::class)
            ->middleware(VerifyMcpSecret::class)
            ->name('mindum.mcp');
    }

    /**
     * Mount the widget token-proxy endpoint and register the Blade component.
     *
     * The route is registered when `widget.token_endpoint` is set; the
     * controller itself surfaces a friendly 503 if the API key is unset,
     * so installs that haven't filled in credentials yet still get an
     * instructive error rather than a 404.
     *
     * The view namespace and Blade component are always registered (cheap,
     * and lets `<x-mindum::widget />` render an empty string when disabled
     * rather than throw a "component not found" error in dev).
     */
    protected function registerWidget(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mindum');
        Blade::componentNamespace('Mindum\\Laravel\\View\\Components', 'mindum');

        $endpoint = ltrim((string) config('mindum.widget.token_endpoint', ''), '/');

        if ($endpoint === '') {
            return;
        }

        Route::post($endpoint, WidgetTokenController::class)->name('mindum.widget.token');
    }
}
