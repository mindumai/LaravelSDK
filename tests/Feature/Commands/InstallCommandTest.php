<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

class InstallCommandTest extends TestCase
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
        $this->toolsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_install_'.bin2hex(random_bytes(6));

        $app->setBasePath($fixtureRoot);

        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_install');
        $app['config']->set('mindum.tools_path', $this->toolsPath);
        $app['config']->set('mindum.tools_namespace', 'App\\Mindum\\Tools');
        $app['config']->set('mindum.scan_paths', ['app/']);
        $app['config']->set('mindum.api_timeout_seconds', 30);
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

    public function test_install_fails_when_api_key_missing(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('MINDUM_API_KEY is not set')
            ->assertExitCode(1);
    }

    public function test_install_runs_end_to_end_against_faked_api(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response($this->fakeApiResponse(), 200),
        ]);

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('Mindum install')
            ->expectsOutputToContain('Install complete.')
            ->assertExitCode(0);

        // Files actually appeared on disk.
        $this->assertFileExists($this->toolsPath.'/CreatePost.php');
        $this->assertFileExists($this->toolsPath.'/ListPosts.php');

        // Files carry the marker.
        $this->assertStringContainsString(
            '@mindum-generated',
            file_get_contents($this->toolsPath.'/CreatePost.php'),
        );

        // API was actually called with the manifest envelope.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/analyze')
                && $request->hasHeader('Authorization', 'Bearer mk_test_install')
                && is_array($request['manifest'])
                && isset($request['manifest']['entries'])
                && count($request['manifest']['entries']) > 0;
        });
    }

    public function test_install_surfaces_api_failure(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response([
                'error' => 'invalid_api_key',
                'message' => 'Bad key',
            ], 401),
        ]);

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('HTTP 401')
            ->assertExitCode(1);
    }

    public function test_install_succeeds_with_cached_response(): void
    {
        Http::fake([
            'api.mindum.ai/*' => Http::response(
                array_merge($this->fakeApiResponse(), ['cached' => true]),
                200,
            ),
        ]);

        $this->artisan('mindum:install', ['--force' => true])
            ->expectsOutputToContain('cached — same manifest')
            ->assertExitCode(0);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeApiResponse(): array
    {
        return [
            'status' => 'ok',
            'manifest_id' => 7,
            'manifest_hash' => str_repeat('a', 64),
            'tool_count' => 2,
            'cached' => false,
            'tools' => [
                [
                    'name' => 'create_post',
                    'description' => 'Creates a post.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['title' => ['type' => 'string']],
                        'required' => ['title'],
                    ],
                    'handle_code' => 'return null;',
                    'operation_type' => 'write',
                    'source_class' => 'App\\Models\\Post',
                ],
                [
                    'name' => 'list_posts',
                    'description' => 'Lists posts.',
                    'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    'handle_code' => 'return [];',
                    'operation_type' => 'read',
                    'source_class' => 'App\\Models\\Post',
                ],
            ],
            'stats' => [
                'batches' => 1,
                'input_tokens' => 1200,
                'output_tokens' => 800,
                'cost_cents' => 1,
            ],
        ];
    }
}
