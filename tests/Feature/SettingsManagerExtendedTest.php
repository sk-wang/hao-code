<?php

namespace Tests\Feature;

use App\Services\Permissions\PermissionMode;
use App\Services\Settings\SettingsManager;
use Tests\TestCase;

class SettingsManagerExtendedTest extends TestCase
{
    private function makeManager(): SettingsManager
    {
        // Pin config to known defaults so tests are isolated from the user's environment
        config([
            'haocode.model'           => 'claude-sonnet-4-20250514',
            'haocode.api_base_url'    => 'https://api.anthropic.com',
            'haocode.max_tokens'      => 16384,
            'haocode.permission_mode' => 'default',
        ]);

        // Return a fresh manager with no disk interactions (cachedSettings = [])
        $manager = new SettingsManager;
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('cachedSettings');
        $prop->setAccessible(true);
        $prop->setValue($manager, []); // empty — no file settings
        return $manager;
    }

    // ─── set() / runtime overrides ────────────────────────────────────────

    public function test_set_model_overrides_config(): void
    {
        $manager = $this->makeManager();
        $manager->set('model', 'claude-opus-4');
        $this->assertSame('claude-opus-4', $manager->getModel());
    }

    public function test_set_api_base_url_overrides_config(): void
    {
        $manager = $this->makeManager();
        $manager->set('api_base_url', 'https://my.proxy.com');
        $this->assertSame('https://my.proxy.com', $manager->getBaseUrl());
    }

    public function test_set_max_tokens_overrides_config(): void
    {
        $manager = $this->makeManager();
        $manager->set('max_tokens', '32768');
        $this->assertSame(32768, $manager->getMaxTokens());
    }

    public function test_set_permission_mode_overrides_config(): void
    {
        $manager = $this->makeManager();
        $manager->set('permission_mode', 'plan');
        $this->assertSame(PermissionMode::Plan, $manager->getPermissionMode());
    }

    public function test_set_output_style_overrides(): void
    {
        $manager = $this->makeManager();
        $manager->set('output_style', 'terse');
        $this->assertSame('terse', $manager->getOutputStyle());
    }

    public function test_set_append_and_system_prompt_overrides(): void
    {
        $manager = $this->makeManager();
        $manager->set('append_system_prompt', 'append me');
        $manager->set('system_prompt', 'replace me');

        $this->assertSame('append me', $manager->getAppendSystemPrompt());
        $this->assertSame('replace me', $manager->getSystemPrompt());
    }

    public function test_set_unknown_key_is_silently_ignored(): void
    {
        $manager = $this->makeManager();
        $manager->set('totally_unknown_key', 'value');
        // Should not throw or cause issues
        $this->assertSame('claude-sonnet-4-20250514', $manager->getModel());
    }

    // ─── getPermissionMode() ──────────────────────────────────────────────

    public function test_permission_mode_defaults_to_default(): void
    {
        $manager = $this->makeManager();
        $this->assertSame(PermissionMode::Default, $manager->getPermissionMode());
    }

    public function test_permission_mode_returns_correct_enum_for_bypass(): void
    {
        $manager = $this->makeManager();
        $manager->set('permission_mode', 'bypass_permissions');
        $this->assertSame(PermissionMode::BypassPermissions, $manager->getPermissionMode());
    }

    public function test_permission_mode_falls_back_to_default_for_invalid_value(): void
    {
        $manager = $this->makeManager();
        // Inject an invalid value directly into runtime overrides
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('runtimeOverrides');
        $prop->setAccessible(true);
        $prop->setValue($manager, ['permission_mode' => 'totally_invalid']);

        $this->assertSame(PermissionMode::Default, $manager->getPermissionMode());
    }

    // ─── getModel() / Kimi endpoint detection ────────────────────────────

    public function test_kimi_endpoint_remaps_claude_model(): void
    {
        $manager = $this->makeManager();
        $manager->set('api_base_url', 'https://api.kimi.com/coding/v1');
        $manager->set('model', 'claude-sonnet-4-20250514');
        $this->assertSame('kimi-for-coding', $manager->getModel());
    }

