<?php

declare(strict_types=1);

namespace Mindum\Laravel\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts a manifest to the Mindum orchestration API and returns the
 * generated tool set. Direct HTTP via Laravel's Http facade (matches
 * the API-side AnthropicClient pattern: Http::fake-friendly, no SDK
 * dependency).
 *
 * Reads config from config('mindum') — `api_url` and `api_key`. The
 * service provider merges defaults from the package's config/mindum.php
 * into the host app's config namespace at boot time.
 */
class MindumApiClient
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     status: string,
     *     manifest_id: int|null,
     *     manifest_hash: string,
     *     tool_count: int,
     *     cached: bool,
     *     tools: array<int, array<string, mixed>>,
     *     stats: array<string, mixed>,
     *     error: ?string
     * }
     *
     * @throws RuntimeException when the API key is missing, the request fails,
     *                          or the server returns an unexpected payload.
     */
    public function analyze(array $manifest): array
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException(
                'Mindum API key not configured. Set MINDUM_API_KEY in your .env.',
            );
        }

        $baseUrl = rtrim((string) config('mindum.api_url', 'https://mindum.online'), '/');
        $timeout = (int) config('mindum.api_timeout_seconds', 180);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/analyze', ['manifest' => $manifest])
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                "Could not reach the Mindum API at {$baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        } catch (RequestException $e) {
            $status = $e->response?->status() ?? 0;
            $body = $e->response?->body() ?? '';
            throw new RuntimeException(
                "Mindum API returned HTTP {$status}: {$body}",
                previous: $e,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Mindum API response was not valid JSON.');
        }

        if (! array_key_exists('tools', $payload) || ! is_array($payload['tools'])) {
            $error = $payload['error'] ?? 'Response missing "tools" array';
            throw new RuntimeException("Mindum API rejected the manifest: {$error}");
        }

        return [
            'status' => (string) ($payload['status'] ?? 'ok'),
            'manifest_id' => isset($payload['manifest_id']) ? (int) $payload['manifest_id'] : null,
            'manifest_hash' => (string) ($payload['manifest_hash'] ?? ''),
            'tool_count' => (int) ($payload['tool_count'] ?? count($payload['tools'])),
            'cached' => (bool) ($payload['cached'] ?? false),
            'tools' => $payload['tools'],
            'stats' => is_array($payload['stats'] ?? null) ? $payload['stats'] : [],
            'error' => isset($payload['error']) ? (string) $payload['error'] : null,
        ];
    }
}
