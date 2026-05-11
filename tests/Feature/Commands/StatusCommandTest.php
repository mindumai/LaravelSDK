<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Commands;

use Illuminate\Filesystem\Filesystem;
use Mindum\Laravel\MindumServiceProvider;
use Mindum\Laravel\Tools\ToolClassRenderer;
use Orchestra\Testbench\TestCase;

class StatusCommandTest extends TestCase
{
    private string $toolsPath;

    private Filesystem $files;

    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->toolsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_status_'.bin2hex(random_bytes(6));

        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_abcdef1234');
        $app['config']->set('mindum.tools_path', $this->toolsPath);
        $app['config']->set('mindum.tools_namespace', 'App\\Mindum\\Tools');
        $app['config']->set('mindum.scan_paths', ['app/']);
        $app['config']->set('mindum.mcp_endpoint', '/mindum/mcp');
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

    public function test_status_shows_config_with_redacted_api_key(): void
    {
        $this->artisan('mindum:status')
            ->expectsOutputToContain('Mindum status')
            ->expectsOutputToContain('https://api.mindum.ai')
            ->expectsOutputToContain('mk_t')
            ->doesntExpectOutputToContain('mk_test_abcdef1234')
            ->assertExitCode(0);
    }

    public function test_status_reports_missing_tools_directory(): void
    {
        $this->artisan('mindum:status')
            ->expectsOutputToContain('Tool directory does not exist yet')
            ->assertExitCode(0);
    }

    public function test_status_distinguishes_generated_vs_user_files(): void
    {
        mkdir($this->toolsPath, 0755, true);

        // Two SDK-generated files (carry marker).
        file_put_contents($this->toolsPath.'/CreatePost.php', "<?php\n\n".ToolClassRenderer::MARKER."\n\nclass CreatePost {}\n");
        file_put_contents($this->toolsPath.'/ListPosts.php', "<?php\n\n".ToolClassRenderer::MARKER."\n\nclass ListPosts {}\n");
        // One user-owned file (no marker).
        file_put_contents($this->toolsPath.'/MyHelper.php', "<?php\n\nclass MyHelper {}\n");

        $this->artisan('mindum:status')
            ->expectsOutputToContain('2 generated')
            ->expectsOutputToContain('1 user-owned')
            ->expectsOutputToContain('CreatePost')
            ->expectsOutputToContain('ListPosts')
            ->doesntExpectOutputToContain('MyHelper')
            ->assertExitCode(0);
    }

    public function test_status_reports_missing_api_key(): void
    {
        config()->set('mindum.api_key', '');

        $this->artisan('mindum:status')
            ->expectsOutputToContain('not set')
            ->assertExitCode(0);
    }
}
