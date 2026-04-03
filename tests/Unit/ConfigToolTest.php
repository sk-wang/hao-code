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
