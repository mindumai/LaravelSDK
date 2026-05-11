<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

class ChatCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    public function test_chat_is_registered_and_prints_post_mvp_notice(): void
    {
        $this->artisan('mindum:chat')
            ->expectsOutputToContain('coming after Milestone 1')
            ->expectsOutputToContain('php artisan tinker')
            ->assertExitCode(0);
    }
}
