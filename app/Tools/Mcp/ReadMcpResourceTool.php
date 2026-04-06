<?php

namespace App\Tools\Mcp;

use App\Services\Mcp\McpConnectionManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

/**
 * Reads a specific resource from a connected MCP server by URI.
 */
final class ReadMcpResourceTool extends BaseTool
{
    public function __construct(
        private readonly McpConnectionManager $connectionManager,
    ) {}

    public function name(): string
    {
        return 'ReadMcpResourceTool';
    }

    public function description(): string
    {
        return 'Reads a specific resource from an MCP server. Requires the server name and resource URI.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'server' => [
                    'type' => 'string',
                    'description' => 'The MCP server name that hosts the resource',
                ],
                'uri' => [
                    'type' => 'string',
                    'description' => 'The URI of the resource to read',
                ],
            ],
            'required' => ['server', 'uri'],
        ], [
            'server' => 'required|string',
            'uri' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $serverName = $input['server'];
        $uri = $input['uri'];

        $client = $this->connectionManager->getClient($serverName);
        if ($client === null) {
            return ToolResult::error("MCP server '{$serverName}' is not connected");
        }

        if (!$client->supportsResources()) {
            return ToolResult::error("MCP server '{$serverName}' does not support resources");
        }

        try {
            $result = $client->readResource($uri);
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to read resource '{$uri}' from '{$serverName}': {$e->getMessage()}");
        }

        $contents = $result['contents'] ?? [];
        if (empty($contents)) {
            return ToolResult::success("Resource '{$uri}' returned empty contents.");
        }

        $parts = [];
        foreach ($contents as $content) {
            $contentUri = $content['uri'] ?? $uri;
            $mimeType = $content['mimeType'] ?? null;

            if (isset($content['text'])) {
                $header = "Resource: {$contentUri}";
                if ($mimeType) {
                    $header .= " ({$mimeType})";
                }
                $parts[] = "{$header}\n{$content['text']}";
            } elseif (isset($content['blob'])) {
                $blobSize = strlen($content['blob']);
                $parts[] = "Resource: {$contentUri} — binary data ({$blobSize} bytes, {$mimeType})";
            } else {
                $parts[] = "Resource: {$contentUri} — no content";
            }
        }

        return ToolResult::success(implode("\n---\n", $parts));
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function getActivityDescription(array $input): ?string
    {
        return "Reading MCP resource {$input['uri']}";
    }
}
