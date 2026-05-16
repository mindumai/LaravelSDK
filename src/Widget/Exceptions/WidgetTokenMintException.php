<?php

declare(strict_types=1);

namespace Mindum\Laravel\Widget\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the SDK cannot mint a widget JWT for the browser.
 *
 * The `reason` discriminator drives the JSON error code returned to the
 * browser so the widget can react sensibly:
 *   - "unconfigured": MINDUM_API_KEY is unset; nothing to retry — 503.
 *   - "unreachable":  orchestrator network failure (DNS, timeout, 5xx); 503.
 *   - "rejected":     orchestrator returned 4xx (auth, validation); 502.
 */
final class WidgetTokenMintException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
