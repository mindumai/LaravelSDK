<?php

declare(strict_types=1);

namespace Mindum\Laravel\Support;

use Illuminate\Contracts\Foundation\Application;
use Mindum\Laravel\Api\MindumApiClient;
use Mindum\Laravel\Scanner\Scanner;
use Mindum\Laravel\Tools\ToolWriter;
use RuntimeException;

/**
 * Glue between Scanner, MindumApiClient, and ToolWriter — the three pieces
 * that together implement "analyze the customer's codebase, fetch tools,
 * write them to disk."
 *
 * Used by mindum:install and mindum:rescan. Keeping it out of the command
 * classes lets the orchestration be tested independently of CLI plumbing.
 */
class AnalyzeRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly MindumApiClient $client,
        private readonly ToolWriter $writer,
    ) {}

    public function run(?callable $onStep = null): AnalyzeResult
    {
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

        $this->emit($onStep, 'api_start', ['entry_count' => count($entries)]);

        $apiResult = $this->client->analyze($manifest);

        $this->emit($onStep, 'api_complete', [
            'tool_count' => $apiResult['tool_count'],
            'cached' => $apiResult['cached'],
            'manifest_id' => $apiResult['manifest_id'],
            'stats' => $apiResult['stats'],
        ]);

        $toolsPath = (string) $this->app['config']->get('mindum.tools_path');
        if ($toolsPath === '') {
            throw new RuntimeException(
                'config(mindum.tools_path) is empty. Publish the package config with `php artisan vendor:publish --tag=mindum-config` and set tools_path.',
            );
        }

        $this->emit($onStep, 'write_start', ['path' => $toolsPath]);

        $writeReport = $this->writer->write($apiResult['tools'], $toolsPath);

        $this->emit($onStep, 'write_complete', [
            'written' => $writeReport->writtenCount(),
            'deleted' => $writeReport->deletedCount(),
            'path' => $writeReport->toolsPath,
        ]);

        return new AnalyzeResult(
            entryCount: count($entries),
            scannerSkipped: count($scanner->skipped),
            scannerErrors: $scanner->errors,
            apiResult: $apiResult,
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
