<?php

declare(strict_types=1);

namespace App\Support\Terminal\Autocomplete;

/**
 * Provides shell history completions for bash-like input.
 */
class ShellHistoryProvider
{
    private string $historyFile;

    public function __construct()
    {
        $this->historyFile = $_SERVER['HOME'] . '/.bash_history';
        if (getenv('HISTFILE')) {
            $this->historyFile = getenv('HISTFILE');
        }
    }

    /**
     * Suggest matching shell history entries.
     *
     * @return array<int, string>
     */
    public function suggest(string $input, int $limit = 5): array
    {
        if (! $this->isBashLike($input)) {
            return [];
        }

        if (! file_exists($this->historyFile) || ! is_readable($this->historyFile)) {
            return [];
        }

        $lines = file($this->historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        // Reverse so newest entries are first
        $lines = array_reverse($lines);
        $results = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                continue; // Skip history timestamps
            }
            if (stripos($line, $input) !== false) {
                if (! isset($seen[$line])) {
                    $seen[$line] = true;
                    $results[] = $line;
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if input looks like a bash command.
     */
    public function isBashLike(string $input): bool
    {
        $bashPrefixes = ['git ', 'php ', 'composer ', 'npm ', 'yarn ', 'make ', 'docker ', 'cat ', 'ls ', 'cd ', 'mkdir ', 'rm ', 'cp ', 'mv ', 'python', 'pytest', 'cargo ', 'go '];
        foreach ($bashPrefixes as $prefix) {
            if (str_starts_with(strtolower($input), $prefix)) {
                return true;
            }
        }
        return false;
    }
}
