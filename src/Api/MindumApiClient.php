<?php

declare(strict_types=1);

namespace Mindum\Laravel\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Async-aware HTTP client for the Mindum orchestration API. Posts a
 * manifest to `POST /api/analyze` (which returns 202 + job_id), then
 * polls `GET /api/analyze/jobs/{id}` until the job is terminal, and
 * finally downloads tool definitions via `GET .../results`.
 *
 * `currentJob()` is the idempotency helper — it returns any in-flight
 * or undownloaded-complete job so `mindum:install` can attach to it
 * instead of starting a fresh scan.
 *
 * Direct HTTP via Laravel's Http facade (Http::fake-friendly, no SDK
 * dependency). Reads config from config('mindum') — `api_url`,
 * `api_key`, `api_timeout_seconds`.
 */
class MindumApiClient
{
    /**
     * POST /api/analyze
     *
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     job_id: string,
     *     status: string,
     *     total_batches: int,
     *     estimated_seconds: int,
     *     poll_url: string,
     *     results_url: string
     * }
     *
     * @throws RuntimeException when the API key is missing or the request fails.
     */
    public function startAnalyzeJob(array $manifest): array
    {
        $response = $this->request('POST', '/api/analyze', ['manifest' => $manifest]);

        $required = ['job_id', 'status', 'total_batches'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $response)) {
                throw new RuntimeException("Mindum API response missing required field: {$key}");
            }
        }

        return [
            'job_id' => (string) $response['job_id'],
            'status' => (string) $response['status'],
            'total_batches' => (int) $response['total_batches'],
            'estimated_seconds' => (int) ($response['estimated_seconds'] ?? 0),
            'poll_url' => (string) ($response['poll_url'] ?? "/api/analyze/jobs/{$response['job_id']}"),
            'results_url' => (string) ($response['results_url'] ?? "/api/analyze/jobs/{$response['job_id']}/results"),
        ];
    }

    /**
     * GET /api/analyze/jobs/{jobId}
     *
     * @return array{
     *     job_id: string,
     *     status: string,
     *     batches_completed: int,
     *     total_batches: int,
     *     tools_generated: int,
     *     started_at: ?string,
     *     completed_at: ?string,
     *     estimated_seconds_remaining: int,
     *     tools_downloaded: bool,
     *     error_message: ?string,
     *     results_url: string
     * }
     *
     * @throws RuntimeException on 404, network errors, or unexpected payloads.
     */
    public function pollJob(string $jobId): array
    {
        $response = $this->request('GET', "/api/analyze/jobs/{$jobId}");

        return [
            'job_id' => (string) ($response['job_id'] ?? $jobId),
            'status' => (string) ($response['status'] ?? 'unknown'),
            'batches_completed' => (int) ($response['batches_completed'] ?? 0),
            'total_batches' => (int) ($response['total_batches'] ?? 0),
            'tools_generated' => (int) ($response['tools_generated'] ?? 0),
            'started_at' => isset($response['started_at']) ? (string) $response['started_at'] : null,
            'completed_at' => isset($response['completed_at']) ? (string) $response['completed_at'] : null,
            'estimated_seconds_remaining' => (int) ($response['estimated_seconds_remaining'] ?? 0),
            'tools_downloaded' => (bool) ($response['tools_downloaded'] ?? false),
            'error_message' => isset($response['error_message']) ? (string) $response['error_message'] : null,
            'results_url' => (string) ($response['results_url'] ?? "/api/analyze/jobs/{$jobId}/results"),
        ];
    }

    /**
     * GET /api/analyze/jobs/{jobId}/results
     *
     * Latches `tools_downloaded=true` server-side on first successful fetch.
     * Caller should only invoke this when polled status is 'completed'.
     *
     * @return array{
     *     job_id: string,
     *     tools_count: int,
     *     tools: array<int, array<string, mixed>>,
     *     cost_summary: array{
     *         input_tokens: int,
     *         output_tokens: int,
     *         approximate_usd: float
     *     }
     * }
     *
     * @throws RuntimeException on 409 (job not completed yet), 404, or network errors.
     */
    public function fetchResults(string $jobId): array
    {
        $response = $this->request('GET', "/api/analyze/jobs/{$jobId}/results");

        if (! isset($response['tools']) || ! is_array($response['tools'])) {
            throw new RuntimeException('Mindum API /results response missing "tools" array');
        }

        $cost = is_array($response['cost_summary'] ?? null) ? $response['cost_summary'] : [];

        return [
            'job_id' => (string) ($response['job_id'] ?? $jobId),
            'tools_count' => (int) ($response['tools_count'] ?? count($response['tools'])),
            'tools' => $response['tools'],
            'cost_summary' => [
                'input_tokens' => (int) ($cost['input_tokens'] ?? 0),
                'output_tokens' => (int) ($cost['output_tokens'] ?? 0),
                'approximate_usd' => (float) ($cost['approximate_usd'] ?? 0.0),
            ],
        ];
    }

    /**
     * GET /api/analyze/jobs/current
     *
     * Returns the latest in-flight or undownloaded-complete job for the
     * authenticated account, or null if no qualifying job exists (204).
     *
     * @return array{
     *     job_id: string,
     *     status: string,
     *     batches_completed: int,
     *     total_batches: int,
     *     tools_generated: int,
     *     started_at: ?string,
     *     completed_at: ?string,
     *     estimated_seconds_remaining: int,
     *     tools_downloaded: bool,
     *     error_message: ?string,
     *     results_url: string
     * }|null
     *
     * @throws RuntimeException on network errors or unexpected non-200/204 responses.
     */
    public function currentJob(): ?array
    {
        $apiKey = $this->requireApiKey();
        $baseUrl = $this->baseUrl();

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout())
                ->acceptJson()
                ->get($baseUrl.'/api/analyze/jobs/current');
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                "Could not reach the Mindum API at {$baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 204) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Mindum API returned HTTP {$response->status()} on /jobs/current");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Mindum API /jobs/current response was not valid JSON.');
        }

        return [
            'job_id' => (string) ($payload['job_id'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'batches_completed' => (int) ($payload['batches_completed'] ?? 0),
            'total_batches' => (int) ($payload['total_batches'] ?? 0),
            'tools_generated' => (int) ($payload['tools_generated'] ?? 0),
            'started_at' => isset($payload['started_at']) ? (string) $payload['started_at'] : null,
            'completed_at' => isset($payload['completed_at']) ? (string) $payload['completed_at'] : null,
            'estimated_seconds_remaining' => (int) ($payload['estimated_seconds_remaining'] ?? 0),
            'tools_downloaded' => (bool) ($payload['tools_downloaded'] ?? false),
            'error_message' => isset($payload['error_message']) ? (string) $payload['error_message'] : null,
            'results_url' => (string) ($payload['results_url'] ?? ''),
        ];
    }

    /**
     * Shared request plumbing for non-204 endpoints. Returns the decoded
     * JSON body or throws RuntimeException with a human-readable message.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $apiKey = $this->requireApiKey();
        $baseUrl = $this->baseUrl();

        $http = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout())
            ->acceptJson();

        try {
            $response = match (strtoupper($method)) {
                'GET' => $http->get($baseUrl.$path),
                'POST' => $http->asJson()->post($baseUrl.$path, $body ?? []),
                default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
            };

            // Inspect status manually so we can produce useful error messages.
            // Treat 202 same as 200 (POST /api/analyze returns 202).
            if ($response->status() < 200 || $response->status() >= 300) {
                $response->throw();
            }
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
            throw new RuntimeException("Mindum API response was not valid JSON (path: {$path}).");
        }

        return $payload;
    }

    private function requireApiKey(): string
    {
        $apiKey = (string) config('mindum.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException(
                'Mindum API key not configured. Set MINDUM_API_KEY in your .env.',
            );
        }

        return $apiKey;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('mindum.api_url', 'https://mindum.online'), '/');
    }

    private function timeout(): int
    {
        return (int) config('mindum.api_timeout_seconds', 30);
    }
}
