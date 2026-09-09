<?php

// @mindum-handwritten
//
// Test fixture — a write tool whose name evades the confirmation prefix
// regex; the declared operation type is what gates it (CC-D3).

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Stubs\Sync\Tools;

use Mindum\Laravel\Tools\GeneratedTool;

class EmailReportTool extends GeneratedTool
{
    public function name(): string
    {
        return 'email_report';
    }

    public function description(): string
    {
        return 'Emails a saved report as CSV.';
    }

    public function operationType(): ?string
    {
        return self::OPERATION_WRITE;
    }

    protected function execute(array $input): mixed
    {
        return 'sent';
    }
}
