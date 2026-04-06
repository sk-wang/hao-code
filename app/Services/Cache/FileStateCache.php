<?php

namespace App\Services\Cache;

/**
 * LRU cache for file read state, matching claude-code's fileStateCache.ts.
 *
 * Tracks file contents and metadata to enforce read-before-write rules
 * and avoid re-reading recently accessed files. All keys are normalized
 * via realpath() for consistent lookups.
 *
 * Defaults: 100 entries, 25 MB total content size.
 */
class FileStateCache
{
    /** @var array<string, FileState> normalized path => state */
    private array $entries = [];

    /** @var string[] ordered keys, most-recently-used last */
    private array $order = [];

    private int $currentSizeBytes = 0;

    public function __construct(
        private readonly int $maxEntries = 100,
        private readonly int $maxSizeBytes = 25 * 1024 * 1024,
    ) {}

    public function get(string $path): ?FileState
    {
        $key = $this->normalize($path);
        if (!isset($this->entries[$key])) {
            return null;
        }

        $this->touch($key);

        return $this->entries[$key];
    }

    public function set(string $path, FileState $state): void
    {
        $key = $this->normalize($path);

        // Remove old entry size if updating
        if (isset($this->entries[$key])) {
            $this->currentSizeBytes -= $this->entries[$key]->contentSizeBytes();
            $this->removeFromOrder($key);
        }

        $this->entries[$key] = $state;
        $this->order[] = $key;
        $this->currentSizeBytes += $state->contentSizeBytes();

        $this->evict();
    }

    public function has(string $path): bool
    {
        return isset($this->entries[$this->normalize($path)]);
    }

    public function delete(string $path): bool
    {
        $key = $this->normalize($path);
        if (!isset($this->entries[$key])) {
            return false;
        }

        $this->currentSizeBytes -= $this->entries[$key]->contentSizeBytes();
        unset($this->entries[$key]);
        $this->removeFromOrder($key);

        return true;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->order = [];
        $this->currentSizeBytes = 0;
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function calculatedSizeBytes(): int
    {
        return $this->currentSizeBytes;
    }

    /**
     * Clone the cache for a sub-agent (deep copy).
     */
    public function clone(): self
    {
        $clone = new self($this->maxEntries, $this->maxSizeBytes);
        foreach ($this->entries as $key => $state) {
            $clone->entries[$key] = clone $state;
        }
        $clone->order = $this->order;
        $clone->currentSizeBytes = $this->currentSizeBytes;

        return $clone;
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function touch(string $key): void
    {
        $this->removeFromOrder($key);
        $this->order[] = $key;
    }

    private function removeFromOrder(string $key): void
    {
        $this->order = array_values(array_filter(
            $this->order,
            fn(string $k) => $k !== $key,
        ));
    }

    /**
     * Evict least-recently-used entries until within limits.
     */
    private function evict(): void
    {
        while (
            (count($this->entries) > $this->maxEntries || $this->currentSizeBytes > $this->maxSizeBytes)
            && !empty($this->order)
        ) {
            $oldest = array_shift($this->order);
            if (isset($this->entries[$oldest])) {
                $this->currentSizeBytes -= $this->entries[$oldest]->contentSizeBytes();
                unset($this->entries[$oldest]);
            }
        }
    }
}
