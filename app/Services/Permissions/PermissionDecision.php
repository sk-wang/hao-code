<?php

namespace App\Services\Permissions;

class PermissionDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $needsPrompt,
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true, false);
    }

    public static function deny(string $reason = ''): self
    {
        return new self(false, false, $reason);
    }

    public static function ask(): self
    {
        return new self(false, true, 'Requires user approval');
    }
}
