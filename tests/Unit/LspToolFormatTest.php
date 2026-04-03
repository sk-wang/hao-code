<?php

namespace Tests\Unit;

use App\Tools\Lsp\LspTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LspTool formatting helpers via reflection.
 * Does NOT start any LSP server processes.
 */
class LspToolFormatTest extends TestCase
{
    private LspTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new LspTool;
        $this->ref = new \ReflectionClass($this->tool);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->tool, ...$args);
    }

    // ─── uriToPath ────────────────────────────────────────────────────────

    public function test_uri_to_path_strips_file_scheme(): void
    {
        $this->assertSame('/home/user/app.php', $this->invoke('uriToPath', 'file:///home/user/app.php'));
    }

    public function test_uri_to_path_returns_non_file_uri_unchanged(): void
    {
        $this->assertSame('/relative/path', $this->invoke('uriToPath', '/relative/path'));
    }

    public function test_uri_to_path_empty_string(): void
    {
        $this->assertSame('', $this->invoke('uriToPath', ''));
    }

    // ─── symbolKindToString ───────────────────────────────────────────────

    public function test_kind_1_is_file(): void
    {
        $this->assertSame('File', $this->invoke('symbolKindToString', 1));
    }

    public function test_kind_5_is_class(): void
    {
        $this->assertSame('Class', $this->invoke('symbolKindToString', 5));
    }

    public function test_kind_6_is_method(): void
    {
        $this->assertSame('Method', $this->invoke('symbolKindToString', 6));
    }

    public function test_kind_12_is_function(): void
    {
        $this->assertSame('Function', $this->invoke('symbolKindToString', 12));
    }

    public function test_kind_13_is_variable(): void
    {
        $this->assertSame('Variable', $this->invoke('symbolKindToString', 13));
    }

    public function test_unknown_kind_returns_symbol(): void
    {
        $this->assertSame('Symbol', $this->invoke('symbolKindToString', 999));
    }

    // ─── formatHover ──────────────────────────────────────────────────────

    public function test_hover_null_returns_no_info(): void
    {
        $result = $this->invoke('formatHover', null);
        $this->assertStringContainsString('No hover information', $result);
    }

    public function test_hover_string_content(): void
    {
        $result = $this->invoke('formatHover', ['contents' => 'function foo(): void']);
        $this->assertSame('function foo(): void', $result);
    }

    public function test_hover_markup_content_with_kind_and_value(): void
    {
        $result = $this->invoke('formatHover', [
            'contents' => ['kind' => 'markdown', 'value' => 'string $name'],
        ]);
        $this->assertStringContainsString('markdown', $result);
        $this->assertStringContainsString('string $name', $result);
    }

    public function test_hover_array_of_strings(): void
    {
        $result = $this->invoke('formatHover', [
            'contents' => ['Type: int', 'The count value'],
        ]);
        $this->assertStringContainsString('Type: int', $result);
        $this->assertStringContainsString('The count value', $result);
    }

    // ─── formatLocations ──────────────────────────────────────────────────

    public function test_format_locations_null_returns_no_locations(): void
    {
        $result = $this->invoke('formatLocations', null);
        $this->assertStringContainsString('No locations found', $result);
    }

    public function test_format_single_location_with_uri(): void
    {
        $loc = ['uri' => 'file:///src/Foo.php', 'range' => ['start' => ['line' => 9, 'character' => 4]]];
        $result = $this->invoke('formatLocations', $loc);
        $this->assertStringContainsString('/src/Foo.php', $result);
        $this->assertStringContainsString('10', $result); // 1-based
    }

    public function test_format_array_of_locations(): void
    {
        $locs = [
            ['uri' => 'file:///src/A.php', 'range' => ['start' => ['line' => 0, 'character' => 0]]],
            ['uri' => 'file:///src/B.php', 'range' => ['start' => ['line' => 4, 'character' => 0]]],
        ];
        $result = $this->invoke('formatLocations', $locs);
        $this->assertStringContainsString('/src/A.php', $result);
        $this->assertStringContainsString('/src/B.php', $result);
    }

    // ─── formatSymbols ────────────────────────────────────────────────────

    public function test_format_symbols_non_array_returns_no_symbols(): void
    {
        $result = $this->invoke('formatSymbols', null);
        $this->assertStringContainsString('No symbols found', $result);
    }

    public function test_format_symbols_includes_name_and_kind(): void
    {
        $symbols = [
            ['name' => 'MyClass', 'kind' => 5, 'range' => ['start' => ['line' => 0]]],
        ];
        $result = $this->invoke('formatSymbols', $symbols);
        $this->assertStringContainsString('MyClass', $result);
        $this->assertStringContainsString('Class', $result);
    }

    public function test_format_symbols_shows_children(): void
    {
        $symbols = [
            [
                'name' => 'MyClass',
                'kind' => 5,
                'range' => ['start' => ['line' => 0]],
                'children' => [
                    ['name' => 'myMethod', 'kind' => 6, 'range' => ['start' => ['line' => 5]]],
                ],
            ],
        ];
        $result = $this->invoke('formatSymbols', $symbols);
        $this->assertStringContainsString('myMethod', $result);
        $this->assertStringContainsString('Method', $result);
    }

    // ─── tool metadata ────────────────────────────────────────────────────

    public function test_name_is_lsp(): void
    {
        $this->assertSame('LSP', $this->tool->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_is_concurrency_safe(): void
    {
        $this->assertTrue($this->tool->isConcurrencySafe([]));
    }

    // ─── call — no LSP server available ───────────────────────────────────

    public function test_call_returns_error_when_no_server_for_language(): void
    {
        // Use an unsupported language (plain text) — guaranteed no LSP server
        $result = $this->tool->call([
            'operation' => 'hover',
            'filePath'  => '/tmp/file.unknownxyz',
            'line'      => 1,
            'character' => 1,
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('No LSP server', $result->output);
    }
}
