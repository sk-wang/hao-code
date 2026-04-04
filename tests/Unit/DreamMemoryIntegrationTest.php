<?php

namespace Tests\Unit;

use App\Services\Memory\DreamConsolidator;
use App\Services\Memory\ConsolidationLock;
use App\Services\Memory\SessionMemory;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the dream consolidation workflow.
 * Tests the interaction between memory, lock, and consolidator.
 */
class DreamMemoryIntegrationTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome = '';
    private SessionMemory $memory;
    private ConsolidationLock $lock;
    private DreamConsolidator $consolidator;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dream_integration_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/.haocode', 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? '';
        $_SERVER['HOME'] = $this->tmpDir;

        // Clean any leftover lock file
        $lockFile = $this->tmpDir . '/.haocode/.consolidate-lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        $this->memory = new SessionMemory();
        $this->lock = new ConsolidationLock();
        $this->consolidator = new DreamConsolidator($this->memory, $this->lock, $this->tmpDir . '/sessions');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        $this->removeTree($this->tmpDir);
    }

    public function test_full_dream_workflow(): void
    {
        // 1. Initially no memories and never consolidated
        $stats = $this->consolidator->getMemoryStats();
        $this->assertSame(0, $stats['count']);
        $this->assertSame(0, $stats['last_consolidated']);

        // 2. Add some memories
        $this->memory->set('project', 'hao-code', 'preference');
        $this->memory->set('model_pref', 'claude-sonnet-4', 'preference');

        // 3. Check stats reflect the new memories
        $stats = $this->consolidator->getMemoryStats();
        $this->assertSame(2, $stats['count']);
        $this->assertGreaterThan(0, $stats['total_chars']);

        // 4. Record a consolidation
        $this->consolidator->recordConsolidation();

        // 5. Verify lock was stamped
        $this->assertGreaterThan(0, $this->lock->readLastConsolidatedAt());
        $this->assertLessThan(0.01, $this->lock->hoursSinceLastConsolidation());

        // 6. Build consolidation prompt
        $prompt = $this->consolidator->buildConsolidationPrompt(
            $this->consolidator->getMemoryRoot(),
            $this->consolidator->getTranscriptDir(),
        );
        $this->assertNotEmpty($prompt);
    }

    public function test_lock_prevents_concurrent_consolidation(): void
    {
        // First acquire succeeds (no prior lock)
        $prior = $this->lock->tryAcquire();
        $this->assertNotNull($prior);

        // Second acquire fails because our own PID holds the lock
        $second = $this->lock->tryAcquire();
        $this->assertNull($second, 'Should block when our own PID holds the lock');
    }

    public function test_lock_rollback_clears_lock(): void
    {
        // Acquire lock
        $prior = $this->lock->tryAcquire();
        $this->assertNotNull($prior);

        // Simulate failure: rollback to prior (0 = no lock existed)
        $this->lock->rollback(0);

        // Lock file should be removed
        $this->assertFileDoesNotExist($this->lock->getLockPath());
    }

    public function test_memory_search_works_after_consolidation(): void
    {
        $this->memory->set('database_config', 'mysql://localhost');
        $this->memory->set('api_endpoint', 'https://api.example.com');

        // Record consolidation
        $this->consolidator->recordConsolidation();

        // Search should still work
        $results = $this->memory->search('database');
        $this->assertArrayHasKey('database_config', $results);

        $results = $this->memory->search('api');
        $this->assertArrayHasKey('api_endpoint', $results);
    }

    public function test_memory_compact_integrates_with_stats(): void
    {
        // Add many memories
        for ($i = 0; $i < 15; $i++) {
            $this->memory->set("key_{$i}", "value_{$i}");
        }

        $statsBefore = $this->consolidator->getMemoryStats();
        $this->assertSame(15, $statsBefore['count']);

        // Compact to 5
        $removed = $this->memory->compact(5);
        $this->assertSame(10, $removed);

        // Stats should reflect compaction
        $statsAfter = $this->consolidator->getMemoryStats();
        $this->assertSame(5, $statsAfter['count']);
    }

    public function test_consolidation_prompt_references_correct_directories(): void
    {
        $memoryRoot = $this->consolidator->getMemoryRoot();
        $transcriptDir = $this->consolidator->getTranscriptDir();

        $prompt = $this->consolidator->buildConsolidationPrompt($memoryRoot, $transcriptDir);

        $this->assertStringContainsString($memoryRoot, $prompt);
        $this->assertStringContainsString($transcriptDir, $prompt);
    }

    public function test_multiple_stamps_update_last_consolidated(): void
    {
        $this->lock->stamp();
        $first = $this->lock->readLastConsolidatedAt();

        // Wait a moment
        usleep(1100000); // 1.1 seconds

        $this->lock->stamp();
        $second = $this->lock->readLastConsolidatedAt();

        $this->assertGreaterThanOrEqual($first, $second);
    }

    public function test_memory_system_prompt_injection_after_dream(): void
    {
        $this->memory->set('user_name', 'Hao');
        $this->memory->set('preferred_lang', 'PHP');

        $prompt = $this->memory->forSystemPrompt();

        $this->assertStringContainsString('user_name', $prompt);
        $this->assertStringContainsString('Hao', $prompt);
        $this->assertStringContainsString('preferred_lang', $prompt);
        $this->assertStringContainsString('PHP', $prompt);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
