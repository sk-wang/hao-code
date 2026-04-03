<?php

namespace Tests\Feature;

use App\Services\Agent\ContextBuilder;
use App\Services\Git\GitContext;
use App\Services\Memory\SessionMemory;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Settings\SettingsManager;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    private function makeBuilder(array $overrides = []): ContextBuilder
    {
        $settings = $overrides['settings'] ?? $this->makeSettings();
        $toolRegistry = $overrides['toolRegistry'] ?? $this->createMock(ToolRegistry::class);
        $sessionMemory = $overrides['sessionMemory'] ?? $this->makeSessionMemory();
        $skillLoader = $overrides['skillLoader'] ?? $this->makeSkillLoader();
        $gitContext = $overrides['gitContext'] ?? $this->makeGitContext();
        $styleLoader = $overrides['styleLoader'] ?? null;

        return new ContextBuilder($settings, $toolRegistry, $sessionMemory, $skillLoader, $gitContext, $styleLoader);
    }

    private function makeSettings(array $stubs = []): SettingsManager
    {
        $m = $this->createMock(SettingsManager::class);
        $m->method('getAppendSystemPrompt')->willReturn($stubs['appendPrompt'] ?? null);
        $m->method('getOutputStyle')->willReturn($stubs['outputStyle'] ?? null);
        return $m;
    }

    private function makeSessionMemory(string $memories = ''): SessionMemory
    {
        $m = $this->createMock(SessionMemory::class);
        $m->method('forSystemPrompt')->willReturn($memories);
        return $m;
    }

    private function makeSkillLoader(string $descriptions = ''): SkillLoader
    {
        $m = $this->createMock(SkillLoader::class);
        $m->method('getSkillDescriptions')->willReturn($descriptions);
        return $m;
    }

    private function makeGitContext(string $diffContext = ''): GitContext
    {
        $m = $this->createMock(GitContext::class);
        $m->method('getDiffContext')->willReturn($diffContext);
        return $m;
    }

    // ─── buildSystemPrompt — return shape ─────────────────────────────────

    public function test_returns_array(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertIsArray($result);
    }

    public function test_returns_single_element_array(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertCount(1, $result);
    }

    public function test_element_has_type_text(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertSame('text', $result[0]['type']);
    }

    public function test_element_has_cache_control_ephemeral(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertSame(['type' => 'ephemeral'], $result[0]['cache_control']);
    }

    public function test_text_field_is_string(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertIsString($result[0]['text']);
    }

    // ─── buildSystemPrompt — content sections ─────────────────────────────

    public function test_prompt_contains_environment_section(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringContainsString('# Environment', $result[0]['text']);
    }

    public function test_prompt_contains_current_date(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringContainsString(date('Y-m-d'), $result[0]['text']);
    }

    public function test_append_system_prompt_is_included(): void
    {
        $settings = $this->makeSettings(['appendPrompt' => 'Extra instructions here']);
        $result = $this->makeBuilder(['settings' => $settings])->buildSystemPrompt();
        $this->assertStringContainsString('Extra instructions here', $result[0]['text']);
    }

    public function test_session_memory_is_included_when_non_empty(): void
    {
        $sessionMemory = $this->makeSessionMemory("Remember: user prefers concise responses");
        $result = $this->makeBuilder(['sessionMemory' => $sessionMemory])->buildSystemPrompt();
        $this->assertStringContainsString('# Session Memory', $result[0]['text']);
        $this->assertStringContainsString('user prefers concise responses', $result[0]['text']);
    }

    public function test_session_memory_section_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Session Memory', $result[0]['text']);
    }

    public function test_skills_section_included_when_non_empty(): void
    {
        $skillLoader = $this->makeSkillLoader("/commit — Create a commit");
        $result = $this->makeBuilder(['skillLoader' => $skillLoader])->buildSystemPrompt();
        $this->assertStringContainsString('# Skills', $result[0]['text']);
        $this->assertStringContainsString('/commit', $result[0]['text']);
    }

    public function test_skills_section_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Skills', $result[0]['text']);
    }

    public function test_git_context_appended(): void
    {
        $gitContext = $this->makeGitContext("Branch: main\n# Git Status\nclean");
        $result = $this->makeBuilder(['gitContext' => $gitContext])->buildSystemPrompt();
        $this->assertStringContainsString('Branch: main', $result[0]['text']);
    }

    public function test_git_context_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('Branch:', $result[0]['text']);
    }

    public function test_output_style_injected_when_set(): void
    {
        $settings = $this->makeSettings(['outputStyle' => 'terse']);

        $styleLoader = $this->createMock(OutputStyleLoader::class);
        $styleLoader->method('getActiveStyleContent')
            ->with('terse')
            ->willReturn('Be very brief.');

        $result = $this->makeBuilder(['settings' => $settings, 'styleLoader' => $styleLoader])
            ->buildSystemPrompt();

        $this->assertStringContainsString('# Output Style Instructions', $result[0]['text']);
        $this->assertStringContainsString('Be very brief.', $result[0]['text']);
    }

    public function test_output_style_absent_when_null(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Output Style Instructions', $result[0]['text']);
    }

    public function test_output_style_absent_when_loader_not_provided(): void
    {
        $settings = $this->makeSettings(['outputStyle' => 'verbose']);
        // No styleLoader passed
        $result = $this->makeBuilder(['settings' => $settings])->buildSystemPrompt();
        $this->assertStringNotContainsString('# Output Style Instructions', $result[0]['text']);
    }
}
