<?php

declare(strict_types=1);

namespace Mindum\Laravel\Commands;

use Illuminate\Console\Command;
use Mindum\Laravel\Support\AnalyzeResult;
use Mindum\Laravel\Support\AnalyzeRunner;
use RuntimeException;
use Throwable;

/**
 * `php artisan mindum:install`
 *
 * First-run command: verifies configuration, scans the customer's codebase
 * (or attaches to an in-flight/undownloaded-complete job if one exists),
 * polls the orchestrator for batch progress, downloads the generated tool
 * definitions, and writes them to disk. Idempotent — safe to re-run after
 * Ctrl+C, machine reboot, or network blip.
 *
 * Same engine as mindum:rescan — the difference is purely the UX framing
 * ("installing for the first time" vs. "refreshing").
 */
class InstallCommand extends Command
{
    protected $signature = 'mindum:install {--force : Skip the confirmation prompt}';

    protected $description = 'Scan your Laravel app and install Mindum-generated MCP tool classes.';

    /** Width of the in-place progress line; padding spaces overwrite stale text. */
    private const PROGRESS_LINE_WIDTH = 80;

    public function handle(AnalyzeRunner $runner): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>Mindum install</>');
        $this->line('<fg=gray>The agent layer for Laravel — from app to agent.</>');
        $this->line('');

        if (! $this->verifyConfig()) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Scan this app and write tool classes to '.config('mindum.tools_path').'?', true)) {
            $this->line('<fg=yellow>Aborted.</>');

