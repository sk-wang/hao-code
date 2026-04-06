<?php

namespace Tests\Unit;

use App\Services\ToolResult\ToolResultStorage;
use PHPUnit\Framework\TestCase;

class ToolResultStorageTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/haocode_test_storage_' . getmypid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            @rmdir($this->testDir);
        }
    }

    private function makeStorage(): ToolResultStorage
    {
        return new ToolResultStorage(uniqid('test_' . getmypid() . '_', true));
    }

    // ─── shouldPersist ──────────────────────────────────────────────────

    public function test_should_persist_returns_true_for_large_output(): void
    {
        $storage = $this->makeStorage();
        $largeOutput = str_repeat('x', 60000);

        $this->assertTrue($storage->shouldPersist($largeOutput, 50000));
    }

    public function test_should_persist_returns_false_for_small_output(): void
    {
        $storage = $this->makeStorage();

        $this->assertFalse($storage->shouldPersist('small', 50000));
    }

    // ─── persist ────────────────────────────────────────────────────────

    public function test_persist_writes_file_and_returns_info(): void
    {
        $storage = $this->makeStorage();
        $output = str_repeat("line content\n", 5000);

        $result = $storage->persist('test_tool_123', $output);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('filepath', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFileExists($result['filepath']);
        $this->assertStringContainsString('persisted-output', $result['message']);
    }

    // ─── generatePreview ────────────────────────────────────────────────

    public function test_preview_truncates_at_newline_boundary(): void
    {
        $storage = $this->makeStorage();
        $content = "short line\n" . str_repeat("a", 3000) . "\n";

        $preview = $storage->generatePreview($content, 2000);

        // Should not exceed 2000 chars
        $this->assertLessThanOrEqual(2000, mb_strlen($preview));
    }

    public function test_preview_returns_full_content_when_small(): void
    {
        $storage = $this->makeStorage();

        $this->assertSame('small content', $storage->generatePreview('small content', 2000));
    }

    // ─── enforceMessageBudget ───────────────────────────────────────────

    public function test_budget_does_not_modify_small_results(): void
    {
        $storage = $this->makeStorage();
        $results = [
            ['tool_use_id' => 'a', 'content' => 'small', 'is_error' => false],
            ['tool_use_id' => 'b', 'content' => 'also small', 'is_error' => false],
        ];

        $enforced = $storage->enforceMessageBudget($results);

        $this->assertSame('small', $enforced[0]['content']);
        $this->assertSame('also small', $enforced[1]['content']);
    }

    // ─── state tracking ─────────────────────────────────────────────────

    public function test_get_and_restore_state(): void
    {
        $storage = $this->makeStorage();
        $storage->persist('id_1', str_repeat('x', 100));

        $state = $storage->getState();
        $this->assertContains('id_1', $state['seenIds']);
        $this->assertArrayHasKey('id_1', $state['replacements']);

        // Create new storage and restore
        $storage2 = $this->makeStorage();
        $storage2->restoreState($state);
        $state2 = $storage2->getState();

        $this->assertContains('id_1', $state2['seenIds']);
    }

    // ─── constants ──────────────────────────────────────────────────────

    public function test_preview_size_constant(): void
    {
        $this->assertSame(2000, ToolResultStorage::PREVIEW_SIZE_BYTES);
    }

    public function test_max_budget_constant(): void
    {
        $this->assertSame(200_000, ToolResultStorage::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS);
    }
}
