<?php

namespace Tests\Feature;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use App\Tools\ToolSearch\ToolSearchTool;
use App\Tools\ToolUseContext;
use Tests\TestCase;

class ToolSearchToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    private function makeRegistry(array $tools): ToolRegistry
    {
        $registry = new ToolRegistry;
        foreach ($tools as $tool) {
            $registry->register($tool);
        }
        return $registry;
    }

    private function makeTool(string $name, string $description): BaseTool
    {
        return new class($name, $description) extends BaseTool {
            public function __construct(
                private string $toolName,
                private string $toolDesc,
            ) {}

            public function name(): string { return $this->toolName; }
            public function description(): string { return $this->toolDesc; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []], []);
            }
            public function call(array $input, \App\Tools\ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };
    }

    // ─── name / description / isReadOnly ──────────────────────────────────

    public function test_name_is_tool_search(): void
    {
        $this->assertSame('ToolSearch', (new ToolSearchTool)->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue((new ToolSearchTool)->isReadOnly([]));
    }

    // ─── no match ─────────────────────────────────────────────────────────

    public function test_no_match_returns_no_tools_found_message(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('Bash', 'Execute shell commands'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'xyzzy_no_match'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No tools found', $result->output);
    }

    // ─── exact name match scores 100 ──────────────────────────────────────

    public function test_exact_name_match_is_included(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('Bash', 'Execute shell commands'),
            $this->makeTool('Read', 'Read files'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'bash'], $this->context);

        $this->assertStringContainsString('Bash', $result->output);
    }

    public function test_exact_name_match_appears_first(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('Bash', 'Execute shell commands'),
            $this->makeTool('BashAlt', 'Alternative bash tool'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'bash'], $this->context);

        // Exact match (Bash) should appear before partial match (BashAlt)
        $posExact = strpos($result->output, 'Bash:');
        $posPartial = strpos($result->output, 'BashAlt:');
        $this->assertNotFalse($posExact);
        $this->assertNotFalse($posPartial);
        $this->assertLessThan($posPartial, $posExact);
    }

    // ─── name contains match scores 80 ────────────────────────────────────

    public function test_name_contains_query_is_matched(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('FileRead', 'Read a file'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'file'], $this->context);

        $this->assertStringContainsString('FileRead', $result->output);
    }

    // ─── description contains match scores 60 ─────────────────────────────

    public function test_description_contains_query_is_matched(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('XTool', 'Executes arbitrary shell commands safely'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'shell'], $this->context);

        $this->assertStringContainsString('XTool', $result->output);
    }

    // ─── word match scores 40 * ratio ─────────────────────────────────────

    public function test_partial_word_match_in_description(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('Alpha', 'Search and index documents'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'search index'], $this->context);

        $this->assertStringContainsString('Alpha', $result->output);
    }

    // ─── result format ────────────────────────────────────────────────────

    public function test_result_shows_tool_count(): void
    {
        $registry = $this->makeRegistry([
            $this->makeTool('Bash', 'Execute shell commands'),
            $this->makeTool('BashFast', 'Execute fast shell commands'),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'bash'], $this->context);

        $this->assertStringContainsString('Found 2 matching tools', $result->output);
    }

    public function test_description_truncated_to_120_chars(): void
    {
        $longDesc = str_repeat('x', 200);
        $registry = $this->makeRegistry([
            $this->makeTool('LongTool', $longDesc),
        ]);
        $this->app->instance(ToolRegistry::class, $registry);

        $result = (new ToolSearchTool)->call(['query' => 'longtool'], $this->context);

        // The tool name is lowercased for comparison, so this should match
        $this->assertStringContainsString('LongTool', $result->output);
        // Description capped at 120 chars
        $this->assertStringNotContainsString(str_repeat('x', 121), $result->output);
    }
}
