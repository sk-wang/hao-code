<?php

namespace App\Services\Settings;

use App\Services\Permissions\PermissionMode;

class SettingsManager
{
    private ?array $cachedSettings = null;
    private array $runtimeOverrides = [];

    public function getApiKey(): string
    {
        return config('haocode.api_key') ?: getenv('ANTHROPIC_API_KEY') ?: '';
    }

    public function getModel(): string
    {
        return $this->runtimeOverrides['model']
            ?? config('haocode.model', 'claude-sonnet-4-20250514');
    }

    public function getBaseUrl(): string
    {
        return $this->runtimeOverrides['api_base_url']
            ?? config('haocode.api_base_url', 'https://api.anthropic.com');
    }

    public function getMaxTokens(): int
    {
        return $this->runtimeOverrides['max_tokens']
            ?? config('haocode.max_tokens', 16384);
    }

    public function getPermissionMode(): PermissionMode
    {
        $mode = $this->runtimeOverrides['permission_mode']
            ?? config('haocode.permission_mode', 'default');
        return PermissionMode::tryFrom($mode) ?? PermissionMode::Default;
    }

    public function getAppendSystemPrompt(): ?string
    {
        $settings = $this->loadProjectSettings();
        return $settings['append_system_prompt'] ?? null;
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

    /**
     * Set a runtime override for a config key.
     */
    public function set(string $key, mixed $value): void
    {
        $allowedKeys = ['model', 'api_base_url', 'max_tokens', 'permission_mode', 'output_style', 'theme'];
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
            $settings['permissions']['allow'][]= $rule;
        });
    }

    /**
     * Add a deny rule persistently to project settings.
     */
    public function addDenyRule(string $rule): void
    {
        $this->modifyProjectSettings(function (array &$settings) use ($rule) {
            $settings['permissions']['deny'][]= $rule;
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
        return [
            'model' => $this->getModel(),
            'api_base_url' => $this->getBaseUrl(),
            'max_tokens' => $this->getMaxTokens(),
            'permission_mode' => $this->getPermissionMode()->value,
            'api_key_set' => !empty($this->getApiKey()),
        ];
    }

    /**
     * Get available models.
     */
    public static function getAvailableModels(): array
    {
        return [
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-haiku-4-20250514',
            'claude-3-5-sonnet-20241022',
            'claude-3-5-haiku-20241022',
        ];
    }

    private function loadProjectSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $this->cachedSettings = [];

        $globalPath = config('haocode.global_settings_path')
            ?? ($_SERVER['HOME'] ?? '~') . '/.haocode/settings.json';

        if (file_exists($globalPath)) {
            $global = json_decode(file_get_contents($globalPath), true) ?: [];
            $this->cachedSettings = array_merge($this->cachedSettings, $global);
        }

        $projectPath = getcwd() . '/.haocode/settings.json';
        if (file_exists($projectPath)) {
            $project = json_decode(file_get_contents($projectPath), true) ?: [];
            $this->cachedSettings = array_merge($this->cachedSettings, $project);
        }

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
}
