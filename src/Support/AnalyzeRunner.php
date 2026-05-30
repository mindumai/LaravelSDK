<?php

declare(strict_types=1);

namespace Mindum\Laravel\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Sleep;
use Mindum\Laravel\Api\MindumApiClient;
use Mindum\Laravel\Scanner\Scanner;
use Mindum\Laravel\Tools\ToolWriter;
use RuntimeException;

/**
 * Async-aware orchestrator for mindum:install and mindum:rescan. Bridges
 * Scanner → MindumApiClient → ToolWriter with idempotent attach semantics
 * (D-A-5): before scanning, check if there's an existing in-flight or
 * undownloaded-complete job for this account, and either attach to it or
 * download its results instead of starting a fresh scan.
 *
 * Lifecycle:
 *   1. currentJob() — does an existing job we can attach to?
 *      - completed (not downloaded) → skip scan, fetch results, write
 *      - in-flight (queued/running) → skip scan, poll, fetch results, write
 *      - none → scan, upload, get back a job_id, poll, fetch results, write
 *   2. pollUntilTerminal — exponential 1s→2s→4s→8s, reset on progress
 *   3. fetchResults — once status=completed, GET the tools array
 *   4. ToolWriter writes tool classes to disk
 *
 * The $onStep callback receives event names + data per stage. CLI commands
 * use it to render live progress lines.
 */
class AnalyzeRunner
{
    /** Polling cadence ladder (D-A-2): 1s → 2s → 4s → 8s capped. */
    private const POLL_BACKOFF_INITIAL_MS = 1000;

    private const POLL_BACKOFF_CAP_MS = 8000;

    public function __construct(
        private readonly Application $app,
        private readonly MindumApiClient $client,
        private readonly ToolWriter $writer,
    ) {}

