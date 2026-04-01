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
Get or set runtime configuration values. Supported keys: model, api_base_url, max_tokens, permission_mode.

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
                    'enum' => ['model', 'api_base_url', 'max_tokens', 'permission_mode'],
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'The value to set (omit to get current value)',
                ],
            ],
        ], [
            'key' => 'nullable|string|in:model,api_base_url,max_tokens,permission_mode',
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
                $lines[] = "  {$k}: {$v}";
            }
            return ToolResult::success("Current settings:\n" . implode("\n", $lines));
        }

        // Get specific key
        if ($value === null) {
            $all = $settings->all();
            $current = $all[$key] ?? 'unknown';
            return ToolResult::success("{$key} = {$current}");
        }

        // Validate and set
        $error = $this->validateValue($key, $value);
        if ($error !== null) {
            return ToolResult::error($error);
        }

        $settings->set($key, $value);

        return ToolResult::success("Set {$key} = {$value}");
    }

    private function validateValue(string $key, string $value): ?string
    {
        return match ($key) {
            'model' => null, // Accept any model string
            'api_base_url' => filter_var($value, FILTER_VALIDATE_URL) ? null : "Invalid URL: {$value}",
            'max_tokens' => is_numeric($value) && (int) $value > 0 ? null : "max_tokens must be a positive integer",
            'permission_mode' => in_array($value, ['default', 'plan', 'accept_edits', 'bypass_permissions'])
                ? null
                : "Invalid permission mode. Must be: default, plan, accept_edits, or bypass_permissions",
            default => "Unknown config key: {$key}",
        };
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
