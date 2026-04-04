<?php

namespace Tests\Unit;

use App\Services\Memory\AutoDreamService;
use App\Services\Memory\ConsolidationLock;
use App\Services\Memory\DreamConsolidator;
use App\Services\Memory\SessionMemory;
use PHPUnit\Framework\TestCase;

class AutoDreamServiceTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/auto_dream_test_' . getmypid();
        mkdir($this->tmpDir . '/.haocode', 0755, true);
        mkdir($this->tmpDir . '/sessions', 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? '';
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        // Clean up files
        $files = [
            $this->tmpDir . '/.haocode/.consolidate-lock',
            $this->tmpDir . '/.haocode/memory.json',
        ];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up session files
        foreach (glob($this->tmpDir . '/sessions/*.jsonl') as $f) {
            unlink($f);
        }

        foreach ([$this->tmpDir . '/sessions', $this->tmpDir . '/.haocode', $this->tmpDir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function createService(array $config = []): AutoDreamService
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock, $this->tmpDir . '/sessions');

        return new AutoDreamService(
            $consolidator,
            $lock,
            minHours: $config['min_hours'] ?? 24,
            minSessions: $config['min_sessions'] ?? 5,
        );
    }

    private function createSessionFiles(int $count, int $ageMinutes = 0): void
    {
        $sessionDir = $this->tmpDir . '/sessions';
        for ($i = 0; $i < $count; $i++) {
            $file = $sessionDir . "/session_{$i}.jsonl";
            file_put_contents($file, '{"type":"user_message","content":"test"}');
            if ($ageMinutes > 0) {
                touch($file, time() - ($ageMinutes * 60));
            }
        }
    }

    public function test_maybe_execute_returns_null_when_time_gate_not_passed(): void
    {
        // Create a recent lock (just stamped)
        $lock = new ConsolidationLock();
        $lock->stamp();

        $service = $this->createService();
        $result = $service->maybeExecute();

        $this->assertNull($result);
    }

    public function test_maybe_execute_returns_null_when_no_sessions(): void
    {
        // Create an old lock (never consolidated = PHP_FLOAT_MAX hours)
        // But no sessions exist, so session gate fails
        $service = $this->createService();

        // Force the time gate to pass by making lock very old
        $lockPath = $this->tmpDir . '/.haocode/.consolidate-lock';
        file_put_contents($lockPath, '1'); // Fake PID
        touch($lockPath, time() - 86400); // 24 hours ago

        // But no sessions means session gate fails
        $result = $service->maybeExecute();
        $this->assertNull($result);
    }

    public function test_maybe_execute_returns_null_when_not_enough_sessions(): void
    {
        // Create old lock
        $lockPath = $this->tmpDir . '/.haocode/.consolidate-lock';
        file_put_contents($lockPath, '1');
        touch($lockPath, time() - 86400); // 24 hours ago

        // Create only 2 sessions (default min is 5)
        $this->createSessionFiles(2);

        $service = $this->createService();
        $result = $service->maybeExecute();

        $this->assertNull($result);
    }

    public function test_build_consolidation_prompt_contains_memory_directory(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock);

        $prompt = $consolidator->buildConsolidationPrompt('/test/memory', '/test/transcripts');

        $this->assertStringContainsString('/test/memory', $prompt);
        $this->assertStringContainsString('/test/transcripts', $prompt);
        $this->assertStringContainsString('Phase 1', $prompt);
        $this->assertStringContainsString('Phase 2', $prompt);
        $this->assertStringContainsString('Phase 3', $prompt);
        $this->assertStringContainsString('Phase 4', $prompt);
    }

    public function test_get_memory_stats_returns_correct_keys(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock);

        $stats = $consolidator->getMemoryStats();

        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('total_chars', $stats);
        $this->assertArrayHasKey('last_consolidated', $stats);
    }

    public function test_get_memory_stats_returns_zero_count_with_no_memories(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock);

        $stats = $consolidator->getMemoryStats();

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0, $stats['total_chars']);
    }

    public function test_record_consolidation_stamps_lock(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock);

        $consolidator->recordConsolidation();

        $this->assertFileExists($lock->getLockPath());
        $this->assertGreaterThan(0, $lock->readLastConsolidatedAt());
    }

    public function test_get_memory_root_returns_haocode_dir(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock);

        $root = $consolidator->getMemoryRoot();
        $this->assertStringContainsString('.haocode', $root);
    }

    public function test_get_transcript_dir_returns_session_path(): void
    {
        $memory = new SessionMemory();
        $lock = new ConsolidationLock();
        $consolidator = new DreamConsolidator($memory, $lock, '/custom/sessions');

        $dir = $consolidator->getTranscriptDir();
        $this->assertSame('/custom/sessions', $dir);
    }
}