    /**
     * @param  callable(string $event, array<string,mixed> $data): void|null  $onStep
     * @param  callable(array<string,mixed> $partialJob): string|null  $onPartialDecision
     *                                                                                     Called when /current returns a failed-with-partial job. Must return
     *                                                                                     one of: 'download' | 'resume' | 'fresh' | 'cancel'. If null (e.g.
     *                                                                                     non-interactive CI), defaults to 'download' (safest per D-P-7).
     * @param  callable(array<string,mixed> $estimate): bool|null  $onEstimateConfirm
     *                                                                                 Called after the scanner finishes but BEFORE the manifest is
     *                                                                                 submitted to the API. Receives an estimate ($candidate_count,
     *                                                                                 $estimated_batches, $estimated_seconds, $estimated_cost_usd,
     *                                                                                 $model) and returns true to proceed or false to cancel.
     *                                                                                 Per Phase E1 (Estimator) — no Anthropic spend until the
     *                                                                                 customer confirms. If null, auto-proceeds (e.g. non-interactive).
     * @param  callable(array<string,mixed> $precheck): string|null  $onInsufficientCredit
     *                                                                                      MS7 — called when the precheck endpoint returns can_proceed=false.
     *                                                                                      The callback receives the full precheck payload (balance_cents,
     *                                                                                      reserve_required_cents, alternatives, topup_suggestion_cents,
     *                                                                                      upgrade_to) and must return ONE of:
     *                                                                                      - 'switch:<model>' — try the precheck again with a cheaper model
     *                                                                                      - 'topup'          — abort with a top-up hint
     *                                                                                      - 'upgrade'        — abort with an upgrade hint
     *                                                                                      - 'cancel'         — abort with a plain cancellation message
     *                                                                                      If null (non-interactive / CI), defaults to 'cancel' — we NEVER
     *                                                                                      spend Anthropic money the customer hasn't confirmed they can
     *                                                                                      afford.
     */
    public function run(
        ?callable $onStep = null,
        ?callable $onPartialDecision = null,
        ?callable $onEstimateConfirm = null,
        ?callable $onInsufficientCredit = null,
    ): AnalyzeResult {
        // Phase 1: detect any existing job to attach to.
        $existing = $this->client->currentJob();

        if ($existing !== null && $existing['status'] === 'completed') {
            // Job finished before we got back (Ctrl+C-and-came-back case),
            // results never fetched. Skip scan + poll; just download.
            $this->emit($onStep, 'attach_completed', $existing);

            return $this->downloadAndWrite(
                jobId: $existing['job_id'],
                attached: true,
                scannerEntries: 0,
                scannerSkipped: 0,
                scannerErrors: [],
                onStep: $onStep,
            );
        }

        if ($existing !== null && in_array($existing['status'], ['queued', 'running'], true)) {
            // Job in flight — re-attach + poll from current progress.
            $this->emit($onStep, 'attach_in_flight', $existing);

            $finalJob = $this->pollUntilTerminal($existing['job_id'], $existing['batches_completed'], $onStep);

            if ($finalJob['status'] !== 'completed') {
                throw new RuntimeException(
                    'Analysis failed: '.($finalJob['error_message'] ?? 'unknown error'),
                );
            }

            return $this->downloadAndWrite(
                jobId: $existing['job_id'],
                attached: true,
                scannerEntries: 0,
                scannerSkipped: 0,
                scannerErrors: [],
                onStep: $onStep,
            );
        }

        // Per Docs/Partial_Resume_Plan.md Phase P3: failed-with-partial job
        // detected. Ask the user (via $onPartialDecision) what to do. In
        // non-interactive mode default to 'download' per D-P-7 (no surprise
        // Anthropic spend).
        if ($existing !== null
            && $existing['status'] === 'failed'
            && ($existing['batches_completed'] ?? 0) > 0
        ) {
            $this->emit($onStep, 'attach_failed_with_partial', $existing);

            $decision = $onPartialDecision !== null
                ? $onPartialDecision($existing)
                : 'download';

            switch ($decision) {
                case 'download':
                    $this->emit($onStep, 'partial_decision', ['choice' => 'download']);

                    return $this->downloadAndWrite(
                        jobId: $existing['job_id'],
                        attached: true,
                        scannerEntries: 0,
                        scannerSkipped: 0,
                        scannerErrors: [],
                        onStep: $onStep,
                    );

                case 'resume':
                    $this->emit($onStep, 'partial_decision', ['choice' => 'resume']);

                    $resumeInfo = $this->client->resumeJob($existing['job_id']);
                    $this->emit($onStep, 'resume_started', $resumeInfo);

                    $finalJob = $this->pollUntilTerminal(
                        $existing['job_id'],
                        (int) $existing['batches_completed'],
                        $onStep,
                    );

                    if ($finalJob['status'] !== 'completed') {
                        throw new RuntimeException(
                            'Analysis failed after resume: '.($finalJob['error_message'] ?? 'unknown error'),
                        );
                    }

                    return $this->downloadAndWrite(
                        jobId: $existing['job_id'],
                        attached: true,
                        scannerEntries: 0,
                        scannerSkipped: 0,
                        scannerErrors: [],
                        onStep: $onStep,
                    );

                case 'fresh':
                    // Fall through to the fresh-scan path. We deliberately
                    // do NOT call resumeJob — the partial job stays in 'failed'
                    // status; the new scan creates a brand new AnalyzeJob row.
                    // If the manifest hashes match, the worker's prior-completed
                    // dedup won't fire (the prior is failed, not completed),
                    // so the new job runs from scratch.
                    $this->emit($onStep, 'partial_decision', ['choice' => 'fresh']);
                    break;

                case 'cancel':
                default:
                    $this->emit($onStep, 'partial_decision', ['choice' => 'cancel']);
                    throw new RuntimeException('Cancelled — no changes made.');
            }
        }

        // Phase 2: no existing job — fresh scan + new job.
        $appRoot = $this->app->basePath();
        $scanPaths = $this->scanPaths();
        $appName = (string) $this->app['config']->get('app.name', 'app');

        $this->emit($onStep, 'scan_start', ['paths' => $scanPaths]);

        $scanner = new Scanner(
            appName: $appName,
            appRoot: $appRoot,
            scanPaths: $scanPaths,
        );

        $entries = $scanner->scan();

        $this->emit($onStep, 'scan_complete', [
            'entry_count' => count($entries),
            'skipped' => count($scanner->skipped),
            'errors' => count($scanner->errors),
            'controller_job_pairs' => $scanner->linkerStats['controller_job_pairs'],
            'same_id_conflict_groups' => $scanner->linkerStats['same_id_conflict_groups'],
        ]);

        if ($entries === []) {
            throw new RuntimeException(
                'Scanner produced 0 candidate entries. Check config/mindum.php scan_paths and that your '.
                'app contains supported kinds (actions, controllers, models, jobs, repositories).',
            );
        }

        $manifest = [
            'app' => $appName,
            'scanned_at' => date('c'),
            'manifest_version' => 1,
            'class_count' => count($entries),
            'entries' => $entries,
        ];

        // Phase E1 + MS7: pre-submit gate. precheck() returns the customer's
        // affordability state alongside cost + time + alternatives. The
        // legacy /analyze/config path stays as a fallback when the api is
        // older than the SDK (precheck endpoint missing → 404).
        //
        // Loop because the customer can pick "switch to cheaper model" from
        // the alternatives prompt — that re-runs precheck with the new model.
        $resolvedModel = null;
        $estimate = null;
        while (true) {
            $estimate = $this->obtainEstimate(count($entries), $resolvedModel);
            $this->emit($onStep, 'estimate_ready', $estimate);

            // MS7: only the precheck-source path knows about affordability.
            // The legacy fallback can't gate — customer sees the estimate and
            // confirms; the worker will catch insufficient-credit mid-scan.
            if ($estimate['source'] === 'precheck' && ! $estimate['can_proceed']) {
                $this->emit($onStep, 'insufficient_credit', $estimate);

                $decision = $onInsufficientCredit !== null
                    ? (string) $onInsufficientCredit($estimate)
                    : 'cancel';

                if (str_starts_with($decision, 'switch:')) {
                    $resolvedModel = substr($decision, strlen('switch:'));

                    // Loop back into precheck with the new model.
                    continue;
                }

                throw new RuntimeException($this->cancelMessageFor($decision, $estimate));
            }

            break;
        }

        $proceed = $onEstimateConfirm !== null
            ? (bool) $onEstimateConfirm($estimate)
            : true;

        if (! $proceed) {
            $this->emit($onStep, 'estimate_declined', $estimate);
            throw new RuntimeException('Cancelled — no analysis submitted.');
        }

        $this->emit($onStep, 'api_submit', ['entry_count' => count($entries)]);

        // Pin the resolved model on the submit so the server doesn't fall
        // back to tier-preferred when the user switched models in the
        // alternatives prompt. For precheck-sourced estimates we pin the
        // server-canonical model name (matches what the user agreed to).
        // For legacy fallback the "model" string carries an "(estimated)"
        // suffix and isn't a real model identifier — pass null to let the
        // server pick the tier default.
        $pinModel = $resolvedModel;
        if ($pinModel === null && $estimate['source'] === 'precheck') {
            $pinModel = $estimate['model'];
        }
        $jobInfo = $this->client->startAnalyzeJob($manifest, $pinModel);

        $this->emit($onStep, 'api_accepted', [
            'job_id' => $jobInfo['job_id'],
            'total_batches' => $jobInfo['total_batches'],
            'estimated_seconds' => $jobInfo['estimated_seconds'],
        ]);

        // Phase 3: poll until terminal.
        $finalJob = $this->pollUntilTerminal($jobInfo['job_id'], 0, $onStep);

        if ($finalJob['status'] !== 'completed') {
            throw new RuntimeException(
                'Analysis failed: '.($finalJob['error_message'] ?? 'unknown error'),
            );
        }

        return $this->downloadAndWrite(
            jobId: $jobInfo['job_id'],
            attached: false,
            scannerEntries: count($entries),
            scannerSkipped: count($scanner->skipped),
            scannerErrors: $scanner->errors,
            onStep: $onStep,
        );
    }

