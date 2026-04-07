<?php

namespace App\Services\Mcp;

use App\Support\Config\Config;

class McpServerConfigManager
{
    /**
     * @return array{global: string, project: string}
     */
    public function paths(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return [
            'global' => Config::get('global_settings_path') ?: $home.'/.haocode/settings.json',
            'project' => getcwd().'/.haocode/settings.json',
        ];
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>
     * }>
     */
    public function listServers(): array
    {
        $merged = [];

        foreach ($this->paths() as $scope => $path) {
            foreach ($this->readServersFromFile($path) as $name => $definition) {
                $merged[$name] = $this->normalizeServerDefinition($name, $definition, $scope, $path);
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @return array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>
     * }|null
     */
    public function getServer(string $name): ?array
    {
        foreach ($this->listServers() as $server) {
            if ($server['name'] === $name) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function addServer(string $name, array $definition, string $scope = 'project'): void
    {
        $path = $this->pathForScope($scope);
        $settings = $this->readSettingsFile($path);
        $settings['mcp_servers'] ??= [];
        $settings['mcp_servers'][$name] = $definition;

        $this->writeSettingsFile($path, $settings);
    }

    public function removeServer(string $name, string $scope = 'all'): int
    {
        $removed = 0;

        foreach ($this->targetScopes($scope) as $targetScope) {
            $path = $this->pathForScope($targetScope);
            $settings = $this->readSettingsFile($path);
            if (! isset($settings['mcp_servers'][$name])) {
                continue;
            }

            unset($settings['mcp_servers'][$name]);
            $this->writeSettingsFile($path, $settings);
            $removed++;
        }

        return $removed;
    }

    public function setEnabled(string $name, bool $enabled, string $scope = 'all'): int
    {
        $updated = 0;

        foreach ($this->targetScopes($scope) as $targetScope) {
            $path = $this->pathForScope($targetScope);
            $settings = $this->readSettingsFile($path);
            if (! isset($settings['mcp_servers']) || ! is_array($settings['mcp_servers'])) {
                continue;
            }

            $names = $name === 'all'
                ? array_keys($settings['mcp_servers'])
                : [$name];

            $didChange = false;
            foreach ($names as $serverName) {
                if (! isset($settings['mcp_servers'][$serverName]) || ! is_array($settings['mcp_servers'][$serverName])) {
                    continue;
                }

                $settings['mcp_servers'][$serverName]['enabled'] = $enabled;
                $updated++;
                $didChange = true;
            }

            if ($didChange) {
                $this->writeSettingsFile($path, $settings);
            }
        }

        return $updated;
    }

    private function pathForScope(string $scope): string
    {
        $paths = $this->paths();

        return $paths[$scope] ?? $paths['project'];
    }

    /**
     * @return array<int, string>
     */
    private function targetScopes(string $scope): array
    {
        if ($scope === 'global' || $scope === 'project') {
            return [$scope];
        }

        return ['global', 'project'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readServersFromFile(string $path): array
    {
        $settings = $this->readSettingsFile($path);
        $servers = $settings['mcp_servers'] ?? [];

        return is_array($servers) ? array_filter($servers, 'is_array') : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettingsFile(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSettingsFile(string $path, array $settings): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>
     * }
     */
    private function normalizeServerDefinition(string $name, array $definition, string $scope, string $path): array
    {
        $transport = is_string($definition['transport'] ?? null)
            ? strtolower($definition['transport'])
            : $this->inferTransport($definition);

        return [
            'name' => $name,
            'scope' => $scope,
            'path' => $path,
            'enabled' => (bool) ($definition['enabled'] ?? true),
            'transport' => $transport,
            'command' => is_string($definition['command'] ?? null) ? $definition['command'] : null,
            'args' => array_values(array_filter($definition['args'] ?? [], 'is_string')),
            'url' => is_string($definition['url'] ?? null) ? $definition['url'] : null,
            'env' => $this->normalizeStringMap($definition['env'] ?? []),
            'headers' => $this->normalizeStringMap($definition['headers'] ?? []),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $mapValue) {
            if (is_scalar($mapValue)) {
                $normalized[(string) $key] = (string) $mapValue;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function inferTransport(array $definition): string
    {
        if (is_string($definition['url'] ?? null)) {
            $url = strtolower($definition['url']);

            if (str_ends_with($url, '/sse')) {
                return 'sse';
            }

            return 'http';
        }

        return 'stdio';
    }
}
