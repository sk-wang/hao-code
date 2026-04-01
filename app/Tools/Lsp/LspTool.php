<?php

namespace App\Tools\Lsp;

use App\Services\Lsp\LspClient;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class LspTool extends BaseTool
{
    public function name(): string
    {
        return 'LSP';
    }

    public function description(): string
    {
        return <<<DESC
Interact with Language Server Protocol servers for code intelligence.

Supported operations:
- goToDefinition: Find where a symbol is defined
- findReferences: Find all references to a symbol
- hover: Get hover information (type info, docs)
- documentSymbol: Get all symbols in a document
- workspaceSymbol: Search for symbols across the workspace

All operations require filePath (absolute), line (1-based), and character (1-based).
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['goToDefinition', 'findReferences', 'hover', 'documentSymbol', 'workspaceSymbol'],
                    'description' => 'The LSP operation to perform',
                ],
                'filePath' => [
                    'type' => 'string',
                    'description' => 'Absolute path to the file',
                ],
                'line' => [
                    'type' => 'integer',
                    'description' => 'Line number (1-based)',
                ],
                'character' => [
                    'type' => 'integer',
                    'description' => 'Character offset (1-based)',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query for workspaceSymbol',
                ],
            ],
            'required' => ['operation', 'filePath'],
        ], [
            'operation' => 'required|string|in:goToDefinition,findReferences,hover,documentSymbol,workspaceSymbol',
            'filePath' => 'required|string',
            'line' => 'nullable|integer|min:0',
            'character' => 'nullable|integer|min:0',
            'query' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $operation = $input['operation'];
        $filePath = $input['filePath'];
        $line = ($input['line'] ?? 1) - 1; // Convert to 0-based
        $character = ($input['character'] ?? 1) - 1; // Convert to 0-based

        $language = LspClient::detectLanguage($filePath);
        $server = LspClient::getServer($language);

        if ($server === null) {
            return ToolResult::error("No LSP server available for language: {$language}. Install a language server for {$language} support.");
        }

        $result = match ($operation) {
            'goToDefinition' => $server->goToDefinition($filePath, $line, $character),
            'findReferences' => $server->findReferences($filePath, $line, $character),
            'hover' => $server->hover($filePath, $line, $character),
            'documentSymbol' => $server->documentSymbol($filePath),
            'workspaceSymbol' => $server->workspaceSymbol($input['query'] ?? ''),
            default => null,
        };

        if ($result === null) {
            return ToolResult::success("No results from LSP for operation: {$operation}");
        }

        return ToolResult::success($this->formatResult($operation, $result));
    }

    private function formatResult(string $operation, mixed $result): string
    {
        return match ($operation) {
            'goToDefinition' => $this->formatLocations($result),
            'findReferences' => $this->formatLocations($result),
            'hover' => $this->formatHover($result),
            'documentSymbol' => $this->formatSymbols($result),
            'workspaceSymbol' => $this->formatWorkspaceSymbols($result),
            default => json_encode($result, JSON_PRETTY_PRINT),
        };
    }

    private function formatLocations(mixed $result): string
    {
        if ($result === null) {
            return 'No locations found.';
        }

        // Single Location
        if (isset($result['uri'])) {
            return $this->formatLocation($result);
        }

        // Array of Locations
        if (is_array($result)) {
            $lines = [];
            foreach ($result as $loc) {
                $lines[] = $this->formatLocation($loc);
            }
            return implode("\n", $lines);
        }

        // Link type
        if (isset($result['targetUri'])) {
            $path = $this->uriToPath($result['targetUri']);
            $range = $result['targetRange'] ?? $result['targetSelectionRange'] ?? [];
            $line = ($range['start']['line'] ?? 0) + 1;
            return "{$path}:{$line}";
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    private function formatLocation(array $loc): string
    {
        if (isset($loc['targetUri'])) {
            $path = $this->uriToPath($loc['targetUri']);
            $range = $loc['targetRange'] ?? [];
            $line = ($range['start']['line'] ?? 0) + 1;
            $char = ($range['start']['character'] ?? 0) + 1;
            return "{$path}:{$line}:{$char}";
        }

        $path = $this->uriToPath($loc['uri'] ?? '');
        $range = $loc['range'] ?? [];
        $line = ($range['start']['line'] ?? 0) + 1;
        $char = ($range['start']['character'] ?? 0) + 1;
        return "{$path}:{$line}:{$char}";
    }

    private function formatHover(mixed $result): string
    {
        if ($result === null) {
            return 'No hover information available.';
        }

        $contents = $result['contents'] ?? '';
        if (is_string($contents)) {
            return $contents;
        }

        if (is_array($contents)) {
            $parts = [];
            if (isset($contents['kind']) && isset($contents['value'])) {
                $parts[] = "```{$contents['kind']}\n{$contents['value']}\n```";
            } elseif (is_array($contents)) {
                foreach ($contents as $item) {
                    if (is_string($item)) {
                        $parts[] = $item;
                    } elseif (isset($item['value'])) {
                        $lang = $item['language'] ?? '';
                        $parts[] = $lang ? "```{$lang}\n{$item['value']}\n```" : $item['value'];
                    }
                }
            }
            return implode("\n\n", $parts);
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    private function formatSymbols(mixed $result): string
    {
        if (!is_array($result)) {
            return 'No symbols found.';
        }

        $lines = [];
        foreach ($result as $symbol) {
            $name = $symbol['name'] ?? 'unknown';
            $kind = $this->symbolKindToString($symbol['kind'] ?? 0);
            $range = $symbol['range'] ?? $symbol['location']['range'] ?? [];
            $line = ($range['start']['line'] ?? 0) + 1;
            $detail = isset($symbol['detail']) ? " — {$symbol['detail']}" : '';
            $lines[] = "  {$kind} {$name}{$detail} (line {$line})";

            // Children
            if (!empty($symbol['children'])) {
                foreach ($symbol['children'] as $child) {
                    $childName = $child['name'] ?? 'unknown';
                    $childKind = $this->symbolKindToString($child['kind'] ?? 0);
                    $childRange = $child['range'] ?? $child['location']['range'] ?? [];
                    $childLine = ($childRange['start']['line'] ?? 0) + 1;
                    $lines[] = "    {$childKind} {$childName} (line {$childLine})";
                }
            }
        }

        return implode("\n", $lines);
    }

    private function formatWorkspaceSymbols(mixed $result): string
    {
        if (!is_array($result)) {
            return 'No symbols found.';
        }

        $lines = [];
        foreach (array_slice($result, 0, 20) as $symbol) {
            $name = $symbol['name'] ?? 'unknown';
            $kind = $this->symbolKindToString($symbol['kind'] ?? 0);
            $path = $this->uriToPath($symbol['location']['uri'] ?? '');
            $range = $symbol['location']['range'] ?? [];
            $line = ($range['start']['line'] ?? 0) + 1;
            $lines[] = "{$kind} {$name} — {$path}:{$line}";
        }

        return implode("\n", $lines);
    }

    private function symbolKindToString(int $kind): string
    {
        return match ($kind) {
            1 => 'File',
            2 => 'Module',
            3 => 'Namespace',
            4 => 'Package',
            5 => 'Class',
            6 => 'Method',
            7 => 'Property',
            8 => 'Field',
            9 => 'Constructor',
            10 => 'Enum',
            11 => 'Interface',
            12 => 'Function',
            13 => 'Variable',
            14 => 'Constant',
            15 => 'String',
            16 => 'Number',
            17 => 'Boolean',
            18 => 'Array',
            19 => 'Object',
            20 => 'Key',
            21 => 'Null',
            22 => 'EnumMember',
            23 => 'Struct',
            24 => 'Event',
            25 => 'Operator',
            26 => 'TypeParameter',
            default => 'Symbol',
        };
    }

    private function uriToPath(string $uri): string
    {
        if (str_starts_with($uri, 'file://')) {
            return substr($uri, 7);
        }
        return $uri;
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
