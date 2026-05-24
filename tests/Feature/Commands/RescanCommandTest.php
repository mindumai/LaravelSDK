<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

class RescanCommandTest extends TestCase
{
    private string $toolsPath;

    private Filesystem $files;

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $fixtureRoot = realpath(__DIR__.'/../../fixtures/laravel-app');
        $this->toolsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_rescan_'.bin2hex(random_bytes(6));

        $app->setBasePath($fixtureRoot);

        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_rescan');
        $app['config']->set('mindum.tools_path', $this->toolsPath);
        $app['config']->set('mindum.tools_namespace', 'App\\Mindum\\Tools');
        $app['config']->set('mindum.scan_paths', ['app/']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->toolsPath) && is_dir($this->toolsPath)) {
            $this->files->deleteDirectory($this->toolsPath);
        }
        parent::tearDown();
    }

    public function test_rescan_fails_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:rescan')
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->assertExitCode(1);
    }

    public function test_rescan_writes_files_and_reports_summary(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01R1', 'status' => 'queued', 'total_batches' => 1, 'estimated_seconds' => 83],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1, 'job_id' => '01R1'],
            ],
            'results' => [
                'job_id' => '01R1',
                'tools_count' => 1,
                'tools' => [[
                    'name' => 'find_post',
                    'description' => 'Find a post',
                    'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    'handle_code' => 'return null;',
                    'operation_type' => 'read',
                ]],
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        $this->artisan('mindum:rescan')
            ->expectsOutputToContain('mindum: 1 tool')
            ->assertExitCode(0);

        $this->assertFileExists($this->toolsPath.'/FindPost.php');
    }

    public function test_rescan_quiet_mode_skips_step_output(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01R2', 'status' => 'queued', 'total_batches' => 0],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 0, 'total_batches' => 0, 'tools_generated' => 0, 'job_id' => '01R2'],
            ],
            'results' => [
                'job_id' => '01R2',
                'tools_count' => 0,
                'tools' => [],
                'cost_summary' => ['input_tokens' => 0, 'output_tokens' => 0, 'approximate_usd' => 0.0],
            ],
        ]));

        $this->artisan('mindum:rescan', ['--quiet-output' => true])
            ->doesntExpectOutputToContain('scanner:')
            ->expectsOutputToContain('mindum: 0 tools')
            ->assertExitCode(0);
    }

    public function test_rescan_attaches_to_completed_undownloaded_job(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'completed',
                'job_id' => '01ATTACH',
                'batches_completed' => 1,
                'total_batches' => 1,
                'tools_generated' => 1,
            ],
            'results' => [
                'job_id' => '01ATTACH',
                'tools_count' => 1,
                'tools' => [[
                    'name' => 'recovered_tool',
                    'description' => 'Tool from a prior job',
                    'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    'handle_code' => 'return null;',
                    'operation_type' => 'read',
                ]],
                'cost_summary' => ['input_tokens' => 500, 'output_tokens' => 200, 'approximate_usd' => 0.005],
            ],
        ]));

        $this->artisan('mindum:rescan')
            ->expectsOutputToContain('attach:')
            ->expectsOutputToContain('mindum: 1 tool (attached)')
            ->assertExitCode(0);

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function routedResponder(array $scenario): callable
    {
        $pollIndex = 0;

        return function (HttpRequest $request) use (&$pollIndex, $scenario) {
            $url = $request->url();
            $method = $request->method();

            // MS7 — precheck (server-canonical estimate + affordability). Defaults to
            // can_proceed=true so existing Rescan tests don't have to opt in.
            if ($method === 'POST' && str_ends_with($url, '/api/analyze/precheck')) {
                return Http::response(array_merge([
                    'can_proceed' => true,
                    'model' => 'claude-sonnet-4-6',
                    'current_tier' => 'starter',
                    'candidate_count' => 1,
                    'estimated_batches' => 1,
                    'estimated_seconds' => 83,
                    'estimated_cost_cents' => 9,
                    'reserve_required_cents' => 30,
                    'balance_cents' => 2000,
                    'alternatives' => [],
                    'topup_suggestion_cents' => null,
                    'upgrade_to' => null,
                ], $scenario['precheck_returns'] ?? []), 200);
            }

            if ($method === 'GET' && str_ends_with($url, '/api/analyze/jobs/current')) {
                $current = $scenario['current_then'] ?? 204;
                if ($current === 204) {
                    return Http::response(null, 204);
                }

                return Http::response($this->mergePollDefaults($current), 200);
            }

            if ($method === 'GET' && str_contains($url, '/results')) {
                return Http::response($scenario['results'] ?? [], 200);
            }

            if ($method === 'GET' && str_contains($url, '/api/analyze/jobs/')) {
                $sequence = $scenario['poll_sequence'] ?? [];
                $next = $sequence[$pollIndex] ?? end($sequence);
                $pollIndex++;

                return Http::response($this->mergePollDefaults($next), 200);
            }

            if ($method === 'POST' && str_ends_with($url, '/api/analyze')) {
                return Http::response(array_merge([
                    'job_id' => '01ANY',
                    'status' => 'queued',
                    'total_batches' => 1,
                    'estimated_seconds' => 83,
                ], $scenario['post_returns'] ?? []), 202);
            }

            return Http::response(['error' => 'unhandled_route', 'url' => $url], 500);
        };
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function mergePollDefaults(array $partial): array
    {
        return array_merge([
            'job_id' => '01ANY',
            'status' => 'running',
            'batches_completed' => 0,
            'total_batches' => 1,
            'tools_generated' => 0,
            'started_at' => '2026-05-22T15:00:00Z',
            'completed_at' => null,
            'estimated_seconds_remaining' => 83,
            'tools_downloaded' => false,
            'error_message' => null,
            'results_url' => '/api/analyze/jobs/'.($partial['job_id'] ?? '01ANY').'/results',
        ], $partial);
    }
}
