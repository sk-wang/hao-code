<?php

declare(strict_types=1);

namespace App\Support\Terminal\Autocomplete;

/**
 * Main autocomplete engine for the REPL.
 * Provides inline ghost text and suggestion lists.
 */
class AutocompleteEngine
{
    public function __construct(
        private readonly CommandSuggestionProvider $commands = new CommandSuggestionProvider(),
        private readonly FilePathSuggestionProvider $files = new FilePathSuggestionProvider(),
        private readonly ShellHistoryProvider $shellHistory = new ShellHistoryProvider(),
    ) {}

    /**
     * Get ghost text (grey completion text) for the current input.
     * Returns the text to append, or null if no completion.
     */
    public function getGhostText(string $input): ?string
    {
        // Slash command completion
        if (str_starts_with($input, '/')) {
            return $this->commands->getGhostText($input);
        }

        // Mid-input slash command: detect / preceded by space
        if (preg_match('/\s\/(\w*)$/', $input, $matches)) {
            $slashPart = '/' . $matches[1];
            $ghost = $this->commands->getGhostText($slashPart);
            if ($ghost !== null) {
                return $ghost;
            }
        }

        return null;
    }

    /**
     * Get suggestion list for display below the input line.
     *
     * @return array<int, array{label: string, description: string, type: string}>
     */
    public function getSuggestions(string $input): array
    {
        $liveSuggestions = $this->getLiveSuggestions($input);
        if ($liveSuggestions !== []) {
            return $liveSuggestions;
        }

        // Shell history suggestions for bash-like commands
        $shellMatches = $this->shellHistory->suggest($input);
        if ($shellMatches !== []) {
            return array_map(fn ($cmd) => [
                'label' => $cmd,
                'description' => 'history',
                'type' => 'shell',
            ], $shellMatches);
        }

        return [];
    }

    /**
     * Get the live dropdown suggestions that should appear while typing.
     *
     * @return array<int, array{label: string, description: string, type: string}>
     */
    public function getLiveSuggestions(string $input): array
    {
        // Slash command suggestions
        if (str_starts_with($input, '/')) {
            $results = $this->commands->suggest($input);
            return array_map(fn ($r) => [
                'label' => '/' . $r['name'],
                'description' => $r['description'],
                'type' => 'command',
            ], $results);
        }

        // @ file path suggestions
        if (preg_match('/@(\S*)$/', $input, $matches)) {
            $partial = $matches[1];
            $results = $this->files->suggest($partial);
            return array_map(fn ($r) => [
                'label' => '@' . $r['name'],
                'description' => $r['path'],
                'type' => $r['type'],
            ], $results);
        }

        return [];
    }

    /**
     * Accept the ghost text: return the completed input.
     */
    public function acceptGhostText(string $input, string $ghostText): string
    {
        return $input . $ghostText;
    }

    /**
     * Accept a suggestion: return the completed input.
     */
    public function acceptSuggestion(string $input, string $suggestionLabel): string
    {
        // For slash commands, replace the partial with the full command
        if (str_starts_with($input, '/') && str_starts_with($suggestionLabel, '/')) {
            // Find where the /command part ends in the input
            if (preg_match('!^(/\S*)!', $input, $m)) {
                return $suggestionLabel . substr($input, strlen($m[1]));
            }
        }

        // For @file suggestions, replace the @partial with the suggestion
        if (preg_match('/@(\S*)$/', $input, $m)) {
            return substr($input, 0, -strlen($m[0])) . $suggestionLabel;
        }

        return $input . $suggestionLabel;
    }

    /**
     * Get a rendered suggestion line for terminal display.
     */
    public function renderSuggestion(array $suggestion, bool $selected = false, int $labelWidth = 16): string
    {
        $prefix = $selected ? '<fg=green>❯</>' : ' ';
        $label = str_pad($suggestion['label'], $labelWidth);
        $desc = $suggestion['description'];

        if ($selected) {
            return "{$prefix} <fg=cyan;bold>{$label}</> <fg=gray>{$desc}</>";
        }
        return "{$prefix} <fg=white>{$label}</> <fg=gray>{$desc}</>";
    }

    /**
     * Get a rendered ghost text line for terminal display.
     */
    public function renderGhostText(string $ghostText): string
    {
        return "<fg=gray>{$ghostText}</>";
    }
}
