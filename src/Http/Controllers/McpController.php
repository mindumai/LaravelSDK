<?php

declare(strict_types=1);

namespace Mindum\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mindum\Laravel\Mcp\ToolDiscovery;
use Mindum\Laravel\Tools\GeneratedTool;
use Throwable;

/**
 * The SDK's own MCP server endpoint (SDKM-D1, Docs/SDK_Own_MCP_Plan.md).
 *
 * Replaces laravel/mcp with ~150 owned lines. Only one client ever talks
 * to this endpoint — the Mindum orchestrator's McpClient — so the wire
 * contract is defined by what that client sends and expects (SDKM-D4):
 *
 *   - JSON-RPC 2.0 envelope over POST, X-Mindum-Secret gate (middleware).
 *   - tools/list with per_page/cursor pagination → {tools[], nextCursor?}.
 *     Tool descriptors: {name, description, inputSchema}.
 *   - tools/call {name, arguments} → {content[], isError}. Unknown tool is
 *     a protocol error (-32602, message contains "not found") at HTTP 200.
 *   - initialize answered minimally for forward compatibility; every other
 *     method is -32601.
 *
 * Runs on Laravel 9+ / PHP 8.1+ — this controller is the reason the SDK's
 * version floor could drop (SDKM-D2).
 */
class McpController
{
    /**
     * Default + maximum page size for tools/list. Matches the orchestrator
     * McpClient's PER_PAGE so one round-trip covers typical installs.
     */
    private const PER_PAGE = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        $id = $body['id'] ?? null;

        if (($body['jsonrpc'] ?? null) !== '2.0' || ! is_string($body['method'] ?? null)) {
            return $this->error($id, -32600, 'Request is not a valid JSON-RPC 2.0 envelope.');
        }

        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        return match ($body['method']) {
            'initialize' => $this->initialize($id),
            'tools/list' => $this->listTools($id, $params),
            'tools/call' => $this->callTool($id, $params),
            default => $this->error($id, -32601, sprintf('Method "%s" not found.', $body['method'])),
        };
    }

    /**
     * Minimal initialize response. The orchestrator doesn't send initialize
     * today; answering it (instead of -32601) keeps room for a future
     * handshake without an SDK release on every install.
     */
    private function initialize(mixed $id): JsonResponse
    {
        return $this->result($id, [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => [
                'name' => 'Mindum',
                'version' => '1.0',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function listTools(mixed $id, array $params): JsonResponse
    {
        $perPage = (int) ($params['per_page'] ?? self::PER_PAGE);
        $perPage = max(1, min($perPage, self::PER_PAGE));
        $offset = max(0, (int) ($params['cursor'] ?? 0));

        $all = ToolDiscovery::discover();
        $page = array_slice($all, $offset, $perPage);

        $descriptors = [];
        foreach ($page as $fqcn) {
            $tool = $this->instantiate($fqcn);
            if ($tool === null) {
                continue;
            }

            $descriptors[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        $result = ['tools' => $descriptors];

        $next = $offset + $perPage;
        if ($next < count($all)) {
            // Opaque to the client — McpClient just echoes it back.
            $result['nextCursor'] = (string) $next;
        }

        return $this->result($id, $result);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function callTool(mixed $id, array $params): JsonResponse
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach (ToolDiscovery::discover() as $fqcn) {
            $tool = $this->instantiate($fqcn);

            if ($tool !== null && $tool->name() === $name) {
                // handle() never throws — tool failures come back as
                // isError results the agent loop can react to.
                return $this->result($id, $tool->handle($arguments));
            }
        }

        return $this->error($id, -32602, sprintf('Tool "%s" not found.', $name));
    }

    /**
     * Resolve a discovered tool through the container (constructor DI for
     * hand-written tools) with a defensive fallback: one broken tool class
     * must not take down the whole endpoint.
     */
    private function instantiate(string $fqcn): ?GeneratedTool
    {
        try {
            $tool = app($fqcn);
        } catch (Throwable) {
            return null;
        }

        return $tool instanceof GeneratedTool ? $tool : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function result(mixed $id, array $result): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}
