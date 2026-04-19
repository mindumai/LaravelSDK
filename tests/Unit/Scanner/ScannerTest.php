<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit\Scanner;

use Mindum\Laravel\Scanner\Scanner;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end scanner test. Runs the scanner against tests/fixtures/laravel-app
 * and asserts that each extractor fired at least once and produced entries
 * with the expected shape.
 */
class ScannerTest extends TestCase
{
    private const FIXTURE_ROOT = __DIR__.'/../../fixtures/laravel-app';

    /** @var array<int, array<string, mixed>> */
    private static array $entries;

    public static function setUpBeforeClass(): void
    {
        $scanner = new Scanner(
            appName: 'fixture-app',
            appRoot: self::FIXTURE_ROOT,
            scanPaths: ['app/'],
        );

        self::$entries = $scanner->scan();
    }

    public function test_scanner_produces_at_least_one_entry_per_kind(): void
    {
        $byKind = [];
        foreach (self::$entries as $entry) {
            $byKind[$entry['kind']] = ($byKind[$entry['kind']] ?? 0) + 1;
        }

        $this->assertArrayHasKey('action', $byKind, 'CreatePost action missing');
        $this->assertArrayHasKey('controller_endpoint', $byKind, 'PostController endpoints missing');
        $this->assertArrayHasKey('job', $byKind, 'PublishPost job missing');
        $this->assertArrayHasKey('model_crud', $byKind, 'Post model CRUD tools missing');
        $this->assertArrayHasKey('repository_method', $byKind, 'PostRepository methods missing');
    }

    public function test_queued_job_is_skipped(): void
    {
        foreach (self::$entries as $entry) {
            $class = $entry['source']['class'] ?? '';
            $this->assertNotSame('App\\Jobs\\SendWelcomeEmail', $class,
                'ShouldQueue job must be skipped');
        }
    }

    public function test_action_entry_shape(): void
    {
        $action = $this->firstOfKind('action');

        $this->assertSame('App\\Services\\CreatePost', $action['source']['class']);
        $this->assertSame('execute', $action['source']['entry_method']);
        $this->assertSame('App\\Models\\Post', $action['source']['returns']);
        $this->assertSame('rules_method', $action['input']['schema_source']);
        $this->assertNotEmpty($action['input']['fields']);
        $this->assertContains('title', array_column($action['input']['fields'], 'name'));
    }

    public function test_controller_endpoint_entry_shape(): void
    {
        $controllerEntries = $this->allOfKind('controller_endpoint');
        $this->assertGreaterThanOrEqual(3, count($controllerEntries),
            'Expected at least 3 controller endpoints (index/store/show)');

        $store = $this->findWhere($controllerEntries,
            fn ($e) => $e['source']['entry_method'] === 'store');
        $this->assertNotNull($store);
        $this->assertSame('App\\Http\\Controllers\\PostController', $store['source']['class']);
        $this->assertContains(
            'App\\Jobs\\PublishPost',
            $store['kind_data']['dispatches_jobs'] ?? [],
        );
    }

    public function test_job_entry_shape_and_controller_link(): void
    {
        $job = $this->firstOfKind('job');
        $this->assertSame('App\\Jobs\\PublishPost', $job['source']['class']);
        $this->assertSame('handle', $job['source']['entry_method']);

        // DuplicateLinker should have paired the job with its controller dispatch site.
        $pairedControllers = $job['kind_data']['paired_with_controllers'] ?? [];
        $this->assertContains(
            'App\\Http\\Controllers\\PostController::store',
            $pairedControllers,
        );
    }

    public function test_model_crud_entry_shape(): void
    {
        $modelEntries = $this->allOfKind('model_crud');
        $this->assertGreaterThan(0, count($modelEntries));
        foreach ($modelEntries as $entry) {
            $this->assertSame('App\\Models\\Post', $entry['source']['class']);
        }

        // The `create` sub-entry carries fillable fields (title, slug, body, ...).
        $create = $this->findWhere(
            $modelEntries,
            fn ($e) => $e['kind_data']['operation'] === 'create',
        );
        $this->assertNotNull($create, 'create sub-entry missing');
        $fields = $create['input']['fields'] ?? [];
        $this->assertContains('title', array_column($fields, 'name'));

        // The `list` sub-entry carries pagination fields (page, per_page, ...).
        $list = $this->findWhere(
            $modelEntries,
            fn ($e) => $e['kind_data']['operation'] === 'list',
        );
        $this->assertNotNull($list, 'list sub-entry missing');

        // SoftDeletes trait should have emitted a restore tool.
        $restore = $this->findWhere(
            $modelEntries,
            fn ($e) => $e['kind_data']['operation'] === 'restore',
        );
        $this->assertNotNull($restore, 'restore tool missing for SoftDeletes model');
    }

    public function test_repository_method_entry_shape(): void
    {
        $repoEntries = $this->allOfKind('repository_method');
        $this->assertGreaterThan(0, count($repoEntries));
        foreach ($repoEntries as $e) {
            $this->assertSame('App\\Repositories\\PostRepository', $e['source']['class']);
        }
    }

    public function test_linker_stats_reflect_paired_count(): void
    {
        // At least the PostController::store → PublishPost pair should link.
        // Re-run to read stats.
        $scanner = new Scanner(
            appName: 'fixture-app',
            appRoot: self::FIXTURE_ROOT,
            scanPaths: ['app/'],
        );
        $scanner->scan();

        $this->assertGreaterThanOrEqual(1, $scanner->linkerStats['controller_job_pairs']);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstOfKind(string $kind): array
    {
        foreach (self::$entries as $entry) {
            if ($entry['kind'] === $kind) {
                return $entry;
            }
        }
        $this->fail("No entry of kind {$kind} found");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allOfKind(string $kind): array
    {
        return array_values(array_filter(
            self::$entries,
            fn (array $e) => $e['kind'] === $kind,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function findWhere(array $entries, callable $predicate): ?array
    {
        foreach ($entries as $entry) {
            if ($predicate($entry)) {
                return $entry;
            }
        }

        return null;
    }
}
