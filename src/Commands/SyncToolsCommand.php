<?php

declare(strict_types=1);

namespace Mindum\Laravel\Commands;

use Illuminate\Console\Command;
use Mindum\Laravel\Api\MindumApiClient;
use Mindum\Laravel\Mcp\ToolDiscovery;
use Mindum\Laravel\Tools\GeneratedTool;
use RuntimeException;
use Throwable;

/**
 * `php artisan mindum:sync-tools`
 *
 * Registers the tool classes installed at `mindum.tools_path` with the
 * Mindum orchestrator WITHOUT running a scan (CC-D2, Docs/Concierge_Chat_Plan.md).
 * Hand-written tools then show in the dashboard with enable/disable,
 * description overrides and agent scoping, exactly like scanned ones.
 *
 * Every tool must declare `operationType()` (CC-D3). The command refuses to
 * sync while any tool has not — a write tool that reaches the orchestrator
 * undeclared would be gated on its name alone, which is the gap this exists
 * to close. Refusing is deliberate: fix the file, run again.
 */
class SyncToolsCommand extends Command
{
    protected $signature = 'mindum:sync-tools {--dry-run : Validate and list what would be sent, without calling the API}';

    protected $description = 'Register the installed Mindum tool classes with the orchestrator (no scan).';

    public function handle(MindumApiClient $client): int
    {
        $classes = ToolDiscovery::discover();

        if ($classes === []) {
            $this->error('No Mindum tools found at '.(string) config('mindum.tools_path', '(tools_path not set)').'.');

            return self::FAILURE;
        }

        $tools = [];
        $undeclared = [];
        $invalid = [];
        $broken = [];

        foreach ($classes as $fqcn) {
            try {
                /** @var GeneratedTool $tool */
                $tool = app()->make($fqcn);
                $operationType = $tool->operationType();
                $entry = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'input_schema' => $tool->inputSchema(),
                    'operation_type' => $operationType,
                    'source_class' => $fqcn,
                ];
            } catch (Throwable $e) {
                $broken[] = $fqcn.' — '.$e->getMessage();

                continue;
            }

            if ($operationType === null) {
                $undeclared[] = $fqcn;

                continue;
            }

            if (! in_array($operationType, GeneratedTool::OPERATION_TYPES, true)) {
                $invalid[] = $fqcn.' — "'.$operationType.'"';

                continue;
            }

            $tools[] = $entry;
        }

        if ($broken !== [] || $undeclared !== [] || $invalid !== []) {
            $this->reportRefusal($broken, $undeclared, $invalid);

            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('<options=bold>%d tool(s) ready to sync</>', count($tools)));
        $this->table(
            ['Tool', 'Operation', 'Class'],
            array_map(fn (array $t) => [$t['name'], $t['operation_type'], class_basename($t['source_class'])], $tools),
        );

        if ($this->option('dry-run')) {
            $this->line('<fg=yellow>Dry run — nothing sent.</>');

            return self::SUCCESS;
        }

        try {
            $result = $client->syncTools($tools);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '<fg=green>Synced %d tool(s)</> — %d created, %d updated.',
            $result['synced'],
            $result['created'],
            $result['updated'],
        ));

        if ($result['disabled'] !== []) {
            $this->line(sprintf(
                '<fg=yellow>%d tool(s) no longer installed here were disabled on the dashboard:</> %s',
                count($result['disabled']),
                implode(', ', $result['disabled']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $broken
     * @param  list<string>  $undeclared
     * @param  list<string>  $invalid
     */
    private function reportRefusal(array $broken, array $undeclared, array $invalid): void
    {
        $this->error('Refusing to sync — fix the tools below and run again.');

        if ($undeclared !== []) {
            $this->newLine();
            $this->line(sprintf('<fg=red>%d tool(s) do not declare operationType():</>', count($undeclared)));
            foreach ($undeclared as $fqcn) {
                $this->line('  '.$fqcn);
            }
            $this->newLine();
            $this->line('Add to each class one of:');
            $this->line("  public function operationType(): ?string { return 'read'; }");
            $this->line("  public function operationType(): ?string { return 'write'; }");
            $this->line("  public function operationType(): ?string { return 'delete'; }");
        }

        if ($invalid !== []) {
            $this->newLine();
            $this->line('<fg=red>Unknown operationType() (must be read, write or delete):</>');
            foreach ($invalid as $line) {
                $this->line('  '.$line);
            }
        }

        if ($broken !== []) {
            $this->newLine();
            $this->line('<fg=red>Could not instantiate:</>');
            foreach ($broken as $line) {
                $this->line('  '.$line);
            }
        }
    }
}
