<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Widget;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Mindum\Laravel\Widget\Exceptions\WidgetTokenMintException;
use Mindum\Laravel\Widget\WidgetTokenProxy;
use Orchestra\Testbench\TestCase;

/**
 * Exercises the server-side widget token proxy with a faked HTTP transport.
 * Confirms request shape (Bearer header, payload), and that each upstream
 * failure mode maps to the right WidgetTokenMintException reason so the
 * controller can pick an appropriate HTTP status.
 */
class WidgetTokenProxyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_abc123');
    }

    public function test_mints_token_against_orchestrator_widget_endpoint(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response([
                'token' => 'eyJ0eXA-fake-jwt',
                'expires_at' => 1747500000,
                'ws' => [
                    'key' => 'pub-key',
                    'host' => 'ws.example.test',
                    'port' => 443,
                    'scheme' => 'https',
                ],
            ], 200),
        ]);

        $result = (new WidgetTokenProxy)->mint('sess-abc', 'eu-7');

        $this->assertSame('eyJ0eXA-fake-jwt', $result['token']);
        $this->assertSame(1747500000, $result['expires_at']);
        $this->assertSame('pub-key', $result['ws']['key']);
        $this->assertSame('ws.example.test', $result['ws']['host']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer mk_test_abc123')
                && str_contains($request->url(), 'api.mindum.ai/api/widget/token')
                && $request['session_id'] === 'sess-abc'
                && $request['end_user_id'] === 'eu-7';
        });
    }

    public function test_omits_end_user_id_when_null(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response([
                'token' => 't',
                'expires_at' => 1,
            ], 200),
        ]);

        (new WidgetTokenProxy)->mint('sess-no-eu');

        Http::assertSent(function ($request) {
            return $request['session_id'] === 'sess-no-eu'
                && ! isset($request['end_user_id']);
        });
    }

    public function test_throws_unconfigured_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        try {
            (new WidgetTokenProxy)->mint('sess-x');
            $this->fail('Expected WidgetTokenMintException');
        } catch (WidgetTokenMintException $e) {
            $this->assertSame('unconfigured', $e->reason);
            $this->assertStringContainsString('MINDUM_API_KEY', $e->getMessage());
        }
    }

    public function test_throws_unreachable_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        try {
            (new WidgetTokenProxy)->mint('sess-x');
            $this->fail('Expected WidgetTokenMintException');
        } catch (WidgetTokenMintException $e) {
            $this->assertSame('unreachable', $e->reason);
            $this->assertStringContainsString('api.mindum.ai', $e->getMessage());
        }
    }

    public function test_throws_rejected_on_4xx_response(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        try {
            (new WidgetTokenProxy)->mint('sess-x');
            $this->fail('Expected WidgetTokenMintException');
        } catch (WidgetTokenMintException $e) {
            $this->assertSame('rejected', $e->reason);
            $this->assertSame(401, $e->upstreamStatus);
        }
    }

    public function test_throws_unreachable_on_5xx_response(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'boom'], 502),
        ]);

        try {
            (new WidgetTokenProxy)->mint('sess-x');
            $this->fail('Expected WidgetTokenMintException');
        } catch (WidgetTokenMintException $e) {
            $this->assertSame('unreachable', $e->reason);
            $this->assertSame(502, $e->upstreamStatus);
        }
    }

    public function test_throws_rejected_when_response_missing_required_fields(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['somethingElse' => true], 200),
        ]);

        try {
            (new WidgetTokenProxy)->mint('sess-x');
            $this->fail('Expected WidgetTokenMintException');
        } catch (WidgetTokenMintException $e) {
            $this->assertSame('rejected', $e->reason);
            $this->assertStringContainsString('token or expires_at', $e->getMessage());
        }
    }
}
