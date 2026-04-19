<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner;

/**
 * Pre-scan index mapping fully-qualified class names to their file paths.
 *
 * Populated by the scanner's pre-pass. Used by extractors that need to
 * resolve cross-file references — e.g., ControllerExtractor looking up
 * a form request's file, or RepositoryExtractor resolving the base class.
 */
class SymbolIndex
{
    /** @var array<string, string> FQCN => absolute file path */
    private array $classes = [];

    public function register(string $fqcn, string $filePath): void
    {
        $this->classes[$fqcn] = $filePath;
    }

    public function findFile(string $fqcn): ?string
    {
        return $this->classes[$fqcn] ?? null;
    }

    public function has(string $fqcn): bool
    {
        return isset($this->classes[$fqcn]);
    }

    public function size(): int
    {
        return count($this->classes);
    }
}
