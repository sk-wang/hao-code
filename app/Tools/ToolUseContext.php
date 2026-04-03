<?php

namespace App\Tools;

class ToolUseContext
{
    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;
    /** @var (\Closure(): bool)|null */
    public readonly \Closure|null $shouldAbort;

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
}