            return self::FAILURE;
        }

        try {
            $result = $runner->run(
                onStep: $this->stepListener(),
                onPartialDecision: $this->partialDecisionPrompt(),
                onEstimateConfirm: $this->estimateConfirmPrompt(),
            );
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result);
        $this->renderPartialFollowUp($result);
        $this->renderNextSteps();

        return self::SUCCESS;
    }

    private function verifyConfig(): bool
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            // Phase E2: interactive prompt closes Firefly Cycle 1 finding #6
            // (dashboard says "paste API key when prompted" but the CLI
            // didn't actually prompt). In non-interactive mode we keep the
            // original error-and-exit so scripted runs don't hang on stdin.
            if (! $this->input->isInteractive() || $this->option('no-interaction')) {
                $this->error('MINDUM_API_KEY is not set. Add it to your .env and re-run.');
                $this->line('  Get a key at <fg=cyan>https://mindum.online</> (or use your existing one).');

                return false;
            }

            if (! $this->promptForApiKey()) {
                return false;
            }
        }

        $toolsPath = (string) config('mindum.tools_path', '');
        if ($toolsPath === '') {
            $this->error('config(mindum.tools_path) is empty.');
            $this->line('  Run <fg=cyan>php artisan vendor:publish --tag=mindum-config</> and re-check config/mindum.php.');

            return false;
        }

        $this->line('  <fg=gray>API URL:</>      '.config('mindum.api_url'));
        $this->line('  <fg=gray>Tools path:</>   '.$toolsPath);
        $this->line('  <fg=gray>Scan paths:</>   '.implode(', ', (array) config('mindum.scan_paths', [])));
        $this->newLine();

        return true;
    }

    /**
     * Ask the customer for their Mindum API key (Phase E2). Input is masked
     * via $this->secret() so the key doesn't end up in shell history or
     * screen recordings. Validates the mk_ prefix to catch paste mistakes
     * early. Returns true on success (config('mindum.api_key') is now set),
     * false on cancellation or validation failure.
     *
     * On success, optionally writes MINDUM_API_KEY to the customer's .env
     * (default yes, so re-runs don't re-prompt). If they decline, the key
     * lives in runtime config only and they'll re-enter on next install.
     */
    private function promptForApiKey(): bool
    {
        $this->line('<fg=yellow>!</> MINDUM_API_KEY is not set in your .env.');
        $this->line('  Get one (or look up your existing one) at <fg=cyan>https://mindum.online/dashboard</>.');
        $this->newLine();

        $apiKey = (string) $this->secret('Paste your Mindum API key (starts with mk_)');

        if ($apiKey === '') {
            $this->error('No key provided. Aborting.');

            return false;
        }

        if (! str_starts_with($apiKey, 'mk_')) {
            $this->error('Invalid key format. Mindum API keys start with mk_ (e.g. mk_live_...). Aborting.');

            return false;
        }

        // Set runtime config immediately so the rest of the install can
        // proceed regardless of whether the user saves to .env.
        config()->set('mindum.api_key', $apiKey);

        if ($this->confirm('Save this key to your .env file?', true)) {
            try {
                $envPath = $this->writeApiKeyToEnv($apiKey);
                $this->line('<fg=green>✓</> Saved <fg=cyan>MINDUM_API_KEY</> to '.$envPath);
            } catch (Throwable $e) {
                // Don't abort the install over a file-write failure — the
                // runtime config already has the key. Just warn the user.
                $this->warn('Could not write to .env ('.$e->getMessage().').');
                $this->line('  Key set in runtime config for this run only; add manually to keep it.');
            }
        } else {
            $this->line('<fg=gray>•</> Key set for this run only — you\'ll re-enter it next time.');
        }

        $this->newLine();

        return true;
    }

    /**
     * Persist MINDUM_API_KEY to the customer's .env file. Creates the file
     * from .env.example if it doesn't exist yet (unusual but possible on a
     * fresh checkout). Idempotent — replaces an existing MINDUM_API_KEY=
     * line in place rather than duplicating.
     *
     * Returns the absolute path of the .env file written.
     */
    private function writeApiKeyToEnv(string $apiKey): string
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        if (! file_exists($envPath)) {
            if (file_exists($examplePath)) {
                if (! @copy($examplePath, $envPath)) {
                    throw new RuntimeException("Could not copy .env.example to .env at {$envPath}");
                }
            } else {
                if (file_put_contents($envPath, "") === false) {
                    throw new RuntimeException("Could not create .env at {$envPath}");
                }
            }
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException("Could not read .env at {$envPath}");
        }

        $line = "MINDUM_API_KEY={$apiKey}";

        if (preg_match('/^MINDUM_API_KEY=.*$/m', $contents)) {
            // Replace existing line — preserves whatever comments / ordering
            // the customer's .env already has.
            $contents = preg_replace('/^MINDUM_API_KEY=.*$/m', $line, $contents);
        } else {
            // Append. Make sure we don't double-newline if the file already
            // ends with one (typical) vs. doesn't (rare but possible).
            $contents = rtrim($contents, "\n")."\n\n{$line}\n";
        }

        if (file_put_contents($envPath, $contents) === false) {
            throw new RuntimeException("Could not write to .env at {$envPath}");
        }

        return $envPath;
    }

    private function stepListener(): callable
    {
        return function (string $event, array $data): void {
            match ($event) {
                'attach_completed' => $this->line(sprintf(
                    '<fg=cyan>↺</> Detected completed job <fg=gray>(%s)</> — downloading results',
                    substr($data['job_id'], 0, 12).'…',
                )),
                'attach_in_flight' => $this->line(sprintf(
                    '<fg=cyan>↺</> Detected in-flight job <fg=gray>(%s)</> — attaching at %d/%d batches',
                    substr($data['job_id'], 0, 12).'…',
                    $data['batches_completed'],
                    $data['total_batches'],
                )),
                // Phase P3 / Feature A: failed job with partial progress.
                // The 4-option prompt is rendered by partialDecisionPrompt();
                // this event just lets the user know what we found before
                // the prompt appears.
                'attach_failed_with_partial' => $this->renderAttachFailedWithPartial($data),
                'partial_decision' => null, // The prompt itself echoes the choice.
                'resume_started' => $this->line(sprintf(
                    '<fg=cyan>↺</> Resuming job — %d batch%s remaining',
                    $data['batches_remaining'],
                    $data['batches_remaining'] === 1 ? '' : 'es',
                )),
                'scan_start' => $this->line('<fg=gray>•</> Scanning codebase...'),
                'scan_complete' => $this->line(sprintf(
                    '<fg=green>✓</> Found <options=bold>%d</> candidate%s (%d skipped, %d parse errors)',
                    $data['entry_count'],
                    $data['entry_count'] === 1 ? '' : 's',
                    $data['skipped'],
                    $data['errors'],
                )),
                'estimate_ready' => $this->renderEstimate($data),
                'estimate_declined' => null, // The throw + error handler prints "Cancelled" already.
                'api_submit' => $this->line('<fg=gray>•</> Uploading manifest to '.config('mindum.api_url').'...'),
                'api_accepted' => $this->renderApiAccepted($data),
                'poll_progress' => $this->renderProgress($data),
                'download_start' => $this->renderPollDone(),
                'download_complete' => $this->renderDownloadComplete($data),
                'write_start' => $this->line('<fg=gray>•</> Writing tool classes to '.$data['path'].'...'),
                'write_complete' => $this->line(sprintf(
                    '<fg=green>✓</> Wrote <options=bold>%d</> tool%s%s',
                    $data['written'],
                    $data['written'] === 1 ? '' : 's',
                    $data['deleted'] > 0 ? sprintf(' (deleted %d orphan%s)', $data['deleted'], $data['deleted'] === 1 ? '' : 's') : '',
                )),
                default => null,
            };
        };
    }

    /**
     * Render the "we found a failed-with-partial job" banner shown before
     * the 4-option prompt. Per Docs/Partial_Resume_Plan.md Phase P3 / D-P-7.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderAttachFailedWithPartial(array $data): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=yellow>✗</> Detected failed scan job <fg=gray>(%s)</> — <options=bold>%d/%d batches</> completed before failure',
            substr($data['job_id'], 0, 12).'…',
            $data['batches_completed'],
            $data['total_batches'],
        ));

        if (! empty($data['error_message'])) {
            $this->line('  <fg=gray>Error:</> '.$data['error_message']);
        }
        $this->newLine();
    }

    /**
     * Build the partial-decision callback. Returns one of:
     *   - 'download' — consume the partial set as-is, no further Anthropic spend
     *   - 'resume'   — re-dispatch the worker to complete remaining batches
     *   - 'fresh'    — discard and start a brand new scan from batch 0
     *   - 'cancel'   — abort the install
     *
     * Non-interactive mode (CI, `--no-interaction`) returns 'download' silently
     * per D-P-7 — never an automatic resume that would surprise the user with
     * Anthropic spend.
     */
    private function partialDecisionPrompt(): callable
    {
        return function (array $partialJob): string {
            // Non-interactive runs (CI, --no-interaction, scripts) default
            // to download. Resume must be explicitly opted into.
            if (! $this->input->isInteractive() || $this->option('no-interaction')) {
                $this->line('<fg=gray>•</> Non-interactive mode: downloading partial set.');

                return 'download';
            }

            $remaining = (int) $partialJob['total_batches'] - (int) $partialJob['batches_completed'];
            $generated = (int) ($partialJob['tools_generated'] ?? 0);

            $this->line('  What would you like to do?');
            $this->newLine();
            $this->line(sprintf(
                '    <fg=cyan>[1]</> Download partial tools — <options=bold>%d batch%s</> worth (~%d tools), no Anthropic cost  <fg=gray>[recommended]</>',
                $partialJob['batches_completed'],
                $partialJob['batches_completed'] === 1 ? '' : 'es',
                $generated,
            ));
            $this->line(sprintf(
                '    <fg=cyan>[2]</> Resume the scan — process remaining <options=bold>%d batch%s</>',
                $remaining,
                $remaining === 1 ? '' : 'es',
            ));
            $this->line('    <fg=cyan>[3]</> Start fresh — discard partial, scan from scratch');
            $this->line('    <fg=cyan>[4]</> Cancel');
            $this->newLine();

            $choice = (string) $this->choice('Choice', ['1', '2', '3', '4'], '1');

            return match ($choice) {
                '1' => 'download',
                '2' => 'resume',
                '3' => 'fresh',
                '4' => 'cancel',
                default => 'download',
            };
        };
    }

    /**
     * Pretty-print the pre-submit estimate from Phase E1. Format mirrors
     * the cost preview a thoughtful SaaS product would show before billing.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderEstimate(array $data): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>About to analyze:</>');
        $this->line(sprintf(
            '  <fg=gray>Model:</>            <options=bold>%s</>',
            $data['model'],
        ));
        $this->line(sprintf(
            '  <fg=gray>Candidates:</>       <options=bold>%d</>  (in %d batch%s of %d)',
            $data['candidate_count'],
            $data['estimated_batches'],
            $data['estimated_batches'] === 1 ? '' : 'es',
            $data['batch_size'],
        ));

        $minutes = max(1, (int) round($data['estimated_seconds'] / 60));
        $this->line(sprintf(
            '  <fg=gray>Estimated time:</>   ~<options=bold>%d</> minute%s',
            $minutes,
            $minutes === 1 ? '' : 's',
        ));

        $this->line(sprintf(
            '  <fg=gray>Estimated cost:</>   ~<options=bold>$%.2f</>  <fg=gray>(±30%%; charged by Anthropic at scan time)</>',
            $data['estimated_cost_usd'],
        ));
        $this->newLine();
    }

    /**
     * Build the estimate-confirmation callback. Per Phase E1 (Estimator):
     * the customer sees the cost + time + model BEFORE any Anthropic spend
     * starts. Returns true to proceed, false to cancel cleanly.
     *
     * Skipped (auto-yes) when:
     *   - `--force` is passed (consistent with the existing scan-confirm prompt)
     *   - `--no-interaction` is passed (CI/scripts)
     *   - The input stream is non-interactive (piped scripts)
     */
    private function estimateConfirmPrompt(): callable
    {
        return function (array $estimate): bool {
            if ($this->option('force')
                || $this->option('no-interaction')
                || ! $this->input->isInteractive()
            ) {
                return true;
            }

            return (bool) $this->confirm('Proceed with analysis?', true);
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderApiAccepted(array $data): void
    {
        $estimateMinutes = max(1, (int) round($data['estimated_seconds'] / 60));
        $this->line(sprintf(
            '<fg=green>✓</> Job accepted <fg=gray>(%s)</> — %d batch%s, est ~%d min',
            substr($data['job_id'], 0, 12).'…',
            $data['total_batches'],
            $data['total_batches'] === 1 ? '' : 'es',
            $estimateMinutes,
        ));
    }

    /**
     * In-place progress line — \r returns to column 0, padded spaces clear
     * any leftover text from a longer prior line. Tests fall back gracefully
     * because Symfony's BufferedOutput honors \r as a literal character.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderProgress(array $data): void
    {
        $completed = (int) $data['batches_completed'];
        $total = max(1, (int) $data['total_batches']);
        $percent = (int) round(($completed / $total) * 100);
        $remainingMin = max(0, (int) round($data['estimated_seconds_remaining'] / 60));

        $line = sprintf(
            '  <fg=gray>•</> Analyzing... <options=bold>%d/%d</> batches (%d%%) <fg=gray>~%d min remaining</>',
            $completed,
            $total,
            $percent,
            $remainingMin,
        );

        $this->output->write("\r".str_pad($line, self::PROGRESS_LINE_WIDTH));
    }

    /**
     * Called when we transition from polling to download — terminate the
     * in-place progress line with a newline + checkmark variant.
     */
    private function renderPollDone(): void
    {
        $this->output->write("\r".str_pad('<fg=green>✓</> Analysis complete', self::PROGRESS_LINE_WIDTH));
        $this->newLine();
        $this->line('<fg=gray>•</> Downloading tool definitions...');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderDownloadComplete(array $data): void
    {
        $cost = $data['cost_summary'];
        $costStr = ($cost['input_tokens'] || $cost['output_tokens'])
            ? sprintf(' <fg=gray>(%s in / %s out, ~$%.2f)</>',
                number_format($cost['input_tokens']),
                number_format($cost['output_tokens']),
                $cost['approximate_usd'],
            )
            : '';

        $this->line(sprintf(
            '<fg=green>✓</> Downloaded <options=bold>%d</> tool%s%s',
            $data['tools_count'],
            $data['tools_count'] === 1 ? '' : 's',
            $costStr,
        ));
    }

    private function renderSummary(AnalyzeResult $result): void
    {
        $this->newLine();
        if ($result->wasPartialDownload()) {
            $this->line('<fg=yellow;options=bold>Install complete (partial).</>');
        } else {
            $this->line('<fg=green;options=bold>Install complete.</>');
        }
        $this->newLine();

        $rows = [
            ['Tools generated', (string) $result->toolCount],
            ['Tools written', (string) $result->writeReport->writtenCount()],
            ['Orphans deleted', (string) $result->writeReport->deletedCount()],
            ['Output directory', $result->writeReport->toolsPath],
            ['Job ID', $result->jobId],
        ];

        if ($result->wasAttached()) {
            $rows[] = ['Attached to existing job', 'yes'];
        }

        if ($result->wasPartialDownload() && $result->partialMeta !== null) {
            $meta = $result->partialMeta;
            $rows[] = ['Partial run', sprintf('%d / %d batches', $meta['batches_completed'], $meta['total_batches'])];
            $rows[] = ['Batches remaining', (string) $meta['batches_remaining']];
            $rows[] = ['Resumable', $meta['resumable'] ? 'yes (within 30 days)' : 'no (window expired)'];
        }

        $this->table(['', ''], $rows);
    }

    private function renderNextSteps(): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Next steps</>');
        $this->line('  1. Commit (or gitignore) <fg=cyan>'.config('mindum.tools_path').'</>');
        $this->line('  2. Register the MCP route. In <fg=cyan>routes/ai.php</> or <fg=cyan>routes/web.php</>:');
        $this->line('     <fg=gray>Mcp::local(\'mindum\', config(\'mindum.tools_namespace\'))->path(config(\'mindum.mcp_endpoint\'));</>');
        $this->line('  3. Run <fg=cyan>php artisan mindum:status</> to verify.');
        $this->newLine();
    }

    /**
     * Print the "this is a partial set — you can resume later" note after a
     * partial install. Called from handle() only when the result indicates
     * a partial download.
     */
    private function renderPartialFollowUp(AnalyzeResult $result): void
    {
        if (! $result->wasPartialDownload() || $result->partialMeta === null) {
            return;
        }

        $meta = $result->partialMeta;

        $this->newLine();
        $this->line('<fg=yellow;options=bold>Note: this is a partial tool set.</>');
        $this->line(sprintf(
            '  <fg=gray>%d of %d batches completed before the scan failed.</>',
            $meta['batches_completed'],
            $meta['total_batches'],
        ));

        if (! empty($meta['error_message'])) {
            $this->line('  <fg=gray>Error:</> '.$meta['error_message']);
        }

        if ($meta['resumable']) {
            $this->line('  <fg=gray>To process the remaining '.$meta['batches_remaining'].' batches later, re-run <fg=cyan>php artisan mindum:install</> and choose "Resume" at the prompt.</>');
        } else {
            $this->line('  <fg=gray>The 30-day resume window has expired. To complete the tool set, re-run <fg=cyan>php artisan mindum:install</> and choose "Start fresh".</>');
        }
        $this->newLine();
    }
}
