<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Widget;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * SDKM-D5 (Docs/SDK_Own_MCP_Plan.md) — the token route is the widget's
 * enforcement boundary, guarded by HOST-app middleware, not SDK-invented
 * roles. This suite locks the Laravel dialect of that contract:
 * `mindum.widget.token_middleware` is applied to the auto-registered
 * route; empty config keeps the route public (pre-D5 behavior).
 */
class WidgetTokenMiddlewareTest extends TestCase
{
    private const ENDPOINT = '/mindum/widget/token';

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_middleware_key');
        $app['config']->set('mindum.widget.token_endpoint', self::ENDPOINT);
        $app['config']->set('mindum.widget.token_middleware', [DenyingGuard::class]);
    }

    public function test_configured_middleware_guards_the_mint_route(): void
    {
        // The guard rejects like a host app's admin gate would — the mint
        // must never happen and the upstream must never be called.
        Http::fake();

        $this->postJson(self::ENDPOINT, ['session_id' => 'sess-guarded'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'host_guard_rejected');

        Http::assertNothingSent();
    }

    public function test_middleware_list_is_attached_to_the_registered_route(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->getName() === 'mindum.widget.token',
        );

        $this->assertNotNull($route);
        $this->assertContains(DenyingGuard::class, $route->gatherMiddleware());
    }
}

/**
 * Stand-in for a customer's auth/role middleware (e.g. auth + can:admin).
 */
class DenyingGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        return response()->json(['error' => 'host_guard_rejected'], 403);
    }
}
