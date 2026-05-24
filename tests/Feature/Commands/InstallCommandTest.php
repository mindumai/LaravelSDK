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

    public function test_install_fails_when_api_key_missing_in_non_interactive_mode(): void
    {
        // Per Phase E2: --no-interaction means "scripted run, don't hang
        // on stdin." Original error-and-exit behavior preserved so CI
        // pipelines still get a clear signal instead of a hung process.
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:install', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->assertExitCode(1);
    }

    public function test_install_prompts_for_api_key_when_missing_runtime_only(): void
    {
        // User declines to save to .env → key lives in runtime config only.
        // The rest of the install flow proceeds with that key.
        config()->set('mindum.api_key', '');

        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01PR', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['prompted_tool']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->expectsQuestion('Paste your Mindum API key (starts with mk_)', 'mk_test_runtime_only_key')
            ->expectsConfirmation('Save this key to your .env file?', 'no')
            ->expectsOutputToContain('for this run only')
            ->doesntExpectOutputToContain('Saved')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        // Runtime config picked it up.
        $this->assertSame('mk_test_runtime_only_key', config('mindum.api_key'));

        // The Bearer header on the subsequent HTTP calls used the new key.
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer mk_test_runtime_only_key'));
    }

    public function test_install_rejects_empty_api_key_at_prompt(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->expectsQuestion('Paste your Mindum API key (starts with mk_)', '')
            ->expectsOutputToContain('No key provided')
            ->assertExitCode(1);
    }

    public function test_install_rejects_invalid_api_key_format_at_prompt(): void
    {
        // Catch paste mistakes early — customers commonly paste an
        // unrelated token or only half the key.
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->expectsQuestion('Paste your Mindum API key (starts with mk_)', 'sk-ant-not-a-mindum-key')
            ->expectsOutputToContain('Invalid key format')
            ->assertExitCode(1);
    }

    public function test_install_writes_api_key_to_env_when_user_agrees(): void
    {
        // Verifies the file write happens. Uses a per-test temp app root so
        // we can inspect .env after the run without polluting the shared
        // fixture directory. We copy the fixture's app/ tree in so the
        // scanner has real candidates to find — otherwise it'd throw
        // "Scanner produced 0 candidate entries" before reaching the
        // analyze step.
        $tempAppRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_envtest_'.bin2hex(random_bytes(6));
        $this->files->makeDirectory($tempAppRoot, 0755, true);
        $this->files->copyDirectory(
            realpath(__DIR__.'/../../fixtures/laravel-app/app'),
            $tempAppRoot.'/app',
        );
        // Seed a minimal .env.example so writeApiKeyToEnv copies-then-appends
        // (the realistic onboarding shape: customer's app has .env.example
        // but no .env yet).
        file_put_contents($tempAppRoot.'/.env.example', "APP_NAME=Demo\nAPP_ENV=local\n");

        $this->app->setBasePath($tempAppRoot);
        config()->set('mindum.api_key', '');
        config()->set('mindum.tools_path', $this->toolsPath);
        config()->set('mindum.scan_paths', ['app/']);

        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01ENV', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['env_tool']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        try {
            $this->artisan('mindum:install', ['--force' => true])
                ->expectsQuestion('Paste your Mindum API key (starts with mk_)', 'mk_live_savemekey123')
                ->expectsConfirmation('Save this key to your .env file?', 'yes')
                ->expectsOutputToContain('Saved')
                ->assertExitCode(0);

            $this->assertFileExists($tempAppRoot.'/.env');
            $envContents = file_get_contents($tempAppRoot.'/.env');
            $this->assertStringContainsString('MINDUM_API_KEY=mk_live_savemekey123', $envContents);
            // Preserved the prior .env.example contents during the copy.
            $this->assertStringContainsString('APP_NAME=Demo', $envContents);
        } finally {
            $this->files->deleteDirectory($tempAppRoot);
        }
    }

    public function test_install_replaces_existing_mindum_api_key_line(): void
    {
        // Customer rotated their key — re-running install should overwrite
        // the existing MINDUM_API_KEY= line rather than duplicating it.
        $tempAppRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_envtest_'.bin2hex(random_bytes(6));
        $this->files->makeDirectory($tempAppRoot, 0755, true);
        $this->files->copyDirectory(
            realpath(__DIR__.'/../../fixtures/laravel-app/app'),
            $tempAppRoot.'/app',
        );
        file_put_contents(
            $tempAppRoot.'/.env',
            "APP_NAME=Demo\nMINDUM_API_KEY=mk_live_old_stale_key\nDB_CONNECTION=sqlite\n",
        );

        $this->app->setBasePath($tempAppRoot);
        config()->set('mindum.api_key', '');
        config()->set('mindum.tools_path', $this->toolsPath);
        config()->set('mindum.scan_paths', ['app/']);

        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01R', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['rotated']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        try {
            $this->artisan('mindum:install', ['--force' => true])
                ->expectsQuestion('Paste your Mindum API key (starts with mk_)', 'mk_live_brand_new_key')
                ->expectsConfirmation('Save this key to your .env file?', 'yes')
                ->assertExitCode(0);

            $envContents = file_get_contents($tempAppRoot.'/.env');

            $this->assertStringNotContainsString('mk_live_old_stale_key', $envContents);
            $this->assertStringContainsString('MINDUM_API_KEY=mk_live_brand_new_key', $envContents);
            // Only ONE line — substr_count is exact.
            $this->assertSame(1, substr_count($envContents, 'MINDUM_API_KEY='));
            // Surrounding lines preserved.
            $this->assertStringContainsString('APP_NAME=Demo', $envContents);
            $this->assertStringContainsString('DB_CONNECTION=sqlite', $envContents);
        } finally {
            $this->files->deleteDirectory($tempAppRoot);
        }
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

    // ────────────────────────────────────────────────────────────────────
    // Phase E1 — Pre-submit estimate + confirmation
    // ────────────────────────────────────────────────────────────────────

    public function test_estimate_is_shown_with_model_candidates_time_and_cost(): void
    {
        // --force auto-confirms, so the install completes; we just want to
        // assert the estimate banner is rendered with the salient numbers.
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'config_returns' => [
                'model' => 'claude-sonnet-4-6',
                'batch_size' => 10,
                'estimated_seconds_per_batch' => 83,
                'estimated_cost_per_candidate_usd' => 0.009,
            ],
            'post_returns' => ['job_id' => '01ABC', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 2],
            ],
            'results' => [
                'tools_count' => 2,
                'tools' => $this->fakeTools(['create_post', 'list_posts']),
                'cost_summary' => ['input_tokens' => 1200, 'output_tokens' => 800, 'approximate_usd' => 0.02],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('About to analyze:')
            ->expectsOutputToContain('claude-sonnet-4-6')
            ->expectsOutputToContain('Estimated time:')
            ->expectsOutputToContain('Estimated cost:')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);
    }

    public function test_no_to_estimate_prompt_cancels_without_creating_job(): void
    {
        // User declines at the estimate prompt → SDK throws "Cancelled" and
        // exits 1, no POST /api/analyze ever fires (= no Anthropic spend).
        Http::fake($this->routedResponder([
            'current_then' => 204,
        ]));

        // The fixture app's mindum:install asks two prompts when --force is
        // omitted: (1) "Scan this app?" then (2) "Proceed with analysis?".
        // We confirm the first and decline the second.
        $this->artisan('mindum:install')
            ->expectsConfirmation('Scan this app and write tool classes to '.$this->toolsPath.'?', 'yes')
            ->expectsOutputToContain('About to analyze:')
            ->expectsConfirmation('Proceed with analysis?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(1);

        // No POST happened.
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/analyze'));
    }

    public function test_force_flag_skips_estimate_prompt(): void
    {
        // --force should bypass BOTH prompts (scan-confirm + estimate-confirm).
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'post_returns' => ['job_id' => '01F', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['t1']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        // No expectsConfirmation calls — both prompts should be skipped.
        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('About to analyze:')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);
    }

    public function test_estimate_shows_haiku_when_server_uses_haiku(): void
    {
        // Server's /precheck returns Haiku → SDK estimate reflects it. MS7
        // shifted the source-of-truth for the active model from /config to
        // /precheck (the latter is now tier-aware), so the test asserts
        // against precheck_returns.
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_returns' => [
                'model' => 'claude-haiku-4-5',
                'estimated_batches' => 1,
                'estimated_seconds' => 19,
                'estimated_cost_cents' => 1,
            ],
            'post_returns' => ['job_id' => '01H', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['t1']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('claude-haiku-4-5')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);
    }

    public function test_precheck_and_config_unreachable_uses_hardcoded_sonnet_fallback(): void
    {
        // MS7: SDK calls /precheck first. When THAT 404s (older api), it
        // falls back to /config. When /config ALSO 404s (even older api),
        // it falls back to Sonnet defaults. End result: still ships an
        // estimate to the customer rather than failing the install over a
        // pre-submit preview.
        Http::fake([
            'api.mindum.ai/api/analyze/precheck' => Http::response(['error' => 'not_found'], 404),
            'api.mindum.ai/api/analyze/config' => Http::response(['error' => 'not_found'], 404),
            'api.mindum.ai/api/analyze/jobs/current' => Http::response(null, 204),
            'api.mindum.ai/api/analyze' => Http::response([
                'job_id' => '01ZZ',
                'status' => 'queued',
                'total_batches' => 1,
                'estimated_seconds' => 83,
            ], 202),
            'api.mindum.ai/api/analyze/jobs/01ZZ' => Http::response($this->mergePollDefaults([
                'job_id' => '01ZZ',
                'status' => 'completed',
                'batches_completed' => 1,
                'total_batches' => 1,
                'tools_generated' => 1,
            ]), 200),
            'api.mindum.ai/api/analyze/jobs/01ZZ/results' => Http::response([
                'job_id' => '01ZZ',
                'tools_count' => 1,
                'tools' => $this->fakeTools(['t1']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ], 200),
        ]);

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('About to analyze:')
            ->expectsOutputToContain('estimated') // the "claude-sonnet-4-6 (estimated)" fallback label
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);
    }

    // ────────────────────────────────────────────────────────────────────
    // MS7 — Insufficient-credit prompt
    // ────────────────────────────────────────────────────────────────────

    public function test_insufficient_credit_user_switches_to_cheaper_model(): void
    {
        // First precheck returns can_proceed=false with Haiku as a viable
        // alternative. User picks [1] Switch to Haiku → SDK re-runs precheck
        // with the new model (this time fakeable as can_proceed=true) and
        // POSTs /analyze with model=claude-haiku-4-5 pinned.
        $precheckCallCount = 0;
        Http::fake(function (HttpRequest $request) use (&$precheckCallCount) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_ends_with($url, '/api/analyze/jobs/current')) {
                return Http::response(null, 204);
            }

            if ($method === 'POST' && str_ends_with($url, '/api/analyze/precheck')) {
                $precheckCallCount++;

                // First call: unaffordable Sonnet.
                if ($precheckCallCount === 1) {
                    return Http::response([
                        'can_proceed' => false,
                        'model' => 'claude-sonnet-4-6',
                        'current_tier' => 'starter',
                        'candidate_count' => 1,
                        'estimated_batches' => 1,
                        'estimated_seconds' => 83,
                        'estimated_cost_cents' => 9,
                        'reserve_required_cents' => 300,
                        'balance_cents' => 100,
                        'alternatives' => [
                            [
                                'model' => 'claude-haiku-4-5',
                                'estimated_cost_cents' => 3,
                                'reserve_required_cents' => 50,
                                'estimated_seconds' => 19,
                                'fits_balance' => true,
                            ],
                        ],
                        'topup_suggestion_cents' => 1000,
                        'upgrade_to' => 'growth',
                    ], 200);
                }

                // Second call: SDK re-precheck'd with Haiku → affordable.
                return Http::response([
                    'can_proceed' => true,
                    'model' => 'claude-haiku-4-5',
                    'current_tier' => 'starter',
                    'candidate_count' => 1,
                    'estimated_batches' => 1,
                    'estimated_seconds' => 19,
                    'estimated_cost_cents' => 3,
                    'reserve_required_cents' => 50,
                    'balance_cents' => 100,
                    'alternatives' => [],
                    'topup_suggestion_cents' => null,
                    'upgrade_to' => null,
                ], 200);
            }

            if ($method === 'POST' && str_ends_with($url, '/api/analyze')) {
                return Http::response([
                    'job_id' => '01SW',
                    'status' => 'queued',
                    'total_batches' => 1,
                    'estimated_seconds' => 19,
                ], 202);
            }

            if ($method === 'GET' && str_contains($url, '/api/analyze/jobs/01SW/results')) {
                return Http::response([
                    'job_id' => '01SW',
                    'tools_count' => 1,
                    'tools' => $this->fakeTools(['t1']),
                    'is_partial' => false,
                    'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/api/analyze/jobs/01SW')) {
                return Http::response($this->mergePollDefaults([
                    'job_id' => '01SW',
                    'status' => 'completed',
                    'batches_completed' => 1,
                    'total_batches' => 1,
                    'tools_generated' => 1,
                ]), 200);
            }

            return Http::response(['error' => 'unhandled', 'url' => $url], 500);
        });

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Insufficient credit.')
            ->expectsChoice('Choice', '1', ['1', '2', '3', '4'])
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        $this->assertSame(2, $precheckCallCount, 'expected one precheck before switch + one after');

        // Confirm the actual POST /api/analyze pinned the chosen model.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/analyze')
            && ($r['model'] ?? null) === 'claude-haiku-4-5');
    }

    public function test_insufficient_credit_user_chooses_topup_aborts_with_link(): void
    {
        // Only one alternative (Haiku at $1, fits_balance=false). Menu:
        //   [1] Switch to claude-haiku-4-5 — doesn't fit, but still listed?
        //   Actually only fits_balance=true alternatives appear, so the
        //   Haiku row is skipped. Menu becomes [1] Top up [2] Upgrade [3] Cancel.
        // User picks Top up → SDK errors with topup URL hint.
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_returns' => [
                'can_proceed' => false,
                'model' => 'claude-sonnet-4-6',
                'balance_cents' => 5,
                'reserve_required_cents' => 300,
                'alternatives' => [
                    [
                        'model' => 'claude-haiku-4-5',
                        'estimated_cost_cents' => 3,
                        'reserve_required_cents' => 50,
                        'estimated_seconds' => 19,
                        'fits_balance' => false,
                    ],
                ],
                'topup_suggestion_cents' => 1000,
                'upgrade_to' => 'growth',
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Insufficient credit.')
            ->expectsChoice('Choice', '1', ['1', '2', '3'])
            ->expectsOutputToContain('Top up at:')
            ->expectsOutputToContain('https://api.mindum.ai/dashboard/billing/topup')
            ->expectsOutputToContain('Insufficient credit — top up and re-run.')
            ->assertExitCode(1);
    }

    public function test_insufficient_credit_user_chooses_cancel(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_returns' => [
                'can_proceed' => false,
                'model' => 'claude-sonnet-4-6',
                'balance_cents' => 5,
                'reserve_required_cents' => 300,
                'alternatives' => [],
                'topup_suggestion_cents' => 1000,
                'upgrade_to' => 'growth',
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Insufficient credit.')
            // alternatives=[] → menu is [1] Top up [2] Upgrade [3] Cancel.
            ->expectsChoice('Choice', '3', ['1', '2', '3'])
            ->expectsOutputToContain('Cancelled — no analysis submitted.')
            ->assertExitCode(1);
    }

    public function test_insufficient_credit_in_non_interactive_mode_aborts(): void
    {
        // CI / scripted runs must NEVER auto-spend on a scan the customer
        // hasn't explicitly authorized. --no-interaction routes through
        // 'cancel'; --force is irrelevant for this gate (force-implying-
        // credit would be a footgun).
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_returns' => [
                'can_proceed' => false,
                'model' => 'claude-sonnet-4-6',
                'balance_cents' => 5,
                'reserve_required_cents' => 300,
                'alternatives' => [],
                'topup_suggestion_cents' => 1000,
                'upgrade_to' => 'growth',
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Insufficient credit')
            ->expectsOutputToContain('Non-interactive mode: aborting')
            ->assertExitCode(1);
    }

    public function test_precheck_403_tier_disallowed_model_aborts(): void
    {
        // Model the customer pinned isn't allowed by their tier. Precheck
        // returns 403 with the same shape as POST /analyze's 403. SDK
        // surfaces the server's error message and exits non-zero — no
        // alternatives prompt (the model is structurally disallowed,
        // switching to a tier-allowed model needs config changes the
        // SDK can't make on the customer's behalf).
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_status' => 403,
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('HTTP 403')
            ->assertExitCode(1);
    }

    public function test_balance_line_appears_in_estimate_panel_for_precheck_source(): void
    {
        // Precheck path renders the customer's current balance inline in the
        // "About to analyze" panel — the legacy /config path doesn't have
        // this data and doesn't render the line.
        //
        // Note: the actual dollar amount can't be asserted via
        // expectsOutputToContain because Symfony's OutputFormatter eats the
        // numeric content inside <options=bold>…</> tags when the test
        // BufferedOutput is non-decorated. The label "Your balance:" alone
        // is enough to prove the source=='precheck' branch ran.
        Http::fake($this->routedResponder([
            'current_then' => 204,
            'precheck_returns' => [
                'can_proceed' => true,
                'balance_cents' => 4250,
            ],
            'post_returns' => ['job_id' => '01BAL', 'status' => 'queued', 'total_batches' => 1],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['t1']),
                'cost_summary' => ['input_tokens' => 100, 'output_tokens' => 50, 'approximate_usd' => 0.001],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('About to analyze:')
            ->expectsOutputToContain('Your balance:')
            ->expectsOutputToContain('deducted from your prepaid balance')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);
    }

    // ────────────────────────────────────────────────────────────────────
    // Phase P3 / P5 — Failed-with-partial decision flow
    // ────────────────────────────────────────────────────────────────────

    public function test_partial_failed_job_user_chooses_download(): void
    {
        // /current returns failed-with-partial → user picks [1] Download.
        // SDK fetches partial results (is_partial=true) and writes them.
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'failed',
                'job_id' => '01PART',
                'batches_completed' => 1,
                'total_batches' => 3,
                'tools_generated' => 1,
                'error_message' => 'Anthropic billing cap',
            ],
            'results' => [
                'job_id' => '01PART',
                'tools_count' => 1,
                'tools' => $this->fakeTools(['partial_tool']),
                'is_partial' => true,
                'partial_meta' => [
                    'batches_completed' => 1,
                    'total_batches' => 3,
                    'batches_remaining' => 2,
                    'error_message' => 'Anthropic billing cap',
                    'resumable' => true,
                ],
                'cost_summary' => ['input_tokens' => 12000, 'output_tokens' => 3000, 'approximate_usd' => 0.08],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected failed scan job')
            ->expectsChoice('Choice', '1', ['1', '2', '3', '4'])
            ->expectsOutputToContain('Install complete (partial).')
            ->expectsOutputToContain('this is a partial tool set')
            ->expectsOutputToContain('Resume')
            ->assertExitCode(0);

        $this->assertFileExists($this->toolsPath.'/PartialTool.php');

        // No /resume POST and no fresh /api/analyze POST.
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/resume'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/analyze'));
    }

    public function test_partial_failed_job_user_chooses_resume(): void
    {
        // User picks [2] Resume → SDK POSTs /resume, polls until complete,
        // then downloads the full set.
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'failed',
                'job_id' => '01RESUME',
                'batches_completed' => 1,
                'total_batches' => 3,
                'tools_generated' => 1,
                'error_message' => 'Anthropic billing cap',
            ],
            'resume_returns' => [
                'job_id' => '01RESUME',
                'status' => 'queued',
                'batches_completed' => 1,
                'total_batches' => 3,
                'batches_remaining' => 2,
                'message' => 'Job resumed.',
            ],
            'poll_sequence' => [
                ['status' => 'running', 'batches_completed' => 2, 'total_batches' => 3],
                ['status' => 'completed', 'batches_completed' => 3, 'total_batches' => 3, 'tools_generated' => 3],
            ],
            'results' => [
                'tools_count' => 3,
                'tools' => $this->fakeTools(['t1', 't2', 't3']),
                'cost_summary' => ['input_tokens' => 36000, 'output_tokens' => 9000, 'approximate_usd' => 0.24],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected failed scan job')
            ->expectsChoice('Choice', '2', ['1', '2', '3', '4'])
            ->expectsOutputToContain('Resuming job')
            ->expectsOutputToContain('Install complete.')
            ->doesntExpectOutputToContain('Install complete (partial)')
            ->assertExitCode(0);

        // /resume was POSTed.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/api/analyze/jobs/01RESUME/resume'));
        // No fresh /api/analyze POST.
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/analyze'));
    }

    public function test_partial_failed_job_user_chooses_fresh(): void
    {
        // User picks [3] Fresh → SDK skips the partial entirely and starts
        // a brand-new scan + POST /api/analyze.
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'failed',
                'job_id' => '01OLD',
                'batches_completed' => 1,
                'total_batches' => 3,
                'tools_generated' => 1,
                'error_message' => 'Anthropic billing cap',
            ],
            'post_returns' => ['job_id' => '01NEW', 'status' => 'queued', 'total_batches' => 1, 'estimated_seconds' => 83],
            'poll_sequence' => [
                ['status' => 'completed', 'batches_completed' => 1, 'total_batches' => 1, 'tools_generated' => 1],
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['fresh_tool']),
                'cost_summary' => ['input_tokens' => 1000, 'output_tokens' => 300, 'approximate_usd' => 0.01],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected failed scan job')
            ->expectsChoice('Choice', '3', ['1', '2', '3', '4'])
            ->expectsOutputToContain('Job accepted')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        // Fresh POST happened.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/api/analyze'));
        // No /resume.
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/resume'));
    }

    public function test_partial_failed_job_user_chooses_cancel(): void
    {
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'failed',
                'job_id' => '01CANCEL',
                'batches_completed' => 1,
                'total_batches' => 3,
                'tools_generated' => 1,
                'error_message' => 'Anthropic billing cap',
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Detected failed scan job')
            ->expectsChoice('Choice', '4', ['1', '2', '3', '4'])
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(1);

        // No POSTs of any kind.
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
    }

    public function test_partial_failed_job_non_interactive_defaults_to_download(): void
    {
        // Per D-P-7: in --no-interaction mode, silently choose Download.
        // Never auto-resume — that would surprise the customer with Anthropic spend.
        Http::fake($this->routedResponder([
            'current_then' => [
                'status' => 'failed',
                'job_id' => '01CI',
                'batches_completed' => 1,
                'total_batches' => 3,
                'tools_generated' => 1,
                'error_message' => 'Anthropic billing cap',
            ],
            'results' => [
                'tools_count' => 1,
                'tools' => $this->fakeTools(['ci_tool']),
                'is_partial' => true,
                'partial_meta' => [
                    'batches_completed' => 1,
                    'total_batches' => 3,
                    'batches_remaining' => 2,
                    'error_message' => 'Anthropic billing cap',
                    'resumable' => true,
                ],
                'cost_summary' => ['input_tokens' => 12000, 'output_tokens' => 3000, 'approximate_usd' => 0.08],
            ],
        ]));

        $this->artisan('mindum:install', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Non-interactive mode')
            ->expectsOutputToContain('Install complete (partial).')
            ->assertExitCode(0);

        $this->assertFileExists($this->toolsPath.'/CiTool.php');

        // No /resume.
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/resume'));
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
     *   - precheck_returns: body for POST /api/analyze/precheck (MS7)
     *   - precheck_status: HTTP status for precheck (404 → legacy fallback)
     *
     * @param  array<string, mixed>  $scenario
     */
    private function routedResponder(array $scenario): callable
    {
        $pollIndex = 0;

        return function (HttpRequest $request) use (&$pollIndex, $scenario) {
            $url = $request->url();
            $method = $request->method();

            // GET /api/analyze/config — Phase E1 (Estimator)
            if ($method === 'GET' && str_ends_with($url, '/api/analyze/config')) {
                return Http::response(array_merge([
                    'model' => 'claude-sonnet-4-6',
                    'batch_size' => 10,
                    'pricing' => [
                        'input_cents_per_million' => 300,
                        'output_cents_per_million' => 1500,
                    ],
                    'estimated_seconds_per_batch' => 83,
                    'estimated_cost_per_candidate_usd' => 0.009,
                ], $scenario['config_returns'] ?? []), 200);
            }

            // POST /api/analyze/precheck — MS7. Defaults to can_proceed=true
            // so the bulk of existing tests don't have to opt into precheck
            // wiring. Scenarios that want the unaffordable path set
            // precheck_returns explicitly. precheck_status=404 simulates an
            // older api so the legacy /config fallback fires.
            if ($method === 'POST' && str_ends_with($url, '/api/analyze/precheck')) {
                $status = (int) ($scenario['precheck_status'] ?? 200);
                if ($status !== 200) {
                    return Http::response(['error' => 'simulated'], $status);
                }

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

            // POST /api/analyze/jobs/{id}/resume — Phase P5
            if ($method === 'POST' && str_contains($url, '/resume')) {
                return Http::response(array_merge([
                    'job_id' => '01RESUME',
                    'status' => 'queued',
                    'batches_completed' => 1,
                    'total_batches' => 3,
                    'batches_remaining' => 2,
                    'message' => 'Job resumed.',
                ], $scenario['resume_returns'] ?? []), 202);
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
