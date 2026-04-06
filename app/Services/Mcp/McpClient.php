<?php

namespace App\Services\Mcp;

/**
 * MCP protocol client — handles initialization handshake, tool/resource
 * discovery and invocation over a McpTransport.
 */
final class McpClient
{
    private bool $initialized = false;

    /** @var array<string, mixed>|null Server capabilities from initialize response */
    private ?array $capabilities = null;

    /** @var array{name: string, version: string}|null */
    private ?array $serverInfo = null;

    /** @var string|null Server instructions */
    private ?string $instructions = null;

    /** @var array<int, array{name: string, description: string, inputSchema: array}>|null Cached tools list */
    private ?array $toolsCache = null;

    /** @var array<int, array{uri: string, name: string, mimeType?: string, description?: string}>|null */
    private ?array $resourcesCache = null;

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $serverName,
    ) {}

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public function getServerInfo(): ?array
    {
        return $this->serverInfo;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    /**
     * Connect to the MCP server and perform the initialization handshake.
     *
     * @throws McpConnectionException
     */
    public function connect(int $timeoutSeconds = 30): void
    {
        $this->transport->connect($timeoutSeconds);

        $result = $this->transport->request('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'roots' => ['listChanged' => true],
            ],
            'clientInfo' => [
                'name' => 'hao-code',
                'version' => '1.0.0',
            ],
        ], $timeoutSeconds);

        if (!is_array($result)) {
            throw new McpConnectionException("Invalid initialize response from {$this->serverName}");
        }

        $this->capabilities = $result['capabilities'] ?? [];
        $this->serverInfo = $result['serverInfo'] ?? null;
        $this->instructions = $result['instructions'] ?? null;

        // Send initialized notification
        $this->transport->notify('notifications/initialized');

        $this->initialized = true;
    }

    /**
     * Whether the server supports tools capability.
     */
    public function supportsTools(): bool
    {
        return !empty($this->capabilities['tools']);
    }

    /**
     * Whether the server supports resources capability.
     */
    public function supportsResources(): bool
    {
        return !empty($this->capabilities['resources']);
    }

    /**
     * Fetch the list of tools from the MCP server.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     * @throws McpConnectionException
     */
    public function listTools(bool $useCache = true): array
    {
        $this->ensureInitialized();

        if ($useCache && $this->toolsCache !== null) {
            return $this->toolsCache;
        }

        if (!$this->supportsTools()) {
            return [];
        }

        $result = $this->transport->request('tools/list');

        $tools = [];
        foreach (($result['tools'] ?? []) as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) {
                continue;
            }
            $tools[] = [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => $tool['annotations'] ?? [],
            ];
        }

        $this->toolsCache = $tools;
        return $tools;
    }

    /**
     * Call a tool on the MCP server.
     *
     * @return array{content: array, isError: bool, structuredContent?: mixed}
     * @throws McpConnectionException
     */
    public function callTool(string $toolName, array $arguments = [], int $timeoutSeconds = 60): array
    {
        $this->ensureInitialized();

        $result = $this->transport->request('tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ], $timeoutSeconds);

        if (!is_array($result)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Empty response from MCP server']],
                'isError' => true,
            ];
        }

        return [
            'content' => $result['content'] ?? [],
            'isError' => (bool) ($result['isError'] ?? false),
            'structuredContent' => $result['structuredContent'] ?? null,
        ];
    }

    /**
     * Fetch resources list from the MCP server.
     *
     * @return array<int, array{uri: string, name: string, mimeType?: string, description?: string}>
     * @throws McpConnectionException
     */
    public function listResources(bool $useCache = true): array
    {
        $this->ensureInitialized();

        if ($useCache && $this->resourcesCache !== null) {
            return $this->resourcesCache;
        }

        if (!$this->supportsResources()) {
            return [];
        }

        $result = $this->transport->request('resources/list');

        $resources = [];
        foreach (($result['resources'] ?? []) as $resource) {
            if (!is_array($resource) || !isset($resource['uri'])) {
                continue;
            }
            $entry = [
                'uri' => (string) $resource['uri'],
                'name' => (string) ($resource['name'] ?? $resource['uri']),
            ];
            if (isset($resource['mimeType'])) {
                $entry['mimeType'] = (string) $resource['mimeType'];
            }
            if (isset($resource['description'])) {
                $entry['description'] = (string) $resource['description'];
            }
            $resources[] = $entry;
        }

        $this->resourcesCache = $resources;
        return $resources;
    }

    /**
     * Read a specific resource from the MCP server.
     *
     * @return array{contents: array<int, array{uri: string, mimeType?: string, text?: string, blob?: string}>}
     * @throws McpConnectionException
     */
    public function readResource(string $uri): array
    {
        $this->ensureInitialized();

        $result = $this->transport->request('resources/read', [
            'uri' => $uri,
        ]);

        return [
            'contents' => $result['contents'] ?? [],
        ];
    }

    /**
     * Clear cached tools and resources (e.g. after reconnection).
     */
    public function clearCache(): void
    {
        $this->toolsCache = null;
        $this->resourcesCache = null;
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        $this->transport->close();
        $this->initialized = false;
        $this->clearCache();
    }

    public function isConnected(): bool
    {
        return $this->initialized && $this->transport->isConnected();
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            throw new McpConnectionException("MCP client for '{$this->serverName}' is not initialized. Call connect() first.");
        }
    }
}
