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

    public function getActivityDescription(array $input): ?string
    {
        return null;
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return ['isSearch' => false, 'isRead' => $this->isReadOnly($input), 'isList' => false];
    }

    /**
     * Detect placeholders like ":12" that only encode a line reference with no path.
     */
    protected function isBareLineReference(string $value): bool
    {
        return preg_match('/^:\d+(?::\d+)?$/', trim($value)) === 1;
    }

    /**
     * Strip trailing :line[:column] from path-like inputs when it looks like the
     * model copied a code reference instead of a raw file path.
     */
    protected function normalizeFileReferencePath(string $path, string $workingDir): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $this->isBareLineReference($trimmed)) {
            return $trimmed;
        }

        if (!preg_match('/^(.*):(\d+)(?::(\d+))?$/', $trimmed, $matches)) {
            return $trimmed;
        }

        $candidate = $matches[1] ?? '';
        if ($candidate === '' || preg_match('/^[A-Za-z]$/', $candidate) === 1) {
            return $trimmed;
        }

        $resolvedCandidate = $this->resolvePath($candidate, $workingDir);
        if (file_exists($resolvedCandidate) || str_contains(basename($candidate), '.')) {
            return $candidate;
        }

        return $trimmed;
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
