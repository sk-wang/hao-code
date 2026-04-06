<?php

namespace App\Services\Cache;

/**
 * Cached state for a file that was read via the Read tool.
 *
 * Mirrors claude-code's FileState type from fileStateCache.ts.
 */
class FileState
{
    public function __construct(
        public readonly string $content,
        public readonly int $timestamp,
        public readonly ?int $offset = null,
        public readonly ?int $limit = null,
        public readonly bool $isPartialView = false,
    ) {}

    /**
     * Byte length of the cached content (for LRU size accounting).
     */
    public function contentSizeBytes(): int
    {
        return max(1, strlen($this->content));
    }
}
