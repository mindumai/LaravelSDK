<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * End-to-end install flow tests against a faked Mindum API. Covers the
 * three idempotent attach states (no existing job, in-flight job to
 * attach to, completed job to download) plus failure modes.
 *
 * Each fake matches on URL+method to route the worker through the right
 * response. Sleep::fake() skips the SDK's poll backoff so tests run
 * instantly.
 */
class InstallCommandTest extends TestCase
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
        $this->toolsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_install_'.bin2hex(random_bytes(6));

        $app->setBasePath($fixtureRoot);

        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_install');
        $app['config']->set('mindum.tools_path', $this->toolsPath);
        $app['config']->set('mindum.tools_namespace', 'App\\Mindum\\Tools');
        $app['config']->set('mindum.scan_paths', ['app/']);
        $app['config']->set('mindum.api_timeout_seconds', 30);
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

    public function test_install_fails_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->assertExitCode(1);
    }

    public function test_install_fresh_scan_happy_path(): void
    {
        // No existing job → 204 on /current; POST returns 202 with a job_id;
        // poll #1 returns running 1/1; poll #2 returns completed; /results
        // returns the tools. The worker writes them to disk.
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01ABC', 'status' => 'queued', 'total_batches' => 1, 'estimated_seconds' => 83],
            'poll_sequence' => [
                ['status' => 'running', 'batches_completed' => 0, 'total_batches' => 1],
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 2],
            ],
            'results' => [
                'tools_count' => 2,
                'tools' => $this->fakeTools(['create_post', 'list_posts']),
                'cost_summary' => ['input_tokens' => 1200, 'output_tokens' => 800, 'approximate_usd' => 0.02],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Mindum install')
            ->expectsOutputToContain('Job accepted')
            ->expectsOutputToContain('Downloaded')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        $this->assertFileExists($this->toolsPath.'/CreatePost.php');
        $this->assertFileExists($this->toolsPath.'/ListPosts.php');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/api/analyze')
            && ! str_contains($r->url(), '/jobs/'));
    }

    public function test_install_attaches_to_in_flight_job(): void
    {
        // /current returns a running job → SDK skips scan, polls existing.
        Http::fake($this->routedResponder([
            'current_then' => ['status' => 'running', 'job_id' => '01OLD', 'batches_completed' => 5, 'total_batches' => 10],
            'poll_sequence' => [
                ['status' => 'running', 'batches_completed' => 8, 'total_batches' => 10],
                ['status' => 'completed', 'batches_completed' => 10, 'total_batches' => 10, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['existing_tool']),
                'cost_summary' => ['input_tokens' => 5000, 'output_tokens' => 1500, 'approximate_usd' => 0.04],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected in-flight job')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        // No POST to /api/analyze (no scan, no upload).
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/api/analyze')
            && ! str_contains($r->url(), '/jobs/'));
    }

    public function test_install_downloads_completed_but_not_downloaded_job(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => ['status' => 'completed', 'job_id' => '01DONE', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['cached_tool']),
                'cost_summary' => ['input_tokens' => 1000, 'output_tokens' => 300, 'approximate_usd' => 0.008],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected completed job')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        // No POST and no polling.
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/jobs/01DONE')
            && ! str_contains($r->url(), '/results'));
    }

    public function test_install_fails_when_job_status_becomes_failed(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01FAIL', 'status' => 'queued', 'total_batches' => 1, 'estimated_seconds' => 83],
            'poll_sequence' => [
                ['status' => 'failed', 'batches_completed' => 0, 'total_batches' => 1, 'error_message' => 'Rate limit exhausted (10 consecutive 429s)'],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Analysis failed')
            ->assertExitCode(1);
    }

    public function test_install_surfaces_post_http_error(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_error_status' => 401,
            'post_error_body' => ['error' => 'invalid_api_key'],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('HTTP 401')
            ->assertExitCode(1);
    }

    /**
     * Build a fake responder closure that routes by URL + method to the
     * right canned response. Each scenario is configured via an array.
     *
     * Supported keys:
     *   - current_then: 204 (no current job) OR an array (return as job)
     *   - post_returns: success body for POST /api/analyze
     *   - post_error_status / post_error_body: failure path for POST
     *   - poll_sequence: list of status responses for GET /jobs/{id}
     *   - results: body for GET /jobs/{id}/results
     *
     * @param  array<string, mixed>  $scenario
     */
    private function routedResponder(array $scenario): callable
    {
        $pollIndex = 0;

        return function (HttpRequest $request) use (&$pollIndex, $scenario) {
            $url = $request->url();
            $method = $request->method();

            // GET /api/analyze/jobs/current
            if ($method === 'GET' && str_ends_with($url, '/api/analyze/jobs/current')) {
                $current = $scenario['current_then'] ?? 204;
                if ($current === 204) {
                    return Http::response(null, 204);
                }

                return Http::response($this->mergePollDefaults($current), 200);
            }

            // GET /api/analyze/jobs/{id}/results
            if ($method === 'GET' && str_contains($url, '/results')) {
                return Http::response(array_merge([
                    'job_id' => '01ANY',
                ], $scenario['results'] ?? []), 200);
            }

            // GET /api/analyze/jobs/{id}
            if ($method === 'GET' && str_contains($url, '/api/analyze/jobs/')) {
                $sequence = $scenario['poll_sequence'] ?? [];
                $next = $sequence[$pollIndex] ?? end($sequence);
                $pollIndex++;

                return Http::response($this->mergePollDefaults($next), 200);
            }

            // POST /api/analyze
            if ($method === 'POST' && str_ends_with($url, '/api/analyze')) {
                if (isset($scenario['post_error_status'])) {
                    return Http::response(
                        $scenario['post_error_body'] ?? ['error' => 'failed'],
                        $scenario['post_error_status'],
                    );
                }

                return Http::response(array_merge([
                    'job_id' => '01POST',
                    'status' => 'queued',
                    'total_batches' => 1,
                    'estimated_seconds' => 83,
                    'poll_url' => '/api/analyze/jobs/01POST',
                    'results_url' => '/api/analyze/jobs/01POST/results',
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

    /**
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function fakeTools(array $names): array
    {
        return array_map(fn ($name) => [
            'name' => $name,
            'description' => "Fake tool {$name}",
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'handle_code' => 'return null;',
            'operation_type' => 'read',
            'source_class' => 'App\\Fake',
        ], $names);
    }
}
