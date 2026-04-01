<?php

namespace App\Services\Hooks;

class HookDefinition
{
    public function __construct(
        public readonly string $event,
        public readonly string $command,
        public readonly ?string $matcher = null,
    ) {}
}
