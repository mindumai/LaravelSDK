<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Feature\Widget;

use Illuminate\Support\Facades\Blade;
use Mindum\Laravel\MindumServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Confirms <x-mindum::widget /> renders the bootstrap config block,
 * carries no secrets, and stays inert when the install is unfinished
 * (API key not yet set or widget endpoint disabled).
 */
class WidgetBladeComponentTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MindumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mindum.api_url', 'https://api.mindum.ai');
        $app['config']->set('mindum.api_key', 'mk_test_widget_blade');
        $app['config']->set('mindum.mcp_secret', 'shhh-mcp-secret-do-not-leak');
        $app['config']->set('mindum.widget.token_endpoint', '/mindum/widget/token');
        $app['config']->set('mindum.widget.ws_url', 'wss://ws.mindum.ai');
        $app['config']->set('mindum.widget.bundle_url', 'https://cdn.mindum.ai/widget/v1/widget.js');
        $app['config']->set('mindum.widget.theme', ['primary' => '#0F172A']);
        $app['config']->set('mindum.widget.position', 'bottom-right');
    }

    public function test_renders_bootstrap_config_and_bundle_script(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-blade-1" />');

        $this->assertStringContainsString('window.__MINDUM_WIDGET__', $html);
        $this->assertStringContainsString('"sessionId":"sess-blade-1"', $html);
        $this->assertStringContainsString('"tokenEndpoint":"\/mindum\/widget\/token"', $html);
        $this->assertStringContainsString('"apiUrl":"https:\/\/api.mindum.ai"', $html);
        $this->assertStringContainsString('"wsUrl":"wss:\/\/ws.mindum.ai"', $html);
        $this->assertStringContainsString('https://cdn.mindum.ai/widget/v1/widget.js', $html);
    }

    public function test_never_emits_api_key_or_mcp_secret(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-blade-2" />');

        $this->assertStringNotContainsString('mk_test_widget_blade', $html);
        $this->assertStringNotContainsString('shhh-mcp-secret-do-not-leak', $html);
    }

    public function test_renders_empty_when_api_key_unset(): void
    {
        config()->set('mindum.api_key', '');

        $html = Blade::render('<x-mindum::widget session-id="sess-blade-3" />');

        $this->assertStringNotContainsString('__MINDUM_WIDGET__', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_renders_empty_when_endpoint_disabled(): void
    {
        config()->set('mindum.widget.token_endpoint', '');

        $html = Blade::render('<x-mindum::widget session-id="sess-blade-4" />');

        $this->assertStringNotContainsString('__MINDUM_WIDGET__', $html);
    }

    public function test_attribute_overrides_take_precedence_over_config(): void
    {
        $html = Blade::render(
            '<x-mindum::widget session-id="sess-blade-5" :theme="[\'primary\' => \'#10b981\']" position="bottom-left" />',
        );

        $this->assertStringContainsString('"primary":"#10b981"', $html);
        $this->assertStringContainsString('"position":"bottom-left"', $html);
    }

    public function test_emits_end_user_id_when_supplied(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-eu" end-user-id="user-99" />');

        $this->assertStringContainsString('"endUserId":"user-99"', $html);
    }

    public function test_emits_null_end_user_id_when_not_supplied(): void
    {
        $html = Blade::render('<x-mindum::widget session-id="sess-no-eu" />');

        $this->assertStringContainsString('"endUserId":null', $html);
    }
}
