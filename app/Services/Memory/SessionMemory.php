<?php

namespace App\Services\Memory;

/**
 * Persistent key-value memory that survives session restarts.
 * Stores memories as JSON in ~/.haocode/memory.json
 */
class SessionMemory
{
    private ?array $memories = null;
    private string $path;

    public function __construct()
    {
        $home = $_SERVER['HOME'] ?? '~';
        $this->path = "{$home}/.haocode/memory.json";
    }

    /**
     * Store a memory entry.
     */
    public function set(string $key, string $value, string $type = 'note'): void
    {
        $this->load();
        $this->memories[$key] = [
            'value' => $value,
            'type' => $type,
            'updated_at' => date('c'),
            'created_at' => $this->memories[$key]['created_at'] ?? date('c'),
        ];
        $this->save();
    }

    /**
     * Get a memory entry.
     */
    public function get(string $key): ?string
    {
        $this->load();
        return $this->memories[$key]['value'] ?? null;
    }

    /**
     * Delete a memory entry.
     */
    public function delete(string $key): bool
    {
        $this->load();
        if (!isset($this->memories[$key])) {
            return false;
        }
        unset($this->memories[$key]);
        $this->save();
        return true;
    }

    /**
     * List all memory entries.
     */
    public function list(): array
    {
        $this->load();
        return $this->memories ?? [];
    }

    /**
     * Search memories by keyword.
     */
    public function search(string $query): array
    {
        $this->load();
        $results = [];
        $query = strtolower($query);

        foreach ($this->memories as $key => $entry) {
            if (str_contains(strtolower($key), $query) || str_contains(strtolower($entry['value']), $query)) {
                $results[$key] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get memories formatted for system prompt injection.
     */
    public function forSystemPrompt(int $maxChars = 3000): string
    {
        $this->load();
        if (empty($this->memories)) {
            return '';
        }

        $lines = ["Persistent memories from previous sessions:"];
        $totalLen = strlen($lines[0]);

        foreach ($this->memories as $key => $entry) {
            $line = "- {$key}: {$entry['value']}";
            if ($totalLen + strlen($line) > $maxChars) break;
            $lines[] = $line;
            $totalLen += strlen($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Compact old memories when they exceed a threshold.
     */
    public function compact(int $maxEntries = 100): int
    {
        $this->load();
        $count = count($this->memories);

        if ($count <= $maxEntries) {
            return 0;
        }

        // Sort by updated_at, keep most recent
        uasort($this->memories, fn($a, $b) => strtotime($b['updated_at']) - strtotime($a['updated_at']));
        $this->memories = array_slice($this->memories, 0, $maxEntries, true);
        $this->save();

        return $count - $maxEntries;
    }

    private function load(): void
    {
        if ($this->memories !== null) {
            return;
        }

        if (file_exists($this->path)) {
            $data = json_decode(file_get_contents($this->path), true);
            $this->memories = is_array($data) ? $data : [];
        } else {
            $this->memories = [];
        }
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->path, json_encode($this->memories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
