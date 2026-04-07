<?php

namespace App\Tools\ToolSearch;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class ToolSearchTool extends BaseTool
{
    public function name(): string { return 'ToolSearch'; }

    public function description(): string
    {
        return 'Search for available tools by keyword. Returns matching tool names and descriptions.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query - keyword or tool name',
                ],
            ],
            'required' => ['query'],
        ], ['query' => 'required|string']);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $query = strtolower(trim((string) $input['query']));
        $registry = app(ToolRegistry::class);
        $tools = $registry->getAllTools();
        $keywords = $this->keywords($query);

        $results = [];
        foreach ($tools as $tool) {
            $name = strtolower($tool->name());
            $desc = strtolower($tool->description());
            $score = $this->scoreMatch($query, $keywords, $name, $desc);

            if ($score > 0) {
                $results[] = [
                    'name' => $tool->name(),
                    'description' => $this->compactDescription($tool->description()),
                    'score' => $score,
                ];
            }
        }

        if (empty($results)) {
            return ToolResult::success("No tools found matching: {$input['query']}");
        }

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        $lines = ["Found " . count($results) . " matching tools:"];
        foreach ($results as $r) {
            $lines[] = "  {$r['name']}: {$r['description']}";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool { return true; }

    /**
     * ToolSearch itself should always be included in the prompt (never deferred).
     */
    public function isConcurrencySafe(array $input): bool { return true; }

    /**
     * @return array<int, string>
     */
    private function keywords(string $query): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', $query, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values(array_unique(array_map('strtolower', $parts)));
    }

    private function scoreMatch(string $query, array $keywords, string $name, string $desc): int
    {
        if ($query === '') {
            return 0;
        }

        if ($name === $query) {
            return 1000;
        }

        $score = 0;

        if (str_contains($name, $query)) {
            $score += 300;
        } elseif (str_contains($desc, $query)) {
            $score += 140;
        }

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $wordPattern = '/\b' . preg_quote($keyword, '/') . '\b/i';

            if (preg_match($wordPattern, $name) === 1) {
                $score += 220;
                continue;
            }

            if (str_contains($name, $keyword)) {
                $score += 140;
                continue;
            }

            if (preg_match($wordPattern, $desc) === 1) {
                $score += 70;
                continue;
            }

            if (str_contains($desc, $keyword)) {
                $score += 35;
            }
        }

        return $score;
    }

    private function compactDescription(string $description): string
    {
        $singleLine = preg_replace('/\s+/u', ' ', trim($description)) ?? trim($description);

        return mb_substr($singleLine, 0, 120);
    }
}
