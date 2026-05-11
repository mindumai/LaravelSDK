<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Mindum\Laravel\Api\MindumApiClient;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * Exercises MindumApiClient with a faked HTTP transport. Confirms the
 * client shapes requests correctly and parses both happy-path and
 * error responses.
 */
class MindumApiClientTest extends TestCase
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

    public function test_posts_manifest_with_bearer_token(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'status' => 'ok',
                'manifest_id' => 42,
                'manifest_hash' => 'deadbeef',
                'tool_count' => 1,
                'cached' => false,
                'tools' => [['name' => 'create_post']],
                'stats' => ['batches' => 1],
            ], 200),
        ]);

        $client = new MindumApiClient;
        $result = $client->analyze(['entries' => [['kind' => 'action', 'id' => 'create_post']]]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(42, $result['manifest_id']);
        $this->assertCount(1, $result['tools']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer mk_test_abc123')
                && str_contains($request->url(), 'api.mindum.ai/api/analyze')
                && is_array($request['manifest'])
                && isset($request['manifest']['entries']);
        });
    }

    public function test_throws_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mindum API key not configured');

        (new MindumApiClient)->analyze(['entries' => []]);
    }

    public function test_throws_with_meaningful_message_on_http_error(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'error' => 'invalid_api_key',
                'message' => 'The provided API key was not recognized.',
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        (new MindumApiClient)->analyze(['entries' => [['kind' => 'action']]]);
    }

    public function test_throws_when_response_missing_tools_array(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'status' => 'ok',
                'error' => 'manifest invalid',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected the manifest');

        (new MindumApiClient)->analyze(['entries' => []]);
    }

    public function test_honors_custom_api_url(): void
    {
        config()->set('mindum.api_url', 'http://localhost:8000/');

        Http::fake([
            'localhost:8000/*' => Http::response([
                'status' => 'ok',
                'tools' => [],
            ], 200),
        ]);

        (new MindumApiClient)->analyze(['entries' => []]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'localhost:8000/api/analyze'));
    }

    public function test_cached_response_flag_is_propagated(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'status' => 'ok',
                'manifest_id' => 1,
                'manifest_hash' => 'abc',
                'tool_count' => 0,
                'cached' => true,
                'tools' => [],
                'stats' => [],
            ], 200),
        ]);

        $result = (new MindumApiClient)->analyze(['entries' => []]);

        $this->assertTrue($result['cached']);
    }
}
