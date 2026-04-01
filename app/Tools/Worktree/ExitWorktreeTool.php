<?php

namespace App\Tools\Worktree;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class ExitWorktreeTool extends BaseTool
{
    public function name(): string
    {
        return 'ExitWorktree';
    }

    public function description(): string
    {
        return 'Exits a worktree session and returns to the original working directory.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['keep', 'remove'],
                    'description' => '"keep" leaves the worktree; "remove" deletes it.',
                ],
                'discard_changes' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to remove even with uncommitted changes.',
                ],
            ],
            'required' => ['action'],
        ], [
            'action' => 'required|string|in:keep,remove',
            'discard_changes' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $action = $input['action'];
        $discardChanges = $input['discard_changes'] ?? false;
        $cwd = $context->workingDirectory;

        // Check if we're actually in a worktree
        $gitCommonDir = exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --git-common-dir 2>/dev/null');
        $gitDir = exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --git-dir 2>/dev/null');

        if (!$gitCommonDir || trim($gitCommonDir) === trim($gitDir)) {
            return ToolResult::error('Not in a worktree session. Nothing to exit.');
        }

        if ($action === 'remove') {
            // Check for uncommitted changes
            $status = exec('cd ' . escapeshellarg($cwd) . ' && git status --porcelain 2>/dev/null');
            if (!empty(trim($status ?? '')) && !$discardChanges) {
                $fileCount = count(array_filter(explode("\n", trim($status))));
                return ToolResult::error(
                    "Worktree has {$fileCount} uncommitted file(s). Set discard_changes to true to remove anyway."
                );
            }

            // Get branch name before removing
            $branch = exec('cd ' . escapeshellarg($cwd) . ' && git branch --show-current 2>/dev/null');

            // Remove worktree
            $command = sprintf(
                'git worktree remove --force %s 2>&1',
                escapeshellarg($cwd),
            );
            $output = shell_exec($command);

            // Also delete the branch if it was a worktree-specific branch
            if ($branch && str_starts_with($branch, 'worktree-')) {
                exec('git branch -D ' . escapeshellarg($branch) . ' 2>/dev/null');
            }

            if (is_dir($cwd)) {
                return ToolResult::error("Failed to remove worktree: {$output}");
            }

            return ToolResult::success(
                "Worktree removed: {$cwd}\n" .
                "Returned to original directory."
            );
        }

        // action === 'keep'
        $branch = exec('cd ' . escapeshellarg($cwd) . ' && git branch --show-current 2>/dev/null');
        return ToolResult::success(
            "Worktree kept: {$cwd}\n" .
            "Branch: {$branch}\n" .
            "Returned to original directory. The worktree is still available."
        );
    }

    public function isReadOnly(array $input): bool
    {
        return ($input['action'] ?? '') !== 'remove';
    }
}
