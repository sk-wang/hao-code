<?php

namespace App\Tools\Mcp;

use App\Contracts\ToolInterface;
use App\Services\Mcp\McpConnectionException;
use App\Services\Mcp\McpConnectionManager;
use App\Services\Permissions\PermissionDecision;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

/**
 * A dynamically generated tool that wraps a single tool from an MCP server.
 * One instance is created per MCP server tool during discovery.
 *
 * Tool name follows the pattern: mcp__<server>__<tool>
 */
final class McpDynamicTool implements ToolInterface
{
    private readonly ToolInputSchema $schema;

    public function __construct(
        private readonly string $qualifiedName,
        private readonly string $serverName,
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $inputJsonSchema,
        private readonly array $annotations,
        private readonly McpConnectionManager $connectionManager,
    ) {
        $this->schema = ToolInputSchema::make($this->inputJsonSchema);
    }

    public function name(): string
    {
        return $this->qualifiedName;
    }

    public function description(): string
    {
        $desc = $this->toolDescription;
        if (mb_strlen($desc) > 2048) {
            $desc = mb_substr($desc, 0, 2045) . '...';
        }
        return $desc;
    }

    public function inputSchema(): ToolInputSchema
    {
        return $this->schema;
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $client = $this->connectionManager->getClient($this->serverName);

        if ($client === null || !$client->isConnected()) {
            // Try to reconnect
            try {
                $client = $this->connectionManager->connectByName($this->serverName);
            } catch (McpConnectionException $e) {
                return ToolResult::error("MCP server '{$this->serverName}' is not connected: {$e->getMessage()}");
            }
        }

        try {
            $result = $client->callTool($this->toolName, $input);
        } catch (McpConnectionException $e) {
            return ToolResult::error("MCP tool call failed: {$e->getMessage()}");
        }

        $output = $this->formatMcpResult($result);

        if ($result['isError']) {
            return ToolResult::error($output);
        }

        return ToolResult::success($output);
    }

    public function isConcurrencySafe(array $input): bool
    {
        return $this->isReadOnly($input);
    }

    public function isReadOnly(array $input): bool
    {
        // Check MCP annotations for readOnlyHint
        if (isset($this->annotations['readOnlyHint'])) {
            return (bool) $this->annotations['readOnlyHint'];
        }
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function userFacingName(array $input): string
    {
        $title = $this->annotations['title'] ?? $this->toolName;
        return "{$this->serverName} - {$title} (MCP)";
    }

    public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision
    {
        // MCP tools always require user approval unless explicitly allowed
        return PermissionDecision::ask("MCP tool: {$this->userFacingName($input)}");
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        return null;
    }

    public function maxResultSizeChars(): int
    {
        return 50000;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        return $input;
    }

    public function getActivityDescription(array $input): ?string
    {
        return "Calling {$this->serverName}/{$this->toolName}";
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return [
            'isSearch' => false,
            'isRead' => $this->isReadOnly($input),
            'isList' => false,
        ];
    }

    /**
     * Format MCP result content blocks into a string.
     */
    private function formatMcpResult(array $result): string
    {
        $content = $result['content'] ?? [];

        if (empty($content)) {
            return '(empty response)';
        }

        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                $parts[] = (string) $block;
                continue;
            }

            $type = $block['type'] ?? 'text';
            match ($type) {
                'text' => $parts[] = $block['text'] ?? '',
                'image' => $parts[] = '[Image: ' . ($block['mimeType'] ?? 'unknown') . ', ' . strlen($block['data'] ?? '') . ' bytes]',
                'resource' => $parts[] = $this->formatResourceContent($block),
                default => $parts[] = json_encode($block, JSON_UNESCAPED_SLASHES),
            };
        }

        return implode("\n", $parts);
    }

    private function formatResourceContent(array $block): string
    {
        $resource = $block['resource'] ?? [];
        $uri = $resource['uri'] ?? 'unknown';
        $text = $resource['text'] ?? null;
        $blob = $resource['blob'] ?? null;

        if ($text !== null) {
            return "Resource [{$uri}]:\n{$text}";
        }
        if ($blob !== null) {
            return "Resource [{$uri}]: binary data (" . strlen($blob) . " bytes)";
        }
        return "Resource [{$uri}]";
    }
}
