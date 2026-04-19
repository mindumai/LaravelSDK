<?php

declare(strict_types=1);

namespace Mindum\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Mindum Laravel SDK service provider.
 *
 * Auto-discovered by Laravel via composer.json's extra.laravel.providers.
 * Handles config publishing, command registration, and MCP tool registration
 * once the customer has run `mindum:install`.
 */
class MindumServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->registerConfig();
        $this->registerCommands();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mindum.php',
            'mindum',
        );
    }

    /**
     * Publish the package config to the host app's config directory.
     */
    protected function registerConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mindum.php' => config_path('mindum.php'),
            ], 'mindum-config');
        }
    }

    /**
     * Register Artisan commands (populated as Phase E lands each command).
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            // Commands added in Phase E:
            // Commands\InstallCommand::class,
            // Commands\RescanCommand::class,
            // Commands\StatusCommand::class,
            // Commands\ChatCommand::class,
        ]);
    }
}
