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
            $result = $runner->run($this->stepListener());
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
        $this->renderNextSteps();

        return self::SUCCESS;
    }

    private function verifyConfig(): bool
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            $this->error('MINDUM_API_KEY is not set. Add it to your .env and re-run.');
            $this->line('  Get a key at <fg=cyan>https://mindum.online</> (or use your existing one).');

            return false;
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
                'scan_start' => $this->line('<fg=gray>•</> Scanning codebase...'),
                'scan_complete' => $this->line(sprintf(
                    '<fg=green>✓</> Found <options=bold>%d</> candidate%s (%d skipped, %d parse errors)',
                    $data['entry_count'],
                    $data['entry_count'] === 1 ? '' : 's',
                    $data['skipped'],
                    $data['errors'],
                )),
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
        $this->line('<fg=green;options=bold>Install complete.</>');
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
}
