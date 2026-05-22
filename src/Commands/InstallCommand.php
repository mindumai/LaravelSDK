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
 * First-run command: verifies configuration, scans the customer's codebase,
 * uploads the manifest to Mindum's API, writes the returned tool classes
 * to disk. Same engine as mindum:rescan — the difference is purely the
 * UX framing ("installing for the first time" vs. "refreshing").
 */
class InstallCommand extends Command
{
    protected $signature = 'mindum:install {--force : Skip the confirmation prompt}';

    protected $description = 'Scan your Laravel app and install Mindum-generated MCP tool classes.';

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
                'scan_start' => $this->line('<fg=gray>•</> Scanning codebase...'),
                'scan_complete' => $this->line(sprintf(
                    '<fg=green>✓</> Found <options=bold>%d</> candidate%s (%d skipped, %d parse errors)',
                    $data['entry_count'],
                    $data['entry_count'] === 1 ? '' : 's',
                    $data['skipped'],
                    $data['errors'],
                )),
                'api_start' => $this->line('<fg=gray>•</> Uploading manifest to '.config('mindum.api_url').'...'),
                'api_complete' => $this->renderApiComplete($data),
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
    private function renderApiComplete(array $data): void
    {
        if ($data['cached']) {
            $this->line(sprintf(
                '<fg=green>✓</> API returned <options=bold>%d</> tool%s <fg=gray>(cached — same manifest as previous scan)</>',
                $data['tool_count'],
                $data['tool_count'] === 1 ? '' : 's',
            ));

            return;
        }

        $stats = $data['stats'];
        $inputTokens = (int) ($stats['input_tokens'] ?? 0);
        $outputTokens = (int) ($stats['output_tokens'] ?? 0);
        $costCents = (int) ($stats['cost_cents'] ?? 0);
        $batches = (int) ($stats['batches'] ?? 0);

        $tokenSummary = ($inputTokens || $outputTokens)
            ? sprintf(' <fg=gray>(%s in / %s out, ~$%.2f)</>',
                number_format($inputTokens),
                number_format($outputTokens),
                $costCents / 100,
            )
            : '';

        $this->line(sprintf(
            '<fg=green>✓</> API generated <options=bold>%d</> tool%s in %d batch%s%s',
            $data['tool_count'],
            $data['tool_count'] === 1 ? '' : 's',
            $batches,
            $batches === 1 ? '' : 'es',
            $tokenSummary,
        ));
    }

    private function renderSummary(AnalyzeResult $result): void
    {
        $this->newLine();
        $this->line('<fg=green;options=bold>Install complete.</>');
        $this->newLine();

        $rows = [
            ['Tools written', (string) $result->writeReport->writtenCount()],
            ['Orphans deleted', (string) $result->writeReport->deletedCount()],
            ['Output directory', $result->writeReport->toolsPath],
            ['Manifest hash', substr($result->manifestHash(), 0, 16).'…'],
        ];

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
