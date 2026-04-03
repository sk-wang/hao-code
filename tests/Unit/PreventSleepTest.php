<?php

namespace Tests\Unit;

use App\Services\System\PreventSleep;
use PHPUnit\Framework\TestCase;

class PreventSleepTest extends TestCase
{
    /**
     * Proxy that overrides protected spawnCaffeinate/killCaffeinate
     * so no real processes are spawned during tests.
     */
    private function makeProxy(): PreventSleep
    {
        return new class extends PreventSleep {
            public int $spawnCalls = 0;
            public int $killCalls = 0;

            protected function spawnCaffeinate(): void
            {
                $this->spawnCalls++;
            }

            protected function killCaffeinate(): void
            {
                $this->killCalls++;
            }
        };
    }

    private function refCount(PreventSleep $ps): int
    {
        // Use the parent class to access inherited private properties
        $prop = (new \ReflectionClass(PreventSleep::class))->getProperty('refCount');
        $prop->setAccessible(true);
        return $prop->getValue($ps);
    }

    // ─── start / stop ref counting ────────────────────────────────────────

    public function test_start_increments_ref_count(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $this->assertSame(1, $this->refCount($ps));
    }

    public function test_multiple_starts_increment_ref_count(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->start();
        $ps->start();
        $this->assertSame(3, $this->refCount($ps));
    }

    public function test_stop_decrements_ref_count(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->start();
        $ps->stop();
        $this->assertSame(1, $this->refCount($ps));
    }

    public function test_stop_when_zero_does_not_go_negative(): void
    {
        $ps = $this->makeProxy();
        $ps->stop(); // already at 0
        $this->assertSame(0, $this->refCount($ps));
    }

    public function test_force_stop_resets_ref_count_to_zero(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->start();
        $ps->forceStop();
        $this->assertSame(0, $this->refCount($ps));
    }

    // ─── spawn/kill coordination ──────────────────────────────────────────

    public function test_spawn_called_only_once_on_first_start(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->start();
        $ps->start();
        $this->assertSame(1, $ps->spawnCalls);
    }

    public function test_kill_called_when_ref_count_reaches_zero(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->start();
        $ps->stop();
        $this->assertSame(0, $ps->killCalls); // still 1 remaining
        $ps->stop();
        $this->assertSame(1, $ps->killCalls); // now 0 → killed
    }

    public function test_stop_below_zero_does_not_call_kill_again(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->stop(); // kills
        $ps->stop(); // no-op, already 0
        $this->assertSame(1, $ps->killCalls);
    }

    public function test_force_stop_calls_kill(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->forceStop();
        $this->assertSame(1, $ps->killCalls);
    }

    public function test_force_stop_without_start_does_not_throw(): void
    {
        $ps = $this->makeProxy();
        $ps->forceStop(); // always calls killCaffeinate (no-op with null pid)
        $this->assertSame(0, $this->refCount($ps));
    }

    public function test_restart_after_stop_spawns_again(): void
    {
        $ps = $this->makeProxy();
        $ps->start();
        $ps->stop();
        $ps->start(); // second lifecycle
        $this->assertSame(2, $ps->spawnCalls);
    }
}
