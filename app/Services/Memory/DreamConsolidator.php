<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Support\Config\Config;
use App\Support\Config\PathHelper;

/**
 * Manual memory consolidation (/dream command).
 * Reviews session transcripts and organizes persistent memories.
 */
class DreamConsolidator
{
    public function __construct(
        private readonly SessionMemory $memory,
        private readonly ConsolidationLock $lock,
        private readonly ?string $transcriptDir = null,
    ) {}

    /**
     * Build the consolidation prompt for the agent to execute.
     */
    public function buildConsolidationPrompt(string $memoryRoot, string $transcriptDir): string
    {
        $memoryFile = $this->memoryFilePath($memoryRoot);

        return <<<PROMPT
# Dream: Memory Consolidation

You are performing a dream — a reflective pass over your memory files.
Synthesize what you've learned recently into durable, well-organized memories
so that future sessions can orient quickly.

Persistent memory file: `{$memoryFile}`
Session transcripts: `{$transcriptDir}` (JSONL files — grep narrowly, don't read whole files)

---

## Phase 1 — Orient

- Read only the persistent memory file to understand what's stored
- Do not list the parent directory or read sibling files such as settings.json; they may contain secrets
- If memory.json does not exist, continue with empty memory context

## Phase 2 — Gather recent signal

Look for new information worth persisting:

1. Recent session transcripts — grep for narrow terms of interest
2. Existing memories that may have drifted or become stale
3. Contradictions between stored memories and current reality

Don't exhaustively read transcripts. Look only for things you already suspect matter.

## Phase 3 — Consolidate

For each thing worth remembering:
- Merge new signal into existing memories rather than creating near-duplicates
- Convert relative dates ("yesterday", "last week") to absolute dates
- Delete contradicted facts
- Use the memory tools (via the agent) to set/update/delete memories

## Phase 4 — Prune and index

- Remove stale or superseded memories
- Ensure memories are concise and useful
- The /memory compact command can help if there are too many entries

---

Return a brief summary of what you consolidated, updated, or pruned.
If nothing changed (memories are already tight), say so.
PROMPT;
    }

    /**
     * Get the memory root path.
     */
    public function getMemoryRoot(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return "{$home}/.haocode";
    }

    /**
     * Get the transcript directory path.
     */
    public function getTranscriptDir(): string
    {
        if ($this->transcriptDir !== null) {
            return $this->transcriptDir;
        }

        return $this->defaultTranscriptDir();
    }

    /**
     * Record that a consolidation just happened (stamps the lock).
     */
    public function recordConsolidation(): void
    {
        $this->lock->stamp();
    }

    /**
     * Get stats about the current memory state.
     */
    public function getMemoryStats(): array
    {
        $memories = $this->memory->list();
        $totalChars = 0;
        foreach ($memories as $entry) {
            $totalChars += strlen($entry['value'] ?? '');
        }

        return [
            'count' => count($memories),
            'total_chars' => $totalChars,
            'last_consolidated' => $this->lock->readLastConsolidatedAt(),
        ];
    }

    private function memoryFilePath(string $memoryRoot): string
    {
        return rtrim($memoryRoot, '/\\').'/memory.json';
    }

    private function defaultTranscriptDir(): string
    {
        $configuredPath = $this->configuredSessionPath();
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        $storagePath = $this->storageSessionPath();
        if ($storagePath !== null) {
            return $storagePath;
        }

        $storagePath = $_SERVER['LARAVEL_STORAGE_PATH'] ?? getenv('LARAVEL_STORAGE_PATH') ?: null;
        if (is_string($storagePath) && $storagePath !== '') {
            return rtrim($storagePath, '/\\').'/app/haocode/sessions';
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return rtrim($home, '/\\').'/.haocode/storage/app/haocode/sessions';
    }

    private function configuredSessionPath(): ?string
    {
        try {
            $path = Config::get('session_path');
        } catch (\Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function storageSessionPath(): ?string
    {
        try {
            $path = PathHelper::storagePath('app/haocode/sessions');
        } catch (\Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }
}