    /**
     * Polls GET /api/analyze/jobs/{id} until status is terminal
     * (`completed` or `failed`). Returns the final poll response.
     *
     * Cadence: starts at 1s, doubles up to 8s when status is stagnant,
     * resets to 1s whenever batches_completed advances.
     *
     * @return array<string, mixed>
     */
    private function pollUntilTerminal(string $jobId, int $lastBatchesCompleted, ?callable $onStep): array
    {
        $backoffMs = self::POLL_BACKOFF_INITIAL_MS;

        while (true) {
            Sleep::for($backoffMs)->milliseconds();

            $job = $this->client->pollJob($jobId);

            if (in_array($job['status'], ['completed', 'failed'], true)) {
                return $job;
            }

            if ($job['batches_completed'] > $lastBatchesCompleted) {
                $lastBatchesCompleted = $job['batches_completed'];
                $backoffMs = self::POLL_BACKOFF_INITIAL_MS;
                $this->emit($onStep, 'poll_progress', [
                    'job_id' => $jobId,
                    'batches_completed' => $job['batches_completed'],
                    'total_batches' => $job['total_batches'],
                    'tools_generated' => $job['tools_generated'],
                    'estimated_seconds_remaining' => $job['estimated_seconds_remaining'],
                ]);
            } else {
                $backoffMs = min($backoffMs * 2, self::POLL_BACKOFF_CAP_MS);
            }
        }
    }

