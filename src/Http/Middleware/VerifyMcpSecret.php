<?php

declare(strict_types=1);

namespace Mindum\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the MCP HTTP endpoint with a shared secret (FR-021).
 *
 * Three outcomes:
 *   - No secret configured  → 503 with a JSON-RPC-shaped error explaining
 *     the missing MINDUM_MCP_SECRET. Keeps the route mounted so
 *     `php artisan route:list` and `mindum:status` stay coherent.
 *   - Header missing/wrong  → 401, also JSON-RPC shaped.
 *   - Header matches        → forward to the MCP server handler.
 *
 * Comparison uses hash_equals() to avoid timing leaks. The secret itself
 * is never logged or echoed.
 */
final class VerifyMcpSecret
{
    public const HEADER = 'X-Mindum-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('mindum.mcp_secret', '');

        if ($configured === '') {
            return $this->jsonRpcError(
                503,
                -32001,
                'Mindum MCP secret is not configured. Set MINDUM_MCP_SECRET in your .env to enable this endpoint.',
            );
        }

        $provided = (string) $request->header(self::HEADER, '');

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return $this->jsonRpcError(
                401,
                -32001,
                'Invalid or missing '.self::HEADER.' header.',
            );
        }

        return $next($request);
    }

    private function jsonRpcError(int $httpStatus, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => null,
        ], $httpStatus);
    }
}
