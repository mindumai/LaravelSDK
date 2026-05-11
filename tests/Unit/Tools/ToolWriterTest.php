<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit\Tools;

use Illuminate\Filesystem\Filesystem;
use Mindum\Laravel\Tools\ToolClassRenderer;
use Mindum\Laravel\Tools\ToolWriter;
use PHPUnit\Framework\TestCase;

class ToolWriterTest extends TestCase
{
    private string $tempDir;

    private ToolWriter $writer;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mindum_writer_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);

        $this->files = new Filesystem;
        $this->writer = new ToolWriter(new ToolClassRenderer, $this->files);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_writes_one_file_per_tool(): void
    {
        $report = $this->writer->write([
            $this->tool('create_post'),
            $this->tool('list_posts'),
            $this->tool('delete_post'),
        ], $this->tempDir);

        $this->assertSame(3, $report->writtenCount());
        $this->assertFileExists($this->tempDir.'/CreatePost.php');
        $this->assertFileExists($this->tempDir.'/ListPosts.php');
        $this->assertFileExists($this->tempDir.'/DeletePost.php');
    }

    public function test_creates_target_directory_if_missing(): void
    {
        $nested = $this->tempDir.'/deep/nested/Tools';
        $this->assertDirectoryDoesNotExist($nested);

        $this->writer->write([$this->tool('create_post')], $nested);

        $this->assertDirectoryExists($nested);
        $this->assertFileExists($nested.'/CreatePost.php');
    }

    public function test_rescan_overwrites_existing_files(): void
    {
        // First write.
        $this->writer->write([
            $this->tool('create_post', description: 'v1 description'),
        ], $this->tempDir);

        $this->assertStringContainsString('v1 description', file_get_contents($this->tempDir.'/CreatePost.php'));

        // Rescan with updated description.
        $this->writer->write([
            $this->tool('create_post', description: 'v2 description'),
        ], $this->tempDir);

        $contents = file_get_contents($this->tempDir.'/CreatePost.php');
        $this->assertStringContainsString('v2 description', $contents);
        $this->assertStringNotContainsString('v1 description', $contents);
    }

    public function test_rescan_deletes_orphaned_sdk_files(): void
    {
        // Install: 3 tools.
        $this->writer->write([
            $this->tool('create_post'),
            $this->tool('list_posts'),
            $this->tool('delete_post'),
        ], $this->tempDir);

        $this->assertFileExists($this->tempDir.'/DeletePost.php');

        // Rescan: delete_post is gone.
        $report = $this->writer->write([
            $this->tool('create_post'),
            $this->tool('list_posts'),
        ], $this->tempDir);

        $this->assertFileDoesNotExist($this->tempDir.'/DeletePost.php');
        $this->assertSame(1, $report->deletedCount());
        $this->assertStringEndsWith('DeletePost.php', $report->orphansDeleted[0]);
    }

    public function test_rescan_does_not_touch_user_files_without_marker(): void
    {
        // User dropped their own helper before mindum:install.
        $userFile = $this->tempDir.'/MyCustomHelper.php';
        file_put_contents($userFile, "<?php\n\n// I wrote this by hand.\n\nclass MyCustomHelper {}\n");

        // Install adds three tools.
        $this->writer->write([
            $this->tool('create_post'),
            $this->tool('list_posts'),
        ], $this->tempDir);

        $this->assertFileExists($userFile, 'User file must not be touched by install');
        $this->assertStringContainsString('I wrote this by hand', file_get_contents($userFile));

        // Rescan with a different tool set — still must not touch user file.
        $this->writer->write([
            $this->tool('find_user'),
        ], $this->tempDir);

        $this->assertFileExists($userFile, 'User file must survive rescan');
        $this->assertFileDoesNotExist($this->tempDir.'/CreatePost.php', 'Orphan SDK file must be deleted');
    }

    public function test_user_file_with_php_extension_but_no_marker_survives(): void
    {
        // Even if the user names their file something tool-like, no marker = no touch.
        $userFile = $this->tempDir.'/CreatePost.php';
        file_put_contents($userFile, "<?php\n\nclass CreatePost { /* hand-written override */ }\n");

        $this->writer->write([$this->tool('list_posts')], $this->tempDir);

        // The user's CreatePost.php is preserved because it lacks the marker.
        $this->assertFileExists($userFile);
        $this->assertStringContainsString('hand-written override', file_get_contents($userFile));
    }

    public function test_returns_correct_class_names_in_report(): void
    {
        $report = $this->writer->write([
            $this->tool('list_users'),
            $this->tool('create_user'),
        ], $this->tempDir);

        $this->assertSame(['ListUsers', 'CreateUser'], $report->classesWritten);
        $this->assertSame($this->tempDir, $report->toolsPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description = 'A tool'): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'handle_code' => 'return null;',
            'operation_type' => 'read',
            'source_class' => 'App\\Stub',
        ];
    }
}
