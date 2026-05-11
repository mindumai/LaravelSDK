<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tools;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Writes generated tool classes to disk under config('mindum.tools_path').
 *
 * Rescan strategy: Option B — header marker.
 *
 * On every write() call:
 *   1) Ensure the target directory exists.
 *   2) Render and write all incoming tools (overwrites existing files at
 *      the same path).
 *   3) Walk the target directory; any .php file that contains the
 *      `// @mindum-generated` marker AND is NOT in the new tool set
 *      gets deleted (orphan cleanup).
 *   4) Files without the marker are NEVER touched — a user can drop their
 *      own classes in the tools dir and the SDK leaves them alone.
 *
 * The class returns a WriteReport describing exactly what happened — used
 * by mindum:install / mindum:rescan to show the operator a summary.
 */
class ToolWriter
{
    private const HEADER_SCAN_BYTES = 1024;

    public function __construct(
        private readonly ToolClassRenderer $renderer,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function write(array $tools, string $toolsPath): WriteReport
    {
        $this->ensureDirectory($toolsPath);

        $writtenFiles = [];
        $writtenClasses = [];

        foreach ($tools as $tool) {
            $rendered = $this->renderer->render($tool);
            $target = $this->joinPath($toolsPath, $rendered['file_name']);
            $this->filesystem->put($target, $rendered['source']);

            $writtenFiles[$target] = true;
            $writtenClasses[] = $rendered['class_name'];
        }

        $orphansDeleted = $this->deleteOrphans($toolsPath, $writtenFiles);

        return new WriteReport(
            classesWritten: $writtenClasses,
            orphansDeleted: $orphansDeleted,
            toolsPath: $toolsPath,
        );
    }

    private function ensureDirectory(string $path): void
    {
        if ($this->filesystem->isDirectory($path)) {
            return;
        }

        if (! $this->filesystem->makeDirectory($path, 0755, true)) {
            throw new RuntimeException("Could not create tools directory at {$path}");
        }
    }

    /**
     * Delete .php files in $toolsPath that:
     *   - Carry the @mindum-generated marker, AND
     *   - Are NOT in $keep (keys are absolute paths just written this run).
     *
     * @param  array<string, true>  $keep
     * @return array<int, string> Absolute paths of files deleted.
     */
    private function deleteOrphans(string $toolsPath, array $keep): array
    {
        $deleted = [];

        foreach ($this->filesystem->files($toolsPath) as $fileInfo) {
            $path = $fileInfo->getPathname();

            if (! str_ends_with($path, '.php')) {
                continue;
            }

            if (isset($keep[$path])) {
                continue;
            }

            if (! $this->fileIsMindumGenerated($path)) {
                continue;
            }

            $this->filesystem->delete($path);
            $deleted[] = $path;
        }

        return $deleted;
    }

    /**
     * Inspect the first ~1KB of a file for the SDK marker. We avoid reading
     * the whole file because customer-owned files could be large and we
     * never need more than the top header to make a determination.
     */
    private function fileIsMindumGenerated(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $head = @fread($handle, self::HEADER_SCAN_BYTES);
        } finally {
            @fclose($handle);
        }

        if (! is_string($head)) {
            return false;
        }

        return str_contains($head, ToolClassRenderer::MARKER);
    }

    private function joinPath(string $dir, string $file): string
    {
        return rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;
    }
}
