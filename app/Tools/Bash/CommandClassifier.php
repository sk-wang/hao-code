<?php

namespace App\Tools\Bash;

/**
 * Classifies bash commands by their behavior, matching claude-code's
 * BashTool command classification.
 *
 * Used for UI collapsing (search/read results), concurrency decisions,
 * and output expectations.
 */
class CommandClassifier
{
    /** Search commands (find, grep, etc.) - produce search results */
    private const SEARCH_COMMANDS = [
        'find', 'grep', 'rg', 'ag', 'ack', 'fgrep', 'egrep',
        'locate', 'which', 'whereis', 'command',
    ];

    /** Read/view commands - display file content or data */
    private const READ_COMMANDS = [
        'cat', 'head', 'tail', 'less', 'more',
        'wc', 'stat', 'file', 'strings',
        'jq', 'yq', 'awk', 'cut', 'sort', 'uniq', 'tr',
        'xxd', 'hexdump', 'od',
    ];

    /** List commands - directory and tree listings */
    private const LIST_COMMANDS = [
        'ls', 'tree', 'du', 'df',
    ];

    /** Semantic-neutral commands - don't affect pipeline classification */
    private const NEUTRAL_COMMANDS = [
        'echo', 'printf', 'true', 'false', ':',
    ];

    /** Silent commands - produce no stdout on success */
    private const SILENT_COMMANDS = [
        'mv', 'cp', 'rm', 'mkdir', 'rmdir', 'chmod', 'chown', 'chgrp',
        'touch', 'ln', 'cd', 'export', 'unset', 'wait',
    ];

    /** Destructive commands requiring extra care */
    private const DESTRUCTIVE_COMMANDS = [
        'rm', 'rmdir', 'dd', 'mkfs', 'fdisk', 'parted',
    ];

    /**
     * Classify a command as search, read, or list.
     *
     * Pipelines and compound commands (&&, ||, ;) are classified by their
     * non-neutral parts: if ALL non-neutral commands are search/read/list,
     * the whole command gets that classification.
     *
     * @return array{isSearch: bool, isRead: bool, isList: bool}
     */
    public static function classify(string $command): array
    {
        $result = ['isSearch' => false, 'isRead' => false, 'isList' => false];

        $parts = self::splitCommand($command);
        if (empty($parts)) {
            return $result;
        }

        $hasNonNeutral = false;
        $allSearchOrRead = true;

        foreach ($parts as $part) {
            $base = self::extractBaseCommand($part);
            if ($base === null) {
                continue;
            }

            // Skip neutral commands
            if (in_array($base, self::NEUTRAL_COMMANDS, true)) {
                continue;
            }

            $hasNonNeutral = true;

            if (in_array($base, self::SEARCH_COMMANDS, true)) {
                $result['isSearch'] = true;
            } elseif (in_array($base, self::READ_COMMANDS, true)) {
                $result['isRead'] = true;
            } elseif (in_array($base, self::LIST_COMMANDS, true)) {
                $result['isList'] = true;
            } else {
                // Non-search/read/list command found
                $allSearchOrRead = false;
            }
        }

        if (!$hasNonNeutral || !$allSearchOrRead) {
            return ['isSearch' => false, 'isRead' => false, 'isList' => false];
        }

        return $result;
    }

    /**
     * Check if a command is expected to produce no stdout.
     */
    public static function isSilent(string $command): bool
    {
        $parts = self::splitCommand($command);
        $hasNonNeutral = false;

        foreach ($parts as $part) {
            $base = self::extractBaseCommand($part);
            if ($base === null) {
                continue;
            }

            if (in_array($base, self::NEUTRAL_COMMANDS, true)) {
                continue;
            }

            $hasNonNeutral = true;

            if (!in_array($base, self::SILENT_COMMANDS, true)) {
                return false;
            }
        }

        return $hasNonNeutral;
    }

    /**
     * Check if a command is destructive (delete, format, etc.).
     */
    public static function isDestructive(string $command): bool
    {
        $parts = self::splitCommand($command);

        foreach ($parts as $part) {
            $base = self::extractBaseCommand($part);
            if ($base !== null && in_array($base, self::DESTRUCTIVE_COMMANDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a command is safe for concurrent execution.
     * Search and read commands that don't have redirects are safe.
     */
    public static function isConcurrencySafe(string $command): bool
    {
        // Commands with output redirection are not safe
        if (preg_match('/[>|]/', $command) && !self::isOnlyPipeToReadCommands($command)) {
            return false;
        }

        $classification = self::classify($command);

        return $classification['isSearch'] || $classification['isRead'] || $classification['isList'];
    }

    /**
     * Split compound commands on operators (&&, ||, ;, |).
     * Strips redirections.
     *
     * @return string[]
     */
    private static function splitCommand(string $command): array
    {
        // Split on &&, ||, ;, and | (but not ||)
        $parts = preg_split('/\s*(?:&&|\|\||;)\s*/', $command);

        // Also split pipes within each part
        $result = [];
        foreach ($parts as $part) {
            $pipeParts = preg_split('/\s*\|\s*/', trim($part));
            foreach ($pipeParts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    // Strip output redirections
                    $p = preg_replace('/\s*[12]?>>?\s*\S+/', '', $p);
                    $p = trim($p);
                    if ($p !== '') {
                        $result[] = $p;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extract the base command name (first word, without path).
     */
    private static function extractBaseCommand(string $part): ?string
    {
        // Strip leading env vars (VAR=val cmd)
        $part = preg_replace('/^(\w+=\S+\s+)+/', '', trim($part));
        // Strip sudo, time, nohup prefixes
        $part = preg_replace('/^(sudo|time|nohup|nice|ionice)\s+/', '', $part);

        $words = preg_split('/\s+/', trim($part));
        $first = $words[0] ?? null;
        if ($first === null || $first === '') {
            return null;
        }

        return basename($first);
    }

    /**
     * Check if all pipes lead to read/search commands (e.g., `cat file | grep pattern`).
     */
    private static function isOnlyPipeToReadCommands(string $command): bool
    {
        // Only consider simple pipe chains
        if (preg_match('/[;]|&&|\|\|/', $command)) {
            return false;
        }

        $parts = preg_split('/\s*\|\s*/', $command);
        foreach ($parts as $part) {
            $base = self::extractBaseCommand(trim($part));
            if ($base === null) {
                continue;
            }
            if (in_array($base, self::NEUTRAL_COMMANDS, true)) {
                continue;
            }
            if (!in_array($base, self::SEARCH_COMMANDS, true)
                && !in_array($base, self::READ_COMMANDS, true)
                && !in_array($base, self::LIST_COMMANDS, true)) {
                return false;
            }
        }

        return true;
    }
}
