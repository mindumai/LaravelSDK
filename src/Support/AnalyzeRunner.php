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

    public function run(?callable $onStep = null): AnalyzeResult
    {
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

        $this->emit($onStep, 'api_submit', ['entry_count' => count($entries)]);

        $jobInfo = $this->client->startAnalyzeJob($manifest);

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
        );
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
