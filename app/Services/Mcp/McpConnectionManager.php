<?php

namespace App\Services\Mcp;

/**
 * Manages the lifecycle of MCP server connections.
 * Connects enabled servers, provides tool discovery, and handles reconnection.
 */
final class McpConnectionManager
{
    /** @var array<string, McpClient> Connected clients by server name */
    private array $clients = [];

    /** @var array<string, McpConnectionException> Failed connections by server name */
    private array $failures = [];

    /** @var bool Whether initial connection has been performed */
    private bool $initialized = false;

    public function __construct(
        private readonly McpServerConfigManager $configManager,
    ) {}

    /**
     * Connect to all enabled MCP servers.
     * Safe to call multiple times — will only connect on the first call.
     *
     * @param callable|null $onServerStatus Called with (string $name, string $status, ?string $error) for progress
     */
    public function connectAll(?callable $onServerStatus = null): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $servers = $this->configManager->listServers();

        foreach ($servers as $server) {
            if (!$server['enabled']) {
                if ($onServerStatus) { $onServerStatus($server['name'], 'disabled', null); }
                continue;
            }

            try {
                if ($onServerStatus) { $onServerStatus($server['name'], 'connecting', null); }
                $client = $this->connectServer($server);
                $this->clients[$server['name']] = $client;
                if ($onServerStatus) { $onServerStatus($server['name'], 'connected', null); }
            } catch (McpConnectionException $e) {
                $this->failures[$server['name']] = $e;
                if ($onServerStatus) { $onServerStatus($server['name'], 'failed', $e->getMessage()); }
            }
        }
    }

    /**
     * Connect to a single server by name.
     *
     * @throws McpConnectionException
     */
    public function connectByName(string $name): McpClient
    {
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        $server = $this->configManager->getServer($name);
        if ($server === null) {
            throw new McpConnectionException("MCP server '{$name}' not found in configuration");
        }

        if (!$server['enabled']) {
            throw new McpConnectionException("MCP server '{$name}' is disabled");
        }

        $client = $this->connectServer($server);
        $this->clients[$name] = $client;
        unset($this->failures[$name]);

        return $client;
    }

    /**
     * Get a connected client by server name.
     */
    public function getClient(string $name): ?McpClient
    {
        return $this->clients[$name] ?? null;
    }

    /**
     * Get all connected clients.
     *
     * @return array<string, McpClient>
     */
    public function getConnectedClients(): array
    {
        return $this->clients;
    }

    /**
     * Get all connection failures.
     *
     * @return array<string, McpConnectionException>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Discover all tools across all connected MCP servers.
     * Returns tools in the format: mcp__<server>__<tool>
     *
     * @return array<int, array{
     *     qualifiedName: string,
     *     serverName: string,
     *     toolName: string,
     *     description: string,
     *     inputSchema: array,
     *     annotations: array,
     * }>
     */
    public function discoverAllTools(): array
    {
        $allTools = [];

        foreach ($this->clients as $serverName => $client) {
            if (!$client->supportsTools()) {
                continue;
            }

            try {
                $tools = $client->listTools();
                foreach ($tools as $tool) {
                    $allTools[] = [
                        'qualifiedName' => self::buildToolName($serverName, $tool['name']),
                        'serverName' => $serverName,
                        'toolName' => $tool['name'],
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema'],
                        'annotations' => $tool['annotations'] ?? [],
                    ];
                }
            } catch (McpConnectionException) {
                // Skip servers that fail tool discovery
            }
        }

        return $allTools;
    }

    /**
     * Discover all resources across all connected MCP servers.
     *
     * @return array<int, array{uri: string, name: string, mimeType?: string, description?: string, server: string}>
     */
    public function discoverAllResources(): array
    {
        $allResources = [];

        foreach ($this->clients as $serverName => $client) {
            if (!$client->supportsResources()) {
                continue;
            }

            try {
                $resources = $client->listResources();
                foreach ($resources as $resource) {
                    $resource['server'] = $serverName;
                    $allResources[] = $resource;
                }
            } catch (McpConnectionException) {
                // Skip servers that fail resource discovery
            }
        }

        return $allResources;
    }

    /**
     * Disconnect a specific server.
     */
    public function disconnect(string $name): void
    {
        if (isset($this->clients[$name])) {
            $this->clients[$name]->close();
            unset($this->clients[$name]);
        }
    }

    /**
     * Disconnect all servers and reset state.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
        $this->failures = [];
        $this->initialized = false;
    }

    /**
     * Build a fully qualified MCP tool name: mcp__<server>__<tool>
     */
    public static function buildToolName(string $serverName, string $toolName): string
    {
        return 'mcp__' . self::normalizeName($serverName) . '__' . self::normalizeName($toolName);
    }

    /**
     * Parse a qualified tool name back to server + tool.
     *
     * @return array{serverName: string, toolName: string}|null
     */
    public static function parseToolName(string $qualifiedName): ?array
    {
        if (!str_starts_with($qualifiedName, 'mcp__')) {
            return null;
        }

        $parts = explode('__', $qualifiedName, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'serverName' => $parts[1],
            'toolName' => $parts[2],
        ];
    }

    /**
     * Normalize a server or tool name for use in qualified tool names.
     * Replaces non-alphanumeric characters with underscores.
     */
    private static function normalizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    /**
     * @param array{name: string, transport: string, command: ?string, args: array, url: ?string, env: array, headers: array} $serverConfig
     * @throws McpConnectionException
     */
    private function connectServer(array $serverConfig): McpClient
    {
        $transport = McpTransport::fromConfig($serverConfig);
        $client = new McpClient($transport, $serverConfig['name']);
        $client->connect();
        return $client;
    }
}
