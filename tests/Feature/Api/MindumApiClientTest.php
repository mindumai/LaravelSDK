<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Mindum\Laravel\Api\MindumApiClient;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * Exercises MindumApiClient's four async methods (startAnalyzeJob, pollJob,
 * fetchResults, currentJob) with a faked HTTP transport. Confirms request
 * shape, response parsing, and error handling.
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
        $app['config']->set('mindum.api_timeout_seconds', 30);
    }

    // ────────────────────────────────────────────────────────────────────
    // startAnalyzeJob
    // ────────────────────────────────────────────────────────────────────

    public function test_start_analyze_job_posts_manifest_with_bearer(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze' => Http::response([
                'job_id' => '01HXY',
                'status' => 'queued',
                'total_batches' => 3,
                'estimated_seconds' => 249,
                'poll_url' => '/api/analyze/jobs/01HXY',
                'results_url' => '/api/analyze/jobs/01HXY/results',
            ], 202),
        ]);

        $result = (new MindumApiClient)->startAnalyzeJob([
            'entries' => [['kind' => 'action', 'id' => 'create_post']],
        ]);

        $this->assertSame('01HXY', $result['job_id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(3, $result['total_batches']);
        $this->assertSame(249, $result['estimated_seconds']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'api.mindum.ai/api/analyze')
                && $request->hasHeader('Authorization', 'Bearer mk_test_abc123')
                && is_array($request['manifest'])
                && isset($request['manifest']['entries']);
        });
    }

    public function test_start_analyze_job_throws_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mindum API key not configured');

        (new MindumApiClient)->startAnalyzeJob(['entries' => []]);
    }

    public function test_start_analyze_job_throws_on_http_error(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'invalid_api_key'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        (new MindumApiClient)->startAnalyzeJob(['entries' => [['kind' => 'action']]]);
    }

    public function test_start_analyze_job_throws_when_response_missing_job_id(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['status' => 'queued'], 202),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field: job_id');

        (new MindumApiClient)->startAnalyzeJob(['entries' => []]);
    }

    // ────────────────────────────────────────────────────────────────────
    // pollJob
    // ────────────────────────────────────────────────────────────────────

    public function test_poll_job_returns_full_status_shape(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/01HXY' => Http::response([
                'job_id' => '01HXY',
                'status' => 'running',
                'batches_completed' => 12,
                'total_batches' => 55,
                'tools_generated' => 282,
                'started_at' => '2026-05-22T15:30:00Z',
                'completed_at' => null,
                'estimated_seconds_remaining' => 3569,
                'tools_downloaded' => false,
                'error_message' => null,
                'results_url' => '/api/analyze/jobs/01HXY/results',
            ], 200),
        ]);

        $result = (new MindumApiClient)->pollJob('01HXY');

        $this->assertSame('running', $result['status']);
        $this->assertSame(12, $result['batches_completed']);
        $this->assertSame(55, $result['total_batches']);
        $this->assertSame(282, $result['tools_generated']);
        $this->assertSame('2026-05-22T15:30:00Z', $result['started_at']);
        $this->assertNull($result['completed_at']);
        $this->assertFalse($result['tools_downloaded']);

        Http::assertSent(fn ($req) => $req->method() === 'GET'
            && str_contains($req->url(), '/jobs/01HXY')
            && $req->hasHeader('Authorization', 'Bearer mk_test_abc123'));
    }

    public function test_poll_job_throws_on_404(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'job_not_found'], 404),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        (new MindumApiClient)->pollJob('01XYZ');
    }

    // ────────────────────────────────────────────────────────────────────
    // fetchResults
    // ────────────────────────────────────────────────────────────────────

    public function test_fetch_results_returns_tools_and_cost_summary(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/01HXY/results' => Http::response([
                'job_id' => '01HXY',
                'tools_count' => 2,
                'tools' => [
                    ['name' => 'list_posts'],
                    ['name' => 'create_post'],
                ],
                'is_partial' => false,
                'cost_summary' => [
                    'input_tokens' => 26000,
                    'output_tokens' => 7800,
                    'approximate_usd' => 0.2,
                ],
            ], 200),
        ]);

        $result = (new MindumApiClient)->fetchResults('01HXY');

        $this->assertSame(2, $result['tools_count']);
        $this->assertCount(2, $result['tools']);
        $this->assertSame('list_posts', $result['tools'][0]['name']);
        $this->assertSame(26000, $result['cost_summary']['input_tokens']);
        $this->assertSame(0.2, $result['cost_summary']['approximate_usd']);
        $this->assertFalse($result['is_partial']);
        $this->assertNull($result['partial_meta']);
    }

    public function test_fetch_results_propagates_partial_meta_when_failed_with_batches(): void
    {
        // Per Docs/Partial_Resume_Plan.md P3: API returns is_partial + meta
        // when serving a failed-with-batches job. SDK exposes the same shape
        // so the runner can drive the UX accordingly.
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/01HXY/results' => Http::response([
                'job_id' => '01HXY',
                'tools_count' => 1,
                'tools' => [['name' => 'list_posts']],
                'is_partial' => true,
                'partial_meta' => [
                    'batches_completed' => 1,
                    'total_batches' => 3,
                    'batches_remaining' => 2,
                    'error_message' => 'Anthropic billing cap',
                    'resumable' => true,
                ],
                'cost_summary' => [
                    'input_tokens' => 12000,
                    'output_tokens' => 3000,
                    'approximate_usd' => 0.08,
                ],
            ], 200),
        ]);

        $result = (new MindumApiClient)->fetchResults('01HXY');

        $this->assertTrue($result['is_partial']);
        $this->assertNotNull($result['partial_meta']);
        $this->assertSame(1, $result['partial_meta']['batches_completed']);
        $this->assertSame(3, $result['partial_meta']['total_batches']);
        $this->assertSame(2, $result['partial_meta']['batches_remaining']);
        $this->assertSame('Anthropic billing cap', $result['partial_meta']['error_message']);
        $this->assertTrue($result['partial_meta']['resumable']);
    }

    public function test_fetch_results_throws_on_409_job_not_completed(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'job_not_completed'], 409),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 409');

        (new MindumApiClient)->fetchResults('01HXY');
    }

    public function test_fetch_results_throws_when_tools_missing(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['job_id' => '01HXY'], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing "tools" array');

        (new MindumApiClient)->fetchResults('01HXY');
    }

    // ────────────────────────────────────────────────────────────────────
    // currentJob
    // ────────────────────────────────────────────────────────────────────

    public function test_current_job_returns_null_on_204(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/current' => Http::response(null, 204),
        ]);

        $result = (new MindumApiClient)->currentJob();

        $this->assertNull($result);
    }

    public function test_current_job_returns_job_data_on_200(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/current' => Http::response([
                'job_id' => '01HXY',
                'status' => 'running',
                'batches_completed' => 5,
                'total_batches' => 10,
                'tools_generated' => 100,
                'started_at' => '2026-05-22T15:00:00Z',
                'completed_at' => null,
                'estimated_seconds_remaining' => 415,
                'tools_downloaded' => false,
                'error_message' => null,
                'results_url' => '/api/analyze/jobs/01HXY/results',
            ], 200),
        ]);

        $result = (new MindumApiClient)->currentJob();

        $this->assertNotNull($result);
        $this->assertSame('01HXY', $result['job_id']);
        $this->assertSame('running', $result['status']);
        $this->assertSame(5, $result['batches_completed']);
    }

    public function test_current_job_throws_on_unexpected_500(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'server'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new MindumApiClient)->currentJob();
    }

    // ────────────────────────────────────────────────────────────────────
    // getAnalyzeConfig — Phase E1 (Estimator)
    // ────────────────────────────────────────────────────────────────────

    public function test_get_analyze_config_returns_model_pricing_and_heuristics(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/config' => Http::response([
                'model' => 'claude-sonnet-4-6',
                'batch_size' => 10,
                'pricing' => [
                    'input_cents_per_million' => 300,
                    'output_cents_per_million' => 1500,
                ],
                'estimated_seconds_per_batch' => 83,
                'estimated_cost_per_candidate_usd' => 0.009,
            ], 200),
        ]);

        $result = (new MindumApiClient)->getAnalyzeConfig();

        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertSame(10, $result['batch_size']);
        $this->assertSame(300, $result['pricing']['input_cents_per_million']);
        $this->assertSame(83, $result['estimated_seconds_per_batch']);
        $this->assertSame(0.009, $result['estimated_cost_per_candidate_usd']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/analyze/config')
                && $request->hasHeader('Authorization', 'Bearer mk_test_abc123');
        });
    }

    public function test_get_analyze_config_throws_on_http_error(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(['error' => 'invalid_api_key'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        (new MindumApiClient)->getAnalyzeConfig();
    }

    // ────────────────────────────────────────────────────────────────────
    // resumeJob — Phase P5 / Feature B
    // ────────────────────────────────────────────────────────────────────

    public function test_resume_job_posts_and_returns_requeued_state(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/jobs/01HXY/resume' => Http::response([
                'job_id' => '01HXY',
                'status' => 'queued',
                'batches_completed' => 1,
                'total_batches' => 3,
                'batches_remaining' => 2,
                'message' => 'Job resumed.',
            ], 202),
        ]);

        $result = (new MindumApiClient)->resumeJob('01HXY');

        $this->assertSame('01HXY', $result['job_id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, $result['batches_completed']);
        $this->assertSame(2, $result['batches_remaining']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/analyze/jobs/01HXY/resume')
                && $request->hasHeader('Authorization', 'Bearer mk_test_abc123');
        });
    }

    public function test_resume_job_throws_on_409_non_resumable(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'error' => 'job_not_resumable',
                'status' => 'completed',
            ], 409),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 409');

        (new MindumApiClient)->resumeJob('01HXY');
    }

    public function test_resume_job_throws_on_410_expired(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'error' => 'resume_window_expired',
            ], 410),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 410');

        (new MindumApiClient)->resumeJob('01HXY');
    }

    // ────────────────────────────────────────────────────────────────────
    // precheckAnalyze — MS7
    // ────────────────────────────────────────────────────────────────────

    public function test_precheck_analyze_posts_candidate_count_and_optional_model(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/precheck' => Http::response([
                'can_proceed' => true,
                'model' => 'claude-sonnet-4-6',
                'current_tier' => 'starter',
                'candidate_count' => 250,
                'estimated_batches' => 10,
                'estimated_seconds' => 830,
                'estimated_cost_cents' => 225,
                'reserve_required_cents' => 300,
                'balance_cents' => 2000,
                'alternatives' => [],
                'topup_suggestion_cents' => null,
                'upgrade_to' => null,
            ], 200),
        ]);

        $result = (new MindumApiClient)->precheckAnalyze(250, 'claude-sonnet-4-6');

        $this->assertTrue($result['can_proceed']);
        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertSame('starter', $result['current_tier']);
        $this->assertSame(300, $result['reserve_required_cents']);
        $this->assertSame(2000, $result['balance_cents']);
        $this->assertSame([], $result['alternatives']);
        $this->assertNull($result['topup_suggestion_cents']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/analyze/precheck')
                && $request->hasHeader('Authorization', 'Bearer mk_test_abc123')
                && $request['candidate_count'] === 250
                && $request['model'] === 'claude-sonnet-4-6';
        });
    }

    public function test_precheck_analyze_omits_model_when_null(): void
    {
        // Server should resolve to tier-preferred when SDK doesn't pin one.
        Http::fake([
            'api.mindum.ai/api/analyze/precheck' => Http::response([
                'can_proceed' => true,
                'model' => 'claude-haiku-4-5',
                'current_tier' => 'free',
                'candidate_count' => 50,
                'estimated_batches' => 2,
                'estimated_seconds' => 38,
                'estimated_cost_cents' => 15,
                'reserve_required_cents' => 10,
                'balance_cents' => 500,
                'alternatives' => [],
                'topup_suggestion_cents' => null,
                'upgrade_to' => null,
            ], 200),
        ]);

        (new MindumApiClient)->precheckAnalyze(50);

        Http::assertSent(fn ($req) => ! isset($req['model']));
    }

    public function test_precheck_analyze_parses_alternatives_and_suggestion(): void
    {
        Http::fake([
            'api.mindum.ai/api/analyze/precheck' => Http::response([
                'can_proceed' => false,
                'model' => 'claude-sonnet-4-6',
                'current_tier' => 'starter',
                'candidate_count' => 250,
                'estimated_batches' => 10,
                'estimated_seconds' => 830,
                'estimated_cost_cents' => 225,
                'reserve_required_cents' => 300,
                'balance_cents' => 150,
                'alternatives' => [
                    [
                        'model' => 'claude-haiku-4-5',
                        'estimated_cost_cents' => 75,
                        'reserve_required_cents' => 50,
                        'estimated_seconds' => 190,
                        'fits_balance' => true,
                    ],
                ],
                'topup_suggestion_cents' => 1000,
                'upgrade_to' => 'growth',
            ], 200),
        ]);

        $result = (new MindumApiClient)->precheckAnalyze(250, 'claude-sonnet-4-6');

        $this->assertFalse($result['can_proceed']);
        $this->assertCount(1, $result['alternatives']);
        $this->assertSame('claude-haiku-4-5', $result['alternatives'][0]['model']);
        $this->assertTrue($result['alternatives'][0]['fits_balance']);
        $this->assertSame(1000, $result['topup_suggestion_cents']);
        $this->assertSame('growth', $result['upgrade_to']);
    }

    public function test_precheck_analyze_throws_on_403_tier_disallowed(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'error' => 'model_not_allowed_for_tier',
                'requested_model' => 'claude-opus-4-7',
            ], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');

        (new MindumApiClient)->precheckAnalyze(100, 'claude-opus-4-7');
    }

    public function test_precheck_analyze_pins_model_on_start_request(): void
    {
        // Confirms startAnalyzeJob carries the model field through when set —
        // the parameter the MS7 "switch model" flow depends on.
        Http::fake([
            'api.mindum.ai/api/analyze' => Http::response([
                'job_id' => '01HXY',
                'status' => 'queued',
                'total_batches' => 1,
            ], 202),
        ]);

        (new MindumApiClient)->startAnalyzeJob(
            ['entries' => [['kind' => 'action']]],
            'claude-haiku-4-5',
        );

        Http::assertSent(fn ($req) => ($req['model'] ?? null) === 'claude-haiku-4-5');
    }

    public function test_start_analyze_job_omits_model_field_when_null(): void
    {
        // Default behavior must not change for callers who don't pass a
        // model — the server's tier-preferred default kicks in.
        Http::fake([
            'api.mindum.ai/api/analyze' => Http::response([
                'job_id' => '01HXY',
                'status' => 'queued',
                'total_batches' => 1,
            ], 202),
        ]);

        (new MindumApiClient)->startAnalyzeJob(['entries' => []]);

        Http::assertSent(fn ($req) => ! isset($req['model']));
    }

    // ────────────────────────────────────────────────────────────────────
    // Custom api_url
    // ────────────────────────────────────────────────────────────────────

    public function test_honors_custom_api_url(): void
    {
        config()->set('mindum.api_url', 'http://localhost:8000/');

        Http::fake([
            'localhost:8000/*' => Http::response([
                'job_id' => '01HXY',
                'status' => 'queued',
                'total_batches' => 0,
            ], 202),
        ]);

        (new MindumApiClient)->startAnalyzeJob(['entries' => []]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'localhost:8000/api/analyze'));
    }
}
