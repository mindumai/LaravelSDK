<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued email job. Because it implements ShouldQueue, the scanner should
 * skip this — we don't want to expose fire-and-forget async work as tools.
 */
class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        // send mail...
    }
}
