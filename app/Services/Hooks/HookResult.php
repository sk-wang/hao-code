<?php

namespace App\Services\Hooks;

class HookResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?array $modifiedInput = null,
        public readonly string $output = '',
    ) {}
}
