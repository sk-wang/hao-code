<?php

namespace App\Services\System;

/**
 * Prevents system sleep during long operations using macOS caffeinate.
 * Uses reference counting so multiple callers can independently hold the lock.
 */
class PreventSleep
{
    private int $refCount = 0;
    private ?int $pid = null;

    /**
     * Start preventing sleep. Safe to call multiple times (reference counted).
     */
    public function start(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        $this->refCount++;

        if ($this->refCount === 1) {
            $this->spawnCaffeinate();
        }
    }

    /**
     * Stop preventing sleep. Only actually stops when ref count reaches 0.
     */
    public function stop(): void
    {
        if ($this->refCount <= 0) {
            return;
        }

        $this->refCount--;

        if ($this->refCount === 0) {
            $this->killCaffeinate();
        }
    }

    /**
     * Force stop regardless of ref count (for cleanup).
     */
    public function forceStop(): void
    {
        $this->refCount = 0;
        $this->killCaffeinate();
    }

    public function isActive(): bool
    {
        return $this->pid !== null && posix_kill($this->pid, 0);
    }

    protected function spawnCaffeinate(): void
    {
        // -i: prevent idle sleep, -t 300: 5-minute auto-timeout (crash safety)
        $command = 'caffeinate -i -t 300 2>/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $this->pid = proc_get_status($process)['pid'];
            // Don't wait - let it run in background
        }
    }

    protected function killCaffeinate(): void
    {
        if ($this->pid !== null) {
            posix_kill($this->pid, SIGTERM);
            $this->pid = null;
        }
    }

    public function __destruct()
    {
        $this->forceStop();
    }
}
