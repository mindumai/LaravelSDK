<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner;

use Mindum\Laravel\Scanner\Extractors\ActionExtractor;
use Mindum\Laravel\Scanner\Extractors\ControllerExtractor;
use Mindum\Laravel\Scanner\Extractors\JobExtractor;
use Mindum\Laravel\Scanner\Extractors\ModelExtractor;
use Mindum\Laravel\Scanner\Extractors\RepositoryExtractor;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Main scanner orchestrator. Two-pass design:
 *   1) Build a SymbolIndex mapping FQCN => file path.
 *   2) Walk files again, run extractors (which can resolve cross-file
 *      references via the index).
 *   3) Cross-link controller↔job pairs and same-id conflicts.
 */
class Scanner
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string> */
    public array $errors = [];

    /** @var array<string> */
    public array $skipped = [];

    /** @var array<string, int> */
    public array $linkerStats = [
        'controller_job_pairs' => 0,
        'same_id_conflict_groups' => 0,
    ];

    public function __construct(
        private readonly string $appName,
        private readonly string $appRoot,
        /** @var array<string> */
        private readonly array $scanPaths,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(): array
    {
        // Phase 1: pre-scan, build FQCN -> file index.
        $index = $this->buildSymbolIndex();

        // Phase 2: run extractors with access to the index.
        $extractors = [
            new ActionExtractor($index, $this->parser),
            new ModelExtractor,
            new ControllerExtractor($index, $this->parser),
            new JobExtractor($index, $this->parser),
            new RepositoryExtractor($index, $this->parser),
        ];

        $entries = [];
        foreach ($this->collectPhpFiles() as $file) {
            $ast = $this->parseFile($file);
            if ($ast === null) {
                continue;
            }

            $matched = false;
            foreach ($extractors as $extractor) {
                $produced = $extractor->extract($file, $ast, $this->appRoot);
                if ($produced !== []) {
                    foreach ($produced as $entry) {
                        $entries[] = $entry;
                    }
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $this->skipped[] = $this->relativePath($file);
            }
        }

        // Phase 3: cross-link duplicates (controller↔job pairs, same-id conflicts).
        $linker = new DuplicateLinker;
        $entries = $linker->link($entries);
        $this->linkerStats = [
            'controller_job_pairs' => $linker->controllerJobPairsLinked,
            'same_id_conflict_groups' => $linker->sameIdConflictGroups,
        ];

        return $entries;
    }

    public function appName(): string
    {
        return $this->appName;
    }

    private function buildSymbolIndex(): SymbolIndex
    {
        $index = new SymbolIndex;

        foreach ($this->collectPhpFiles() as $file) {
            $ast = $this->parseFile($file);
            if ($ast === null) {
                continue;
            }
            $fqcn = $this->extractClassFqcn($ast);
            if ($fqcn !== null) {
                $index->register($fqcn, $file);
            }
        }

        return $index;
    }

    /** @param array<Node> $ast */
    private function extractClassFqcn(array $ast): ?string
    {
        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class || $class->name === null) {
            return null;
        }
        $namespace = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
        $ns = $namespace?->name?->toString();

        return $ns !== null ? "{$ns}\\{$class->name->toString()}" : $class->name->toString();
    }

    /**
     * @return iterable<string>
     */
    private function collectPhpFiles(): iterable
    {
        foreach ($this->scanPaths as $pattern) {
            $fullPattern = rtrim($this->appRoot, '/\\').DIRECTORY_SEPARATOR.$pattern;
            $roots = str_contains($pattern, '*')
                ? glob($fullPattern, GLOB_ONLYDIR)
                : [rtrim($fullPattern, '/\\')];

            foreach ($roots ?: [] as $root) {
                if (! is_dir($root)) {
                    continue;
                }
                yield from $this->walkDirectory($root);
            }
        }
    }

    /**
     * @return iterable<string>
     */
    private function walkDirectory(string $dir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            yield $file->getPathname();
        }
    }

    /**
     * @return array<Node>|null
     */
    private function parseFile(string $file): ?array
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            $this->errors[] = "read_failed: {$this->relativePath($file)}";

            return null;
        }

        try {
            $ast = $this->parser->parse($code);

            return $ast ?? [];
        } catch (ParserError $e) {
            $this->errors[] = "parse_error: {$this->relativePath($file)} — {$e->getMessage()}";

            return null;
        }
    }

    private function relativePath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', $this->appRoot);
        if (str_starts_with($normalized, $root)) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }

        return $normalized;
    }
}
