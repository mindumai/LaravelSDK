<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Widget;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Mindum\Laravel\MindumServiceProvider;
use Mindum\Laravel\Widget\WidgetTokenProxy;
use Orchestra\Testbench\TestCase;

/**
 * Scoped Agents (orchestrator's Docs/Scoped_Agents_Plan.md, AG3) — the
 * SDK's only job is a faithful passthrough of the agent slug:
 * Blade attr → bootstrap config → token proxy → orchestrator mint.
 * Resolution and degradation live server-side in the orchestrator.
 */
class WidgetAgentPassthroughTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_agent_pass');
        $app['config']->set('mindum.widget.token_endpoint', '/mindum/widget/token');
        $app['config']->set('mindum.widget.bundle_url', 'https://cdn.mindum.ai/widget/v1/widget.js');
    }

    public function test_blade_component_emits_agent_slug_in_bootstrap_config(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-ag-1" agent="refund-helper" />');

        $this->assertStringContainsString('"agent":"refund-helper"', $html);
    }

    public function test_blade_component_emits_null_agent_when_attr_absent(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-ag-2" />');

        $this->assertStringContainsString('"agent":null', $html);
    }

    public function test_blade_component_normalizes_blank_agent_to_null(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-ag-3" agent="  " />');

        $this->assertStringContainsString('"agent":null', $html);
    }

    public function test_proxy_forwards_agent_to_orchestrator_mint(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response(['token' => 't', 'expires_at' => 1], 200),
        ]);

        (new WidgetTokenProxy)->mint('sess-ag-4', null, 'refund-helper');

        Http::assertSent(function ($request) {
            return $request['session_id'] === 'sess-ag-4'
                && $request['agent'] === 'refund-helper';
        });
    }

    public function test_proxy_omits_agent_when_null(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response(['token' => 't', 'expires_at' => 1], 200),
        ]);

        (new WidgetTokenProxy)->mint('sess-ag-5');

        Http::assertSent(fn ($request) => ! isset($request['agent']));
    }

    public function test_token_endpoint_forwards_agent_from_browser_request(): void
    {
        Http::fake([
            'api.mindum.ai/api/widget/token' => Http::response(['token' => 't', 'expires_at' => 1], 200),
        ]);

        $this->postJson('/mindum/widget/token', [
            'session_id' => 'sess-ag-6',
            'agent' => 'refund-helper',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request['agent'] === 'refund-helper');
    }

    public function test_token_endpoint_rejects_overlong_agent_slug(): void
    {
        $this->postJson('/mindum/widget/token', [
            'session_id' => 'sess-ag-7',
            'agent' => str_repeat('a', 65),
        ])->assertStatus(422);
    }
}
