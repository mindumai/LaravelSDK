<?php

declare(strict_types=1);

namespace Mindum\Laravel\Tests\Unit\Tools;

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
        // laravel/mcp 0.5+/0.7 API: schema(JsonSchema $schema): array.
        $this->assertStringContainsString('use Illuminate\\Contracts\\JsonSchema\\JsonSchema;', $output['source']);
        $this->assertStringContainsString('public function schema(JsonSchema $schema): array', $output['source']);
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

    public function test_schema_emits_required_calls_for_required_fields(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 255],
                    'body' => ['type' => 'string'],
                    'tags' => ['type' => 'array'],
                ],
                'required' => ['title', 'body'],
            ],
        ]));

        // New API emits a property-name => typed-builder map: each property is
        // `'name' => $schema->TYPE()...`, with ->required() appended for the
        // ones listed in `required`.
        $this->assertStringContainsString("'title' => \$schema->string()", $output['source']);
        $this->assertStringContainsString("'body' => \$schema->string()", $output['source']);
        $this->assertStringContainsString("'tags' => \$schema->array()", $output['source']);

        // title and body must be required; tags must not.
        $this->assertMatchesRegularExpression("/'title' => \\\$schema->.+->required\(\)/", $output['source']);
        $this->assertMatchesRegularExpression("/'body' => \\\$schema->.+->required\(\)/", $output['source']);
        $this->assertDoesNotMatchRegularExpression("/'tags' => \\\$schema->.+->required\(\)/", $output['source']);
    }

    public function test_no_input_fields_produces_placeholder_comment(): void
    {
        $renderer = new ToolClassRenderer;

        $output = $renderer->render($this->minimalTool([
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
        ]));

        $this->assertStringContainsString('// No input fields.', $output['source']);
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
