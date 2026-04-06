<?php

namespace Tests\Unit;

use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpConnectionException;
use App\Services\Mcp\McpConnectionManager;
use App\Services\Mcp\McpServerConfigManager;
use App\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;

class McpClientTest extends TestCase
{
    // ─── McpTransport ─────────────────────────────────────────────────

    public function test_transport_from_config_stdio(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => 'echo',
            'args' => ['hello'],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->assertSame('stdio', $transport->getTransportType());
    }

    public function test_transport_from_config_http(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => ['Authorization' => 'Bearer test'],
        ]);

        $this->assertSame('http', $transport->getTransportType());
    }

    public function test_stdio_connect_fails_without_command(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => null,
            'args' => [],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('command');
        $transport->connect();
    }

    public function test_unsupported_transport_throws(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'websocket',
            'command' => null,
            'args' => [],
            'url' => null,
            'env' => [],
            'headers' => [],
        ]);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('Unsupported transport');
        $transport->connect();
    }

    // ─── McpConnectionManager ──────────────────────────────────────��──

    public function test_build_tool_name(): void
    {
        $this->assertSame(
            'mcp__my_server__my_tool',
            McpConnectionManager::buildToolName('my-server', 'my-tool')
        );
    }

    public function test_parse_tool_name(): void
    {
        $parsed = McpConnectionManager::parseToolName('mcp__github__create_issue');
        $this->assertNotNull($parsed);
        $this->assertSame('github', $parsed['serverName']);
        $this->assertSame('create_issue', $parsed['toolName']);
    }

    public function test_parse_tool_name_returns_null_for_non_mcp(): void
    {
        $this->assertNull(McpConnectionManager::parseToolName('Bash'));
        $this->assertNull(McpConnectionManager::parseToolName('mcp__only_one_part'));
    }

    public function test_connection_manager_starts_empty(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->getConnectedClients());
        $this->assertEmpty($manager->getFailures());
    }

    public function test_get_client_returns_null_for_unknown(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertNull($manager->getClient('nonexistent'));
    }

    public function test_connect_by_name_throws_for_missing_server(): void
    {
        $configManager = new class extends McpServerConfigManager {
            public function paths(): array { return ['global' => '/tmp/g.json', 'project' => '/tmp/p.json']; }
            public function listServers(): array { return []; }
            public function getServer(string $name): ?array { return null; }
        };

        $manager = new McpConnectionManager($configManager);

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('not found');
        $manager->connectByName('nonexistent');
    }

    public function test_discover_all_tools_empty_when_no_connections(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->discoverAllTools());
    }

    public function test_discover_all_resources_empty_when_no_connections(): void
    {
        $configManager = new McpServerConfigManager();
        $manager = new McpConnectionManager($configManager);

        $this->assertEmpty($manager->discoverAllResources());
    }

    // ─── McpClient (unit, without real transport) ─────────────────────

    public function test_client_not_initialized_throws(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => [],
        ]);

        $client = new McpClient($transport, 'test');

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('not initialized');
        $client->listTools();
    }

    public function test_client_is_not_connected_before_init(): void
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://example.com/mcp',
            'env' => [],
            'headers' => [],
        ]);

        $client = new McpClient($transport, 'test');
        $this->assertFalse($client->isConnected());
    }
}
