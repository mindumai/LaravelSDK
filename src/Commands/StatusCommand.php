<?php

declare(strict_types=1);

namespace Mindum\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Mindum\Laravel\Tools\ToolClassRenderer;

/**
 * `php artisan mindum:status`
 *
 * Diagnostic command. Shows current configuration, what tool files are
 * on disk, and which of them carry the SDK's @mindum-generated marker.
 *
 * Does NOT call the Mindum API — it's safe to run offline and on every
 * deploy as a sanity check.
 */
class StatusCommand extends Command
{
    protected $signature = 'mindum:status';

    protected $description = 'Show Mindum SDK configuration and the current state of installed tool files.';

    public function handle(Filesystem $filesystem): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>Mindum status</>');
        $this->newLine();

        $this->renderConfig();
        $this->renderTools($filesystem);
        $this->newLine();

        return self::SUCCESS;
    }

    private function renderConfig(): void
    {
        $apiKey = (string) config('mindum.api_key', '');
        $apiKeyDisplay = $apiKey === '' ? '<fg=red>not set</>' : '<fg=green>set</> '.$this->redactKey($apiKey);

        $rows = [
            ['API URL', (string) config('mindum.api_url', '(not set)')],
            ['API key', $apiKeyDisplay],
            ['MCP endpoint', (string) config('mindum.mcp_endpoint', '(not set)')],
            ['Tools path', (string) config('mindum.tools_path', '(not set)')],
            ['Tools namespace', (string) config('mindum.tools_namespace', '(not set)')],
            ['Scan paths', implode(', ', (array) config('mindum.scan_paths', []))],
        ];

        $this->table(['Setting', 'Value'], $rows);
    }

    private function renderTools(Filesystem $filesystem): void
    {
        $this->newLine();
        $toolsPath = (string) config('mindum.tools_path', '');

        if ($toolsPath === '' || ! $filesystem->isDirectory($toolsPath)) {
            $this->line('<fg=yellow>Tool directory does not exist yet.</>');
            $this->line('Run <fg=cyan>php artisan mindum:install</> to generate your first tool set.');

            return;
        }

        $generated = 0;
        $userFiles = 0;
        $generatedNames = [];

        foreach ($filesystem->files($toolsPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $head = @file_get_contents($file->getPathname(), false, null, 0, 1024);
            if (is_string($head) && str_contains($head, ToolClassRenderer::MARKER)) {
                $generated++;
                $generatedNames[] = $file->getBasename('.php');
            } else {
                $userFiles++;
            }
        }

        $this->line('<options=bold>Tool files at '.$toolsPath.':</>');
        $this->line(sprintf('  <fg=green>%d</> generated (carry the @mindum-generated marker)', $generated));
        $this->line(sprintf('  <fg=gray>%d</> user-owned (no marker — SDK leaves these alone)', $userFiles));

        if ($generated > 0) {
            sort($generatedNames);
            $this->newLine();
            $this->line('<fg=gray>Generated tools:</>');
            foreach ($generatedNames as $name) {
                $this->line('  '.$name);
            }
        }
    }

    private function redactKey(string $key): string
    {
        if (strlen($key) <= 8) {
            return '<fg=gray>(redacted)</>';
        }

        return '<fg=gray>'.substr($key, 0, 4).'…'.substr($key, -4).'</>';
    }
}
