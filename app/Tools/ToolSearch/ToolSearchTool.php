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
        $query = strtolower($input['query']);
        $registry = app(ToolRegistry::class);
        $tools = $registry->getAllTools();

        $results = [];
        foreach ($tools as $tool) {
            $name = strtolower($tool->name());
            $desc = strtolower($tool->description());

            // Score: exact name match > name contains > description contains
            $score = 0;
            if ($name === $query) {
                $score = 100;
            } elseif (str_contains($name, $query)) {
                $score = 80;
            } elseif (str_contains($desc, $query)) {
                $score = 60;
            }

            // Also check individual words
            if ($score === 0) {
                $words = explode(' ', $query);
                $matchCount = 0;
                foreach ($words as $word) {
                    if (str_contains($name, $word) || str_contains($desc, $word)) {
                        $matchCount++;
                    }
                }
                if ($matchCount > 0) {
                    $score = 40 * ($matchCount / count($words));
                }
            }

            if ($score > 0) {
                $results[] = [
                    'name' => $tool->name(),
                    'description' => mb_substr($tool->description(), 0, 120),
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
}
