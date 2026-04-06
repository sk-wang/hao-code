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
     * Maximum result size in characters before truncation/persistence.
     * Return PHP_INT_MAX to disable truncation (e.g., FileReadTool).
     */
    public function maxResultSizeChars(): int;

    /**
     * Normalize tool input in-place before it is observed by hooks, permissions, or transcripts.
     * Expands ~ and relative paths to absolute paths so that permission patterns cannot be bypassed.
     */
    public function backfillObservableInput(array $input, ToolUseContext $context): array;

    /**
     * Human-readable activity description for the spinner.
     * e.g., "Reading foo.ts", "Searching for pattern".
     * Returns null to use the tool name as-is.
     */
    public function getActivityDescription(array $input): ?string;

    /**
     * Classify this tool invocation for UI display (collapsible results).
     *
     * @return array{isSearch: bool, isRead: bool, isList: bool}
     */
    public function isSearchOrReadCommand(array $input): array;
}
