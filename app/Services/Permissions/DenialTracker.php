<?php

namespace App\Services\Permissions;

/**
 * Tracks permission denials to prevent auto-approve loops.
 */
class DenialTracker
{
    private const MAX_DENIALS = 20;
    private const AUTO_DENY_THRESHOLD = 3;

    /** @var array<int, array{tool: string, reason: string, timestamp: float}> */
    private array $denials = [];

    /**
     * Record a permission denial.
     */
    public function record(string $tool, string $command, string $reason): void
    {
        $this->denials[] = [
            'tool' => $tool,
            'command' => mb_substr($command, 0, 200),
            'reason' => $reason,
            'timestamp' => microtime(true),
        ];

        // Ring buffer - keep only last N
        if (count($this->denials) > self::MAX_DENIALS) {
            $this->denials = array_slice($this->denials, -self::MAX_DENIALS);
        }
    }

    /**
     * Check if we've denied the same tool+command pattern recently.
     * If so, skip auto-approve and force explicit approval.
     */
    public function shouldForceAsk(string $tool, string $command): bool
    {
        $recentCount = 0;
        $commandPrefix = mb_substr($command, 0, 50);

        foreach (array_reverse($this->denials) as $denial) {
            if ($denial['tool'] !== $tool) continue;
            if (str_starts_with($denial['command'], $commandPrefix)) {
                $recentCount++;
            }
        }

        return $recentCount >= self::AUTO_DENY_THRESHOLD;
    }

    /**
     * Get recent denials for display.
     * @return array<int, array{tool: string, command: string, reason: string, timestamp: float}>
     */
    public function getRecent(int $limit = 10): array
    {
        return array_slice(array_reverse($this->denials), 0, $limit);
    }

    /**
     * Get total denial count.
     */
    public function count(): int
    {
        return count($this->denials);
    }

    /**
     * Clear all denials (e.g. on mode change).
     */
    public function clear(): void
    {
        $this->denials = [];
    }
}
