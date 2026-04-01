<?php

namespace App\Tools;

class ToolUseContext
{
    /** @var (\Closure(mixed): mixed)|null */
    public readonly \Closure|null $onProgress;

    public function __construct(
        public readonly string $workingDirectory,
        public readonly string $sessionId,
        \Closure|null $onProgress = null,
    ) {
        $this->onProgress = $onProgress;
    }
}
