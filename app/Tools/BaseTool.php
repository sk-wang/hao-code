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

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        return $input;
    }

    /**
     * Resolve a path: expand ~, handle relative paths against the working directory.
     */
    protected function resolvePath(string $path, string $workingDir): string
    {
        // Expand tilde to home directory
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
            $path = $home . substr($path, 1);
        } elseif (str_starts_with($path, '~')) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
            $path = $home . substr($path, 1);
        }

        // Resolve relative paths against working directory
        if (!str_starts_with($path, '/')) {
            $path = rtrim($workingDir, '/') . '/' . $path;
        }

        // Normalize (resolve . and ..)
        $path = realpath($path) ?: $path;

        return $path;
    }
}
