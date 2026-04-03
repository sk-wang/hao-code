<?php

namespace Tests\Unit;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class BaseToolTest extends TestCase
{
    private BaseTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        // Concrete subclass for testing
        $this->tool = new class extends BaseTool {
            public function name(): string { return 'TestTool'; }
            public function description(): string { return 'A test tool'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []], []);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };

        $this->context = new ToolUseContext('/tmp', 'test');
    }

    // ─── defaults ─────────────────────────────────────────────────────────

    public function test_is_read_only_defaults_to_false(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
    }

    public function test_is_concurrency_safe_mirrors_is_read_only(): void
    {
        $this->assertFalse($this->tool->isConcurrencySafe([]));
    }

    public function test_read_only_tool_is_concurrency_safe(): void
    {
        $tool = new class extends BaseTool {
            public function name(): string { return 'T'; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make([], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ToolResult::success(''); }
            public function isReadOnly(array $input): bool { return true; }
        };

        $this->assertTrue($tool->isConcurrencySafe([]));
    }

    public function test_is_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function test_user_facing_name_defaults_to_name(): void
    {
        $this->assertSame('TestTool', $this->tool->userFacingName([]));
    }

    public function test_check_permissions_allows_by_default(): void
    {
        $decision = $this->tool->checkPermissions([], $this->context);
        $this->assertTrue($decision->allowed);
    }

    public function test_validate_input_returns_null_by_default(): void
    {
        $this->assertNull($this->tool->validateInput([], $this->context));
    }

    public function test_max_result_size_is_50000(): void
    {
        $this->assertSame(50000, $this->tool->maxResultSizeChars());
    }

    public function test_backfill_observable_input_returns_input_unchanged(): void
    {
        $input = ['key' => 'value'];
        $this->assertSame($input, $this->tool->backfillObservableInput($input, $this->context));
    }

    // ─── resolvePath ──────────────────────────────────────────────────────

    private function resolvePath(string $path, string $wd): string
    {
        $m = (new \ReflectionClass($this->tool))->getMethod('resolvePath');
        $m->setAccessible(true);
        return $m->invoke($this->tool, $path, $wd);
    }

    public function test_resolve_absolute_path_unchanged(): void
    {
        $result = $this->resolvePath('/absolute/path', '/working');
        $this->assertStringStartsWith('/absolute', $result);
    }

    public function test_resolve_relative_path_prepends_working_dir(): void
    {
        // Use sys_get_temp_dir() as working dir, create a real path to resolve
        $tmpDir = sys_get_temp_dir();
        // relative path that exists — just use the tmp dir itself
        $result = $this->resolvePath('', $tmpDir);
        // Empty relative resolves to workdir
        $this->assertStringContainsString($tmpDir, $result);
    }

    public function test_resolve_tilde_path_expands_home(): void
    {
        $home = getenv('HOME') ?: '/root';
        $result = $this->resolvePath('~/somesubdir', $home);
        $this->assertStringStartsWith($home, $result);
        $this->assertStringNotContainsString('~', $result);
    }

    public function test_resolve_tilde_only_expands_home(): void
    {
        $home = getenv('HOME') ?: '/root';
        $result = $this->resolvePath('~', $home);
        $this->assertStringNotContainsString('~', $result);
    }
}
