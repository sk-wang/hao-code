<?php

namespace App\Tools\Mcp;

use App\Services\Mcp\McpConnectionManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

/**
 * Lists available resources across all connected MCP servers,
 * or filtered to a specific server.
 */
final class ListMcpResourcesTool extends BaseTool
{
    public function __construct(
        private readonly McpConnectionManager $connectionManager,
    ) {}

    public function name(): string
    {
        return 'ListMcpResourcesTool';
    }

    public function description(): string
    {
        return 'Lists available MCP resources. Can optionally filter by server name.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'server' => [
                    'type' => 'string',
                    'description' => 'Optional server name to filter resources by',
                ],
            ],
            'required' => [],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $serverFilter = $input['server'] ?? null;

        if ($serverFilter !== null) {
            $client = $this->connectionManager->getClient($serverFilter);
            if ($client === null) {
                return ToolResult::error("MCP server '{$serverFilter}' is not connected");
            }

            if (!$client->supportsResources()) {
                return ToolResult::success("MCP server '{$serverFilter}' does not support resources.");
            }

            try {
                $resources = $client->listResources();
                $resources = array_map(fn($r) => array_merge($r, ['server' => $serverFilter]), $resources);
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to list resources from '{$serverFilter}': {$e->getMessage()}");
            }
        } else {
            $resources = $this->connectionManager->discoverAllResources();
        }

        if (empty($resources)) {
            return ToolResult::success('No MCP resources available.');
        }

        $lines = ['Available MCP resources (' . count($resources) . '):'];
        foreach ($resources as $resource) {
            $line = "  [{$resource['server']}] {$resource['uri']}";
            if (isset($resource['name']) && $resource['name'] !== $resource['uri']) {
                $line .= " — {$resource['name']}";
            }
            if (isset($resource['mimeType'])) {
                $line .= " ({$resource['mimeType']})";
            }
            if (isset($resource['description'])) {
                $line .= "\n    {$resource['description']}";
            }
            $lines[] = $line;
        }

        return ToolResult::success(implode("\n", $lines));
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function getActivityDescription(array $input): ?string
    {
        $server = $input['server'] ?? null;
        return $server ? "Listing MCP resources from {$server}" : 'Listing MCP resources';
    }
}
