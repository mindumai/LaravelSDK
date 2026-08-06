<?php

declare(strict_types=1);

namespace Mindum\Laravel\Support;

/**
 * Minimal sleep abstraction (SDKM-D2). Illuminate\Support\Sleep only
 * exists on Laravel 10+, and the SDK's floor is Laravel 9 — this is the
 * one place the SDK ever sleeps (analyze poll backoff), so a two-method
 * shim beats a framework version fork.
 *
 * Tests call fake() to make sleeps no-ops; there is no assertion API
 * because nothing asserts on sleep counts — the backoff behavior itself
 * is covered through the poll-loop tests.
 */
final class Sleeper
{
    private static bool $fake = false;

    public static function fake(): void
    {
        self::$fake = true;
    }

    public static function restore(): void
    {
        self::$fake = false;
    }

    public static function milliseconds(int $ms): void
    {
        if (! self::$fake && $ms > 0) {
            usleep($ms * 1000);
        }
    }
}
