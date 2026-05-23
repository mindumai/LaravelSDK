<?php

declare(strict_types=1);

namespace Mindum\Laravel\Commands;

use Illuminate\Console\Command;
use Mindum\Laravel\Support\AnalyzeResult;
use Mindum\Laravel\Support\AnalyzeRunner;
use RuntimeException;
use Throwable;

/**
 * `php artisan mindum:rescan`
 *
 * Re-runs the full analyze pipeline. Same engine as mindum:install, but
 * intended to be called from a deploy script — quieter output, no
 * confirmation prompt, exit code is what matters.
 */
class RescanCommand extends Command
{
    protected $signature = 'mindum:rescan {--quiet-output : Suppress per-step output, print only the final summary line}';

    protected $description = 'Re-scan your Laravel app and regenerate Mindum tool classes.';

    public function handle(AnalyzeRunner $runner): int
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            $this->error('MINDUM_API_KEY is not set. Run `php artisan mindum:install` first.');

            return self::FAILURE;
        }

        $quiet = (bool) $this->option('quiet-output');

        try {
            $result = $runner->run($quiet ? null : $this->stepListener());
        } catch (RuntimeException $e) {
            $this->error('Rescan failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->renderFinalLine($result);

        return self::SUCCESS;
    }

    private function stepListener(): callable
    {
        return function (string $event, array $data): void {
            match ($event) {
                'scan_complete' => $this->line(sprintf('  scanner:  %d entries', $data['entry_count'])),
                'api_accepted' => $this->line(sprintf(
                    '  api:      job %s queued (%d batches)',
                    substr($data['job_id'], 0, 12).'…',
                    $data['total_batches'],
                )),
                'attach_completed' => $this->line(sprintf(
                    '  attach:   downloading from job %s (already complete)',
                    substr($data['job_id'], 0, 12).'…',
                )),
                'attach_in_flight' => $this->line(sprintf(
                    '  attach:   joining job %s at %d/%d batches',
                    substr($data['job_id'], 0, 12).'…',
                    $data['batches_completed'],
                    $data['total_batches'],
                )),
                'poll_progress' => $this->line(sprintf(
                    '  poll:     %d/%d batches done',
                    $data['batches_completed'],
                    $data['total_batches'],
                )),
                'download_complete' => $this->line(sprintf(
                    '  download: %d tools (~$%.2f)',
                    $data['tools_count'],
                    $data['cost_summary']['approximate_usd'],
                )),
                'write_complete' => $this->line(sprintf(
                    '  writer:   %d written, %d orphans deleted',
                    $data['written'],
                    $data['deleted'],
                )),
                default => null,
            };
        };
    }

    private function renderFinalLine(AnalyzeResult $result): void
    {
        $this->info(sprintf(
            'mindum: %d tool%s%s at %s',
            $result->toolCount,
            $result->toolCount === 1 ? '' : 's',
            $result->wasAttached() ? ' (attached)' : '',
            $result->writeReport->toolsPath,
        ));
    }
}
