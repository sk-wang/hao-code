<?php

namespace Tests\Unit;

use App\Services\Cost\CostTracker;
use PHPUnit\Framework\TestCase;

class CostTrackerTest extends TestCase
{
    public function test_it_calculates_cost_from_tokens(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);

        // 1M input tokens = $3.00
        $tracker->addUsage(1_000_000, 0);
        $this->assertEqualsWithDelta(3.0, $tracker->getTotalCost(), 0.0001);
    }

    public function test_it_calculates_output_token_cost(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);

        // 1M output tokens = $15.00
        $tracker->addUsage(0, 1_000_000);
        $this->assertEqualsWithDelta(15.0, $tracker->getTotalCost(), 0.0001);
    }

    public function test_it_calculates_cache_write_cost(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);

        // 1M cache write tokens = $3.75
        $tracker->addUsage(0, 0, 1_000_000, 0);
        $this->assertEqualsWithDelta(3.75, $tracker->getTotalCost(), 0.0001);
    }

    public function test_it_calculates_cache_read_cost(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);

        // 1M cache read tokens = $0.30
        $tracker->addUsage(0, 0, 0, 1_000_000);
        $this->assertEqualsWithDelta(0.30, $tracker->getTotalCost(), 0.0001);
    }

    public function test_it_accumulates_cost_across_multiple_calls(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);

        $tracker->addUsage(100_000, 0); // $0.30
        $tracker->addUsage(100_000, 0); // $0.30
        $this->assertEqualsWithDelta(0.60, $tracker->getTotalCost(), 0.0001);
    }

    public function test_should_stop_when_threshold_exceeded(): void
    {
        $tracker = new CostTracker(warnThreshold: 1.0, stopThreshold: 2.0);

        $tracker->addUsage(0, 100_000); // $1.50
        $this->assertFalse($tracker->shouldStop());

        $tracker->addUsage(0, 100_000); // another $1.50 → total $3.00
        $this->assertTrue($tracker->shouldStop());
    }

    public function test_should_warn_when_warn_threshold_reached(): void
    {
        $tracker = new CostTracker(warnThreshold: 1.0, stopThreshold: 100.0);

        $tracker->addUsage(0, 50_000); // $0.75 — below warn
        $this->assertFalse($tracker->shouldWarn());

        $tracker->addUsage(0, 50_000); // $0.75 more → $1.50 — above warn
        $this->assertTrue($tracker->shouldWarn());
    }

    public function test_it_fires_warning_callback_exactly_once(): void
    {
        $tracker = new CostTracker(warnThreshold: 1.0, stopThreshold: 100.0);
        $fired = 0;

        $tracker->setOnWarning(function () use (&$fired) {
            $fired++;
        });

        $tracker->addUsage(0, 50_000);  // $0.75 — no warn yet
        $tracker->addUsage(0, 50_000);  // $1.50 — warn fires
        $tracker->addUsage(0, 50_000);  // $2.25 — already warned, no second fire

        $this->assertSame(1, $fired);
    }

    public function test_it_resets_warning_flag_when_thresholds_are_reset(): void
    {
        $tracker = new CostTracker(warnThreshold: 1.0, stopThreshold: 100.0);
        $fired = 0;

        $tracker->setOnWarning(function () use (&$fired) {
            $fired++;
        });

        $tracker->addUsage(0, 100_000); // $1.50 — warn fires
        $this->assertSame(1, $fired);

        // Reset thresholds clears warnedAtThreshold
        $tracker->setThresholds(1.0, 100.0);
        $tracker->addUsage(0, 1); // small cost — already past warn threshold
        $this->assertSame(2, $fired);
    }

    public function test_set_total_cost_overrides_accumulated(): void
    {
        $tracker = new CostTracker(warnThreshold: 10.0, stopThreshold: 100.0);
        $tracker->addUsage(0, 1_000_000); // $15.00

        $tracker->setTotalCost(0.50);
        $this->assertEqualsWithDelta(0.50, $tracker->getTotalCost(), 0.0001);
    }

    public function test_get_summary_contains_cost_figures(): void
    {
        $tracker = new CostTracker(warnThreshold: 5.0, stopThreshold: 50.0);
        $tracker->addUsage(0, 100_000); // $1.50

        $summary = $tracker->getSummary();

        $this->assertStringContainsString('$1.50', $summary);
        $this->assertStringContainsString('$5.00', $summary);
        $this->assertStringContainsString('$50.00', $summary);
    }

    public function test_it_exposes_threshold_accessors(): void
    {
        $tracker = new CostTracker(warnThreshold: 3.0, stopThreshold: 30.0);

        $this->assertEqualsWithDelta(3.0, $tracker->getWarnThreshold(), 0.0001);
        $this->assertEqualsWithDelta(30.0, $tracker->getStopThreshold(), 0.0001);
    }
}
