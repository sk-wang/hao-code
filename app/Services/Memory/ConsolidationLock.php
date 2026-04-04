<?php

declare(strict_types=1);

namespace App\Services\Memory;

/**
 * Lock file whose mtime IS lastConsolidatedAt.
 * Lives in ~/.haocode/.consolidate-lock
 */
class ConsolidationLock
{
    private const LOCK_FILE = '.consolidate-lock';
    private const HOLDER_STALE_MS = 60 * 60 * 1000; // 1 hour

    private string $lockPath;

    public function __construct()
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $this->lockPath = "{$home}/.haocode/" . self::LOCK_FILE;
    }

    /**
     * mtime of the lock file = lastConsolidatedAt. 0 if absent.
     */
    public function readLastConsolidatedAt(): int
    {
        if (!file_exists($this->lockPath)) {
            return 0;
        }

        return (int)(filemtime($this->lockPath) * 1000); // ms
    }

    /**
     * Hours since last consolidation.
     */
    public function hoursSinceLastConsolidation(): float
    {
        $lastMs = $this->readLastConsolidatedAt();
        if ($lastMs === 0) {
            return PHP_FLOAT_MAX; // Never consolidated
        }

        return (microtime(true) * 1000 - $lastMs) / 3_600_000;
    }

    /**
     * Acquire: write PID -> mtime = now.
     * Returns the pre-acquire mtime (for rollback), or null if blocked.
     */
    public function tryAcquire(): ?int
    {
        $mtimeMs = 0;
        $holderPid = null;

        if (file_exists($this->lockPath)) {
            $mtimeMs = (int)(filemtime($this->lockPath) * 1000);
            $body = @file_get_contents($this->lockPath);
            $parsed = (int)trim($body ?? '');
            $holderPid = $parsed > 0 ? $parsed : null;
        }

        // Check if lock is fresh and holder is alive
        $nowMs = (int)(microtime(true) * 1000);
        if ($mtimeMs > 0 && ($nowMs - $mtimeMs) < self::HOLDER_STALE_MS) {
            if ($holderPid !== null && $this->isProcessRunning($holderPid)) {
                return null; // Lock held by live process
            }
            // Dead PID — reclaim
        }

        // Ensure directory exists
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write lock
        file_put_contents($this->lockPath, (string)getmypid());

        // Verify we won the race
        $verify = @file_get_contents($this->lockPath);
        if ((int)trim($verify ?? '') !== getmypid()) {
            return null;
        }

        return $mtimeMs ?: 0;
    }

    /**
     * Rewind mtime to pre-acquire after a failed consolidation.
     */
    public function rollback(int $priorMtime): void
    {
        if ($priorMtime === 0) {
            @unlink($this->lockPath);

            return;
        }

        file_put_contents($this->lockPath, '');
        $t = $priorMtime / 1000;
        @touch($this->lockPath, (int)$t);
    }

    /**
     * Stamp the lock file with current time (after successful consolidation).
     */
    public function stamp(): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->lockPath, (string)getmypid());
    }

    /**
     * Get the lock file path (for testing).
     */
    public function getLockPath(): string
    {
        return $this->lockPath;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // posix_kill with signal 0 checks if process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc on Linux, or assume stale on macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec("ps -p {$pid} -o pid= 2>/dev/null");

            return trim($output ?? '') !== '';
        }

        return file_exists("/proc/{$pid}");
    }
}
