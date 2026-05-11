<?php

declare(strict_types=1);

namespace Mindum\Laravel\Support;

use Mindum\Laravel\Tools\WriteReport;

/**
 * Immutable result of one AnalyzeRunner::run() invocation. Bundles together
 * scanner stats, API response, and on-disk write report so command classes
 * can render a single coherent summary to the operator.
 */
final class AnalyzeResult
{
    /**
     * @param  array<int, string>  $scannerErrors
     * @param  array<string, mixed>  $apiResult
     */
    public function __construct(
        public readonly int $entryCount,
        public readonly int $scannerSkipped,
        public readonly array $scannerErrors,
        public readonly array $apiResult,
        public readonly WriteReport $writeReport,
    ) {}

    public function isCached(): bool
    {
        return (bool) ($this->apiResult['cached'] ?? false);
    }

    public function toolCount(): int
    {
        return (int) ($this->apiResult['tool_count'] ?? $this->writeReport->writtenCount());
    }

    public function manifestHash(): string
    {
        return (string) ($this->apiResult['manifest_hash'] ?? '');
    }
}
