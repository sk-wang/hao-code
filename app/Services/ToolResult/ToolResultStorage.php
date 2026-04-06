<?php

namespace App\Services\ToolResult;

/**
 * Persists large tool results to disk and generates previews.
 *
 * Matches claude-code's toolResultStorage.ts behavior:
 * - Results exceeding a per-tool threshold are written to session storage
 * - A newline-boundary-aware preview is shown to the model
 * - Per-message aggregate budget ensures total result size stays manageable
 */
class ToolResultStorage
{
    /** Preview truncation size in bytes. */
    public const PREVIEW_SIZE_BYTES = 2000;

    /** Per-message aggregate budget for all tool results (chars). */
    public const MAX_TOOL_RESULTS_PER_MESSAGE_CHARS = 200_000;

    /** Default per-tool persistence threshold (chars). */
    public const DEFAULT_MAX_RESULT_SIZE_CHARS = 50_000;

    private string $storageDir;

    /** @var array<string, string> tool_use_id => persisted preview (for replay stability) */
    private array $replacements = [];

    /** @var array<string, bool> tool_use_id => true (fate frozen, cannot change) */
    private array $seenIds = [];

    public function __construct(?string $sessionId = null)
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? sys_get_temp_dir());
        $sessionId ??= 'default';
        $this->storageDir = $home . '/.haocode/sessions/' . $sessionId . '/tool-results';
    }

    /**
     * Check if a tool result should be persisted to disk.
     */
    public function shouldPersist(string $output, int $threshold): bool
    {
        return mb_strlen($output) > $threshold;
    }

    /**
     * Persist a tool result to disk and return the preview message.
     *
     * @return array{filepath: string, size: int, preview: string, message: string}|null
     */
    public function persist(string $toolUseId, string $output): ?array
    {
        if (!@mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            return null;
        }

        $filepath = $this->storageDir . '/' . $toolUseId . '.txt';

        if (file_put_contents($filepath, $output) === false) {
            return null;
        }

        $size = mb_strlen($output);
        $preview = $this->generatePreview($output, self::PREVIEW_SIZE_BYTES);
        $sizeLabel = $this->formatSize($size);
        $message = $this->buildPersistedMessage($filepath, $sizeLabel, $preview);

        // Track for replay stability
        $this->seenIds[$toolUseId] = true;
        $this->replacements[$toolUseId] = $message;

        return [
            'filepath' => $filepath,
            'size' => $size,
            'preview' => $preview,
            'message' => $message,
        ];
    }

    /**
     * Enforce per-message aggregate budget across tool results.
     *
     * Returns the results array with large results replaced by persisted previews.
     *
     * @param array<int, array{tool_use_id: string, content: string, is_error: bool}> $results
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    public function enforceMessageBudget(array $results): array
    {
        $totalChars = 0;
        foreach ($results as $r) {
            $totalChars += mb_strlen($r['content'] ?? '');
        }

        if ($totalChars <= self::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS) {
            // Mark all as seen
            foreach ($results as $r) {
                $this->seenIds[$r['tool_use_id']] = true;
            }
            return $results;
        }

        // Sort candidates by size (largest first) for greedy replacement
        $candidates = [];
        foreach ($results as $idx => $r) {
            $id = $r['tool_use_id'];
            $size = mb_strlen($r['content'] ?? '');

            // Previously replaced → re-apply cached replacement
            if (isset($this->replacements[$id])) {
                $results[$idx]['content'] = $this->replacements[$id];
                continue;
            }

            // Previously seen but not replaced → frozen (cannot change)
            if (isset($this->seenIds[$id])) {
                continue;
            }

            // Errors are never replaced
            if ($r['is_error'] ?? false) {
                $this->seenIds[$id] = true;
                continue;
            }

            $candidates[] = ['idx' => $idx, 'id' => $id, 'size' => $size];
        }

        // Sort largest first
        usort($candidates, fn($a, $b) => $b['size'] <=> $a['size']);

        // Recalculate total after re-applying cached replacements
        $currentTotal = 0;
        foreach ($results as $r) {
            $currentTotal += mb_strlen($r['content'] ?? '');
        }

        // Replace largest fresh results until under budget
        foreach ($candidates as $c) {
            if ($currentTotal <= self::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS) {
                $this->seenIds[$c['id']] = true;
                continue;
            }

            $persisted = $this->persist($c['id'], $results[$c['idx']]['content']);
            if ($persisted !== null) {
                $oldSize = mb_strlen($results[$c['idx']]['content']);
                $results[$c['idx']]['content'] = $persisted['message'];
                $currentTotal -= $oldSize;
                $currentTotal += mb_strlen($persisted['message']);
            }

            $this->seenIds[$c['id']] = true;
        }

        return $results;
    }

    /**
     * Generate a preview truncated at a newline boundary.
     */
    public function generatePreview(string $content, int $maxBytes): string
    {
        if (mb_strlen($content) <= $maxBytes) {
            return $content;
        }

        $truncated = mb_substr($content, 0, $maxBytes);
        $lastNewline = strrpos($truncated, "\n");

        // Use newline boundary if it's past 50% of the limit
        if ($lastNewline !== false && $lastNewline > $maxBytes * 0.5) {
            return mb_substr($content, 0, $lastNewline);
        }

        return $truncated;
    }

    /**
     * Build the persisted-output message shown to the model.
     */
    private function buildPersistedMessage(string $filepath, string $sizeLabel, string $preview): string
    {
        return "<persisted-output>\n"
            . "Output too large ({$sizeLabel}). Full output saved to: {$filepath}\n\n"
            . "Preview (first " . self::PREVIEW_SIZE_BYTES . "B):\n"
            . $preview
            . "\n...\n"
            . "</persisted-output>";
    }

    private function formatSize(int $chars): string
    {
        if ($chars >= 1_000_000) {
            return round($chars / 1_000_000, 1) . 'M chars';
        }
        if ($chars >= 1_000) {
            return round($chars / 1_000, 1) . 'K chars';
        }

        return $chars . ' chars';
    }

    /**
     * Get replacement state for session resume.
     *
     * @return array{seenIds: string[], replacements: array<string, string>}
     */
    public function getState(): array
    {
        return [
            'seenIds' => array_keys($this->seenIds),
            'replacements' => $this->replacements,
        ];
    }

    /**
     * Restore state from a previous session (for resume).
     *
     * @param array{seenIds?: string[], replacements?: array<string, string>} $state
     */
    public function restoreState(array $state): void
    {
        foreach ($state['seenIds'] ?? [] as $id) {
            $this->seenIds[$id] = true;
        }
        foreach ($state['replacements'] ?? [] as $id => $msg) {
            $this->replacements[$id] = $msg;
        }
    }
}
