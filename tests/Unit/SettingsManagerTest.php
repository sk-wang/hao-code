<?php

namespace Tests\Unit;

use App\Services\Settings\SettingsManager;
use Tests\TestCase;

class SettingsManagerTest extends TestCase
{
    public function test_it_maps_claude_models_to_kimi_for_kimi_coding_endpoint(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.kimi.com/coding/',
            'haocode.model' => 'claude-sonnet-4-20250514',
        ]);

        $settings = new SettingsManager;

        $this->assertSame('kimi-for-coding', $settings->getModel());
        $this->assertContains('kimi-for-coding', SettingsManager::getAvailableModels());
    }

    public function test_it_keeps_the_configured_model_for_non_kimi_endpoints(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-20250514',
        ]);

        $settings = new SettingsManager;

        $this->assertSame('claude-sonnet-4-20250514', $settings->getModel());
    }

    // ─── runtime overrides ────────────────────────────────────────────────

    public function test_set_runtime_override_affects_get_model(): void
    {
        config(['haocode.api_base_url' => 'https://api.anthropic.com']);

        $settings = new SettingsManager;
        $settings->set('model', 'claude-haiku-4-20250514');

        $this->assertSame('claude-haiku-4-20250514', $settings->getModel());
    }

    public function test_set_ignores_unknown_keys(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-20250514',
        ]);

        $settings = new SettingsManager;
        $settings->set('unknown_key', 'anything');

        // Should not affect model
        $this->assertSame('claude-sonnet-4-20250514', $settings->getModel());
    }

    // ─── getBaseUrl ───────────────────────────────────────────────────────

    public function test_get_base_url_returns_configured_value(): void
    {
        config(['haocode.api_base_url' => 'https://custom.api.com']);

        $settings = new SettingsManager;

        $this->assertSame('https://custom.api.com', $settings->getBaseUrl());
    }

    public function test_set_runtime_override_affects_base_url(): void
    {
        $settings = new SettingsManager;
        $settings->set('api_base_url', 'https://override.api.com');

        $this->assertSame('https://override.api.com', $settings->getBaseUrl());
    }

    // ─── getMaxTokens ─────────────────────────────────────────────────────

    public function test_get_max_tokens_returns_configured_value(): void
    {
        config(['haocode.max_tokens' => 8192]);

        $settings = new SettingsManager;

        $this->assertSame(8192, $settings->getMaxTokens());
    }

    // ─── getPermissionMode ────────────────────────────────────────────────

    public function test_get_permission_mode_returns_default(): void
    {
        config(['haocode.permission_mode' => 'default']);

        $settings = new SettingsManager;

        $this->assertSame(\App\Services\Permissions\PermissionMode::Default, $settings->getPermissionMode());
    }

    // ─── all() ────────────────────────────────────────────────────────────

    public function test_all_returns_expected_keys(): void
    {
        config([
            'haocode.api_base_url' => 'https://api.anthropic.com',
            'haocode.model' => 'claude-sonnet-4-20250514',
        ]);

        $settings = new SettingsManager;
        $all = $settings->all();

        $this->assertArrayHasKey('model', $all);
        $this->assertArrayHasKey('api_base_url', $all);
        $this->assertArrayHasKey('max_tokens', $all);
        $this->assertArrayHasKey('permission_mode', $all);
        $this->assertArrayHasKey('theme', $all);
        $this->assertArrayHasKey('output_style', $all);
        $this->assertArrayHasKey('statusline_enabled', $all);
        $this->assertArrayHasKey('statusline_layout', $all);
        $this->assertArrayHasKey('statusline_path_levels', $all);
        $this->assertArrayHasKey('statusline_show_tools', $all);
        $this->assertArrayHasKey('statusline_show_agents', $all);
        $this->assertArrayHasKey('statusline_show_todos', $all);
        $this->assertArrayHasKey('api_key_set', $all);
    }

    public function test_statusline_defaults_to_enabled_and_supports_runtime_override(): void
    {
        $settings = new SettingsManager;
        $this->assertTrue($settings->isStatuslineEnabled());

        $settings->set('statusline_enabled', false);

        $this->assertFalse($settings->isStatuslineEnabled());
    }

    public function test_statusline_defaults_include_layout_path_depth_and_section_toggles(): void
    {
        $settings = new SettingsManager;
        $statusline = $settings->getStatuslineConfig();

        $this->assertSame('expanded', $statusline['layout']);
        $this->assertSame(2, $statusline['path_levels']);
        $this->assertTrue($statusline['show_tools']);
        $this->assertTrue($statusline['show_agents']);
        $this->assertTrue($statusline['show_todos']);
    }

    public function test_statusline_runtime_overrides_are_normalized(): void
    {
        $settings = new SettingsManager;
        $settings->set('statusline_layout', 'compact');
        $settings->set('statusline_path_levels', 9);
        $settings->set('statusline_show_tools', 'off');
        $settings->set('statusline_show_agents', '0');
        $settings->set('statusline_show_todos', 'yes');

        $statusline = $settings->getStatuslineConfig();

        $this->assertSame('compact', $statusline['layout']);
        $this->assertSame(3, $statusline['path_levels']);
        $this->assertFalse($statusline['show_tools']);
        $this->assertFalse($statusline['show_agents']);
        $this->assertTrue($statusline['show_todos']);
    }

    public function test_statusline_setters_persist_project_configuration(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_statusline_' . getmypid() . '_' . uniqid();
        mkdir($tmpDir . '/.haocode', 0755, true);

        config(['haocode.global_settings_path' => '/nonexistent/path/settings.json']);

        $origDir = getcwd();
        chdir($tmpDir);

        try {
            $settings = new SettingsManager;
            $settings->setStatuslineLayout('compact');
            $settings->setStatuslinePathLevels(1);
            $settings->setStatuslineSectionVisibility('tools', false);
            $settings->setStatuslineSectionVisibility('agents', false);

            $reloaded = new SettingsManager;
            $statusline = $reloaded->getStatuslineConfig();

            $this->assertSame('compact', $statusline['layout']);
            $this->assertSame(1, $statusline['path_levels']);
            $this->assertFalse($statusline['show_tools']);
            $this->assertFalse($statusline['show_agents']);
            $this->assertTrue($statusline['show_todos']);
        } finally {
            chdir($origDir);
            @unlink($tmpDir . '/.haocode/settings.json');
            @rmdir($tmpDir . '/.haocode');
            @rmdir($tmpDir);
        }
    }

    // ─── permissions merge from global + project settings ─────────────────

    public function test_permissions_from_global_and_project_are_accumulated_not_overwritten(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_merge_' . getmypid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Bash(git:*)'], 'deny' => []],
        ]));

        // Project only sets deny rules — should not lose global allow rules
        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'permissions' => ['deny' => ['Bash(rm -rf /)']],
        ]));

        config(['haocode.global_settings_path' => $globalDir . '/settings.json']);

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;

            // Global allow rule must survive despite project only having deny rules
            $this->assertContains('Bash(git:*)', $settings->getAllowRules(),
                'Global allow rule was lost when project settings define only deny rules (array_merge clobber bug)');
            $this->assertContains('Bash(rm -rf /)', $settings->getDenyRules(),
                'Project deny rule should be present');
        } finally {
            chdir($origDir);
            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }

    public function test_add_allow_rule_does_not_create_duplicates(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_dedup_' . getmypid();
        $projectSettingsDir = $tmpDir . '/.haocode';
        mkdir($projectSettingsDir, 0755, true);

        config(['haocode.global_settings_path' => '/nonexistent/path/settings.json']);

        $origDir = getcwd();
        chdir($tmpDir);

        try {
            $settings = new SettingsManager;

            // Add same rule twice
            $settings->addAllowRule('Bash(git:*)');
            $settings->addAllowRule('Bash(git:*)');

            $settings2 = new SettingsManager;
            $allow = $settings2->getAllowRules();

            $count = count(array_filter($allow, fn($r) => $r === 'Bash(git:*)'));
            $this->assertSame(1, $count, 'Same allow rule should not be added twice');
        } finally {
            chdir($origDir);
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($projectSettingsDir);
            @rmdir($tmpDir);
        }
    }

    public function test_both_global_and_project_allow_rules_are_present_after_load(): void
    {
        $tmpDir = sys_get_temp_dir() . '/smtest_combined_' . getmypid();
        $globalDir = $tmpDir . '/global/.haocode';
        $projectDir = $tmpDir . '/project';
        $projectSettingsDir = $projectDir . '/.haocode';
        mkdir($globalDir, 0755, true);
        mkdir($projectSettingsDir, 0755, true);

        file_put_contents($globalDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Bash(git:*)']],
        ]));

        file_put_contents($projectSettingsDir . '/settings.json', json_encode([
            'permissions' => ['allow' => ['Read(*:*)']],
        ]));

        config(['haocode.global_settings_path' => $globalDir . '/settings.json']);

        $origDir = getcwd();
        chdir($projectDir);

        try {
            $settings = new SettingsManager;
            $allow = $settings->getAllowRules();

            $this->assertContains('Bash(git:*)', $allow, 'Global allow rule should be present');
            $this->assertContains('Read(*:*)', $allow, 'Project allow rule should be present');
        } finally {
            chdir($origDir);
            @unlink($globalDir . '/settings.json');
            @unlink($projectSettingsDir . '/settings.json');
            @rmdir($globalDir);
            @rmdir($projectSettingsDir);
            @rmdir(dirname($globalDir));
            @rmdir($projectDir);
            @rmdir($tmpDir);
        }
    }
}
