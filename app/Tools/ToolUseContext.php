<?php

namespace App\Tools;

use App\Services\Cache\FileState;
use App\Services\Cache\FileStateCache;

class ToolUseContext
{
    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;
    /** @var (\Closure(): bool)|null */
    public readonly \Closure|null $shouldAbort;

    /** @var array<string, int> Tracks which files have been read (path => timestamp) */
    private static array $readFileState = [];

    private FileStateCache $fileStateCache;

    public function __construct(
        public readonly string $workingDirectory,
        public readonly string $sessionId,
        \Closure|null $onProgress = null,
        \Closure|null $shouldAbort = null,
        ?FileStateCache $fileStateCache = null,
    ) {
        $this->onProgress = $onProgress;
        $this->shouldAbort = $shouldAbort;
        $this->fileStateCache = $fileStateCache ?? new FileStateCache();
    }

    public function isAborted(): bool
    {
        return $this->shouldAbort ? (bool) ($this->shouldAbort)() : false;
    }

    /**
     * Record that a file was read and cache its content.
     */
    public function recordFileRead(string $filePath, ?string $content = null, ?int $offset = null, ?int $limit = null, bool $isPartialView = false): void
    {
        $resolved = realpath($filePath) ?: $filePath;
        self::$readFileState[$resolved] = time();

        if ($content !== null) {
            $this->fileStateCache->set($filePath, new FileState(
                content: $content,
                timestamp: time(),
                offset: $offset,
                limit: $limit,
                isPartialView: $isPartialView,
            ));
        }
    }

    public function wasFileRead(string $filePath): bool
    {
        return isset(self::$readFileState[realpath($filePath) ?: $filePath]);
    }

    /**
     * Get cached file state (content, offset, partial view flag).
     */
    public function getFileState(string $filePath): ?FileState
    {
        return $this->fileStateCache->get($filePath);
    }

    public function getFileStateCache(): FileStateCache
    {
        return $this->fileStateCache;
    }

    public static function resetReadState(): void
    {
        self::$readFileState = [];
    }

    /**
     * Get a snapshot of the current readFileState for IPC serialization.
     * Used by parallel tool execution: the child process captures its state
     * and the parent merges it back after the child exits.
     *
     * @return array<string, int>
     */
    public static function getReadFileStateSnapshot(): array
    {
        return self::$readFileState;
    }

    /**
     * Merge a readFileState snapshot (from a child process) into the current state.
     * Only adds entries that are newer or missing in the parent.
     *
     * @param array<string, int> $snapshot
     */
    public static function mergeReadFileStateSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $path => $timestamp) {
            if (!isset(self::$readFileState[$path]) || $timestamp > self::$readFileState[$path]) {
                self::$readFileState[$path] = $timestamp;
            }
        }
    }
}
