<?php

namespace Tests\Unit;

use App\Tools\Notebook\NotebookEditTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class NotebookEditToolTest extends TestCase
{
    private ToolUseContext $context;
    private NotebookEditTool $tool;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tool = new NotebookEditTool;
        $this->tmpDir = sys_get_temp_dir() . '/notebook_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->context = new ToolUseContext(workingDirectory: $this->tmpDir, sessionId: 'test');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.ipynb') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeNotebook(array $cells = []): string
    {
        if (empty($cells)) {
            $cells = [
                ['cell_type' => 'code', 'metadata' => [], 'source' => ['print("hello")'], 'execution_count' => null, 'outputs' => []],
                ['cell_type' => 'markdown', 'metadata' => [], 'source' => ['# Section']],
                ['cell_type' => 'code', 'metadata' => [], 'source' => ['x = 1'], 'execution_count' => null, 'outputs' => []],
            ];
        }
        $notebook = [
            'nbformat' => 4,
            'nbformat_minor' => 5,
            'metadata' => [],
            'cells' => $cells,
        ];
        $path = $this->tmpDir . '/test_' . uniqid() . '.ipynb';
        file_put_contents($path, json_encode($notebook, JSON_PRETTY_PRINT));
        return $path;
    }

    // ─── error cases ──────────────────────────────────────────────────────

    public function test_non_ipynb_file_returns_error(): void
    {
        $result = $this->tool->call([
            'notebook_path' => '/tmp/file.py',
            'new_source' => 'print("x")',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('.ipynb', $result->output);
    }

    public function test_nonexistent_file_returns_error(): void
    {
        $result = $this->tool->call([
            'notebook_path' => '/tmp/does_not_exist.ipynb',
            'new_source' => 'print("x")',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_invalid_json_returns_error(): void
    {
        $path = $this->tmpDir . '/invalid.ipynb';
        file_put_contents($path, '{not valid json}');

        $result = $this->tool->call([
            'notebook_path' => $path,
            'new_source' => 'x',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('parse notebook JSON', $result->output);
    }

    public function test_notebook_without_cells_returns_error(): void
    {
        $path = $this->tmpDir . '/nocells.ipynb';
        file_put_contents($path, json_encode(['nbformat' => 4]));

        $result = $this->tool->call([
            'notebook_path' => $path,
            'new_source' => 'x',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('cells array', $result->output);
    }

    // ─── replace mode ─────────────────────────────────────────────────────

    public function test_replace_updates_cell_source(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'print("replaced")',
            'edit_mode' => 'replace',
        ], $this->context);

        $this->assertFalse($result->isError);
        $notebook = json_decode(file_get_contents($path), true);
        $this->assertContains('print("replaced")', $notebook['cells'][0]['source']);
    }

    public function test_replace_default_mode_when_not_specified(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'new code here',
        ], $this->context);

        $this->assertFalse($result->isError);
        $notebook = json_decode(file_get_contents($path), true);
        $this->assertContains('new code here', $notebook['cells'][0]['source']);
    }

    public function test_replace_preserves_cell_count(): void
    {
        $path = $this->makeNotebook();
        $originalCount = count(json_decode(file_get_contents($path), true)['cells']);

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 1,
            'new_source' => '## New Header',
            'edit_mode' => 'replace',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        $this->assertCount($originalCount, $notebook['cells']);
    }

    public function test_replace_out_of_bounds_returns_error(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 99,
            'new_source' => 'x = 1',
            'edit_mode' => 'replace',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    public function test_replace_can_change_cell_type(): void
    {
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => '# Now markdown',
            'cell_type' => 'markdown',
            'edit_mode' => 'replace',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        $this->assertSame('markdown', $notebook['cells'][0]['cell_type']);
    }

    public function test_replace_output_mentions_cell_number(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 2,
            'new_source' => 'y = 2',
            'edit_mode' => 'replace',
        ], $this->context);

        $this->assertStringContainsString('2', $result->output);
    }

    // ─── delete mode ──────────────────────────────────────────────────────

    public function test_delete_removes_cell(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 1,
            'new_source' => '',
            'edit_mode' => 'delete',
        ], $this->context);

        $this->assertFalse($result->isError);
        $notebook = json_decode(file_get_contents($path), true);
        $this->assertCount(2, $notebook['cells']);
    }

    public function test_delete_removes_correct_cell(): void
    {
        $path = $this->makeNotebook();

        // Delete cell 0 (print("hello"))
        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => '',
            'edit_mode' => 'delete',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        // The first remaining cell should now be the markdown cell
        $this->assertSame('markdown', $notebook['cells'][0]['cell_type']);
    }

    public function test_delete_out_of_bounds_returns_error(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 50,
            'new_source' => '',
            'edit_mode' => 'delete',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist', $result->output);
    }

    public function test_delete_success_message_mentions_deleted(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => '',
            'edit_mode' => 'delete',
        ], $this->context);

        $this->assertStringContainsString('Deleted', $result->output);
    }

    // ─── insert mode ──────────────────────────────────────────────────────

    public function test_insert_adds_cell_after_given_index(): void
    {
        $path = $this->makeNotebook();
        $originalCount = count(json_decode(file_get_contents($path), true)['cells']);

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'inserted_cell = True',
            'edit_mode' => 'insert',
        ], $this->context);

        $this->assertFalse($result->isError);
        $notebook = json_decode(file_get_contents($path), true);
        $this->assertCount($originalCount + 1, $notebook['cells']);
        // Cell at index 1 should be the new cell (inserted after index 0)
        $this->assertContains('inserted_cell = True', $notebook['cells'][1]['source']);
    }

    public function test_insert_defaults_to_code_cell_type(): void
    {
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'x = 1',
            'edit_mode' => 'insert',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        $this->assertSame('code', $notebook['cells'][1]['cell_type']);
    }

    public function test_insert_respects_cell_type_markdown(): void
    {
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => '# New Section',
            'cell_type' => 'markdown',
            'edit_mode' => 'insert',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        $this->assertSame('markdown', $notebook['cells'][1]['cell_type']);
    }

    public function test_insert_code_cell_has_outputs_and_execution_count(): void
    {
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'import numpy',
            'cell_type' => 'code',
            'edit_mode' => 'insert',
        ], $this->context);

        $notebook = json_decode(file_get_contents($path), true);
        $newCell = $notebook['cells'][1];
        $this->assertArrayHasKey('outputs', $newCell);
        $this->assertArrayHasKey('execution_count', $newCell);
        $this->assertNull($newCell['execution_count']);
    }

    public function test_insert_success_message_says_inserted(): void
    {
        $path = $this->makeNotebook();

        $result = $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => 'x = 1',
            'edit_mode' => 'insert',
        ], $this->context);

        $this->assertStringContainsString('Inserted', $result->output);
    }

    // ─── source line format (nbformat compliance) ─────────────────────────

    public function test_multiline_replace_source_lines_end_with_newline(): void
    {
        // The nbformat spec requires source arrays to have \n at the end of every
        // line except the last. Without it, Jupyter concatenates "line1" + "line2"
        // into "line1line2" — losing all line breaks.
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => "line1\nline2\nline3",
            'edit_mode' => 'replace',
        ], $this->context);

        $source = json_decode(file_get_contents($path), true)['cells'][0]['source'];
        $this->assertIsArray($source);
        $this->assertCount(3, $source);
        $this->assertSame("line1\n", $source[0], 'First line must end with \\n');
        $this->assertSame("line2\n", $source[1], 'Middle line must end with \\n');
        $this->assertSame('line3',   $source[2], 'Last line must NOT end with \\n');
    }

    public function test_multiline_insert_source_lines_end_with_newline(): void
    {
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => "a = 1\nb = 2",
            'edit_mode' => 'insert',
        ], $this->context);

        $source = json_decode(file_get_contents($path), true)['cells'][1]['source'];
        $this->assertIsArray($source);
        $this->assertCount(2, $source);
        $this->assertSame("a = 1\n", $source[0], 'First line must end with \\n');
        $this->assertSame('b = 2',   $source[1], 'Last line must NOT end with \\n');
    }

    public function test_source_trailing_newline_does_not_produce_extra_empty_element(): void
    {
        // If new_source ends with \n, the resulting array should not have a
        // spurious empty string as the last element.
        $path = $this->makeNotebook();

        $this->tool->call([
            'notebook_path' => $path,
            'cell_number' => 0,
            'new_source' => "x = 1\n",
            'edit_mode' => 'replace',
        ], $this->context);

        $source = json_decode(file_get_contents($path), true)['cells'][0]['source'];
        $this->assertIsArray($source);
        // Should be ["x = 1\n"] — one element, not ["x = 1\n", ""]
        $this->assertCount(1, $source);
        $this->assertSame("x = 1\n", $source[0]);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_read_only_returns_false(): void
    {
        $this->assertFalse($this->tool->isReadOnly([]));
    }

    public function test_name_is_notebook_edit(): void
    {
        $this->assertSame('NotebookEdit', $this->tool->name());
    }
}
