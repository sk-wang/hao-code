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

    // Per-million-token pricing by model family
    private const MODEL_PRICING = [
        'opus' => ['input' => 15.0, 'output' => 75.0, 'cache_write' => 18.75, 'cache_read' => 1.50],
        'sonnet' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'haiku' => ['input' => 0.80, 'output' => 4.0, 'cache_write' => 1.0, 'cache_read' => 0.08],
    ];

    private string $currentModel = '';

    public function setModel(string $model): void
    {
        $this->currentModel = $model;
    }

    /**
     * Get pricing for the current model.
     *
     * @return array{input: float, output: float, cache_write: float, cache_read: float}
     */
    private function getPricing(): array
    {
        $model = strtolower($this->currentModel);

        foreach (self::MODEL_PRICING as $family => $pricing) {
            if (str_contains($model, $family)) {
                return $pricing;
            }
        }

        // Default to Sonnet pricing
        return self::MODEL_PRICING['sonnet'];
    }

    /**
     * Add cost from a single API call.
     */
    public function addUsage(int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): void
    {
        $pricing = $this->getPricing();

        $cost = (
            $inputTokens * $pricing['input'] +
            $outputTokens * $pricing['output'] +
            $cacheWriteTokens * $pricing['cache_write'] +
            $cacheReadTokens * $pricing['cache_read']
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
