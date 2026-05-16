<?php

declare(strict_types=1);

namespace Mindum\Laravel\View\Components;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * <x-mindum::widget /> — drops the Mindum chat widget into a Blade page.
 *
 * Emits a minimal <script> bootstrap that:
 *   1. Sets window.__MINDUM_WIDGET__ with config the bundle reads on load.
 *   2. Loads the widget JS bundle from `widget.bundle_url`.
 *
 * The bootstrap NEVER includes the customer's API key or the MCP secret.
 * The browser only gets:
 *   - sessionId (per-tab, opaque)
 *   - token URL on the customer's app (same-origin, mints JWT)
 *   - orchestrator API URL (for chat POST)
 *   - Reverb WS URL (for real-time subscribe)
 *   - theme + position (visual only)
 *
 * Renders nothing when `widget.token_endpoint` is empty (widget disabled)
 * or when `mindum.api_key` is unset (cannot mint tokens anyway).
 *
 * Customer overrides via component attributes:
 *   <x-mindum::widget
 *       session-id="from-customer-auth-flow"
 *       :theme="['primary' => '#10b981']"
 *       position="bottom-left"
 *   />
 */
class Widget extends Component
{
    /** @var array<string, mixed> */
    public array $config;

    public bool $enabled;

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>|null  $theme
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $endUserId = null,
        array|Arrayable|null $theme = null,
        ?string $position = null,
    ) {
        $tokenEndpoint = (string) config('mindum.widget.token_endpoint', '');
        $apiKey = (string) config('mindum.api_key', '');

        $this->enabled = $tokenEndpoint !== '' && $apiKey !== '';

        $themeArray = $theme instanceof Arrayable ? $theme->toArray() : ($theme ?? []);
        $baseTheme = (array) config('mindum.widget.theme', []);

        $this->config = [
            'sessionId' => $sessionId ?? '',
            'endUserId' => $endUserId,
            'tokenEndpoint' => $tokenEndpoint === '' ? null : '/'.ltrim($tokenEndpoint, '/'),
            'apiUrl' => rtrim((string) config('mindum.api_url', ''), '/'),
            'wsUrl' => (string) config('mindum.widget.ws_url', ''),
            'theme' => array_merge($baseTheme, $themeArray),
            'position' => $position ?? (string) config('mindum.widget.position', 'bottom-right'),
            'bundleUrl' => (string) config('mindum.widget.bundle_url', ''),
        ];
    }

    public function render(): View
    {
        return view('mindum::widget-bootstrap');
    }
}
