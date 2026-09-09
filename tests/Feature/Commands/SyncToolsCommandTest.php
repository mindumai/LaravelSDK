<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * `mindum:sync-tools` (CC-D2/CC-D3, Docs/Concierge_Chat_Plan.md): registers
 * installed tool classes with the orchestrator without a scan, and refuses
 * while any tool has not declared operationType().
 */
class SyncToolsCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_abc123');
        $app['config']->set('mindum.tools_path', __DIR__.'/../../Stubs/Sync/Tools');
        $app['config']->set('mindum.tools_namespace', 'Mindum\\Laravel\\Tests\\Stubs\\Sync\\Tools');
    }

    public function test_syncs_declared_tools_with_their_operation_types(): void
    {
        Http::fake([
            'api.mindum.ai/api/tools/sync' => Http::response([
                'synced' => 2, 'created' => 1, 'updated' => 1, 'disabled' => ['old_tool'],
            ]),
        ]);

        $this->artisan('mindum:sync-tools')
            ->expectsOutputToContain('2 tool(s) ready to sync')
            ->expectsOutputToContain('Synced 2 tool(s)')
            ->expectsOutputToContain('old_tool')
            ->assertExitCode(0);

        Http::assertSent(function (HttpRequest $request) {
            $tools = $request->data()['tools'] ?? [];
            $byName = array_column($tools, null, 'name');

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/tools/sync')
                && $request->hasHeader('Authorization', 'Bearer mk_test_abc123')
                && count($tools) === 2
                && $byName['email_report']['operation_type'] === 'write'
                && $byName['list_orders']['operation_type'] === 'read'
                && $byName['list_orders']['input_schema']['properties']['limit']['type'] === 'integer'
                && str_ends_with($byName['email_report']['source_class'], 'EmailReportTool');
        });
    }

    public function test_dry_run_lists_tools_without_calling_the_api(): void
    {
        Http::fake();

        $this->artisan('mindum:sync-tools', ['--dry-run' => true])
            ->expectsOutputToContain('2 tool(s) ready to sync')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_refuses_when_a_tool_has_not_declared_operation_type(): void
    {
        // The MCP stubs predate CC-D3 and declare nothing.
        config()->set('mindum.tools_path', __DIR__.'/../../Stubs/Mcp/Tools');
        config()->set('mindum.tools_namespace', 'Mindum\\Laravel\\Tests\\Stubs\\Mcp\\Tools');
        Http::fake();

        $this->artisan('mindum:sync-tools')
            ->expectsOutputToContain('Refusing to sync')
            ->expectsOutputToContain('do not declare operationType()')
            ->expectsOutputToContain('AddNumbersTool')
            ->expectsOutputToContain('EchoTool')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_fails_when_no_tools_are_installed(): void
    {
        config()->set('mindum.tools_path', sys_get_temp_dir().'/mindum_none_'.bin2hex(random_bytes(4)));

        $this->artisan('mindum:sync-tools')
            ->expectsOutputToContain('No Mindum tools found')
            ->assertExitCode(1);
    }

    public function test_reports_api_errors_without_a_stack_trace(): void
    {
        Http::fake([
            'api.mindum.ai/api/tools/sync' => Http::response(['message' => 'nope'], 422),
        ]);

        $this->artisan('mindum:sync-tools')
            ->expectsOutputToContain('Mindum API returned HTTP 422')
            ->assertExitCode(1);
    }
}
