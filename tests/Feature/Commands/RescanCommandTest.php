<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

class RescanCommandTest extends TestCase
{
    private string $toolsPath;

    private Filesystem $files;

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $fixtureRoot = realpath(__DIR__.'/../../fixtures/laravel-app');
        $this->toolsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_rescan_'.bin2hex(random_bytes(6));

        $app->setBasePath($fixtureRoot);

        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_rescan');
        $app['config']->set('mindum.tools_path', $this->toolsPath);
        $app['config']->set('mindum.tools_namespace', 'App\\Mindum\\Tools');
        $app['config']->set('mindum.scan_paths', ['app/']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
    }

    protected function tearDown(): void
    {
        if (isset($this->toolsPath) && is_dir($this->toolsPath)) {
            $this->files->deleteDirectory($this->toolsPath);
        }
        parent::tearDown();
    }

    public function test_rescan_fails_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:rescan')
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->assertExitCode(1);
    }

    public function test_rescan_writes_files_and_reports_summary(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'status' => 'ok',
                'manifest_id' => 1,
                'manifest_hash' => str_repeat('b', 64),
                'tool_count' => 1,
                'cached' => false,
                'tools' => [[
                    'name' => 'find_post',
                    'description' => 'Find a single post.',
                    'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                    'handle_code' => 'return null;',
                    'operation_type' => 'read',
                ]],
                'stats' => ['batches' => 1, 'input_tokens' => 100, 'output_tokens' => 50, 'cost_cents' => 0],
            ], 200),
        ]);

        $this->artisan('mindum:rescan')
            ->expectsOutputToContain('mindum: 1 tool')
            ->assertExitCode(0);

        $this->assertFileExists($this->toolsPath.'/FindPost.php');
    }

    public function test_rescan_quiet_mode_skips_step_output(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'status' => 'ok',
                'manifest_id' => 1,
                'manifest_hash' => 'xx',
                'tool_count' => 0,
                'cached' => true,
                'tools' => [],
                'stats' => [],
            ], 200),
        ]);

        $this->artisan('mindum:rescan', ['--quiet-output' => true])
            ->doesntExpectOutputToContain('scanner:')
            ->expectsOutputToContain('mindum: 0 tools')
            ->assertExitCode(0);
    }
}
