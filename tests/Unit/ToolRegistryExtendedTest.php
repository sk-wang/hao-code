<?php

namespace Tests\Unit;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolRegistryExtendedTest extends TestCase
{
    private function makeTool(string $name, bool $enabled = true): BaseTool
    {
        return new class($name, $enabled) extends BaseTool
        {
            public function __construct(private string $toolName, private bool $toolEnabled)
            {
            }

            public function name(): string { return $this->toolName; }
            public function description(): string { return "Tool {$this->toolName}"; }
            public function isEnabled(): bool { return $this->toolEnabled; }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };
    }

    public function test_get_tool_returns_registered_tool(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Bash'));

        $this->assertNotNull($registry->getTool('Bash'));
    }

    public function test_get_tool_returns_null_for_unknown_name(): void
    {
        $registry = new ToolRegistry;

        $this->assertNull($registry->getTool('DoesNotExist'));
    }

    public function test_get_all_tools_excludes_disabled_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('EnabledTool', true));
        $registry->register($this->makeTool('DisabledTool', false));

        $all = $registry->getAllTools();

        $this->assertArrayHasKey('EnabledTool', $all);
        $this->assertArrayNotHasKey('DisabledTool', $all);
    }

    public function test_to_api_tools_returns_only_enabled_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('VisibleTool', true));
        $registry->register($this->makeTool('HiddenTool', false));

        $apiTools = $registry->toApiTools();

        $names = array_column($apiTools, 'name');
        $this->assertContains('VisibleTool', $names);
        $this->assertNotContains('HiddenTool', $names);
    }

    public function test_to_api_tools_produces_correct_structure(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('ReadFile', true));

        $apiTools = $registry->toApiTools();

        $this->assertCount(1, $apiTools);
        $this->assertArrayHasKey('name', $apiTools[0]);
        $this->assertArrayHasKey('description', $apiTools[0]);
        $this->assertArrayHasKey('input_schema', $apiTools[0]);
        $this->assertSame('ReadFile', $apiTools[0]['name']);
    }

    public function test_registering_same_name_overwrites_previous(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('MyTool'));

        $second = $this->makeTool('MyTool');
        $registry->register($second);

        $this->assertSame($second, $registry->getTool('MyTool'));
    }

    public function test_to_api_tools_returns_empty_array_when_no_tools(): void
    {
        $registry = new ToolRegistry;

        $this->assertSame([], $registry->toApiTools());
    }
}
