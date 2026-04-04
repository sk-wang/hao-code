<?php

namespace Tests\Unit;

use App\Services\Memory\ConsolidationLock;
use PHPUnit\Framework\TestCase;

class ConsolidationLockTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome = '';
    private ConsolidationLock $lock;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/consolidation_lock_test_' . getmypid();
        mkdir($this->tmpDir . '/.haocode', 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? '';
        $_SERVER['HOME'] = $this->tmpDir;

        $this->lock = new ConsolidationLock();
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        $lockFile = $this->tmpDir . '/.haocode/.consolidate-lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        if (is_dir($this->tmpDir . '/.haocode')) {
            rmdir($this->tmpDir . '/.haocode');
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_read_last_consolidated_at_returns_zero_when_no_lock(): void
    {
        $this->assertSame(0, $this->lock->readLastConsolidatedAt());
    }

    public function test_stamp_creates_lock_file(): void
    {
        $this->lock->stamp();

        $this->assertFileExists($this->lock->getLockPath());
    }

    public function test_stamp_writes_pid(): void
    {
        $this->lock->stamp();

        $content = trim(file_get_contents($this->lock->getLockPath()));
        $this->assertSame((string)getmypid(), $content);
    }

    public function test_read_last_consolidated_at_returns_mtime_after_stamp(): void
    {
        $beforeMs = (int)(microtime(true) * 1000);
        $this->lock->stamp();
        $afterMs = (int)(microtime(true) * 1000);

        $lastMs = $this->lock->readLastConsolidatedAt();
        $this->assertGreaterThan(0, $lastMs);
        // mtime should be close to the stamp time (within a few seconds tolerance)
        $this->assertGreaterThan($beforeMs - 5000, $lastMs);
        $this->assertLessThan($afterMs + 5000, $lastMs);
    }

    public function test_hours_since_last_consolidation_returns_max_when_never(): void
    {
        $hours = $this->lock->hoursSinceLastConsolidation();
        $this->assertEquals(PHP_FLOAT_MAX, $hours);
    }

    public function test_hours_since_last_consolidation_returns_small_value_after_stamp(): void
    {
        $this->lock->stamp();
        $hours = $this->lock->hoursSinceLastConsolidation();

        // Should be very close to 0
        $this->assertLessThan(0.01, $hours);
    }

    public function test_try_acquire_succeeds_when_no_lock_exists(): void
    {
        $priorMtime = $this->lock->tryAcquire();
        $this->assertNotNull($priorMtime);
        $this->assertSame(0, $priorMtime);
    }

    public function test_try_acquire_creates_lock_file(): void
    {
        $this->lock->tryAcquire();
        $this->assertFileExists($this->lock->getLockPath());
    }

    public function test_try_acquire_blocks_when_another_process_holds_lock(): void
    {
        // Simulate a live process holding the lock
        $lockPath = $this->lock->getLockPath();
        file_put_contents($lockPath, (string)getmypid());

        // We hold the lock ourselves, so tryAcquire should detect our PID is live
        // and return null (blocked)
        $result = $this->lock->tryAcquire();
        $this->assertNull($result);
    }

    public function test_try_acquire_reclaims_stale_lock(): void
    {
        $lockPath = $this->lock->getLockPath();

        // Create a stale lock: old mtime, dead PID (use PID 1 which is init/systemd)
        file_put_contents($lockPath, '99999999'); // Very unlikely PID
        // Set mtime to old enough to be stale
        touch($lockPath, time() - 7200); // 2 hours ago

        $priorMtime = $this->lock->tryAcquire();
        $this->assertNotNull($priorMtime, 'Should reclaim stale lock');
    }

    public function test_rollback_removes_lock_when_prior_mtime_is_zero(): void
    {
        $this->lock->tryAcquire();
        $this->assertFileExists($this->lock->getLockPath());

        $this->lock->rollback(0);
        $this->assertFileDoesNotExist($this->lock->getLockPath());
    }

    public function test_rollback_restores_prior_mtime(): void
    {
        // Stamp first to create a known mtime
        $this->lock->stamp();
        $priorMs = $this->lock->readLastConsolidatedAt();

        // Acquire (which updates mtime)
        $this->lock->tryAcquire();

        // Rollback to prior
        $this->lock->rollback($priorMs);

        // mtime should be close to what it was before
        $restoredMs = $this->lock->readLastConsolidatedAt();
        $this->assertGreaterThan($priorMs - 5000, $restoredMs);
        $this->assertLessThan($priorMs + 5000, $restoredMs);
    }

    public function test_get_lock_path_returns_correct_path(): void
    {
        $expected = $this->tmpDir . '/.haocode/.consolidate-lock';
        $this->assertSame($expected, $this->lock->getLockPath());
    }
}
