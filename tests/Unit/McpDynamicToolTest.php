<?php

namespace Tests\Unit;

use App\Services\Mcp\McpConnectionManager;
use App\Tools\Mcp\McpDynamicTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stub that replaces the final McpServerConfigManager for testing.
 */
class StubConfigManager extends \App\Services\Mcp\McpServerConfigManager
{
    public function paths(): array { return ['global' => '/tmp/g.json', 'project' => '/tmp/p.json']; }
    public function listServers(): array { return []; }
    public function getServer(string $name): ?array { return null; }
}

class McpDynamicToolTest extends TestCase
{
    private McpConnectionManager $connectionManager;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->connectionManager = new McpConnectionManager(new StubConfigManager());
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test-session',
        );
    }

    private function makeTool(array $overrides = []): McpDynamicTool
    {
        return new McpDynamicTool(
            qualifiedName: $overrides['qualifiedName'] ?? 'mcp__test__my_tool',
            serverName: $overrides['serverName'] ?? 'test',
            toolName: $overrides['toolName'] ?? 'my_tool',
            toolDescription: $overrides['toolDescription'] ?? 'A test tool',
            inputJsonSchema: $overrides['inputSchema'] ?? [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
            annotations: $overrides['annotations'] ?? [],
            connectionManager: $this->connectionManager,
        );
    }

    public function test_name_returns_qualified_name(): void
    {
        $tool = $this->makeTool();
        $this->assertSame('mcp__test__my_tool', $tool->name());
    }

    public function test_description_truncates_long_text(): void
    {
        $long = str_repeat('A', 3000);
        $tool = $this->makeTool(['toolDescription' => $long]);
        $this->assertLessThanOrEqual(2048, mb_strlen($tool->description()));
    }

    public function test_is_read_only_from_annotations(): void
    {
        $readOnly = $this->makeTool(['annotations' => ['readOnlyHint' => true]]);
        $this->assertTrue($readOnly->isReadOnly([]));

        $writable = $this->makeTool(['annotations' => ['readOnlyHint' => false]]);
        $this->assertFalse($writable->isReadOnly([]));

        $noHint = $this->makeTool();
        $this->assertFalse($noHint->isReadOnly([]));
    }

    public function test_user_facing_name_uses_title_annotation(): void
    {
        $tool = $this->makeTool(['annotations' => ['title' => 'Pretty Name']]);
        $this->assertSame('test - Pretty Name (MCP)', $tool->userFacingName([]));
    }

    public function test_user_facing_name_falls_back_to_tool_name(): void
    {
        $tool = $this->makeTool();
        $this->assertSame('test - my_tool (MCP)', $tool->userFacingName([]));
    }

    public function test_call_returns_error_when_server_disconnected(): void
    {
        $tool = $this->makeTool();
        $result = $tool->call(['query' => 'test'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not connected', $result->output, "Expected 'not connected' in: {$result->output}");
    }

    public function test_activity_description(): void
    {
        $tool = $this->makeTool();
        $this->assertSame('Calling test/my_tool', $tool->getActivityDescription([]));
    }

    public function test_permissions_require_ask(): void
    {
        $tool = $this->makeTool();
        $decision = $tool->checkPermissions([], $this->context);
        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt);
    }

    public function test_concurrency_safe_matches_read_only(): void
    {
        $readOnly = $this->makeTool(['annotations' => ['readOnlyHint' => true]]);
        $this->assertTrue($readOnly->isConcurrencySafe([]));

        $writable = $this->makeTool();
        $this->assertFalse($writable->isConcurrencySafe([]));
    }
}