    /**
     * @param  array<int, string>  $scannerErrors
     */
    private function downloadAndWrite(
        string $jobId,
        bool $attached,
        int $scannerEntries,
        int $scannerSkipped,
        array $scannerErrors,
        ?callable $onStep,
    ): AnalyzeResult {
        $this->emit($onStep, 'download_start', ['job_id' => $jobId]);

        $results = $this->client->fetchResults($jobId);

        $this->emit($onStep, 'download_complete', [
            'job_id' => $jobId,
            'tools_count' => $results['tools_count'],
            'cost_summary' => $results['cost_summary'],
            'is_partial' => $results['is_partial'],
            'partial_meta' => $results['partial_meta'],
        ]);

        $toolsPath = (string) $this->app['config']->get('mindum.tools_path');
        if ($toolsPath === '') {
            throw new RuntimeException(
                'config(mindum.tools_path) is empty. Publish the package config with '.
                '`php artisan vendor:publish --tag=mindum-config` and set tools_path.',
            );
        }

        $this->emit($onStep, 'write_start', ['path' => $toolsPath]);

        $writeReport = $this->writer->write($results['tools'], $toolsPath);

        $this->emit($onStep, 'write_complete', [
            'written' => $writeReport->writtenCount(),
            'deleted' => $writeReport->deletedCount(),
            'path' => $writeReport->toolsPath,
        ]);

        return new AnalyzeResult(
            entryCount: $scannerEntries,
            scannerSkipped: $scannerSkipped,
            scannerErrors: $scannerErrors,
            jobId: $jobId,
            toolCount: $results['tools_count'],
            costSummary: $results['cost_summary'],
            attached: $attached,
            writeReport: $writeReport,
            isPartial: $results['is_partial'],
            partialMeta: $results['partial_meta'],
        );
    }

