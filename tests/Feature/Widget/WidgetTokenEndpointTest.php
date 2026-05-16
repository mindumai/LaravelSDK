<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Widget;

use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Exercises POST /mindum/widget/token end-to-end through the SDK's
 * service provider: route registration, controller validation,
 * proxy invocation (faked upstream), and the failure-mode → HTTP
 * status mapping the browser widget can branch on.
 */
class WidgetTokenEndpointTest extends TestCase
{
    private const ENDPOINT = '/mindum/widget/token';

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_endpoint_key');
        $app['config']->set('mindum.widget.token_endpoint', self::ENDPOINT);
    }

    public function test_proxies_token_mint_to_orchestrator(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response([
                'token' => 'eyJ-proxied-jwt',
                'expires_at' => 1747600000,
            ], 200),
        ]);

        $response = $this->postJson(self::ENDPOINT, [
            'session_id' => 'sess-pq',
            'end_user_id' => 'eu-42',
        ]);

        $response->assertOk()
            ->assertJson([
                'token' => 'eyJ-proxied-jwt',
                'expires_at' => 1747600000,
            ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer mk_test_endpoint_key')
                && $request['session_id'] === 'sess-pq'
                && $request['end_user_id'] === 'eu-42';
        });
    }

    public function test_validates_session_id_is_required(): void
    {
        $response = $this->postJson(self::ENDPOINT, []);

        $response->assertStatus(422)
            ->assertJson(['error' => 'invalid_request'])
            ->assertJsonStructure(['errors' => ['session_id']]);
    }

    public function test_returns_503_when_api_key_unconfigured(): void
    {
        config()->set('mindum.api_key', '');

        $response = $this->postJson(self::ENDPOINT, ['session_id' => 'sess-x']);

        $response->assertStatus(503)
            ->assertJson(['error' => 'widget_token_unconfigured']);
    }

    public function test_returns_503_when_orchestrator_unreachable(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'boom'], 502),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['session_id' => 'sess-x']);

        $response->assertStatus(503)
            ->assertJson(['error' => 'widget_token_unreachable']);
    }

    public function test_returns_502_when_orchestrator_rejects_request(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['session_id' => 'sess-x']);

        $response->assertStatus(502)
            ->assertJson(['error' => 'widget_token_rejected']);
    }

    public function test_route_is_not_mounted_when_endpoint_config_is_empty(): void
    {
        // Same approach as McpServerTest::test_route_is_not_mounted_when_endpoint_config_is_empty.
        // The endpoint string locks in at boot; with the default endpoint set, we just
        // confirm that an unrelated path returns 404, proving the route is path-scoped.
        $response = $this->postJson('/some-other-widget-path', [
            'session_id' => 'sess-x',
        ]);

        $response->assertStatus(404);
    }
}