    public function test_kimi_endpoint_does_not_remap_non_claude_model(): void
    {
        $manager = $this->makeManager();
        $manager->set('api_base_url', 'https://api.kimi.com/coding/v1');
        $manager->set('model', 'kimi-for-coding');
        $this->assertSame('kimi-for-coding', $manager->getModel());
    }

    public function test_non_kimi_endpoint_does_not_remap_model(): void
    {
        $manager = $this->makeManager();
        $manager->set('api_base_url', 'https://api.anthropic.com');
        $manager->set('model', 'claude-opus-4');
        $this->assertSame('claude-opus-4', $manager->getModel());
    }

    // ─── all() ────────────────────────────────────────────────────────────

    public function test_all_returns_all_required_keys(): void
    {
        $manager = $this->makeManager();
        $all = $manager->all();

        $this->assertArrayHasKey('model', $all);
        $this->assertArrayHasKey('api_base_url', $all);
        $this->assertArrayHasKey('max_tokens', $all);
        $this->assertArrayHasKey('permission_mode', $all);
        $this->assertArrayHasKey('theme', $all);
        $this->assertArrayHasKey('output_style', $all);
        $this->assertArrayHasKey('statusline_enabled', $all);
        $this->assertArrayHasKey('api_key_set', $all);
    }

    public function test_all_reflects_runtime_overrides(): void
    {
        $manager = $this->makeManager();
        $manager->set('model', 'my-custom-model');
        $all = $manager->all();
        $this->assertSame('my-custom-model', $all['model']);
    }

    public function test_all_permission_mode_is_string_value(): void
    {
        $manager = $this->makeManager();
        $all = $manager->all();
        $this->assertIsString($all['permission_mode']);
        $this->assertSame('default', $all['permission_mode']);
    }

    // ─── getAvailableModels() ────────────────────────────────────────────

    public function test_get_available_models_returns_array(): void
    {
        $models = SettingsManager::getAvailableModels();
        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
    }

    public function test_available_models_includes_kimi(): void
    {
        $models = SettingsManager::getAvailableModels();
        $this->assertContains('kimi-for-coding', $models);
    }

    public function test_available_models_includes_claude_sonnet(): void
    {
        $models = SettingsManager::getAvailableModels();
        $hasClaudeSonnet = false;
        foreach ($models as $m) {
            if (str_contains($m, 'claude') && str_contains($m, 'sonnet')) {
                $hasClaudeSonnet = true;
                break;
            }
        }
        $this->assertTrue($hasClaudeSonnet);
    }

    // ─── getOutputStyle() ────────────────────────────────────────────────

    public function test_output_style_null_by_default(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->getOutputStyle());
    }

    public function test_output_style_set_via_runtime_override(): void
    {
        $manager = $this->makeManager();
        $manager->set('output_style', 'verbose');
        $this->assertSame('verbose', $manager->getOutputStyle());
    }

    public function test_theme_defaults_to_dark(): void
    {
        $manager = $this->makeManager();
        $this->assertSame('dark', $manager->getTheme());
    }

    public function test_theme_set_via_runtime_override(): void
    {
        $manager = $this->makeManager();
        $manager->set('theme', 'ansi');
        $this->assertSame('ansi', $manager->getTheme());
    }

    public function test_statusline_enabled_defaults_true_and_can_be_overridden(): void
    {
        $manager = $this->makeManager();
        $this->assertTrue($manager->isStatuslineEnabled());

        $manager->set('statusline_enabled', false);

        $this->assertFalse($manager->isStatuslineEnabled());
    }

    public function test_statusline_helpers_are_available_from_feature_manager(): void
    {
        $manager = $this->makeManager();

        $this->assertSame('expanded', $manager->getStatuslineLayout());
        $this->assertSame(2, $manager->getStatuslinePathLevels());
        $this->assertTrue($manager->shouldShowStatuslineTools());
        $this->assertTrue($manager->shouldShowStatuslineAgents());
        $this->assertTrue($manager->shouldShowStatuslineTodos());
    }
}
