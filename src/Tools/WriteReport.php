<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tools;

/**
 * Immutable summary of a single ToolWriter::write() invocation. Returned to
 * Artisan commands so they can render a human-readable result table.
 */
final class WriteReport
{
    /**
     * @param  array<int, string>  $classesWritten  Short class names (no namespace).
     * @param  array<int, string>  $orphansDeleted  Absolute paths.
     */
    public function __construct(
        public readonly array $classesWritten,
        public readonly array $orphansDeleted,
        public readonly string $toolsPath,
    ) {}

    public function writtenCount(): int
    {
        return count($this->classesWritten);
    }

    public function deletedCount(): int
    {
        return count($this->orphansDeleted);
    }
}
