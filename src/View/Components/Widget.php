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
 *       agent="refund-helper"
 *       :theme="['primary' => '#10b981']"
 *       position="bottom-left"
 *       welcome-message="Hi! What would you like to do?"
 *       :welcome-prompts="['Show my tasks', 'Create a project']"
 *   />
 *
 * `agent` (Scoped Agents) selects a dashboard-configured scoped agent by
 * slug: a page-specific persona limited to a hand-picked set of tools.
 * The slug travels browser → token proxy → orchestrator mint; an unknown
 * or disabled slug degrades to the default widget rather than erroring.
 */
class Widget extends Component
{
    /** @var array<string, mixed> */
    public array $config;

    public bool $enabled;

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>|null  $theme
     * @param  array<int, string>|Arrayable<int, string>|null  $welcomePrompts
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $endUserId = null,
        ?string $agent = null,
        array|Arrayable|null $theme = null,
        ?string $position = null,
        ?string $welcomeMessage = null,
        array|Arrayable|null $welcomePrompts = null,
    ) {
        $tokenEndpoint = (string) config('mindum.widget.token_endpoint', '');
        $apiKey = (string) config('mindum.api_key', '');

        $this->enabled = $tokenEndpoint !== '' && $apiKey !== '';

        $themeArray = $theme instanceof Arrayable ? $theme->toArray() : ($theme ?? []);
        $baseTheme = (array) config('mindum.widget.theme', []);

        $promptsArray = $welcomePrompts instanceof Arrayable ? $welcomePrompts->toArray() : $welcomePrompts;
        $welcome = (array) config('mindum.widget.welcome', []);
        $welcomeMessageResolved = $welcomeMessage ?? (string) ($welcome['message'] ?? '');
        $welcomePromptsResolved = $promptsArray ?? (array) ($welcome['prompts'] ?? []);
        // Coerce to flat list of non-empty strings — defensive against
        // accidental ['key' => 'value'] config or null entries.
        $welcomePromptsResolved = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? trim($p) : '', $welcomePromptsResolved),
            static fn (string $p) => $p !== '',
        ));

        $this->config = [
            'sessionId' => $sessionId ?? '',
            'endUserId' => $endUserId,
            'agent' => ($agent !== null && trim($agent) !== '') ? trim($agent) : null,
            'tokenEndpoint' => $tokenEndpoint === '' ? null : '/'.ltrim($tokenEndpoint, '/'),
            'apiUrl' => rtrim((string) config('mindum.api_url', ''), '/'),
            'wsUrl' => (string) config('mindum.widget.ws_url', ''),
            // Drop null entries (e.g. the unset primary default) so the
            // bootstrap only carries real overrides and the widget's own
            // defaults apply cleanly.
            'theme' => array_filter(
                array_merge($baseTheme, $themeArray),
                static fn ($v) => $v !== null,
            ),
            'position' => $position ?? (string) config('mindum.widget.position', 'bottom-right'),
            'welcome' => [
                'message' => $welcomeMessageResolved,
                'prompts' => $welcomePromptsResolved,
            ],
            'bundleUrl' => (string) config('mindum.widget.bundle_url', ''),
        ];
    }

    public function render(): View
    {
        return view('mindum::widget-bootstrap');
    }
}
