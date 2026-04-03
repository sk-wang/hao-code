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
        $this->assertArrayHasKey('api_key_set', $all);
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
