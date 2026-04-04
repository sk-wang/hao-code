<?php

namespace App\Tools\Config;

use App\Services\Settings\SettingsManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class ConfigTool extends BaseTool
{
    public function name(): string
    {
        return 'Config';
    }

    public function description(): string
    {
        return <<<DESC
Get or set runtime configuration values. Supported keys: model, active_provider, api_base_url, max_tokens, permission_mode, theme, output_style, stream_output.

Usage:
- To get all settings: call with no arguments
- To get a specific key: call with key only
- To set a value: call with key and value

This tool takes effect immediately for the current session.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'The config key to get or set',
                    'enum' => ['model', 'active_provider', 'api_base_url', 'max_tokens', 'permission_mode', 'theme', 'output_style', 'stream_output'],
                ],
                'value' => [
                    'type' => ['string', 'null'],
                    'description' => 'The value to set (omit to get current value)',
                ],
            ],
        ], [
            'key' => 'nullable|string|in:model,active_provider,api_base_url,max_tokens,permission_mode,theme,output_style,stream_output',
            'value' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        /** @var SettingsManager $settings */
        $settings = app(SettingsManager::class);
        $key = $input['key'] ?? null;
        $value = $input['value'] ?? null;

        // Get all settings
        if ($key === null) {
            $all = $settings->all();
            $lines = [];
            foreach ($all as $k => $v) {
                $lines[] = "  {$k}: ".$this->displayValue($v);
            }
            return ToolResult::success("Current settings:\n" . implode("\n", $lines));
        }

        // Get specific key
        if ($value === null) {
            $all = $settings->all();
            $current = array_key_exists($key, $all) ? $all[$key] : 'unknown';
            return ToolResult::success("{$key} = ".$this->displayValue($current));
        }

        if ($key === 'active_provider') {
            $normalizedValue = is_string($value) && in_array(strtolower(trim($value)), ['off', 'none', 'clear'], true)
                ? null
                : trim((string) $value);

            if ($normalizedValue !== null) {
                $providers = array_keys($settings->getConfiguredProviders());
                if ($providers === []) {
                    return ToolResult::error('No providers are configured. Add a "provider" object to your settings.json first.');
                }

                if (! in_array($normalizedValue, $providers, true)) {
                    return ToolResult::error('Unknown provider: '.$normalizedValue.'. Available: '.implode(', ', $providers));
                }
            }

            $settings->set('active_provider', $normalizedValue);

            return ToolResult::success('Set active_provider = '.$this->displayValue($normalizedValue));
        }

        if ($key === 'stream_output') {
            $normalizedValue = $this->normalizeBooleanValue((string) $value);

            if ($normalizedValue === null) {
                return ToolResult::error('Invalid stream_output. Must be true/false, on/off, yes/no, or 1/0');
            }

            $settings->set('stream_output', $normalizedValue);

            return ToolResult::success('Set stream_output = '.$this->displayValue($normalizedValue));
        }

        // Validate and set
        $error = $this->validateValue($key, $value);
        if ($error !== null) {
            return ToolResult::error($error);
        }

        $settings->set(
            $key,
            $key === 'output_style' && in_array(strtolower($value), ['off', 'none'], true) ? null : $value,
        );

        return ToolResult::success("Set {$key} = ".$this->displayValue(
            $key === 'output_style' && in_array(strtolower($value), ['off', 'none'], true) ? null : $value,
        ));
    }

    private function validateValue(string $key, string $value): ?string
    {
        return match ($key) {
            'model' => null, // Accept any model string
            'active_provider' => null,
            'api_base_url' => filter_var($value, FILTER_VALIDATE_URL) ? null : "Invalid URL: {$value}",
            'max_tokens' => is_numeric($value) && (int) $value > 0 ? null : "max_tokens must be a positive integer",
            'permission_mode' => in_array($value, ['default', 'plan', 'accept_edits', 'bypass_permissions'])
                ? null
                : "Invalid permission mode. Must be: default, plan, accept_edits, or bypass_permissions",
            'theme' => in_array($value, ['dark', 'light', 'ansi'], true)
                ? null
                : "Invalid theme. Must be: dark, light, or ansi",
            'output_style' => null,
            'stream_output' => $this->normalizeBooleanValue($value) !== null
                ? null
                : 'Invalid stream_output. Must be true/false, on/off, yes/no, or 1/0',
            default => "Unknown config key: {$key}",
        };
    }

    private function normalizeBooleanValue(string $value): ?bool
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return 'off';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unknown';
    }

    public function isReadOnly(array $input): bool
    {
        return !isset($input['value']);
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
