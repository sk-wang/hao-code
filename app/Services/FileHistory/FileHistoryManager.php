<?php

namespace App\Services\FileHistory;

/**
 * Tracks file changes across the session with snapshots.
 * Stores versioned file backups for diff generation and rewind support.
 */
class FileHistoryManager
{
    private string $historyPath;
    private const MAX_SNAPSHOTS = 100;

    /** @var array<int, FileSnapshot> */
    private array $snapshots = [];

    public function __construct(?string $sessionId = null)
    {
        $base = sys_get_temp_dir() . '/haocode_file_history';
        $this->historyPath = $base . '/' . ($sessionId ?? 'default');
        if (!is_dir($this->historyPath)) {
            mkdir($this->historyPath, 0755, true);
        }
    }

    /**
     * Record a snapshot of a file before it's modified.
     */
    public function recordBefore(string $filePath): void
    {
        if (!file_exists($filePath)) return;

        $content = file_get_contents($filePath);
        $hash = md5($content);
        $snapshotId = count($this->snapshots);

        // Don't record if content hasn't changed
        if ($snapshotId > 0) {
            $last = $this->snapshots[$snapshotId - 1] ?? null;
            if ($last && $last->filePath === $filePath && $last->contentHash === $hash) {
                return;
            }
        }

        $this->snapshots[] = new FileSnapshot(
            id: $snapshotId,
            filePath: $filePath,
            content: $content,
            contentHash: $hash,
            timestamp: time(),
        );

        // Trim to max
        if (count($this->snapshots) > self::MAX_SNAPSHOTS) {
            $this->snapshots = array_slice($this->snapshots, -self::MAX_SNAPSHOTS);
        }

        // Persist snapshot
        $backupFile = $this->historyPath . '/' . $snapshotId . '_' . basename($filePath);
        file_put_contents($backupFile, $content);
    }

    /**
     * Get the diff between two snapshots.
     */
    public function getDiff(int $fromId, int $toId): ?string
    {
        $from = $this->snapshots[$fromId] ?? null;
        $to = $this->snapshots[$toId] ?? null;

        if (!$from || !$to) return null;

        $fromFile = $this->historyPath . '/' . $fromId . '_' . basename($from->filePath);
        $toContent = $to->content;

        if (!file_exists($fromFile)) return null;

        // Use diff command for proper diff output
        $toFile = tempnam(sys_get_temp_dir(), 'diff_');
        file_put_contents($toFile, $toContent);

        $output = shell_exec(
            sprintf('diff -u %s %s 2>/dev/null', escapeshellarg($fromFile), escapeshellarg($toFile))
        );

        @unlink($toFile);
        return $output ?: "No differences found.";
    }

    /**
     * Restore a file to a previous snapshot.
     */
    public function restore(int $snapshotId): bool
    {
        $snapshot = $this->snapshots[$snapshotId] ?? null;
        if (!$snapshot) return false;

        return file_put_contents($snapshot->filePath, $snapshot->content) !== false;
    }

    /**
     * Get recent snapshots for a specific file.
     * @return FileSnapshot[]
     */
    public function getSnapshotsForFile(string $filePath): array
    {
        return array_filter($this->snapshots, fn($s) => $s->filePath === $filePath);
    }

    /**
     * Get all snapshots.
     * @return FileSnapshot[]
     */
    public function getAllSnapshots(): array
    {
        return $this->snapshots;
    }

    /**
     * Get the latest snapshot.
     */
    public function getLatest(): ?FileSnapshot
    {
        return end($this->snapshots) ?: null;
    }

    /**
     * Get summary of all tracked changes.
     */
    public function getSummary(): string
    {
        if (empty($this->snapshots)) {
            return 'No file changes tracked.';
        }

        $files = array_unique(array_map(fn($s) => $s->filePath, $this->snapshots));
        $lines = ["Tracked " . count($this->snapshots) . " snapshots across " . count($files) . " files:"];

        foreach ($files as $file) {
            $count = count(array_filter($this->snapshots, fn($s) => $s->filePath === $file));
            $lines[] = "  " . basename($file) . " ({$count} versions)";
        }

        return implode("\n", $lines);
    }
}
