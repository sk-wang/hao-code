<?php

namespace App\Tools;

use App\Services\Permissions\PermissionDecision;

abstract class BaseTool implements \App\Contracts\ToolInterface
{
    public function isConcurrencySafe(array $input): bool
    {
        return $this->isReadOnly($input);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function userFacingName(array $input): string
    {
        return $this->name();
    }

    public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision
    {
        return PermissionDecision::allow();
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        return null;
    }

    public function maxResultSizeChars(): int
    {
        return 50000;
    }
}
