<?php

namespace App\Services\Cost;

/**
 * Tracks spending per session with configurable thresholds and warnings.
 */
class CostTracker
{
    private float $totalCost = 0.0;
    private float $warnThreshold;
    private float $stopThreshold;
    private bool $warnedAtThreshold = false;

    /** @var callable|null */
    private $onWarning = null;

    public function __construct(
        ?float $warnThreshold = null,
        ?float $stopThreshold = null,
    ) {
        $this->warnThreshold = $warnThreshold ?? (float) ($_ENV['HAOCODE_COST_WARN'] ?? 5.00);
        $this->stopThreshold = $stopThreshold ?? (float) ($_ENV['HAOCODE_COST_STOP'] ?? 50.00);
    }

    /**
     * Add cost from a single API call.
     */
    public function addUsage(int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): void
    {
        $cost = (
            $inputTokens * 3.0 +
            $outputTokens * 15.0 +
            $cacheWriteTokens * 3.75 +
            $cacheReadTokens * 0.30
        ) / 1_000_000;

        $this->totalCost += $cost;

        if (!$this->warnedAtThreshold && $this->totalCost >= $this->warnThreshold) {
            $this->warnedAtThreshold = true;
            if ($this->onWarning) {
                ($this->onWarning)($this->totalCost, $this->warnThreshold, 'warning');
            }
        }
    }

    /**
     * Set a flat cost (used when importing from AgentLoop's accumulated totals).
     */
    public function setTotalCost(float $cost): void
    {
        $this->totalCost = $cost;
    }

    public function reset(): void
    {
        $this->totalCost = 0.0;
        $this->warnedAtThreshold = false;
    }

    public function getTotalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * Check if the hard stop threshold has been exceeded.
     */
    public function shouldStop(): bool
    {
        return $this->totalCost >= $this->stopThreshold;
    }

    /**
     * Check if the warning threshold has been reached.
     */
    public function shouldWarn(): bool
    {
        return $this->totalCost >= $this->warnThreshold;
    }

    public function setOnWarning(callable $callback): void
    {
        $this->onWarning = $callback;
    }

    public function getWarnThreshold(): float
    {
        return $this->warnThreshold;
    }

    public function getStopThreshold(): float
    {
        return $this->stopThreshold;
    }

    public function setThresholds(float $warn, float $stop): void
    {
        $this->warnThreshold = $warn;
        $this->stopThreshold = $stop;
        $this->warnedAtThreshold = false;
    }

    /**
     * Get a summary string for display.
     */
    public function getSummary(): string
    {
        $cost = '$' . number_format($this->totalCost, 2);
        $warn = '$' . number_format($this->warnThreshold, 2);
        $stop = '$' . number_format($this->stopThreshold, 2);
        return "Cost: {$cost} (warn at {$warn}, stop at {$stop})";
    }
}
