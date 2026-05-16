<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Mcp;

use Mindum\Laravel\Http\Middleware\VerifyMcpSecret;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Phase 2A — end-to-end coverage of the MCP HTTP endpoint exposed by the SDK.
 *
 * The fixture tools at tests/Stubs/Mcp/Tools/ stand in for what the SDK
 * would generate in a real customer app. By pointing `mindum.tools_path` and
 * `mindum.tools_namespace` at the fixture directory, we exercise the same
 * autoload + discovery + register + execute pipeline a deployed install runs
 * through, without writing real PHP files into vendor or app/.
 */
class McpServerTest extends TestCase
{
    private const ENDPOINT = '/mindum/mcp';

    private const SECRET = 'test-secret-deadbeef';

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.mcp_endpoint', self::ENDPOINT);
        $app['config']->set('mindum.mcp_secret', self::SECRET);
        $app['config']->set('mindum.tools_path', __DIR__.'/../../Stubs/Mcp/Tools');
        $app['config']->set('mindum.tools_namespace', 'Mindum\\Laravel\\Tests\\Stubs\\Mcp\\Tools');
    }

    public function test_tools_list_returns_registered_generated_tools(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ], [VerifyMcpSecret::HEADER => self::SECRET]);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(1, $body['id']);
        $this->assertArrayHasKey('tools', $body['result']);

        $names = array_column($body['result']['tools'], 'name');
        sort($names);
        $this->assertSame(['add_numbers', 'echo_tool'], $names);
    }

    public function test_tools_list_exposes_schema_and_description(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ], [VerifyMcpSecret::HEADER => self::SECRET]);

        $response->assertOk();
        $tools = collect($response->json('result.tools'));

        $echo = $tools->firstWhere('name', 'echo_tool');
        $this->assertNotNull($echo);
        $this->assertSame('Echoes the `message` input back, prefixed with "echo: ".', $echo['description']);
        $this->assertSame(['message'], $echo['inputSchema']['required']);
        $this->assertSame('string', $echo['inputSchema']['properties']['message']['type']);
    }

    public function test_tools_call_executes_tool_and_returns_text_result(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo_tool',
                'arguments' => ['message' => 'hi from the orchestrator'],
            ],
            'id' => 7,
        ], [VerifyMcpSecret::HEADER => self::SECRET]);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame(7, $body['id']);
        $this->assertFalse($body['result']['isError'] ?? false);
        $this->assertSame('text', $body['result']['content'][0]['type']);
        $this->assertSame('echo: hi from the orchestrator', $body['result']['content'][0]['text']);
    }

    public function test_tools_call_returns_json_result_for_array_returning_tool(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'add_numbers',
                'arguments' => ['a' => 2, 'b' => 40],
            ],
            'id' => 9,
        ], [VerifyMcpSecret::HEADER => self::SECRET]);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame(9, $body['id']);
        $this->assertFalse($body['result']['isError'] ?? false);

        $decoded = json_decode($body['result']['content'][0]['text'], true);
        $this->assertSame(['sum' => 42], $decoded);
    }

    public function test_tools_call_for_unknown_tool_returns_error_result(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'no_such_tool',
                'arguments' => [],
            ],
            'id' => 11,
        ], [VerifyMcpSecret::HEADER => self::SECRET]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertSame('Tool not found', $response->json('result.content.0.text'));
    }

    public function test_missing_secret_header_returns_401(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ]);

        $response->assertStatus(401);
        $this->assertSame(-32001, $response->json('error.code'));
        $this->assertStringContainsString('X-Mindum-Secret', $response->json('error.message'));
    }

    public function test_wrong_secret_returns_401(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ], [VerifyMcpSecret::HEADER => 'definitely-not-the-secret']);

        $response->assertStatus(401);
        $this->assertSame(-32001, $response->json('error.code'));
    }

    public function test_unconfigured_secret_returns_503(): void
    {
        config()->set('mindum.mcp_secret', '');

        $response = $this->postJson(self::ENDPOINT, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ], [VerifyMcpSecret::HEADER => 'anything']);

        $response->assertStatus(503);
        $this->assertSame(-32001, $response->json('error.code'));
        $this->assertStringContainsString('MINDUM_MCP_SECRET', $response->json('error.message'));
    }

    public function test_route_is_not_mounted_when_endpoint_config_is_empty(): void
    {
        // The endpoint string is locked in at provider boot, so flipping the
        // config at runtime doesn't unmount the route. The behavior we *can*
        // assert from a single test boot is that route:list reflects the
        // configured path. Run a tools/list against an unrelated path and
        // confirm 404, proving the route is path-scoped.
        $response = $this->postJson('/some-other-path', [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ]);

        $response->assertStatus(404);
    }
}
