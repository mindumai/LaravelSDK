<?php

declare(strict_types=1);

namespace Mindum\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Mindum\Laravel\Widget\Exceptions\WidgetTokenMintException;
use Mindum\Laravel\Widget\WidgetTokenProxy;

/**
 * POST /mindum/widget/token — same-origin endpoint the chat widget hits
 * to obtain a short-lived JWT. Forwards to the orchestrator's
 * /api/widget/token using the SDK's stored API key.
 *
 * The browser never sees the API key. The widget caches the returned
 * JWT in memory until expires_at - 30s, then re-mints.
 *
 * `end_user_id` is optional and meant for customer apps that resolve it
 * from their own auth layer (e.g., a controller-level helper that pulls
 * auth()->id() and posts it through). We don't pull it from auth() here
 * to keep the contract explicit — every customer app's user model differs.
 */
class WidgetTokenController extends Controller
{
    public function __construct(
        private readonly WidgetTokenProxy $proxy,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => ['required', 'string', 'min:1', 'max:128'],
                'end_user_id' => ['nullable', 'string', 'max:128'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'invalid_request',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $minted = $this->proxy->mint(
                $validated['session_id'],
                $validated['end_user_id'] ?? null,
            );
        } catch (WidgetTokenMintException $e) {
            $httpStatus = $e->reason === 'rejected' ? 502 : 503;

            return response()->json([
                'error' => 'widget_token_'.$e->reason,
                'message' => $e->getMessage(),
            ], $httpStatus);
        }

        return response()->json($minted);
    }
}
