<?php

namespace App\Services\Settings;

use App\Services\Permissions\PermissionMode;

class SettingsManager
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-20250514';
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    private const DEFAULT_MAX_TOKENS = 16384;
    private const DEFAULT_APPROVAL_POLICY = 'on-request';
    private const DEFAULT_SANDBOX_MODE = 'workspace-write';
    private const DEFAULT_STREAM_MODE = 'final';

    private const DEFAULT_STATUSLINE = [
        'enabled' => true,
        'layout' => 'expanded',
        'path_levels' => 2,
        'show_tools' => true,
        'show_agents' => true,
        'show_todos' => true,
    ];

    private ?array $cachedSettings = null;
    private array $runtimeOverrides = [];

    public function getApiKey(): string
    {
        $settings = $this->loadProjectSettings();
        $providerConfig = $this->getProviderConfig();
        $apiKey = $providerConfig['api_key']
            ?? $settings['api_key']
            ?? config('haocode.api_key')
            ?: getenv('ANTHROPIC_API_KEY')
            ?: '';

        return is_string($apiKey) ? trim($apiKey) : '';
    }

    public function getModel(): string
    {
        $settings = $this->loadProjectSettings();
        $runtimeModel = $this->resolveModelOverride($this->runtimeOverrides['model'] ?? null, $settings);
        $providerConfig = $this->getProviderConfig();
        $settingsModel = $this->resolveModelOverride($settings['model'] ?? null, $settings);

        $model = $runtimeModel
            ?? $providerConfig['model']
            ?? $settingsModel
            ?? config('haocode.model', self::DEFAULT_MODEL);

        if (! is_string($model) || trim($model) === '') {
            $model = self::DEFAULT_MODEL;
        }

        // Kimi's Anthropic-compatible coding endpoint expects its own model name.
        if ($this->isKimiCodingEndpoint() && str_starts_with($model, 'claude-')) {
            return 'kimi-for-coding';
        }

        return $model;
    }

    public function getBaseUrl(): string
    {
        $settings = $this->loadProjectSettings();
        $baseUrl = $this->runtimeOverrides['api_base_url']
            ?? $this->getProviderConfig()['api_base_url']
            ?? $settings['api_base_url']
            ?? config('haocode.api_base_url', self::DEFAULT_BASE_URL);

        return is_string($baseUrl) && trim($baseUrl) !== ''
            ? $baseUrl
            : self::DEFAULT_BASE_URL;
    }

    public function getMaxTokens(): int
    {
        $settings = $this->loadProjectSettings();
        $maxTokens = $this->runtimeOverrides['max_tokens']
            ?? $this->getProviderConfig()['max_tokens']
            ?? $settings['max_tokens']
            ?? config('haocode.max_tokens', self::DEFAULT_MAX_TOKENS);

        return is_numeric($maxTokens) ? (int) $maxTokens : self::DEFAULT_MAX_TOKENS;
    }

    public function getActiveProviderName(): ?string
    {
        return $this->getModelProvider();
    }

    public function getModelProvider(): ?string
    {
        return $this->resolveSelectedProviderName($this->loadProjectSettings());
    }

    /**
     * @return array<string, array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null}>
     */
    public function getConfiguredProviders(): array
    {
        $settings = $this->loadProjectSettings();
        $providers = $this->configuredProvidersFromSettings($settings);
        $normalized = [];

        foreach ($providers as $name => $provider) {
            $normalized[$name] = $this->normalizeProviderConfig($name, $provider);
        }

        return $normalized;
    }

    /**
     * @return array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null}|null
     */
    public function getProviderConfig(?string $name = null): ?array
    {
        $providers = $this->getConfiguredProviders();
        $selected = $name !== null ? $this->normalizeProviderName($name) : $this->getActiveProviderName();

        if ($selected === null || ! array_key_exists($selected, $providers)) {
            return null;
        }

        return $providers[$selected];
    }

    public function getResolvedModelIdentifier(): string
    {
        $settings = $this->loadProjectSettings();
        $provider = $this->getActiveProviderName();
        $runtimeModel = $this->runtimeOverrides['model'] ?? null;
        $settingsModel = $settings['model'] ?? null;
        $runtimeSelection = $this->parseQualifiedModel($runtimeModel, $settings);
        $settingsSelection = $this->parseQualifiedModel($settingsModel, $settings);

        if ($runtimeSelection['provider'] === null && is_string($runtimeModel) && trim($runtimeModel) !== '' && str_contains($runtimeModel, '/')) {
            return trim($runtimeModel);
        }

        if ($provider !== null) {
            return $provider.'/'.$this->getModel();
        }

        if ($settingsSelection['provider'] === null && is_string($settingsModel) && trim($settingsModel) !== '' && str_contains($settingsModel, '/')) {
            return trim($settingsModel);
        }

        return $this->getModel();
    }

    public function getPermissionMode(): PermissionMode
    {
        $settings = $this->loadProjectSettings();

        if (array_key_exists('permission_mode', $this->runtimeOverrides)) {
            return $this->normalizePermissionModeValue($this->runtimeOverrides['permission_mode']);
        }

        if (array_key_exists('approval_policy', $this->runtimeOverrides)
            || array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            return $this->permissionModeFromModernConfig(
                $this->runtimeOverrides['approval_policy'] ?? null,
                $this->runtimeOverrides['sandbox_mode'] ?? null,
            );
        }

        if (array_key_exists('permission_mode', $settings)) {
            return $this->normalizePermissionModeValue($settings['permission_mode']);
        }

        if (array_key_exists('approval_policy', $settings) || array_key_exists('sandbox_mode', $settings)) {
            return $this->permissionModeFromModernConfig(
                $settings['approval_policy'] ?? null,
                $settings['sandbox_mode'] ?? null,
            );
        }

        if (config('haocode.approval_policy') !== null || config('haocode.sandbox_mode') !== null) {
            return $this->permissionModeFromModernConfig(
                config('haocode.approval_policy'),
                config('haocode.sandbox_mode'),
            );
        }

        return $this->normalizePermissionModeValue(config('haocode.permission_mode', PermissionMode::Default->value));
    }

    public function getApprovalPolicy(): string
    {
        $settings = $this->loadProjectSettings();

        if (array_key_exists('approval_policy', $this->runtimeOverrides)) {
            $mode = $this->normalizeApprovalPolicy($this->runtimeOverrides['approval_policy']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('permission_mode', $this->runtimeOverrides)
            || array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
        }

        if (array_key_exists('approval_policy', $settings)) {
            $mode = $this->normalizeApprovalPolicy($settings['approval_policy']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('permission_mode', $settings) || array_key_exists('sandbox_mode', $settings)) {
            return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
        }

        $configApprovalPolicy = $this->normalizeApprovalPolicy(config('haocode.approval_policy'));
        if ($configApprovalPolicy !== null) {
            return $configApprovalPolicy;
        }

        return $this->approvalPolicyFromPermissionMode($this->getPermissionMode());
    }

    public function getSandboxMode(): string
    {
        $settings = $this->loadProjectSettings();

        if (array_key_exists('sandbox_mode', $this->runtimeOverrides)) {
            $mode = $this->normalizeSandboxMode($this->runtimeOverrides['sandbox_mode']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('permission_mode', $this->runtimeOverrides)
            || array_key_exists('approval_policy', $this->runtimeOverrides)) {
            return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
        }

        if (array_key_exists('sandbox_mode', $settings)) {
            $mode = $this->normalizeSandboxMode($settings['sandbox_mode']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('permission_mode', $settings) || array_key_exists('approval_policy', $settings)) {
            return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
        }

        $configSandboxMode = $this->normalizeSandboxMode(config('haocode.sandbox_mode'));
        if ($configSandboxMode !== null) {
            return $configSandboxMode;
        }

        return $this->sandboxModeFromPermissionMode($this->getPermissionMode());
    }

    public function getAppendSystemPrompt(): ?string
    {
        if (array_key_exists('append_system_prompt', $this->runtimeOverrides)) {
            return $this->runtimeOverrides['append_system_prompt'];
        }

        $settings = $this->loadProjectSettings();
        return $settings['append_system_prompt'] ?? null;
    }

    public function getSystemPrompt(): ?string
    {
        if (array_key_exists('system_prompt', $this->runtimeOverrides)) {
            return $this->runtimeOverrides['system_prompt'];
        }

        $settings = $this->loadProjectSettings();

        return $settings['system_prompt'] ?? null;
    }

    public function getAllowRules(): array
    {
        $settings = $this->loadProjectSettings();
        return $settings['permissions']['allow'] ?? [];
    }

    public function getDenyRules(): array
    {
        $settings = $this->loadProjectSettings();
        return $settings['permissions']['deny'] ?? [];
    }

    public function getSessionPath(): string
    {
        return config('haocode.session_path', storage_path('app/haocode/sessions'));
    }

    public function getOutputStyle(): ?string
    {
        return $this->runtimeOverrides['output_style']
            ?? $this->loadProjectSettings()['output_style']
            ?? null;
    }

    public function isStreamOutputEnabled(): bool
    {
        return $this->getStreamMode() === 'coalesced';
    }

    public function getStreamMode(): string
    {
        $settings = $this->loadProjectSettings();

        if (array_key_exists('stream_mode', $this->runtimeOverrides)) {
            $mode = $this->normalizeStreamMode($this->runtimeOverrides['stream_mode']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('stream_output', $this->runtimeOverrides)) {
            return $this->streamModeFromLegacyToggle($this->runtimeOverrides['stream_output']);
        }

        if (array_key_exists('stream_mode', $settings)) {
            $mode = $this->normalizeStreamMode($settings['stream_mode']);
            if ($mode !== null) {
                return $mode;
            }
        }

        if (array_key_exists('stream_output', $settings)) {
            return $this->streamModeFromLegacyToggle($settings['stream_output']);
        }

        $configStreamMode = $this->normalizeStreamMode(config('haocode.stream_mode'));
        if ($configStreamMode !== null) {
            return $configStreamMode;
        }

        return $this->streamModeFromLegacyToggle(config('haocode.stream_output', false));
    }

    public function getTheme(): string
    {
        return $this->runtimeOverrides['theme']
            ?? $this->loadProjectSettings()['theme']
            ?? 'dark';
    }

    public function isStatuslineEnabled(): bool
    {
        return (bool) $this->getStatuslineConfig()['enabled'];
    }

    public function setStatuslineEnabled(bool $enabled): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($enabled) {
            $settings['statusline'] ??= [];
            $settings['statusline']['enabled'] = $enabled;
        });
    }

    /**
     * @return array{
     *   enabled: bool,
     *   layout: string,
     *   path_levels: int,
     *   show_tools: bool,
     *   show_agents: bool,
     *   show_todos: bool
     * }
     */
    public function getStatuslineConfig(): array
    {
        $settings = $this->loadProjectSettings();
        $statusline = is_array($settings['statusline'] ?? null) ? $settings['statusline'] : [];

        return [
            'enabled' => (bool) (
                $this->runtimeOverrides['statusline_enabled']
                ?? $statusline['enabled']
                ?? self::DEFAULT_STATUSLINE['enabled']
            ),
            'layout' => $this->normalizeStatuslineLayout(
                $this->runtimeOverrides['statusline_layout']
                ?? $statusline['layout']
                ?? self::DEFAULT_STATUSLINE['layout']
            ),
            'path_levels' => $this->normalizeStatuslinePathLevels(
                $this->runtimeOverrides['statusline_path_levels']
                ?? $statusline['path_levels']
                ?? self::DEFAULT_STATUSLINE['path_levels']
            ),
            'show_tools' => $this->normalizeStatuslineToggle(
                $this->runtimeOverrides['statusline_show_tools']
                ?? $statusline['show_tools']
                ?? self::DEFAULT_STATUSLINE['show_tools'],
                self::DEFAULT_STATUSLINE['show_tools'],
            ),
            'show_agents' => $this->normalizeStatuslineToggle(
                $this->runtimeOverrides['statusline_show_agents']
                ?? $statusline['show_agents']
                ?? self::DEFAULT_STATUSLINE['show_agents'],
                self::DEFAULT_STATUSLINE['show_agents'],
            ),
            'show_todos' => $this->normalizeStatuslineToggle(
                $this->runtimeOverrides['statusline_show_todos']
                ?? $statusline['show_todos']
                ?? self::DEFAULT_STATUSLINE['show_todos'],
                self::DEFAULT_STATUSLINE['show_todos'],
            ),
        ];
    }

    public function getStatuslineLayout(): string
    {
        return $this->getStatuslineConfig()['layout'];
    }

    public function getStatuslinePathLevels(): int
    {
        return $this->getStatuslineConfig()['path_levels'];
    }

    public function shouldShowStatuslineTools(): bool
    {
        return $this->getStatuslineConfig()['show_tools'];
    }

    public function shouldShowStatuslineAgents(): bool
    {
        return $this->getStatuslineConfig()['show_agents'];
    }

    public function shouldShowStatuslineTodos(): bool
    {
        return $this->getStatuslineConfig()['show_todos'];
    }

    public function setStatuslineLayout(string $layout): void
    {
        $layout = $this->normalizeStatuslineLayout($layout);

        $this->modifyProjectSettings(function (array &$settings) use ($layout) {
            $settings['statusline'] ??= [];
            $settings['statusline']['layout'] = $layout;
        });
    }

    public function setStatuslinePathLevels(int $levels): void
    {
        $levels = $this->normalizeStatuslinePathLevels($levels);

        $this->modifyProjectSettings(function (array &$settings) use ($levels) {
            $settings['statusline'] ??= [];
            $settings['statusline']['path_levels'] = $levels;
        });
    }

    public function setStatuslineSectionVisibility(string $section, bool $enabled): void
    {
        $key = match ($section) {
            'tools' => 'show_tools',
            'agents' => 'show_agents',
            'todos' => 'show_todos',
            default => null,
        };

        if ($key === null) {
            throw new \InvalidArgumentException("Unknown statusline section: {$section}");
        }

        $this->modifyProjectSettings(function (array &$settings) use ($key, $enabled) {
            $settings['statusline'] ??= [];
            $settings['statusline'][$key] = $enabled;
        });
    }

    public function resetStatuslineConfig(): void
    {
        $this->modifyProjectSettings(function (array &$settings) {
            unset($settings['statusline']);
        });
    }

    /**
     * Set a runtime override for a config key.
     */
    public function set(string $key, mixed $value): void
    {
        $allowedKeys = [
            'model',
            'active_provider',
            'model_provider',
            'api_base_url',
            'max_tokens',
            'permission_mode',
            'approval_policy',
            'sandbox_mode',
            'output_style',
            'stream_output',
            'stream_mode',
            'theme',
            'statusline_enabled',
            'statusline_layout',
            'statusline_path_levels',
            'statusline_show_tools',
            'statusline_show_agents',
            'statusline_show_todos',
            'append_system_prompt',
            'system_prompt',
            'thinking_enabled',
            'thinking_budget',
            'vim_mode',
            'effort_level',
        ];
        if (! in_array($key, $allowedKeys, true)) {
            return;
        }

        if ($key === 'active_provider' || $key === 'model_provider') {
            $this->runtimeOverrides['active_provider'] = $value;
            $this->runtimeOverrides['model_provider'] = $value;

            return;
        }

        if ($key === 'permission_mode') {
            unset($this->runtimeOverrides['approval_policy'], $this->runtimeOverrides['sandbox_mode']);
        }

        if ($key === 'approval_policy' || $key === 'sandbox_mode') {
            unset($this->runtimeOverrides['permission_mode']);
        }

        if ($key === 'stream_output') {
            unset($this->runtimeOverrides['stream_mode']);
        }

        if ($key === 'stream_mode') {
            unset($this->runtimeOverrides['stream_output']);
        }

        $this->runtimeOverrides[$key] = $value;
    }

    /**
     * Add an allow rule persistently to project settings.
     */
    public function addAllowRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            if (!in_array($rule, $settings['permissions']['allow'] ?? [], true)) {
                $settings['permissions']['allow'][] = $rule;
            }
        });
    }

    /**
     * Add a deny rule persistently to project settings.
     */
    public function addDenyRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            if (!in_array($rule, $settings['permissions']['deny'] ?? [], true)) {
                $settings['permissions']['deny'][] = $rule;
            }
        });
    }

    /**
     * Remove an allow rule from project settings.
     */
    public function removeAllowRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            $key = array_search($rule, $settings['permissions']['allow'] ?? []);
            if ($key !== false) {
                unset($settings['permissions']['allow'][$key]);
                $settings['permissions']['allow'] = array_values($settings['permissions']['allow']);
            }
        });
    }

    /**
     * Remove a deny rule from project settings.
     */
    public function removeDenyRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            $key = array_search($rule, $settings['permissions']['deny'] ?? []);
            if ($key !== false) {
                unset($settings['permissions']['deny'][$key]);
                $settings['permissions']['deny'] = array_values($settings['permissions']['deny']);
            }
        });
    }

    public function isThinkingEnabled(): bool
    {
        if (array_key_exists('thinking_enabled', $this->runtimeOverrides)) {
            return (bool) $this->runtimeOverrides['thinking_enabled'];
        }

        $envValue = getenv('HAOCODE_THINKING');

        return $envValue !== false && filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
    }

    public function getThinkingBudget(): int
    {
        if (array_key_exists('thinking_budget', $this->runtimeOverrides)) {
            return (int) $this->runtimeOverrides['thinking_budget'];
        }

        $envValue = getenv('HAOCODE_THINKING_BUDGET');

        return $envValue !== false && is_numeric($envValue) ? (int) $envValue : 10000;
    }

    public function getEffortLevel(): string
    {
        return $this->runtimeOverrides['effort_level'] ?? 'auto';
    }

    public function isVimMode(): bool
    {
        return (bool) ($this->runtimeOverrides['vim_mode'] ?? false);
    }

    /**
     * Get all current settings as a flat array.
     */
    public function all(): array
    {
        $statusline = $this->getStatuslineConfig();

        return [
            'model' => $this->getModel(),
            'model_identifier' => $this->getResolvedModelIdentifier(),
            'active_provider' => $this->getActiveProviderName(),
            'configured_providers' => array_keys($this->getConfiguredProviders()),
            'api_base_url' => $this->getBaseUrl(),
            'max_tokens' => $this->getMaxTokens(),
            'permission_mode' => $this->getPermissionMode()->value,
            'theme' => $this->getTheme(),
            'output_style' => $this->getOutputStyle(),
            'stream_output' => $this->isStreamOutputEnabled(),
            'statusline_enabled' => $statusline['enabled'],
            'statusline_layout' => $statusline['layout'],
            'statusline_path_levels' => $statusline['path_levels'],
            'statusline_show_tools' => $statusline['show_tools'],
            'statusline_show_agents' => $statusline['show_agents'],
            'statusline_show_todos' => $statusline['show_todos'],
            'api_key_set' => !empty($this->getApiKey()),
        ];
    }

    /**
     * Get available models.
     */
    public static function getAvailableModels(): array
    {
        return [
            'kimi-for-coding',
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-haiku-4-20250514',
            'claude-3-5-sonnet-20241022',
            'claude-3-5-haiku-20241022',
        ];
    }

    private function isKimiCodingEndpoint(): bool
    {
        $baseUrl = strtolower(rtrim($this->getBaseUrl(), '/'));

        return str_contains($baseUrl, 'api.kimi.com/coding');
    }

    private function loadProjectSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $globalPath = config('haocode.global_settings_path')
            ?? ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()) . '/.haocode/settings.json';
        $projectPath = getcwd() . '/.haocode/settings.json';
        $global = $this->loadSettingsFile($globalPath);
        $project = $this->loadSettingsFile($projectPath);

        $globalPerms = is_array($global['permissions'] ?? null) ? $global['permissions'] : [];
        $projectPerms = is_array($project['permissions'] ?? null) ? $project['permissions'] : [];

        unset($global['permissions'], $project['permissions']);

        $this->cachedSettings = array_merge($global, $project);

        $providers = $this->mergeProviderMaps($global, $project);
        unset($this->cachedSettings['providers']);
        if ($providers !== []) {
            $this->cachedSettings['provider'] = $providers;
        }

        // Permissions accumulate across both files — project rules ADD to global rules
        // rather than replacing them. This prevents silent loss of global deny/allow rules.
        $this->cachedSettings['permissions'] = [
            'allow' => array_merge($globalPerms['allow'] ?? [], $projectPerms['allow'] ?? []),
            'deny'  => array_merge($globalPerms['deny'] ?? [],  $projectPerms['deny'] ?? []),
        ];

        return $this->cachedSettings;
    }

    private function loadSettingsFile(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveSelectedProviderName(array $settings): ?string
    {
        $providers = $this->configuredProvidersFromSettings($settings);
        if ($providers === []) {
            return null;
        }

        $runtimeSelection = $this->parseQualifiedModel($this->runtimeOverrides['model'] ?? null, $settings);
        if ($runtimeSelection['provider'] !== null) {
            return $runtimeSelection['provider'];
        }

        $runtimeProviderValue = array_key_exists('model_provider', $this->runtimeOverrides)
            ? $this->runtimeOverrides['model_provider']
            : (array_key_exists('active_provider', $this->runtimeOverrides)
                ? $this->runtimeOverrides['active_provider']
                : null);

        if (array_key_exists('model_provider', $this->runtimeOverrides)
            || array_key_exists('active_provider', $this->runtimeOverrides)) {
            $runtimeProvider = $this->normalizeProviderName($runtimeProviderValue);
            if ($runtimeProvider !== null && array_key_exists($runtimeProvider, $providers)) {
                return $runtimeProvider;
            }
        } else {
            $settingsProvider = $this->normalizeProviderName(
                $settings['model_provider']
                    ?? $settings['active_provider']
                    ?? config('haocode.model_provider')
                    ?? config('haocode.active_provider')
                    ?? null,
            );
            if ($settingsProvider !== null && array_key_exists($settingsProvider, $providers)) {
                return $settingsProvider;
            }
        }

        $settingsSelection = $this->parseQualifiedModel($settings['model'] ?? null, $settings);
        if ($settingsSelection['provider'] !== null) {
            return $settingsSelection['provider'];
        }

        if (! $this->hasLegacyTopLevelConfig($settings)) {
            return array_key_first($providers);
        }

        return null;
    }

    private function normalizePermissionModeValue(mixed $value): PermissionMode
    {
        if (! is_string($value)) {
            return PermissionMode::Default;
        }

        return PermissionMode::tryFrom($value) ?? PermissionMode::Default;
    }

    private function permissionModeFromModernConfig(mixed $approvalPolicy, mixed $sandboxMode): PermissionMode
    {
        $normalizedSandbox = $this->normalizeSandboxMode($sandboxMode);
        if ($normalizedSandbox === 'read-only') {
            return PermissionMode::Plan;
        }

        if ($normalizedSandbox === 'danger-full-access') {
            return PermissionMode::BypassPermissions;
        }

        $normalizedApproval = $this->normalizeApprovalPolicy($approvalPolicy);
        if ($normalizedApproval === 'never') {
            return PermissionMode::BypassPermissions;
        }

        if ($normalizedApproval === 'on-failure') {
            return PermissionMode::AcceptEdits;
        }

        return PermissionMode::Default;
    }

    private function approvalPolicyFromPermissionMode(PermissionMode $mode): string
    {
        return match ($mode) {
            PermissionMode::AcceptEdits => 'on-failure',
            PermissionMode::BypassPermissions => 'never',
            default => self::DEFAULT_APPROVAL_POLICY,
        };
    }

    private function sandboxModeFromPermissionMode(PermissionMode $mode): string
    {
        return match ($mode) {
            PermissionMode::Plan => 'read-only',
            PermissionMode::BypassPermissions => 'danger-full-access',
            default => self::DEFAULT_SANDBOX_MODE,
        };
    }

    private function streamModeFromLegacyToggle(mixed $value): string
    {
        return $this->normalizeStatuslineToggle($value, false)
            ? 'coalesced'
            : self::DEFAULT_STREAM_MODE;
    }

    private function normalizeApprovalPolicy(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'untrusted', 'on-request', 'on-failure', 'never' => strtolower(trim($value)),
            default => null,
        };
    }

    private function normalizeSandboxMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'read-only', 'workspace-write', 'danger-full-access' => strtolower(trim($value)),
            default => null,
        };
    }

    private function normalizeStreamMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'final', 'off', 'disabled' => 'final',
            'coalesced', 'stream', 'streaming', 'live', 'on', 'enabled' => 'coalesced',
            default => null,
        };
    }

    private function resolveModelOverride(mixed $value, array $settings): ?string
    {
        $selection = $this->parseQualifiedModel($value, $settings);

        return $selection['model'];
    }

    /**
     * @return array{provider: string|null, model: string|null}
     */
    private function parseQualifiedModel(mixed $value, array $settings): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['provider' => null, 'model' => null];
        }

        $model = trim($value);
        if (! str_contains($model, '/')) {
            return ['provider' => null, 'model' => $model];
        }

        [$candidateProvider, $candidateModel] = explode('/', $model, 2);
        $candidateProvider = $this->normalizeProviderName($candidateProvider);
        $candidateModel = trim($candidateModel);

        if ($candidateProvider !== null
            && $candidateModel !== ''
            && array_key_exists($candidateProvider, $this->configuredProvidersFromSettings($settings))) {
            return ['provider' => $candidateProvider, 'model' => $candidateModel];
        }

        return ['provider' => null, 'model' => $model];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredProvidersFromSettings(array $settings): array
    {
        $providers = [];

        foreach (['provider', 'providers'] as $key) {
            $raw = $settings[$key] ?? null;
            if (! is_array($raw)) {
                continue;
            }

            foreach ($raw as $name => $provider) {
                $normalizedName = $this->normalizeProviderName($name);
                if ($normalizedName === null || ! is_array($provider)) {
                    continue;
                }

                $providers[$normalizedName] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergeProviderMaps(array $global, array $project): array
    {
        return array_replace_recursive(
            $this->configuredProvidersFromSettings($global),
            $this->configuredProvidersFromSettings($project),
        );
    }

    /**
     * @param array<string, mixed> $provider
     * @return array{api_key: string|null, api_base_url: string|null, model: string|null, max_tokens: int|null}
     */
    private function normalizeProviderConfig(string $name, array $provider): array
    {
        $options = is_array($provider['options'] ?? null) ? $provider['options'] : [];

        return [
            'api_key' => $this->firstNonEmptyString(
                $provider['api_key'] ?? null,
                $provider['apiKey'] ?? null,
                $options['apiKey'] ?? null,
                $options['api_key'] ?? null,
            ),
            'api_base_url' => $this->firstNonEmptyString(
                $provider['api_base_url'] ?? null,
                $provider['apiBaseUrl'] ?? null,
                $provider['base_url'] ?? null,
                $provider['baseURL'] ?? null,
                $options['baseURL'] ?? null,
                $options['base_url'] ?? null,
                $options['apiBaseUrl'] ?? null,
            ),
            'model' => $this->firstNonEmptyString(
                $provider['model'] ?? null,
                $provider['default_model'] ?? null,
                $provider['defaultModel'] ?? null,
            ),
            'max_tokens' => $this->firstNumericValue(
                $provider['max_tokens'] ?? null,
                $provider['maxTokens'] ?? null,
                $options['maxTokens'] ?? null,
                $options['max_tokens'] ?? null,
            ),
        ];
    }

    private function hasLegacyTopLevelConfig(array $settings): bool
    {
        $model = $this->parseQualifiedModel($settings['model'] ?? null, $settings);

        return $this->firstNonEmptyString($settings['api_key'] ?? null) !== null
            || $this->firstNonEmptyString($settings['api_base_url'] ?? null) !== null
            || ($model['provider'] === null && $model['model'] !== null);
    }

    private function normalizeProviderName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = trim($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function firstNumericValue(mixed ...$values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Modify project settings file and invalidate cache.
     */
    private function modifyProjectSettings(callable $modifier): void
    {
        $projectPath = getcwd() . '/.haocode/settings.json';

        $settings = [];
        if (file_exists($projectPath)) {
            $settings = json_decode(file_get_contents($projectPath), true) ?: [];
        }

        if (!isset($settings['permissions'])) {
            $settings['permissions'] = ['allow' => [], 'deny' => []];
        }

        $modifier($settings);

        $dir = dirname($projectPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($projectPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Invalidate cache
        $this->cachedSettings = null;
    }

    private function normalizeStatuslineLayout(mixed $layout): string
    {
        if (! is_string($layout)) {
            return self::DEFAULT_STATUSLINE['layout'];
        }

        $normalized = strtolower(trim($layout));

        return in_array($normalized, ['expanded', 'compact'], true)
            ? $normalized
            : self::DEFAULT_STATUSLINE['layout'];
    }

    private function normalizeStatuslinePathLevels(mixed $levels): int
    {
        if (! is_int($levels) && ! is_numeric($levels)) {
            return self::DEFAULT_STATUSLINE['path_levels'];
        }

        return max(1, min(3, (int) $levels));
    }

    private function normalizeStatuslineToggle(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return $default;
    }
}
