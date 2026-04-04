<?php

namespace Tests\Unit;

use App\Services\Settings\SettingsManager;
use App\Tools\Config\ConfigTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ConfigToolTest extends TestCase
{
    private ToolUseContext $context;
    private ConfigTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ConfigTool;
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── validateValue via reflection ────────────────────────────────────

    private function validateValue(string $key, string $value): ?string
    {
        $ref = new \ReflectionClass($this->tool);
        $method = $ref->getMethod('validateValue');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $key, $value);
    }

    // model: accepts any string
    public function test_validate_model_accepts_any_string(): void
    {
        $this->assertNull($this->validateValue('model', 'claude-opus-4'));
        $this->assertNull($this->validateValue('model', 'some-random-model'));
    }

    public function test_validate_active_provider_accepts_any_string(): void
    {
        $this->assertNull($this->validateValue('active_provider', 'zai'));
    }

    // api_base_url: must be a valid URL
    public function test_validate_api_base_url_accepts_valid_url(): void
    {
        $this->assertNull($this->validateValue('api_base_url', 'https://api.anthropic.com'));
    }

    public function test_validate_api_base_url_rejects_invalid_url(): void
    {
        $error = $this->validateValue('api_base_url', 'not-a-url');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Invalid URL', $error);
    }

    public function test_validate_api_base_url_accepts_http_url(): void
    {
        $this->assertNull($this->validateValue('api_base_url', 'http://localhost:8080'));
    }

    // max_tokens: must be a positive integer
    public function test_validate_max_tokens_accepts_positive_integer(): void
    {
        $this->assertNull($this->validateValue('max_tokens', '8192'));
    }

    public function test_validate_max_tokens_rejects_zero(): void
    {
        $error = $this->validateValue('max_tokens', '0');
        $this->assertNotNull($error);
        $this->assertStringContainsString('positive integer', $error);
    }

    public function test_validate_max_tokens_rejects_negative(): void
    {
        $error = $this->validateValue('max_tokens', '-100');
        $this->assertNotNull($error);
    }

    public function test_validate_max_tokens_rejects_non_numeric(): void
    {
        $error = $this->validateValue('max_tokens', 'lots');
        $this->assertNotNull($error);
    }

    // permission_mode: must be one of the valid modes
    public function test_validate_permission_mode_accepts_valid_modes(): void
    {
        foreach (['default', 'plan', 'accept_edits', 'bypass_permissions'] as $mode) {
            $this->assertNull($this->validateValue('permission_mode', $mode), "Mode {$mode} should be valid");
        }
    }

    public function test_validate_permission_mode_rejects_unknown(): void
    {
        $error = $this->validateValue('permission_mode', 'superuser');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Invalid permission mode', $error);
    }

    public function test_validate_theme_accepts_known_values(): void
    {
        foreach (['dark', 'light', 'ansi'] as $theme) {
            $this->assertNull($this->validateValue('theme', $theme));
        }
    }

    public function test_validate_theme_rejects_unknown_values(): void
    {
        $error = $this->validateValue('theme', 'solarized');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Invalid theme', $error);
    }

    public function test_validate_output_style_accepts_arbitrary_values(): void
    {
        $this->assertNull($this->validateValue('output_style', 'terse'));
        $this->assertNull($this->validateValue('output_style', 'off'));
    }

    public function test_validate_stream_output_accepts_boolean_like_values(): void
    {
        foreach (['true', 'false', 'on', 'off', 'yes', 'no', '1', '0'] as $value) {
            $this->assertNull($this->validateValue('stream_output', $value), "Value {$value} should be valid");
        }
    }

    public function test_validate_stream_output_rejects_unknown_values(): void
    {
        $error = $this->validateValue('stream_output', 'maybe');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Invalid stream_output', $error);
    }

    // unknown key
    public function test_validate_unknown_key_returns_error(): void
    {
        $error = $this->validateValue('unknown_setting', 'anything');
        $this->assertNotNull($error);
        $this->assertStringContainsString('Unknown config key', $error);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_read_only_when_no_value(): void
    {
        $this->assertTrue($this->tool->isReadOnly(['key' => 'model']));
    }

    public function test_is_read_only_when_no_input(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_is_not_read_only_when_value_present(): void
    {
        $this->assertFalse($this->tool->isReadOnly(['key' => 'model', 'value' => 'gpt-4']));
    }

    // ─── call() — uses SettingsManager mock ──────────────────────────────

    private function makeToolWithSettings(SettingsManager $settings): ConfigTool
    {
        // Bind mock to the container
        app()->instance(SettingsManager::class, $settings);
        return new ConfigTool;
    }

    public function test_call_get_all_returns_current_settings(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('all')->willReturn(['model' => 'claude-opus-4', 'max_tokens' => '16384']);
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call([], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('model', $result->output);
        $this->assertStringContainsString('claude-opus-4', $result->output);
    }

    public function test_call_get_specific_key(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('all')->willReturn(['model' => 'test-model']);
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'model'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('model', $result->output);
        $this->assertStringContainsString('test-model', $result->output);
    }

    public function test_call_set_valid_value_calls_settings_set(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('model', 'claude-haiku-3');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'model', 'value' => 'claude-haiku-3'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set model', $result->output);
    }

    public function test_call_set_active_provider_calls_settings_set(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('getConfiguredProviders')
            ->willReturn([
                'zai' => ['model' => 'glm-5.1'],
            ]);
        $settings->expects($this->once())
            ->method('set')
            ->with('active_provider', 'zai');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'active_provider', 'value' => 'zai'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set active_provider = zai', $result->output);
    }

    public function test_call_set_active_provider_rejects_unknown_provider(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('getConfiguredProviders')
            ->willReturn([
                'anthropic' => ['model' => 'claude-sonnet-4-20250514'],
            ]);
        $settings->expects($this->never())->method('set');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'active_provider', 'value' => 'zai'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown provider', $result->output);
    }

    public function test_call_set_stream_output_normalizes_to_boolean(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('stream_output', true);
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'stream_output', 'value' => 'on'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set stream_output = true', $result->output);
    }

    public function test_call_setting_active_provider_off_stores_null(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->never())->method('getConfiguredProviders');
        $settings->expects($this->once())
            ->method('set')
            ->with('active_provider', null);
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'active_provider', 'value' => 'off'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set active_provider = off', $result->output);
    }

    public function test_call_set_invalid_value_returns_error(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->never())->method('set');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'max_tokens', 'value' => '-5'], $this->context);

        $this->assertTrue($result->isError);
    }

    public function test_call_set_invalid_permission_mode_returns_error(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->never())->method('set');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'permission_mode', 'value' => 'root'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid permission mode', $result->output);
    }

    public function test_call_set_theme_calls_settings_set(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('theme', 'light');
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'theme', 'value' => 'light'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set theme = light', $result->output);
    }

    public function test_call_setting_output_style_off_stores_null(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('output_style', null);
        $tool = $this->makeToolWithSettings($settings);

        $result = $tool->call(['key' => 'output_style', 'value' => 'off'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Set output_style = off', $result->output);
    }

    // ─── tool metadata ────────────────────────────────────────────────────

    public function test_name_is_config(): void
    {
        $this->assertSame('Config', $this->tool->name());
    }

    public function test_description_is_not_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }
}
