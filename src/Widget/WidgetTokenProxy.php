<?php

declare(strict_types=1);

namespace Mindum\Laravel\Widget;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\Widget\Exceptions\WidgetTokenMintException;

/**
 * Server-side proxy that mints a widget JWT for the browser.
 *
 * Flow: customer's app holds the long-lived Mindum API key in its server
 * env; the browser asks `POST /mindum/widget/token` (same-origin) for a
 * short-lived JWT; this service forwards that request to the orchestrator's
 * `POST /api/widget/token` with the API key, and returns the minted token
 * to the browser. The API key never leaves the customer's server.
 *
 * Direct HTTP via Laravel's Http facade — same pattern as MindumApiClient,
 * so test suites can fake it with Http::fake().
 */
class WidgetTokenProxy
{
    public const TIMEOUT_SECONDS = 10;

    /**
     * @return array{token: string, expires_at: int}
     *
     * @throws WidgetTokenMintException
     */
    public function mint(string $sessionId, ?string $endUserId = null): array
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            throw new WidgetTokenMintException(
                reason: 'unconfigured',
                message: 'Mindum API key not configured. Set MINDUM_API_KEY in your .env.',
            );
        }

        $baseUrl = rtrim((string) config('mindum.api_url', 'https://api.mindum.ai'), '/');
        $payload = ['session_id' => $sessionId];
        if ($endUserId !== null && $endUserId !== '') {
            $payload['end_user_id'] = $endUserId;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/widget/token', $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new WidgetTokenMintException(
                reason: 'unreachable',
                message: "Could not reach the Mindum API at {$baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        } catch (RequestException $e) {
            $status = $e->response?->status() ?? 0;
            throw new WidgetTokenMintException(
                reason: $status >= 500 ? 'unreachable' : 'rejected',
                message: "Mindum API returned HTTP {$status} when minting widget token.",
                upstreamStatus: $status,
                previous: $e,
            );
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['token'], $payload['expires_at'])) {
            throw new WidgetTokenMintException(
                reason: 'rejected',
                message: 'Mindum API response missing token or expires_at fields.',
                upstreamStatus: $response->status(),
            );
        }

        return [
            'token' => (string) $payload['token'],
            'expires_at' => (int) $payload['expires_at'],
        ];
    }
}
