<?php

namespace App\Tools\Worktree;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class EnterWorktreeTool extends BaseTool
{
    public function name(): string
    {
        return 'EnterWorktree';
    }

    public function description(): string
    {
        return 'Creates an isolated git worktree and switches the current session into it. Only use when the user explicitly mentions "worktree".';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional name for the worktree. If not provided, a random name is generated.',
                ],
            ],
        ], [
            'name' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'] ?? null;
        $cwd = $context->workingDirectory;

        // Check if we're in a git repo
        $gitCheck = exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --is-inside-work-tree 2>/dev/null');
        if (trim($gitCheck ?? '') !== 'true') {
            return ToolResult::error('Not inside a git repository. Worktrees require a git repo.');
        }

        // Check if already in a linked worktree (git-dir differs from git-common-dir)
        $gitDir = trim(exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --git-dir 2>/dev/null') ?? '');
        $commonDir = trim(exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --git-common-dir 2>/dev/null') ?? '');
        if ($gitDir !== '' && $commonDir !== '' && $gitDir !== $commonDir) {
            return ToolResult::error('Already in a worktree session.');
        }

        // Generate name if not provided
        if (!$name) {
            $name = 'worktree_' . bin2hex(random_bytes(4));
        }

        // Sanitize name
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
        if ($name === '') {
            $name = 'worktree_' . bin2hex(random_bytes(4));
        }
        if (mb_strlen($name) > 64) {
            $name = mb_substr($name, 0, 64);
        }

        // Create .claude/worktrees directory
        $worktreeBase = $cwd . '/.claude/worktrees';
        if (!is_dir($worktreeBase)) {
            mkdir($worktreeBase, 0755, true);
        }

        $worktreePath = $worktreeBase . '/' . $name;

        // Check if worktree already exists
        if (is_dir($worktreePath)) {
            return ToolResult::error("Worktree already exists: {$name}");
        }

        // Create worktree from HEAD
        $branchName = 'worktree-' . $name;
        $command = sprintf(
            'cd %s && git worktree add -b %s %s HEAD 2>&1',
            escapeshellarg($cwd),
            escapeshellarg($branchName),
            escapeshellarg($worktreePath),
        );

        $output = shell_exec($command);

        if (!is_dir($worktreePath)) {
            return ToolResult::error("Failed to create worktree: {$output}");
        }

        // Add .claude/worktrees to .gitignore if not already
        $gitignore = $cwd . '/.gitignore';
        $gitignoreContent = file_exists($gitignore) ? file_get_contents($gitignore) : '';
        if (!str_contains($gitignoreContent, '.claude/worktrees')) {
            file_put_contents($gitignore, "\n.claude/worktrees\n", FILE_APPEND);
        }

        return ToolResult::success(
            "Created worktree: {$name}\n" .
            "Path: {$worktreePath}\n" .
            "Branch: {$branchName}\n" .
            "The session's working directory has been switched to the worktree.\n" .
            "Use ExitWorktree to leave the worktree when done."
        );
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $cwd = $context->workingDirectory;
        $gitCheck = exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse --is-inside-work-tree 2>/dev/null');
        if (trim($gitCheck ?? '') !== 'true') {
            return 'Not inside a git repository.';
        }
        return null;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
