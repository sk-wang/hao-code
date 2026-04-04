<?php

namespace App\Tools;

class ToolUseContext
{
    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;
    /** @var (\Closure(): bool)|null */
    public readonly \Closure|null $shouldAbort;

    /** @var array<string, int> Tracks which files have been read (path => timestamp) */
    private static array $readFileState = [];

    public function __construct(
        public readonly string $workingDirectory,
        public readonly string $sessionId,
        \Closure|null $onProgress = null,
        \Closure|null $shouldAbort = null,
    ) {
        $this->onProgress = $onProgress;
        $this->shouldAbort = $shouldAbort;
    }

    public function isAborted(): bool
    {
        return $this->shouldAbort ? (bool) ($this->shouldAbort)() : false;
    }

    public function recordFileRead(string $filePath): void
    {
        self::$readFileState[realpath($filePath) ?: $filePath] = time();
    }

    public function wasFileRead(string $filePath): bool
    {
        return isset(self::$readFileState[realpath($filePath) ?: $filePath]);
    }

    public static function resetReadState(): void
    {
        self::$readFileState = [];
    }
}
