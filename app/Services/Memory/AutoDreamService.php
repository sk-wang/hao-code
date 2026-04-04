<?php

declare(strict_types=1);

namespace App\Services\Memory;

/**
 * Automatic background memory consolidation.
 * Fires after agent turns when time/session gates pass.
 *
 * Gate order (cheapest first):
 *   1. Time: hours since lastConsolidatedAt >= minHours
 *   2. Sessions: transcript count with mtime > lastConsolidatedAt >= minSessions
 *   3. Lock: no other process mid-consolidation
 */
class AutoDreamService
{
    private const SESSION_SCAN_INTERVAL_MS = 10 * 60 * 1000; // 10 minutes

    private int $lastSessionScanAt = 0;

    public function __construct(
        private readonly DreamConsolidator $consolidator,
        private readonly ConsolidationLock $lock,
        private readonly float $minHours = 24,
        private readonly int $minSessions = 5,
    ) {}

    /**
     * Check if auto-dream should fire and execute if so.
     * Called after each assistant turn.
     */
    public function maybeExecute(): ?array
    {
        // --- Time gate ---
        $minHours = $this->getMinHours();
        $hoursSince = $this->lock->hoursSinceLastConsolidation();
        if ($hoursSince < $minHours) {
            return null;
        }

        // --- Scan throttle ---
        $nowMs = (int)(microtime(true) * 1000);
        if (($nowMs - $this->lastSessionScanAt) < self::SESSION_SCAN_INTERVAL_MS) {
            return null;
        }
        $this->lastSessionScanAt = $nowMs;

        // --- Session gate ---
        $lastAt = $this->lock->readLastConsolidatedAt();
        $sessionCount = $this->countSessionsTouchedSince($lastAt);
        $minSessions = $this->getMinSessions();
        if ($sessionCount < $minSessions) {
            return null;
        }

        // --- Lock gate ---
        $priorMtime = $this->lock->tryAcquire();
        if ($priorMtime === null) {
            return null; // Another process holds the lock
        }

        // Execute consolidation
        try {
            $prompt = $this->consolidator->buildConsolidationPrompt(
                $this->consolidator->getMemoryRoot(),
                $this->consolidator->getTranscriptDir(),
            );

            $this->consolidator->recordConsolidation();

            return [
                'triggered' => true,
                'hours_since' => round($hoursSince, 1),
                'sessions_reviewed' => $sessionCount,
                'prompt' => $prompt,
            ];
        } catch (\Throwable $e) {
            $this->lock->rollback($priorMtime);

            return [
                'triggered' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Count session files modified since a given timestamp (in ms).
     */
    private function countSessionsTouchedSince(int $sinceMs): int
    {
        $sessionDir = $this->consolidator->getTranscriptDir();
        if (!is_dir($sessionDir)) {
            return 0;
        }

        $sinceSec = $sinceMs / 1000;
        $count = 0;
        foreach (glob($sessionDir . '/*.jsonl') as $file) {
            if (filemtime($file) > $sinceSec) {
                $count++;
            }
        }

        return $count;
    }

    private function getMinHours(): float
    {
        return $this->minHours;
    }

    private function getMinSessions(): int
    {
        return $this->minSessions;
    }
}
