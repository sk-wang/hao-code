<?php

namespace App\Services\Git;

/**
 * Provides git context for the agent loop — current diff, branch info, etc.
 * Injected into the system prompt so the agent has awareness of uncommitted changes.
 */
class GitContext
{
    /**
     * Get the git diff summary for the working tree (unstaged + staged changes).
     * Returns a formatted string suitable for system prompt injection.
     */
    public function getDiffContext(): string
    {
        if (!$this->isGitRepo()) {
            return '';
        }

        $branch = $this->getCurrentBranch();
        $remote = $this->getRemoteUrl();
        $defaultBranch = $this->getDefaultBranch();
        $diff = $this->getWorkingTreeDiff();

        if ($diff === '') {
            return "\n# Git Status\n- Branch: {$branch}"
                . ($remote ? "\n- Remote: {$remote}" : '')
                . ($defaultBranch ? "\n- Default branch: {$defaultBranch}" : '')
                . "\n- Working tree: clean";
        }

        return "\n# Git Status\n- Branch: {$branch}"
            . ($remote ? "\n- Remote: {$remote}" : '')
            . ($defaultBranch ? "\n- Default branch: {$defaultBranch}" : '')
            . "\n\n# Uncommitted Changes\n```\n{$diff}\n```";
    }

    /**
     * Check if the current directory is inside a git repository.
     */
    public function isGitRepo(): bool
    {
        exec('git rev-parse --is-inside-work-tree 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get the current branch name.
     */
    public function getCurrentBranch(): string
    {
        exec('git rev-parse --abbrev-ref HEAD 2>/dev/null', $output);
        return trim($output[0] ?? 'unknown');
    }

    /**
     * Whether the working tree has tracked or untracked changes.
     */
    public function hasUncommittedChanges(): bool
    {
        exec('git status --porcelain 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && trim(implode("\n", $output)) !== '';
    }

    /**
     * Get the remote URL.
     */
    public function getRemoteUrl(): string
    {
        exec('git config --get remote.origin.url 2>/dev/null', $output);
        return trim($output[0] ?? '');
    }

    /**
     * Get the default branch (main or master).
     */
    public function getDefaultBranch(): string
    {
        // Try to detect from remote HEAD
        exec('git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null', $output);
        if (!empty($output[0])) {
            return str_replace('refs/remotes/origin/', '', $output[0]);
        }
        return '';
    }

    /**
     * Get a summary of working tree changes (diff stats + first few hunks).
     */
    public function getWorkingTreeDiff(): string
    {
        // Get diff stat summary
        exec('git diff --stat HEAD 2>/dev/null', $statOutput, $exitCode);
        if ($exitCode !== 0 || empty($statOutput)) {
            return '';
        }

        $stat = implode("\n", $statOutput);

        // If diff has many files, just return the stat summary
        $fileCount = count(array_filter($statOutput, fn($l) => str_contains($l, '|')));
        if ($fileCount > 50) {
            return $stat . "\n(diff truncated — use Bash tool to see full diff)";
        }

        // Get actual diff hunks (limited)
        exec('git diff HEAD --no-color 2>/dev/null | head -200', $diffOutput);
        $diff = implode("\n", $diffOutput);

        if (mb_strlen($diff) > 5000) {
            return $stat . "\n\n(diff too large to include, showing stat only)";
        }

        return $stat . "\n\n" . $diff;
    }

    /**
     * Check if a file is gitignored.
     */
    public function isGitIgnored(string $path): bool
    {
        exec('git check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get the git root directory.
     */
    public function getGitRoot(): string
    {
        exec('git rev-parse --show-toplevel 2>/dev/null', $output);
        return trim($output[0] ?? getcwd());
    }
}
