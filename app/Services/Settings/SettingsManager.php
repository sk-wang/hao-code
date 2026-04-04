<?php

namespace App\Services\Settings;

use App\Services\Permissions\PermissionMode;

class SettingsManager
{
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
        $apiKey = $settings['api_key']
            ?? config('haocode.api_key')
            ?: getenv('ANTHROPIC_API_KEY')
            ?: '';

        return is_string($apiKey) ? $apiKey : '';
    }

    public function getModel(): string
    {
        $settings = $this->loadProjectSettings();
        $model = $this->runtimeOverrides['model']
            ?? $settings['model']
            ?? config('haocode.model', 'claude-sonnet-4-20250514');

        if (! is_string($model) || trim($model) === '') {
            $model = 'claude-sonnet-4-20250514';
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
            ?? $settings['api_base_url']
            ?? config('haocode.api_base_url', 'https://api.anthropic.com');

        return is_string($baseUrl) && trim($baseUrl) !== ''
            ? $baseUrl
            : 'https://api.anthropic.com';
    }

    public function getMaxTokens(): int
    {
        $settings = $this->loadProjectSettings();
        $maxTokens = $this->runtimeOverrides['max_tokens']
            ?? $settings['max_tokens']
            ?? config('haocode.max_tokens', 16384);

        return is_numeric($maxTokens) ? (int) $maxTokens : 16384;
    }

    public function getPermissionMode(): PermissionMode
    {
        $settings = $this->loadProjectSettings();
        $mode = $this->runtimeOverrides['permission_mode']
            ?? $settings['permission_mode']
            ?? config('haocode.permission_mode', 'default');

        if (! is_string($mode)) {
            return PermissionMode::Default;
        }

        return PermissionMode::tryFrom($mode) ?? PermissionMode::Default;
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
            'api_base_url',
            'max_tokens',
            'permission_mode',
            'output_style',
            'theme',
            'statusline_enabled',
            'statusline_layout',
            'statusline_path_levels',
            'statusline_show_tools',
            'statusline_show_agents',
            'statusline_show_todos',
            'append_system_prompt',
            'system_prompt',
        ];
        if (in_array($key, $allowedKeys)) {
            $this->runtimeOverrides[$key] = $value;
        }
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

    /**
     * Get all current settings as a flat array.
     */
    public function all(): array
    {
        $statusline = $this->getStatuslineConfig();

        return [
            'model' => $this->getModel(),
            'api_base_url' => $this->getBaseUrl(),
            'max_tokens' => $this->getMaxTokens(),
            'permission_mode' => $this->getPermissionMode()->value,
            'theme' => $this->getTheme(),
            'output_style' => $this->getOutputStyle(),
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

        $this->cachedSettings = [];
        $globalPerms = [];

        $globalPath = config('haocode.global_settings_path')
            ?? ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()) . '/.haocode/settings.json';

        if (file_exists($globalPath)) {
            $global = json_decode(file_get_contents($globalPath), true) ?: [];
            $globalPerms = $global['permissions'] ?? [];
            unset($global['permissions']);
            $this->cachedSettings = array_merge($this->cachedSettings, $global);
        }

        $projectPerms = [];
        $projectPath = getcwd() . '/.haocode/settings.json';
        if (file_exists($projectPath)) {
            $project = json_decode(file_get_contents($projectPath), true) ?: [];
            $projectPerms = $project['permissions'] ?? [];
            unset($project['permissions']);
            $this->cachedSettings = array_merge($this->cachedSettings, $project);
        }

        // Permissions accumulate across both files — project rules ADD to global rules
        // rather than replacing them. This prevents silent loss of global deny/allow rules.
        $this->cachedSettings['permissions'] = [
            'allow' => array_merge($globalPerms['allow'] ?? [], $projectPerms['allow'] ?? []),
            'deny'  => array_merge($globalPerms['deny'] ?? [],  $projectPerms['deny'] ?? []),
        ];

        return $this->cachedSettings;
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
