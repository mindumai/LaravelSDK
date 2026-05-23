<?php

declare(strict_types=1);

namespace Mindum\Laravel\Support;

use Mindum\Laravel\Tools\WriteReport;

/**
 * Immutable result of one AnalyzeRunner::run() invocation. Bundles scanner
 * stats (may be zero/empty when attaching to an existing job — no fresh
 * scan ran), job identification, final tool count, cost summary, and the
 * on-disk write report.
 */
final class AnalyzeResult
{
    /**
     * @param  array<int, string>  $scannerErrors
     * @param  array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     approximate_usd: float
     * }  $costSummary
     */
    public function __construct(
        public readonly int $entryCount,
        public readonly int $scannerSkipped,
        public readonly array $scannerErrors,
        public readonly string $jobId,
        public readonly int $toolCount,
        public readonly array $costSummary,
        public readonly bool $attached,
        public readonly WriteReport $writeReport,
    ) {}

    /**
     * True if this run attached to a pre-existing job (in-flight or
     * already completed) rather than starting a fresh scan + upload.
     */
    public function wasAttached(): bool
    {
        return $this->attached;
    }
}
