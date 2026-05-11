<?php

declare(strict_types=1);

namespace Mindum\Laravel;

use Illuminate\Support\ServiceProvider;
use Mindum\Laravel\Commands\ChatCommand;
use Mindum\Laravel\Commands\InstallCommand;
use Mindum\Laravel\Commands\RescanCommand;
use Mindum\Laravel\Commands\StatusCommand;

/**
 * Mindum Laravel SDK service provider.
 *
 * Auto-discovered by Laravel via composer.json's extra.laravel.providers.
 * Handles config publishing, command registration, and (post-Phase E)
 * MCP tool registration.
 */
class MindumServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerConfig();
        $this->registerCommands();
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
}
