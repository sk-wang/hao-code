<?php

declare(strict_types=1);

namespace App\Support\Terminal\Autocomplete;

/**
 * Fuzzy search for slash commands.
 * Matches against command names, aliases, and descriptions.
 */
class CommandSuggestionProvider
{
    public function __construct(
        private readonly SlashCommandCatalog $catalog = new SlashCommandCatalog(),
    ) {}

    /**
     * Get suggestions for a partial command input.
     * Input should start with '/'.
     *
     * @return array<int, array{name: string, description: string, score: float}>
     */
    public function suggest(string $input, int $limit = 5): array
    {
        $query = ltrim($input, '/');
        if ($query === '') {
            // Return first N commands when just '/' is typed
            return array_map(
                fn (array $cmd) => ['name' => $cmd['name'], 'description' => $cmd['description'], 'score' => 1.0],
                array_slice($this->catalog->all(), 0, $limit),
            );
        }

        $query = strtolower($query);
        $results = [];

        foreach ($this->catalog->all() as $cmd) {
            $score = $this->matchScore($query, $cmd['name'], $cmd['aliases'], $cmd['description']);
            if ($score > 0) {
                $results[] = [
                    'name' => $cmd['name'],
                    'description' => $cmd['description'],
                    'score' => $score,
                ];
            }
        }

        // Sort by score descending
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Get the best ghost-text completion for a partial command.
     * Returns the remaining characters to complete the command name.
     */
    public function getGhostText(string $input): ?string
    {
        $query = ltrim($input, '/');
        if ($query === '') {
            return null;
        }

        $queryLower = strtolower($query);

        // First check exact prefix match on command names
        foreach ($this->catalog->all() as $cmd) {
            if (str_starts_with($cmd['name'], $queryLower) && $cmd['name'] !== $queryLower) {
                return substr($cmd['name'], strlen($query));
            }
        }

        // Then check aliases
        foreach ($this->catalog->all() as $cmd) {
            foreach ($cmd['aliases'] as $alias) {
                if (str_starts_with($alias, $queryLower) && $alias !== $queryLower) {
                    return substr($alias, strlen($query));
                }
            }
        }

        return null;
    }

    private function matchScore(string $query, string $name, array $aliases, string $description): float
    {
        $nameLower = strtolower($name);

        // Exact match gets highest score
        if ($nameLower === $query) {
            return 100.0;
        }

        // Exact alias match
        foreach ($aliases as $alias) {
            if (strtolower($alias) === $query) {
                return 95.0;
            }
        }

        // Prefix match on name
        if (str_starts_with($nameLower, $query)) {
            return 80.0 + (strlen($query) / strlen($nameLower)) * 10;
        }

        // Prefix match on alias
        foreach ($aliases as $alias) {
            if (str_starts_with(strtolower($alias), $query)) {
                return 75.0;
            }
        }

        // Contains match on name
        if (str_contains($nameLower, $query)) {
            return 50.0;
        }

        // Contains match on description
        if (str_contains(strtolower($description), $query)) {
            return 30.0;
        }

        // Fuzzy match: count matching characters in order
        $fuzzyScore = $this->fuzzyMatch($query, $nameLower);
        if ($fuzzyScore > 0.5) {
            return $fuzzyScore * 20;
        }

        return 0;
    }

    /**
     * Simple fuzzy match: returns 0-1 score based on character overlap.
     */
    private function fuzzyMatch(string $query, string $target): float
    {
        $qi = 0;
        $matched = 0;
        $queryLen = strlen($query);

        for ($ti = 0; $ti < strlen($target) && $qi < $queryLen; $ti++) {
            if ($target[$ti] === $query[$qi]) {
                $matched++;
                $qi++;
            }
        }

        return $matched / $queryLen;
    }
}
