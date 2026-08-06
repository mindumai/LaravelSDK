<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit\Tools;

use Mindum\Laravel\Tools\GeneratedTool;
use Mindum\Laravel\Tools\ToolClassRenderer;
use PHPUnit\Framework\TestCase;

class ToolClassRendererTest extends TestCase
{
    public function test_class_name_is_pascal_case(): void
    {
        $renderer = new ToolClassRenderer;

        $this->assertSame('CreatePost', $renderer->classNameFromToolName('create_post'));
        $this->assertSame('ListUsers', $renderer->classNameFromToolName('list_users'));
        $this->assertSame('FindUserByEmail', $renderer->classNameFromToolName('find_user_by_email'));
    }

    public function test_class_name_falls_back_when_starts_with_digit(): void
    {
        $renderer = new ToolClassRenderer;

        $this->assertSame('Tool42Things', $renderer->classNameFromToolName('42_things'));
    }

    public function test_rendered_file_has_mindum_generated_marker_on_first_lines(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool());

        // Marker must appear in the first 200 bytes for ToolWriter's scan.
        $this->assertStringContainsString(
            ToolClassRenderer::MARKER,
            substr($output['source'], 0, 200),
        );
    }

    public function test_rendered_source_is_syntactically_valid_php(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool());

        $tmp = tempnam(sys_get_temp_dir(), 'mindum_tool_').'.php';
        file_put_contents($tmp, $output['source']);

        $exitCode = 0;
        $lintOutput = [];
        exec('php -l '.escapeshellarg($tmp).' 2>&1', $lintOutput, $exitCode);

        @unlink($tmp);

        $this->assertSame(0, $exitCode, "php -l failed:\n".implode("\n", $lintOutput)."\n\nSource:\n".$output['source']);
    }

    public function test_rendered_source_emits_class_with_expected_methods(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool());

        $this->assertSame('CreatePost', $output['class_name']);
        $this->assertSame('CreatePost.php', $output['file_name']);
        $this->assertStringContainsString('namespace App\\Mindum\\Tools;', $output['source']);
        $this->assertStringContainsString('class CreatePost extends GeneratedTool', $output['source']);
        $this->assertStringContainsString('public function name(): string', $output['source']);
        $this->assertStringContainsString('public function description(): string', $output['source']);
        // SDKM-D2: plain-array inputSchema(), no Illuminate contracts —
        // emitted code must load on Laravel 9 / PHP 8.1.
        $this->assertStringNotContainsString('JsonSchema', $output['source']);
        $this->assertStringContainsString('public function inputSchema(): array', $output['source']);
        $this->assertStringContainsString('protected function execute(array $input): mixed', $output['source']);
    }

    public function test_handle_code_is_indented_inside_execute(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'handle_code' => 'return \\App\\Models\\Post::create($input);',
        ]));

        $this->assertStringContainsString(
            '        return \\App\\Models\\Post::create($input);',
            $output['source'],
        );
    }

    public function test_schema_round_trips_through_the_generated_class(): void
    {
        $renderer = new ToolClassRenderer(namespace: 'MindumRendererRoundTrip');

        $inputSchema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 255],
                'body' => ['type' => 'string'],
                'tags' => ['type' => 'array'],
            ],
            'required' => ['title', 'body'],
        ];

        $output = $renderer->render($this->minimalTool(['input_schema' => $inputSchema]));

        // The strongest possible assertion: load the emitted class and check
        // inputSchema() returns the manifest's schema byte-for-byte. This is
        // what tools/list serves the orchestrator (SDKM-D4).
        $tmp = tempnam(sys_get_temp_dir(), 'mindum_schema_').'.php';
        file_put_contents($tmp, $output['source']);
        require $tmp;
        @unlink($tmp);

        $fqcn = 'MindumRendererRoundTrip\\CreatePost';
        $this->assertTrue(class_exists($fqcn, false));

        /** @var GeneratedTool $tool */
        $tool = new $fqcn;
        $this->assertSame($inputSchema, $tool->inputSchema());
    }

    public function test_schema_with_no_fields_emits_bare_object_schema(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ]));

        $this->assertStringContainsString("return [\n            'type' => 'object',\n        ];", $output['source']);
    }

    public function test_provenance_lines_appear_when_metadata_present(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'source_class' => 'App\\Services\\CreatePost',
            'operation_type' => 'write',
            'default_enabled' => true,
        ]));

        $this->assertStringContainsString('// Source class: App\\Services\\CreatePost', $output['source']);
        $this->assertStringContainsString('// Operation type: write', $output['source']);
        $this->assertStringContainsString('// Default enabled: true', $output['source']);
    }

    public function test_custom_namespace_is_honored(): void
    {
        $renderer = new ToolClassRenderer(namespace: 'Acme\\Foo\\Tools');

        $output = $renderer->render($this->minimalTool());

        $this->assertStringContainsString('namespace Acme\\Foo\\Tools;', $output['source']);
    }

    public function test_descriptions_with_special_chars_are_escaped(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'description' => "Line 1\nLine 2 with 'single' and \"double\" quotes.",
        ]));

        // Eval the description-return path indirectly: ensure php -l accepts.
        $tmp = tempnam(sys_get_temp_dir(), 'mindum_desc_').'.php';
        file_put_contents($tmp, $output['source']);

        $exitCode = 0;
        exec('php -l '.escapeshellarg($tmp).' 2>&1', $lintOutput, $exitCode);
        @unlink($tmp);

        $this->assertSame(0, $exitCode);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function minimalTool(array $overrides = []): array
    {
        return array_merge([
            'name' => 'create_post',
            'description' => 'Creates a new blog post.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 255],
                ],
                'required' => ['title'],
            ],
            'handle_code' => 'return \\App\\Models\\Post::create($input);',
            'operation_type' => 'write',
            'source_class' => 'App\\Models\\Post',
            'default_enabled' => true,
        ], $overrides);
    }
}
