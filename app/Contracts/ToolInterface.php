<?php

namespace App\Contracts;

use App\Services\Permissions\PermissionDecision;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): ToolInputSchema;

    public function call(array $input, ToolUseContext $context): ToolResult;

    public function isConcurrencySafe(array $input): bool;

    public function isReadOnly(array $input): bool;

    public function isEnabled(): bool;

    public function userFacingName(array $input): string;

    public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision;

    /**
     * Validate input semantically before execution.
     * Returns null if valid, or an error message string if invalid.
     */
    public function validateInput(array $input, ToolUseContext $context): ?string;

    /**
     * Maximum result size in characters before truncation.
     * Return PHP_INT_MAX to disable truncation.
     */
    public function maxResultSizeChars(): int;

    /**
     * Normalize tool input in-place before it is observed by hooks, permissions, or transcripts.
     * Expands ~ and relative paths to absolute paths so that permission patterns cannot be bypassed.
     */
    public function backfillObservableInput(array $input, ToolUseContext $context): array;
}