    /**
     * MS7 — try precheck first (the affordability-aware path); fall back to
     * the legacy /analyze/config flow if the api doesn't have /precheck
     * (HTTP 404) or precheck itself fails for network reasons.
     *
     * Returned shape carries a `source` discriminator the caller branches
     * on:
     *   - 'precheck' → also has can_proceed, balance_cents, reserve_required_cents,
     *     alternatives[], topup_suggestion_cents, upgrade_to. The InstallCommand
     *     uses these to render the alternatives prompt when can_proceed=false.
     *   - 'legacy'   → only the original E1 fields (candidate_count, model,
     *     batch_size, estimated_batches, estimated_seconds, estimated_cost_usd).
     *     Affordability cannot be gated client-side; the worker will catch
     *     insufficient-credit mid-scan instead.
     *
     * Both shapes carry the original E1 fields so the existing render code
     * in InstallCommand stays unchanged for the common path.
     *
     * @return array<string, mixed>
     */
    private function obtainEstimate(int $candidateCount, ?string $model): array
    {
        try {
            $precheck = $this->client->precheckAnalyze($candidateCount, $model);

            return [
                'source' => 'precheck',
                'candidate_count' => $precheck['candidate_count'],
                'model' => $precheck['model'],
                'current_tier' => $precheck['current_tier'],
                'batch_size' => max(1, (int) ceil($precheck['candidate_count'] / max(1, $precheck['estimated_batches']))),
                'estimated_batches' => $precheck['estimated_batches'],
                'estimated_seconds' => $precheck['estimated_seconds'],
                'estimated_cost_usd' => round($precheck['estimated_cost_cents'] / 100, 2),
                'can_proceed' => $precheck['can_proceed'],
                'balance_cents' => $precheck['balance_cents'],
                'reserve_required_cents' => $precheck['reserve_required_cents'],
                'estimated_cost_cents' => $precheck['estimated_cost_cents'],
                'alternatives' => $precheck['alternatives'],
                'topup_suggestion_cents' => $precheck['topup_suggestion_cents'],
                'upgrade_to' => $precheck['upgrade_to'],
            ];
        } catch (\Throwable $e) {
            // 404 → endpoint missing, older api. 403 → model_not_allowed_for_tier;
            // bubble up because the customer needs to fix their request (the
            // legacy path would only mask the real problem). Network errors
            // bubble too — they'll fail the same way on the actual submit.
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }

            return $this->legacyEstimate($candidateCount);
        }
    }

    /**
     * Legacy /analyze/config-based estimate. Retained for SDK-newer-than-api
     * deployments where /precheck doesn't exist yet. Loses the affordability
     * gate — the worker catches insufficient credit mid-scan instead.
     *
     * @return array<string, mixed>
     */
    private function legacyEstimate(int $candidateCount): array
    {
        try {
            $config = $this->client->getAnalyzeConfig();
        } catch (\Throwable $e) {
            // Conservative fallback. Show user a number based on Sonnet
            // defaults — if it's wildly off they'll know to cancel.
            $config = [
                'model' => 'claude-sonnet-4-6 (estimated)',
                'batch_size' => 10,
                'estimated_seconds_per_batch' => 83,
                'estimated_cost_per_candidate_usd' => 0.009,
            ];
        }

        $batchSize = max(1, (int) $config['batch_size']);
        $estimatedBatches = (int) ceil($candidateCount / $batchSize);
        $estimatedSeconds = $estimatedBatches * (int) $config['estimated_seconds_per_batch'];
        $estimatedCostUsd = $candidateCount * (float) $config['estimated_cost_per_candidate_usd'];

        return [
            'source' => 'legacy',
            'candidate_count' => $candidateCount,
            'model' => (string) $config['model'],
            'batch_size' => $batchSize,
            'estimated_batches' => $estimatedBatches,
            'estimated_seconds' => $estimatedSeconds,
            'estimated_cost_usd' => round($estimatedCostUsd, 2),
        ];
    }

    /**
     * Build the cancellation RuntimeException message for an
     * onInsufficientCredit return value. The InstallCommand's prompt
     * renders the topup / upgrade URL hints at the prompt site (Symfony's
     * error-box wrapping garbles long URLs), so the exception message
     * itself just needs to identify the abort reason.
     */
    private function cancelMessageFor(string $decision, array $estimate): string
    {
        return match ($decision) {
            'topup' => 'Insufficient credit — top up and re-run.',
            'upgrade' => 'Insufficient credit — upgrade and re-run.',
            default => 'Cancelled — no analysis submitted.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function scanPaths(): array
    {
        $configured = $this->app['config']->get('mindum.scan_paths', ['app/']);

        return is_array($configured) ? array_values(array_map('strval', $configured)) : ['app/'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(?callable $onStep, string $event, array $data): void
    {
        if ($onStep !== null) {
            $onStep($event, $data);
        }
    }
}
